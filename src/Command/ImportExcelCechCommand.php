<?php

namespace App\Command;

use App\Service\Import\ExcelCechImporterService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:excel',
    description: 'Importa e vincula informações complementares dos docentes e produções (Departamentos, Qualis) a partir das planilhas Excel'
)]
class ImportExcelCechCommand extends Command
{
    public function __construct(
        private readonly ExcelCechImporterService $importerService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('info-file', null, InputOption::VALUE_OPTIONAL, 'Planilha de Info Docentes', 'docs/banco/2026-08-23 - Info docentes do CECH.xlsx')
            ->addOption('prod-file', null, InputOption::VALUE_OPTIONAL, 'Planilha de Produção Científica', 'docs/banco/2026-08-23 - Producao cientifica-tecnologica-cultura.xlsx');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Importação e Enriquecimento de Dados CECH via Excel');

        $infoFile = $input->getOption('info-file');
        $prodFile = $input->getOption('prod-file');

        if (file_exists($infoFile)) {
            $io->section("1. Importando departamentos e dados complementares dos docentes ({$infoFile})...");
            try {
                $c1 = $this->importerService->importFacultyInfo($infoFile);
                $io->success("{$c1} docentes atualizados com departamentos e metadados!");
            } catch (\Throwable $e) {
                $io->error("Erro ao importar dados dos docentes: " . $e->getMessage());
            }
        } else {
            $io->warning("Arquivo de info docentes não encontrado: {$infoFile}");
        }

        if (file_exists($prodFile)) {
            $io->section("2. Importando estratos Qualis das produções ({$prodFile})...");
            try {
                $c2 = $this->importerService->importProductionQualis($prodFile);
                $io->success("{$c2} produções atualizadas com estratos Qualis!");
            } catch (\Throwable $e) {
                $io->error("Erro ao importar dados de produção: " . $e->getMessage());
            }
        } else {
            $io->warning("Arquivo de produção não encontrado: {$prodFile}");
        }

        $io->success('Enriquecimento de dados Excel concluído com sucesso!');
        return Command::SUCCESS;
    }
}
