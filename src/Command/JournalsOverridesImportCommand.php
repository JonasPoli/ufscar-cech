<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Journal\JournalOverridesImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Aplica o arquivo de overrides curados (docs/evolutions/periodicos-por-base.md, A6.8). Idempotente; linhas que não
 * podem ser aplicadas são puladas e listadas, sem interromper a importação.
 */
#[AsCommand(
    name: 'app:journals:overrides:import',
    description: 'Aplica os overrides curados de periódicos (JSONL): área, nome, família e nome exibido.'
)]
class JournalsOverridesImportCommand extends Command
{
    public function __construct(
        private readonly JournalOverridesImporter $importer,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Arquivo JSONL de overrides (relativo à raiz do projeto ou absoluto)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Aplica tudo numa transação e desfaz no fim, sem gravar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');

        $io = new SymfonyStyle($input, $output);
        $io->title('Overrides de periódicos');

        $file = $this->resolvePath((string) $input->getArgument('file'));
        $dryRun = (bool) $input->getOption('dry-run');
        if (!is_file($file)) {
            $io->error(sprintf('Arquivo de overrides não encontrado: %s', $file));

            return Command::FAILURE;
        }

        try {
            $report = $this->importer->importFile($file, $dryRun);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $byType = [];
        $skippedRows = [];
        foreach ($report['lines'] as $line) {
            $type = $line['type'] !== '' ? $line['type'] : '(inválida)';
            $byType[$type] ??= ['aplicado' => 0, 'pulado' => 0];
            ++$byType[$type][$line['status']];
            if ($line['status'] === JournalOverridesImporter::STATUS_SKIPPED) {
                $skippedRows[] = [$line['line'], $type, $line['message']];
            }
        }

        $io->definitionList(
            ['Arquivo' => $file],
            ['Linhas aplicadas' => (string) $report['applied']],
            ['Linhas puladas' => (string) $report['skipped']],
        );

        if ($byType !== []) {
            $rows = [];
            foreach ($byType as $type => $counts) {
                $rows[] = [$type, $counts['aplicado'], $counts['pulado']];
            }
            $io->table(['Tipo', 'Aplicadas', 'Puladas'], $rows);
        }

        if ($skippedRows !== []) {
            $io->section('Linhas puladas (revisar)');
            $io->table(['Linha', 'Tipo', 'Motivo'], $skippedRows);
        }

        if ($dryRun) {
            $io->warning('Modo simulação (--dry-run): nenhuma alteração foi gravada no banco.');
        } else {
            $io->success(sprintf('%d linha(s) aplicada(s), %d pulada(s).', $report['applied'], $report['skipped']));
        }

        return Command::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;
    }
}
