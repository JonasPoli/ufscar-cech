<?php

namespace App\Command;

use App\Entity\AcademicDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-bibliomap-databases',
    description: 'Import academic databases and journal relationships from the bibliomap database into CECH.'
)]
class ImportBibliomapDatabasesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Importando Bases de Indexação Acadêmica do BiblioMap');

        $conn = $this->em->getConnection();

        // 1. Fetch from bibliomap.academic_database
        $io->section('Lendo dados de bibliomap.academic_database...');
        try {
            $stmt = $conn->executeQuery('SELECT id, name, acronym, url, logo, file_formats, signature_columns, description, import_instructions FROM bibliomap.academic_database ORDER BY id ASC');
            $rawBases = $stmt->fetchAllAssociative();
        } catch (\Throwable $e) {
            $io->error('Erro ao consultar bibliomap.academic_database: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->text(sprintf('Encontradas %d bases no BiblioMap.', count($rawBases)));

        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $conn->executeStatement('TRUNCATE TABLE academic_database');
        $conn->executeStatement('TRUNCATE TABLE qualis_journal_academic_database');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $insertSql = 'INSERT INTO academic_database (id, name, acronym, url, logo, file_formats, signature_columns, description, import_instructions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $importedBases = 0;
        foreach ($rawBases as $row) {
            $logo = $row['logo'];
            if ($logo) {
                // Adjust logo path to CECH's public/images/databases/
                $filename = basename($logo);
                $logo = '/images/databases/' . $filename;
            }

            $conn->executeStatement($insertSql, [
                (int)$row['id'],
                $row['name'],
                strtolower(trim($row['acronym'])),
                $row['url'],
                $logo,
                $row['file_formats'],
                $row['signature_columns'],
                $row['description'],
                $row['import_instructions'],
            ]);
            $importedBases++;
        }

        $io->success(sprintf('%d bases acadêmicas importadas com sucesso.', $importedBases));

        // 2. Fetch and import associations from bibliomap.qualis_journal_academic_database
        $io->section('Sincronizando vínculos de revistas com bases acadêmicas...');
        try {
            $linkStmt = $conn->executeQuery('
                SELECT qb.qualis_journal_id, qb.academic_database_id 
                FROM bibliomap.qualis_journal_academic_database qb
                INNER JOIN qualis_journals q ON q.id = qb.qualis_journal_id
                INNER JOIN academic_database ad ON ad.id = qb.academic_database_id
            ');
            $rawLinks = $linkStmt->fetchAllAssociative();
        } catch (\Throwable $e) {
            $io->error('Erro ao consultar vínculos de bibliomap.qualis_journal_academic_database: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->text(sprintf('Encontrados %d vínculos válidos no BiblioMap.', count($rawLinks)));

        $batchSize = 500;
        $values = [];
        $params = [];
        $importedLinks = 0;

        $insertLinkSql = 'INSERT IGNORE INTO qualis_journal_academic_database (qualis_journal_id, academic_database_id) VALUES ';

        foreach ($rawLinks as $link) {
            $values[] = '(?, ?)';
            $params[] = (int)$link['qualis_journal_id'];
            $params[] = (int)$link['academic_database_id'];
            $importedLinks++;

            if (count($values) >= $batchSize) {
                $conn->executeStatement($insertLinkSql . implode(', ', $values), $params);
                $values = [];
                $params = [];
            }
        }

        if (count($values) > 0) {
            $conn->executeStatement($insertLinkSql . implode(', ', $values), $params);
        }

        $io->success(sprintf('%d associações de periódicos e bases importadas com sucesso.', $importedLinks));

        return Command::SUCCESS;
    }
}
