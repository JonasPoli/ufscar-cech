<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\RepositoryImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:repository',
    description: 'Importa e enriquece teses e dissertações do Repositório Institucional da UFSCar (TeD-UFSCar)',
    aliases: ['app:import:ted']
)]
class ImportRepositoryCommand extends Command
{
    public function __construct(
        private readonly RepositoryImportService $importService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', 'f', InputOption::VALUE_OPTIONAL, 'Caminho do arquivo CSV de Teses e Dissertações', 'docs/banco/TeD-UFSCar.csv')
            ->addOption('center', 'c', InputOption::VALUE_OPTIONAL, 'Filtrar por centro acadêmico (ex: CECH)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limitar o número de registros processados')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Executa em modo de simulação sem alterar o banco de dados');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startTime = microtime(true);

        $filePath = (string)$input->getOption('file');
        $centerFilter = $input->getOption('center');
        $limit = $input->getOption('limit') !== null ? (int)$input->getOption('limit') : null;
        $dryRun = (bool)$input->getOption('dry-run');

        $io->title('Importação do Repositório Institucional da UFSCar (TeD-UFSCar)');

        if (!file_exists($filePath)) {
            $io->error(sprintf('Arquivo não encontrado: %s', $filePath));
            return Command::FAILURE;
        }

        $io->section('Parâmetros de Execução');
        $io->listing([
            sprintf('Arquivo CSV: <info>%s</info> (%s)', $filePath, $this->formatBytes((int)filesize($filePath))),
            sprintf('Modo Simulação (--dry-run): <info>%s</info>', $dryRun ? 'SIM (Nenhuma alteração será gravada)' : 'NÃO (Gravação em banco ativa)'),
            sprintf('Filtro de Centro: <info>%s</info>', $centerFilter ?: 'Nenhum (Todos os docentes cadastrados no sistema)'),
            sprintf('Limite de Registros: <info>%s</info>', $limit !== null ? (string)$limit : 'Sem limite (Arquivo completo)'),
        ]);

        $io->section('Processando base de dados...');

        $progressBar = $io->createProgressBar();
        $progressBar->setFormat(' %current% registros processados [%bar%] %elapsed% (Mem: %memory%)');

        try {
            $stats = $this->importService->import(
                csvFilePath: $filePath,
                dryRun: $dryRun,
                limit: $limit,
                centerFilter: $centerFilter,
                progressCallback: function (int $processed, int $total) use ($progressBar) {
                    $progressBar->advance();
                }
            );

            $progressBar->finish();
            $io->newLine(2);

            $executionTime = round(microtime(true) - $startTime, 2);
            $memoryUsage = $this->formatBytes(memory_get_peak_usage(true));

            $io->section('Relatório Final de Importação e Enriquecimento');

            $table = new Table($output);
            $table->setHeaders(['Métrica', 'Quantidade', 'Descrição / Status']);
            $table->addRows([
                ['Total de Linhas no CSV', number_format($stats['totalCsvRows'], 0, ',', '.'), 'Obras cadastradas no arquivo'],
                ['Linhas Processadas', number_format($stats['processedRows'], 0, ',', '.'), 'Registros analisados conforme filtros'],
                new TableSeparator(),
                ['Match: Orientador(a)', number_format($stats['matchedAdvisorRows'], 0, ',', '.'), 'Obras com orientador(a) vinculado(a) no sistema'],
                ['Match: Coorientador(a)', number_format($stats['matchedCoadvisorRows'], 0, ',', '.'), 'Obras com coorientador(a) vinculado(a) no sistema'],
                ['Não Vinculados ao Sistema', number_format($stats['unmatchedRows'], 0, ',', '.'), 'Docentes de outros centros/universidades'],
                new TableSeparator(),
                ['<info>Orientações Enriquecidas</info>', '<info>' . number_format($stats['enrichedOrientations'], 0, ',', '.') . '</info>', '<info>Match com Lattes &rarr; Link Handle, PPG e Resumo adicionados</info>'],
                ['<comment>Novas Orientações Criadas</comment>', '<comment>' . number_format($stats['newOrientationsCreated'], 0, ',', '.') . '</comment>', '<comment>Exclusivas do Repositório (Não constavam no Lattes)</comment>'],
                ['<fg=cyan>Orientações Puladas / Idempotência</>', '<fg=cyan>' . number_format($stats['skippedOrientations'], 0, ',', '.') . '</>', '<fg=cyan>Já importadas / Sem alterações necessárias</>'],
                new TableSeparator(),
                ['Tempo de Execução', sprintf('%s segundos', $executionTime), ''],
                ['Pico de Memória', $memoryUsage, ''],
            ]);
            $table->render();

            if ($dryRun) {
                $io->warning('Modo Simulação concluído. Nenhuma alteração foi gravada no banco de dados. Execute sem --dry-run para aplicar.');
            } else {
                $io->success(sprintf('Importação concluída com sucesso! %d orientações enriquecidas e %d novas orientações cadastradas.', $stats['enrichedOrientations'], $stats['newOrientationsCreated']));
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $progressBar->finish();
            $io->newLine(2);
            $io->error(sprintf('Erro durante a execução do comando: %s', $e->getMessage()));
            if ($output->isVeryVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int)floor(log($bytes, 1024)) : 0;
        return number_format($bytes / (1024 ** $power), 2, ',', '.') . ' ' . ($units[$power] ?? 'B');
    }
}
