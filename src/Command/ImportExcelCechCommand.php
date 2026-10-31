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
    description: 'Complementa os docentes (departamento, ORCID, anos de entrada e saída) a partir da planilha Excel de info docentes. Só complementa departamentos: não importa Qualis (ele vem de qualis_journals, pela indexação de periódicos).'
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
            // O passo de Qualis da planilha de produção foi removido; a opção continua aceita só para não quebrar quem a repassa
            ->addOption('prod-file', null, InputOption::VALUE_OPTIONAL, '[Removida, ignorada] O Qualis vem de qualis_journals, não da planilha de produção')
            ->setHelp(
                "Complementa os docentes que já vieram do Lattes (ou os cria, com o ID Lattes de 16 dígitos) com o departamento, o ORCID e os anos de entrada e saída da planilha.\n\n"
                . "O Qualis dos artigos NÃO vem daqui: ele é sempre o de qualis_journals, gravado pela indexação de periódicos (app:index:journals).\n\n"
                . "Termina com erro (código diferente de zero) se a planilha não existe, se não consegue ser lida ou se não tem nenhum docente."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Complemento dos docentes do CECH via Excel (departamentos)');

        $infoFile = (string)$input->getOption('info-file');

        if ($input->hasParameterOption('--prod-file')) {
            $io->warning('A opção --prod-file foi removida e é ignorada: o Qualis dos artigos vem de qualis_journals (app:index:journals), não da planilha de produção.');
        }

        if (!file_exists($infoFile)) {
            $io->error("Arquivo de info docentes não encontrado: {$infoFile}");

            return Command::FAILURE;
        }

        $io->section("Importando departamentos e dados complementares dos docentes ({$infoFile})...");
        try {
            $count = $this->importerService->importFacultyInfo($infoFile);
        } catch (\Throwable $e) {
            $io->error('Erro ao importar dados dos docentes: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($count === 0) {
            // Uma planilha sem nenhum ID Lattes de 16 dígitos na coluna A não é a de docentes: não finge que deu certo
            $io->error('Nenhum docente foi importado: a planilha não tem nenhuma linha com ID Lattes de 16 dígitos na coluna A. Confira o arquivo.');

            return Command::FAILURE;
        }

        $io->success("{$count} docentes atualizados com departamentos e metadados!");

        return Command::SUCCESS;
    }
}
