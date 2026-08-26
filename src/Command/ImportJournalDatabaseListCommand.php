<?php

namespace App\Command;

use App\Entity\AcademicDatabase;
use App\Service\Thesaurus\JournalDatabaseImporterService;
use App\Service\Thesaurus\JournalFileDetectorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-journal-database-list',
    description: 'Imports a journal catalog (CSV, XLSX) and links periodicals to an Academic Indexing Database.'
)]
class ImportJournalDatabaseListCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalDatabaseImporterService $importer,
        private readonly JournalFileDetectorService $detector
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the journal catalog file (.csv, .xlsx)')
            ->addOption('base', 'b', InputOption::VALUE_OPTIONAL, 'Acronym of the academic database (e.g. scopus, wos, scielo, pubmed, doaj)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Importador de Listas de Periódicos por Base de Indexação');

        $filePath = (string)$input->getArgument('file');
        if (!file_exists($filePath)) {
            $io->error('Arquivo não encontrado: ' . $filePath);
            return Command::FAILURE;
        }

        $baseAcronym = $input->getOption('base');
        $database = null;

        if ($baseAcronym) {
            $database = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => strtolower(trim($baseAcronym))]);
            if (!$database) {
                $io->error(sprintf('Base de dados com acrônimo "%s" não encontrada no cadastro.', $baseAcronym));
                return Command::FAILURE;
            }
            $io->text(sprintf('Base especificada via parâmetro: <info>%s</info> (%s)', $database->getName(), $database->getAcronym()));
        } else {
            // Auto-detect
            $io->section('Analisando estrutura do arquivo para auto-detecção...');
            $detection = $this->detector->detect($filePath);

            if ($detection['database'] && $detection['confidence'] >= 0.70) {
                $database = $detection['database'];
                $io->success(sprintf('Base detectada automaticamente: %s (%s) [Confiança: %d%%]', $database->getName(), $database->getAcronym(), (int)($detection['confidence'] * 100)));
            } else {
                $io->warning('Não foi possível identificar a base automaticamente com alta confiança.');
                $allBases = $this->em->getRepository(AcademicDatabase::class)->findBy([], ['name' => 'ASC']);
                if (empty($allBases)) {
                    $io->error('Nenhuma base cadastrada no banco de dados. Execute app:import-bibliomap-databases primeiro.');
                    return Command::FAILURE;
                }

                $choices = [];
                foreach ($allBases as $b) {
                    $choices[$b->getAcronym()] = sprintf('%s (%s)', $b->getName(), $b->getAcronym());
                }

                $selectedAcronym = $io->choice('Selecione a base de indexação correspondente a este arquivo:', $choices);
                $database = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => $selectedAcronym]);
            }
        }

        $io->section(sprintf('Importando "%s" para a base %s...', basename($filePath), $database->getName()));

        $progressBar = $io->createProgressBar();
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');

        $result = $this->importer->import($filePath, $database ? $database->getAcronym() : null, function (int $current, int $total, string $msg) use ($progressBar) {
            $progressBar->setMaxSteps($total);
            $progressBar->setProgress($current);
            $progressBar->setMessage($msg);
        });

        $progressBar->finish();
        $io->newLine(2);

        if (!$result['success']) {
            $io->error('Falha na importação:');
            foreach ($result['errors'] as $err) {
                $io->text('- ' . $err);
            }
            return Command::FAILURE;
        }

        $io->success([
            'Importação concluída com sucesso!',
            sprintf('Base vinculada: %s', $result['databaseName'] ?? $database->getName()),
            sprintf('Total de linhas lidas: %d', $result['totalRead']),
            sprintf('Novos periódicos inseridos: %d', $result['inserted']),
            sprintf('Periódicos existentes atualizados: %d', $result['updated']),
            sprintf('Novos vínculos com a base criados: %d', $result['linksCreated']),
        ]);

        return Command::SUCCESS;
    }
}
