<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CapesAreaVariant;
use App\Service\Journal\JournalAreaThesaurus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tesauro de categorias de revistas (docs/THESAURUS.md): semeia `capes_area_variants` dos arquivos de
 * `config/journal_areas`, recalcula o uso de cada rótulo e abre uma linha pendente para o rótulo usado e ainda sem
 * área. A regra está em JournalAreaThesaurus; aqui só ficam a opção e o relatório.
 */
#[AsCommand(
    name: 'app:journals:areas:thesaurus',
    description: 'Semeia o tesauro de categorias de revistas (rótulo => área CAPES), conta o uso de cada rótulo e lista os pendentes'
)]
class JournalsAreasThesaurusCommand extends Command
{
    private const STATUS_LABELS = [
        CapesAreaVariant::STATUS_ACTIVE => 'Ativos',
        CapesAreaVariant::STATUS_PENDING => 'Pendentes',
        CapesAreaVariant::STATUS_IGNORED => 'Ignorados',
    ];

    public function __construct(
        private readonly JournalAreaThesaurus $thesaurus
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcula e mostra o relatório sem gravar nada (tudo é feito e desfeito)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');

        $io = new SymfonyStyle($input, $output);
        $io->title('Tesauro de categorias de revistas');

        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $result = $this->thesaurus->sync($dryRun);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            if ($output->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }

            return Command::FAILURE;
        }

        $io->table(
            ['Resultado', 'Linhas'],
            [
                [$dryRun ? 'Sementes a criar (dos arquivos e da taxonomia)' : 'Sementes criadas (dos arquivos e da taxonomia)', $this->format($result['inserted'])],
                [$dryRun ? 'Linhas a atualizar (rótulo ou número de revistas)' : 'Linhas atualizadas (rótulo ou número de revistas)', $this->format($result['updated'])],
                [$dryRun ? 'Sementes a remover (saíram do arquivo)' : 'Sementes removidas (saíram do arquivo)', $this->format($result['deleted'])],
                ['Rótulos com correção manual (não são tocados)', $this->format($result['protected'])],
                [$dryRun ? 'Pendentes a criar (rótulo usado e não reconhecido)' : 'Pendentes criados (rótulo usado e não reconhecido)', $this->format($result['pending_inserted'])],
                [$dryRun ? 'Pendentes a apagar (reconhecidos ou sem uso)' : 'Pendentes apagados (reconhecidos ou sem uso)', $this->format($result['pending_deleted'])],
            ]
        );

        $rows = [];
        $totals = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach ($result['vocabularies'] as $vocabulary => $counts) {
            $rows[] = [
                $vocabulary,
                $this->format($counts[CapesAreaVariant::STATUS_ACTIVE]),
                $this->format($counts[CapesAreaVariant::STATUS_PENDING]),
                $this->format($counts[CapesAreaVariant::STATUS_IGNORED]),
                $this->format(array_sum($counts)),
            ];
            foreach (array_keys(self::STATUS_LABELS) as $status) {
                $totals[$status] += $counts[$status];
            }
        }
        $rows[] = [
            '<info>total</info>',
            '<info>' . $this->format($totals[CapesAreaVariant::STATUS_ACTIVE]) . '</info>',
            '<info>' . $this->format($totals[CapesAreaVariant::STATUS_PENDING]) . '</info>',
            '<info>' . $this->format($totals[CapesAreaVariant::STATUS_IGNORED]) . '</info>',
            '<info>' . $this->format(array_sum($totals)) . '</info>',
        ];
        $io->section('Tabela depois da execução' . ($dryRun ? ' (simulada)' : ''));
        $io->table(['Vocabulário', self::STATUS_LABELS[CapesAreaVariant::STATUS_ACTIVE], self::STATUS_LABELS[CapesAreaVariant::STATUS_PENDING], self::STATUS_LABELS[CapesAreaVariant::STATUS_IGNORED], 'Total'], $rows);

        if ($result['pending'] !== []) {
            $io->section('Pendentes (rótulos usados que o tesauro ainda não reconhece)');
            $io->table(
                ['Vocabulário', 'Rótulo', 'Usos'],
                array_map(
                    fn (array $pending): array => [$pending['vocabulary'], $pending['label'], $this->format($pending['occurrences'])],
                    $result['pending']
                )
            );
            if ($result['pending_total'] > count($result['pending'])) {
                $io->writeln(sprintf('  ... e mais %s pendente(s).', $this->format($result['pending_total'] - count($result['pending']))));
            }
            $io->note('Decida cada pendente em /admin/journal-categories: definir a área ou ignorar o rótulo.');
        }

        $seconds = number_format($result['seconds'], 1, ',', '.');
        if ($dryRun) {
            $io->warning(sprintf('Modo simulação (--dry-run): nada foi gravado no banco (%s s).', $seconds));
        } else {
            $io->success(sprintf(
                '%s semente(s) criada(s), %s linha(s) atualizada(s), %s removida(s); %s pendente(s) em aberto (%s s). A classificação só muda ao rodar app:journals:classify (ou o pipeline).',
                $this->format($result['inserted']),
                $this->format($result['updated']),
                $this->format($result['deleted']),
                $this->format($result['pending_total']),
                $seconds
            ));
        }

        return Command::SUCCESS;
    }

    private function format(int $number): string
    {
        return number_format($number, 0, ',', '.');
    }
}
