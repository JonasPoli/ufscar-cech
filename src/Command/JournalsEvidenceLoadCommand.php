<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Journal\JournalEvidenceLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:journals:evidence:load',
    description: 'Carrega as evidências de área do artefato (journal_evidence.jsonl.gz) no banco; opções criam linhas faltantes, evidência legada e regras de título',
)]
class JournalsEvidenceLoadCommand extends Command
{
    private const DEFAULT_ARTIFACT = 'docs/banco/derived/journal_evidence.jsonl.gz';

    public function __construct(
        private readonly JournalEvidenceLoader $loader,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
                Grava em <info>journal_area_evidence</info> um rótulo bruto de fonte por linha (A6.3). A carga é idempotente: antes de
                inserir apaga as evidências das fontes declaradas em <info>_meta.sources</info> do artefato (nunca <info>ai</info>, <info>legacy</info> nem
                <info>title_rule</info>) e roda numa transação única.

                  <info>--create-missing-for-articles</info>  cria a linha do cadastro para ISSN de artigo que só existe nas fontes (A5.5)
                  <info>--legacy</info>                       evidência <info>legacy</info> a partir do valor original de area (nomes de áreas CAPES)
                  <info>--title-rules</info>                  evidência <info>title_rule</info> a partir do título (precisa de families antes)
                  <info>--dry-run</info>                      faz tudo e desfaz; mostra os números da carga real

                <comment>Atenção:</comment> <info>--title-rules</info> sozinho aplica só as regras de título e NÃO lê o artefato (passo 11 de A8);
                combinado com <info>--legacy</info> ou <info>--create-missing-for-articles</info>, recarrega também o artefato.
                HELP)
            ->addArgument('artifact', InputArgument::OPTIONAL, 'Artefato de evidências (relativo à raiz do projeto ou absoluto)', self::DEFAULT_ARTIFACT)
            ->addOption('create-missing-for-articles', null, InputOption::VALUE_NONE, 'Cria a linha do cadastro para artigo com ISSN válido ausente do cadastro e presente nas fontes')
            ->addOption('legacy', null, InputOption::VALUE_NONE, 'Cria evidência "legacy" para linhas cujo valor original de area é o nome de uma área CAPES')
            ->addOption('title-rules', null, InputOption::VALUE_NONE, 'Cria evidência "title_rule" pelas regras de título; sozinha, não lê o artefato')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Executa a carga numa transação e a desfaz, sem gravar no banco');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');

        $io = new SymfonyStyle($input, $output);
        $io->title('Carga de evidências de área dos periódicos');

        $createMissing = (bool) $input->getOption('create-missing-for-articles');
        $legacy = (bool) $input->getOption('legacy');
        $titleRules = (bool) $input->getOption('title-rules');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($titleRules && !$legacy && !$createMissing) {
            return $this->executeTitleRulesOnly($io, $output, $dryRun);
        }

        $artifact = $this->resolvePath((string) $input->getArgument('artifact'));
        $io->definitionList(
            ['Artefato' => $artifact],
            ['Linhas faltantes (--create-missing-for-articles)' => $createMissing ? 'sim' : 'não'],
            ['Legado (--legacy)' => $legacy ? 'sim' : 'não'],
            ['Regras de título (--title-rules)' => $titleRules ? 'sim' : 'não'],
            ['Simulação (--dry-run)' => $dryRun ? 'sim, nada será gravado' : 'não']
        );

        try {
            $result = $this->loader->load(
                $artifact,
                $createMissing,
                $legacy,
                $titleRules,
                static function (string $message) use ($io): void {
                    $io->writeln(' ' . $message);
                },
                $dryRun
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            if ($output->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }

            return Command::FAILURE;
        }

        $io->newLine();
        $this->renderResult($io, $result, $createMissing, $legacy, $titleRules);

        if ($dryRun) {
            $io->warning('Modo simulação (--dry-run): nenhuma alteração foi gravada no banco.');
        } else {
            $extras = [];
            if ($legacy) {
                $extras[] = sprintf('%s legacy', $this->number($result['legacy_rows']));
            }
            if ($titleRules) {
                $extras[] = sprintf('%s title_rule', $this->number($result['title_rule_rows']));
            }
            $io->success(sprintf(
                'Carga concluída: %s linhas de evidência do artefato em %s linhas do cadastro (lote %s)%s.',
                $this->number($result['evidence_rows']),
                $this->number($result['journals_hit']),
                $result['batch'],
                $extras === [] ? '' : ', mais ' . implode(' e ', $extras)
            ));
        }

        return Command::SUCCESS;
    }

    private function executeTitleRulesOnly(SymfonyStyle $io, OutputInterface $output, bool $dryRun): int
    {
        $io->note('Só as regras de título: o artefato não é lido e as evidências das demais fontes ficam como estão.');

        try {
            $rows = $this->loader->loadTitleRules($dryRun);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            if ($output->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->warning(sprintf('Modo simulação (--dry-run): %s linhas "title_rule" seriam gravadas; nada foi alterado.', $this->number($rows)));
        } else {
            $io->success(sprintf('%s linhas de evidência "title_rule" gravadas.', $this->number($rows)));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function renderResult(SymfonyStyle $io, array $result, bool $createMissing, bool $legacy, bool $titleRules): void
    {
        $recordRows = [];
        foreach ($result['records_by_source'] as $source => $count) {
            $recordRows[] = [$source, $this->number($count)];
        }
        $recordRows[] = ['<info>total</info>', '<info>' . $this->number($result['records']) . '</info>'];
        $io->table(['Fonte do artefato', 'Registros lidos'], $recordRows);

        $sourceRows = [];
        foreach ($result['by_source'] as $source => $count) {
            $sourceRows[] = [$source, $this->number($count)];
        }
        $sourceRows[] = ['<info>total</info>', '<info>' . $this->number(array_sum($result['by_source'])) . '</info>'];
        $io->table(['Fonte de evidência', 'Linhas gravadas'], $sourceRows);

        $summary = [
            ['Evidências anteriores removidas (fontes do artefato)', $this->number($result['deleted'])],
            ['Linhas de evidência do artefato', $this->number($result['evidence_rows'])],
            ['Linhas do cadastro atingidas', $this->number($result['journals_hit'])],
            ['Registros sem linha no cadastro', $this->number($result['records_unmatched'])],
        ];
        if ($result['records_skipped'] > 0) {
            $summary[] = ['<comment>Registros de fonte não declarada em _meta.sources (ignorados)</comment>', $this->number($result['records_skipped'])];
        }
        if ($createMissing) {
            $summary[] = ['ISSNs de artigos ausentes do cadastro', $this->number($result['missing_issns'])];
            $summary[] = ['  sem registro nas fontes (nada a criar)', $this->number($result['missing_without_record'])];
            $summary[] = ['Linhas criadas', $this->number($result['created'])];
            $summary[] = ['Vínculos com bases criados', $this->number($result['created_links'])];
        }
        if ($legacy) {
            $summary[] = ['Evidências legadas (legacy)', $this->number($result['legacy_rows'])];
        }
        if ($titleRules) {
            $summary[] = ['Evidências de regras de título (title_rule)', $this->number($result['title_rule_rows'])];
        }
        $summary[] = ['Tempo', sprintf('%.1f s', $result['seconds'])];
        $io->table(['Indicador', 'Valor'], $summary);

        if ($result['created_rows'] !== []) {
            $io->section('Linhas criadas');
            $io->table(
                ['ID', 'Título', 'ISSN impresso', 'ISSN online', 'ISSN-L', 'Fonte do título', 'Bases'],
                array_map(
                    static fn (array $row): array => [
                        $row['id'],
                        $row['title'],
                        $row['issn_imp'] ?? '',
                        $row['issn_e'] ?? '',
                        $row['issn_l'] ?? '',
                        $row['source'],
                        implode(', ', $row['bases']),
                    ],
                    $result['created_rows']
                )
            );
        }
    }

    private function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;
    }
}
