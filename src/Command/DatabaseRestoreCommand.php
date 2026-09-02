<?php

namespace App\Command;

use App\Service\Backup\DatabaseBackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:database:restore',
    description: 'Restaura a base de dados do CECH a partir de um arquivo SQL/ZIP de backup gerado pelo sistema',
    aliases: ['app:db:restore', 'app:backup:import']
)]
class DatabaseRestoreCommand extends Command
{
    public function __construct(
        private readonly DatabaseBackupService $backupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Caminho ou nome do arquivo de backup (.sql, .zip ou .gz) na pasta var/backups')
            ->addOption('latest', null, InputOption::VALUE_NONE, 'Restaura automaticamente o backup mais recente disponível em var/backups')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Pula a confirmação interativa de restauração')
            ->setHelp('Este comando restaura integralmente o banco de dados a partir de um arquivo de superdump (.zip ou .sql).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Restauração de Base de Dados - UFSCar CECH');

        $fileArg = $input->getArgument('file');
        $useLatest = $input->getOption('latest');
        $force = $input->getOption('force');

        $filePath = null;

        if ($useLatest || empty($fileArg)) {
            $backups = $this->backupService->listBackups();
            if (empty($backups)) {
                $io->error('Nenhum arquivo de backup encontrado na pasta ' . $this->backupService->getBackupDir());
                return Command::FAILURE;
            }

            $latestBackup = $backups[0];
            $filePath = $latestBackup['path'];
            $dateStr = $latestBackup['createdAt'] instanceof \DateTimeInterface ? $latestBackup['createdAt']->format('d/m/Y H:i:s') : 'recente';
            $io->info(sprintf('Utilizando o backup mais recente: %s (%s, criado em %s)', $latestBackup['filename'], $latestBackup['sizeFormatted'], $dateStr));
        } else {
            if (file_exists($fileArg)) {
                $filePath = $fileArg;
            } else {
                $backupFile = $this->backupService->getBackupFile($fileArg);
                if ($backupFile) {
                    $filePath = $backupFile->getRealPath();
                } else {
                    $io->error(sprintf('Arquivo de backup "%s" não foi encontrado.', $fileArg));
                    return Command::FAILURE;
                }
            }
        }

        if (!$force && $input->isInteractive()) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                '⚠️  ATENÇÃO: A restauração irá sobrescrever todas as tabelas e dados existentes. Deseja continuar? (s/N) ',
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $io->warning('Operação de restauração cancelada pelo usuário.');
                return Command::SUCCESS;
            }
        }

        $io->section('Iniciando Restauração...');
        $progressBar = new ProgressBar($output, 100);
        $progressBar->setFormat(' %current%% [%bar%] %message%');
        $progressBar->start();

        $progressCallback = function(string $status, string $message, int $percent) use ($progressBar) {
            $progressBar->setProgress($percent);
            $progressBar->setMessage($message ?: $status);
        };

        try {
            $result = $this->backupService->importDatabase($filePath, $progressCallback);
            $progressBar->finish();
            $io->newLine(2);

            $io->success(sprintf(
                'Banco de dados restaurado com sucesso em %s segundos! (Modo: %s)',
                $result['durationSec'],
                $result['nativeExecuted'] ? 'Nativo MySQL rápido' : 'PHP Streaming'
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $progressBar->finish();
            $io->newLine(2);
            $io->error('Erro ao restaurar banco de dados: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
