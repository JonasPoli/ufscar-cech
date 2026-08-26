<?php

namespace App\Command;

use App\Service\Backup\DatabaseBackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:database:dump',
    description: 'Exporta a base de dados completa do CECH em arquivo SQL superdump comprimido (.zip)',
    aliases: ['app:backup:export', 'app:db:dump']
)]
class DatabaseDumpCommand extends Command
{
    public function __construct(
        private readonly DatabaseBackupService $backupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('no-zip', null, InputOption::VALUE_NONE, 'Não comprimir o dump em ZIP (mantém .sql descompactado)')
            ->addOption('filename', 'f', InputOption::VALUE_REQUIRED, 'Nome customizado do arquivo (sem extensão)')
            ->setHelp('Este comando gera um superdump completo com todas as tabelas, esquemas, chaves estrangeiras e dados da aplicação, gerando um pacote .zip pronto para restauração.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Exportação de Superdump da Base de Dados - UFSCar CECH');

        $useZip = !$input->getOption('no-zip');
        $customFilename = $input->getOption('filename');

        $overview = $this->backupService->getDatabaseOverview();

        $io->section('Informações do Banco de Dados');
        $io->table(
            ['Propriedade', 'Valor'],
            [
                ['Banco de Dados', $overview['database']],
                ['Versão do Servidor', $overview['serverVersion']],
                ['Total de Tabelas', (string)$overview['tableCount']],
                ['Total Estimado de Registros', number_format($overview['totalRows'], 0, ',', '.')],
                ['Tamanho dos Dados', $overview['dataSizeFormatted']],
                ['Tamanho dos Índices', $overview['indexSizeFormatted']],
                ['Tamanho Total em Disco', $overview['totalSizeFormatted']],
                ['Formato de Saída', $useZip ? 'Arquivo Compactado (.sql.zip)' : 'Arquivo SQL (.sql)'],
                ['Diretório de Destino', $this->backupService->getBackupDir()],
            ]
        );

        $progressBar = null;
        if ($output->isVerbose() || !$output->isQuiet()) {
            $progressBar = new ProgressBar($output, $overview['tableCount']);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
            $progressBar->setMessage('Iniciando exportação...');
            $progressBar->start();
        }

        $progressCallback = function(
            string $status,
            string $currentTable,
            int $tableIndex,
            int $totalTables,
            int $percent,
            int $processedRows,
            int $totalRows
        ) use ($progressBar) {
            if (!$progressBar) {
                return;
            }

            if ($status === 'dumping_table') {
                $progressBar->setProgress($tableIndex);
                $progressBar->setMessage("Exportando tabela: <info>{$currentTable}</info> (" . number_format($processedRows, 0, ',', '.') . " linhas)");
            } elseif ($status === 'compressing') {
                $progressBar->setProgress($totalTables);
                $progressBar->setMessage('<comment>Comprimindo arquivo SQL para ZIP...</comment>');
            } elseif ($status === 'completed') {
                $progressBar->finish();
            }
        };

        try {
            $result = $this->backupService->exportDatabase($useZip, $progressCallback, $customFilename);

            if ($progressBar) {
                $io->newLine(2);
            }

            $io->success(sprintf('Superdump gerado com sucesso em %s segundos!', $result['durationSec']));

            $io->table(
                ['Métrica', 'Resultado'],
                [
                    ['Arquivo Gerado', $result['filename']],
                    ['Caminho Completo', $result['filePath']],
                    ['Tamanho do Arquivo', $result['fileSizeFormatted']],
                    ['Tamanho SQL Original', $result['sqlSizeFormatted']],
                    ['Tabelas Processadas', (string)$result['tableCount']],
                    ['Total de Linhas Exportadas', number_format($result['rowCount'], 0, ',', '.')],
                    ['Tempo Decorrido', $result['durationSec'] . ' s'],
                ]
            );

            $io->note('Para restaurar este backup em outra máquina:' . PHP_EOL .
                '1. Descompacte o arquivo: unzip ' . $result['filename'] . PHP_EOL .
                '2. Importe no MySQL: mysql -u <usuario> -p <banco> < ' . basename($result['filePath'], '.zip')
            );

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($progressBar) {
                $io->newLine();
            }
            $io->error('Erro ao gerar superdump da base de dados: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
