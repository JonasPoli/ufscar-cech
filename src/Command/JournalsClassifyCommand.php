<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Journal\CapesTaxonomy;
use App\Service\Journal\JournalAreaClassifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:journals:classify',
    description: 'Classifica as famílias de periódicos nas áreas CAPES (journal_areas e qualis_journals.area) a partir das evidências',
)]
class JournalsClassifyCommand extends Command
{
    /** Áreas de maior participação mostradas por `--journal`. */
    private const SHARES_SHOWN = 8;

    public function __construct(
        private readonly JournalAreaClassifier $classifier,
        private readonly CapesTaxonomy $taxonomy,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
                Lê as evidências de <info>journal_area_evidence</info> de cada família (<info>COALESCE(canonical_journal_id, id)</info>), calcula a
                área principal e até duas secundárias (A6.4) e grava:

                  - <info>journal_areas</info>: as áreas, <info>method='auto'</info>, em todas as linhas da família (as automáticas anteriores são refeitas);
                  - <info>qualis_journals.area</info>: o rótulo canônico da principal, com o texto original guardado em <info>area_raw</info>;
                    família sem classificação volta a <info>area = area_raw</info>.

                Família sem evidência mapeada e ligada à base PubMed recebe <info>Ciências da Saúde</info> (confiança baixa). Canônica com
                <info>areas_locked=1</info> é pulada. Pode rodar quantas vezes quiser: o resultado é o mesmo.

                  <info>--journal=ID</info>   classifica só a família dessa linha e mostra a decisão
                  <info>--run-id=TEXTO</info> identifica a execução em journal_areas.run_id (até 40 caracteres; padrão: data e hora)
                  <info>--dry-run</info>      calcula e relata, sem gravar nada
                HELP)
            ->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'Identificador da execução gravado em journal_areas.run_id (até 40 caracteres)')
            ->addOption('journal', null, InputOption::VALUE_REQUIRED, 'Classifica somente a família desta linha (id de qualis_journals)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcula e mostra o relatório sem gravar no banco');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');

        $io = new SymfonyStyle($input, $output);
        $io->title('Classificação de áreas dos periódicos');

        $dryRun = (bool) $input->getOption('dry-run');
        $runId = $input->getOption('run-id');
        $runId = $runId === null ? date('Ymd-Hi') : trim((string) $runId);
        if ($runId === '' || mb_strlen($runId) > 40) {
            $io->error('O --run-id deve ter de 1 a 40 caracteres.');

            return Command::FAILURE;
        }

        $journal = $input->getOption('journal');
        if ($journal !== null && (!ctype_digit((string) $journal) || (int) $journal < 1)) {
            $io->error('O --journal deve ser o id (número inteiro positivo) de uma linha de qualis_journals.');

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Execução (run id)' => $runId],
            ['Escopo' => $journal === null ? 'todas as famílias' : sprintf('a família da linha #%d', (int) $journal)],
            ['Simulação (--dry-run)' => $dryRun ? 'sim, nada será gravado' : 'não']
        );

        $startedAt = microtime(true);
        try {
            if ($journal !== null) {
                return $this->classifyOne($io, (int) $journal, $runId, $dryRun);
            }

            $summary = $this->classifyAll($io, $output, $runId, $dryRun);
        } catch (\Throwable $e) {
            $io->newLine();
            $io->error($e->getMessage());
            if ($output->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }

            return Command::FAILURE;
        }

        $this->renderSummary($io, $summary, microtime(true) - $startedAt, $dryRun);

        if ($dryRun) {
            $io->warning('Modo simulação (--dry-run): nenhuma alteração foi gravada no banco.');
        } else {
            $io->success(sprintf(
                '%s famílias classificadas (%s alta, %s média, %s baixa confiança); %s sem área; %s travadas por curadoria manual.',
                $this->number($summary['classified']),
                $this->number($summary['high']),
                $this->number($summary['medium']),
                $this->number($summary['low']),
                $this->number($summary['no_evidence']),
                $this->number($summary['locked'])
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function classifyAll(SymfonyStyle $io, OutputInterface $output, string $runId, bool $dryRun): array
    {
        $bar = null;
        $progress = static function (int $done, int $total) use ($output, &$bar): void {
            if ($bar === null) {
                $bar = new ProgressBar($output, $total);
                $bar->setFormat(' famílias %current%/%max% [%bar%] %percent:3s%%');
                $bar->start();
            }
            $bar->setProgress($done);
        };

        try {
            $summary = $this->classifier->classifyAll($runId, $progress, $dryRun);
        } finally {
            $bar?->finish();
            $io->newLine(2);
        }

        return $summary;
    }

    private function classifyOne(SymfonyStyle $io, int $rowId, string $runId, bool $dryRun): int
    {
        $result = $this->classifier->classifyFamily($rowId, $runId, $dryRun);

        if ($result === null) {
            $locked = $this->em->getConnection()->fetchAssociative(
                'SELECT f.id AS family, f.areas_locked FROM qualis_journals q JOIN qualis_journals f ON f.id = COALESCE(q.canonical_journal_id, q.id) WHERE q.id = ?',
                [$rowId]
            );

            if ($locked === false) {
                $io->error(sprintf('A linha #%d não existe em qualis_journals.', $rowId));

                return Command::FAILURE;
            }

            $io->warning(sprintf(
                'A família #%d tem areas_locked=1 (curadoria manual): nada foi alterado. Use "Voltar ao automático" no admin para destravar.',
                (int) $locked['family']
            ));

            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            ' Família #%d, %d linha(s): %s.',
            $result['family'],
            count($result['rows']),
            implode(', ', array_map(static fn (int $id): string => '#' . $id, $result['rows']))
        ));
        $io->newLine();

        if ($result['primary'] === null) {
            $io->writeln(' <comment>Sem evidência mapeada e sem vínculo com PubMed: a família fica sem área (area volta a area_raw).</comment>');
        } else {
            $rows = [['principal', $this->describe($result['primary']['slug']), $this->percent($result['primary']['share']), $result['confidence']]];
            foreach ($result['secondary'] as $secondary) {
                $rows[] = ['secundária', $this->describe($secondary['slug']), $this->percent($secondary['share']), ''];
            }
            $io->table(['Papel', 'Área', 'Participação', 'Confiança'], $rows);

            if ($result['fallback'] === 'pubmed') {
                $io->note('Fallback PubMed: sem evidência mapeada, a família está ligada à base PubMed.');
            }
        }

        if ($result['shares'] !== []) {
            $shareRows = [];
            foreach (array_slice($result['shares'], 0, self::SHARES_SHOWN, true) as $slug => $share) {
                $shareRows[] = [$this->describe($slug), $this->percent($share)];
            }
            $io->section('Participação de cada área na evidência');
            $io->table(['Área', 'Participação'], $shareRows);
        }

        $sourceRows = [];
        foreach ($result['sources'] as $source => $weight) {
            $sourceRows[] = [$source, number_format($weight, 3, ',', '.')];
        }
        if ($sourceRows !== []) {
            $sourceRows[] = ['<info>soma dos pesos</info>', '<info>' . number_format($result['total_weight'], 3, ',', '.') . '</info>'];
            $io->table(['Fonte que contribuiu', 'Peso'], $sourceRows);
        }

        if ($dryRun) {
            $io->warning('Modo simulação (--dry-run): nenhuma alteração foi gravada no banco.');
        } else {
            $io->success(sprintf('Família #%d classificada (run id %s).', $result['family'], $runId));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, int> $summary
     */
    private function renderSummary(SymfonyStyle $io, array $summary, float $seconds, bool $dryRun): void
    {
        $classified = $summary['classified'];
        $level2 = $classified - $summary['level1'];
        $coverage = $summary['visible_total'] > 0
            ? sprintf(' (%s%%)', number_format(100 * $summary['visible_classified'] / $summary['visible_total'], 1, ',', '.'))
            : '';

        $io->table(['Indicador', 'Valor'], [
            ['Famílias do cadastro', $this->number($summary['families'])],
            ['Travadas por curadoria manual (areas_locked), puladas', $this->number($summary['locked'])],
            ['Classificadas', $this->number($classified)],
            ['  confiança alta (high)', $this->number($summary['high'])],
            ['  confiança média (medium)', $this->number($summary['medium'])],
            ['  confiança baixa (low)', $this->number($summary['low'])],
            ['  principal em área de avaliação (nível 2)', $this->number($level2)],
            ['  principal só na grande área (nível 1)', $this->number($summary['level1'])],
            ['Sem evidência mapeada (sem área)', $this->number($summary['no_evidence'])],
            ['Famílias visíveis (com artigos)', $this->number($summary['visible_total'])],
            ['  com área', $this->number($summary['visible_classified']) . $coverage],
            ['Linhas de journal_areas ' . ($dryRun ? 'que seriam gravadas' : 'gravadas'), $this->number($summary['areas_written'])],
            ['Linhas de qualis_journals com area/area_raw ' . ($dryRun ? 'que seriam alteradas' : 'alterados'), $this->number($summary['rows_updated'])],
            ['Áreas automáticas não gravadas (a linha já tem a manual)', $this->number($summary['manual_kept'])],
            ['Tempo', sprintf('%.1f s', $seconds)],
            ['Pico de memória', sprintf('%.1f MB', memory_get_peak_usage(true) / 1048576)],
        ]);
    }

    private function describe(string $slug): string
    {
        return sprintf('%s (%s)', $this->taxonomy->areaLabel($slug), $slug);
    }

    private function percent(float $share): string
    {
        return number_format($share * 100, 1, ',', '.') . '%';
    }

    private function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
