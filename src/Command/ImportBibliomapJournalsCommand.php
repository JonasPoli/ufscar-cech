<?php

namespace App\Command;

use App\Entity\JournalVariation;
use App\Entity\QualisJournal;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-bibliomap-journals',
    description: 'Import all Qualis Journals and Thesaurus Variations from the bibliomap database into CECH.'
)]
class ImportBibliomapJournalsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Importando Periódicos e Tesauros do BiblioMap');

        $conn = $this->em->getConnection();

        // 1. Fetch from bibliomap.qualis_journal
        $io->section('Lendo dados de bibliomap.qualis_journal...');
        try {
            $stmt = $conn->executeQuery('SELECT id, issn, normalized_issn, title, qualis FROM bibliomap.qualis_journal ORDER BY id ASC');
            $rawJournals = $stmt->fetchAllAssociative();
        } catch (\Throwable $e) {
            $io->error('Erro ao conectar ou consultar bibliomap: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->text(sprintf('Encontrados %d periódicos no BiblioMap.', count($rawJournals)));

        // Clear existing qualis_journals and variants for fresh sync
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $conn->executeStatement('TRUNCATE TABLE journal_name_variants');
        $conn->executeStatement('TRUNCATE TABLE qualis_journals');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $idMap = [];
        $batchSize = 500;
        $count = 0;

        $insertSql = 'INSERT INTO qualis_journals (id, title, issn, normalized_issn, qualis) VALUES ';
        $values = [];
        $params = [];

        foreach ($rawJournals as $row) {
            $oldId = (int)$row['id'];
            $title = trim($row['title']);
            // Fix encoding mojibake if any
            if (!mb_check_encoding($title, 'UTF-8')) {
                $title = mb_convert_encoding($title, 'UTF-8', 'ISO-8859-1');
            }
            $title = mb_substr($title, 0, 500, 'UTF-8');

            $issn = $row['issn'] ? trim($row['issn']) : null;
            $normIssn = $row['normalized_issn'] ? trim($row['normalized_issn']) : ($issn ? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $issn)) : null);
            $qualis = $row['qualis'] ? strtoupper(trim($row['qualis'])) : null;

            $values[] = '(?, ?, ?, ?, ?)';
            $params[] = $oldId;
            $params[] = $title;
            $params[] = $issn;
            $params[] = $normIssn;
            $params[] = $qualis;
            $idMap[$oldId] = $oldId;
            $count++;

            if (count($values) >= $batchSize) {
                $conn->executeStatement($insertSql . implode(', ', $values), $params);
                $values = [];
                $params = [];
            }
        }

        if (count($values) > 0) {
            $conn->executeStatement($insertSql . implode(', ', $values), $params);
        }

        $io->success(sprintf('%d periódicos importados com sucesso.', $count));

        // 2. Fetch and import variations from bibliomap.qualis_journal_variacoes_nome
        $io->section('Lendo variações de nome do tesauro...');
        try {
            $vStmt = $conn->executeQuery('SELECT id, journal_id, variation_name, variation_type, status, created_at, updated_at FROM bibliomap.qualis_journal_variacoes_nome ORDER BY id ASC');
            $rawVars = $vStmt->fetchAllAssociative();
        } catch (\Throwable $e) {
            $io->warning('Não foi possível ler variações de bibliomap: ' . $e->getMessage());
            $rawVars = [];
        }

        $io->text(sprintf('Encontradas %d variações de periódicos.', count($rawVars)));

        $varValues = [];
        $varParams = [];
        $varCount = 0;
        $varInsertSql = 'INSERT INTO journal_name_variants (journal_id, variation_name, normalized_name, variation_type, status, created_at, updated_at) VALUES ';

        foreach ($rawVars as $vRow) {
            $jId = (int)$vRow['journal_id'];
            if (!isset($idMap[$jId])) continue;

            $varName = trim($vRow['variation_name']);
            // Ignore error trace entries or HTML tags
            if (str_starts_with($varName, '<') || str_contains($varName, 'Fatal error') || str_contains($varName, 'Stack trace')) {
                continue;
            }

            if (!mb_check_encoding($varName, 'UTF-8')) {
                $varName = mb_convert_encoding($varName, 'UTF-8', 'ISO-8859-1');
            }
            $varName = mb_substr($varName, 0, 500, 'UTF-8');
            $normName = StringNormalizer::normalizeString($varName, true);
            if ($normName === '') continue;

            $varType = $vRow['variation_type'] ?: 'alternative';
            $status = (int)($vRow['status'] ?? 1);
            $createdAt = $vRow['created_at'] ?: date('Y-m-d H:i:s');
            $updatedAt = $vRow['updated_at'] ?: date('Y-m-d H:i:s');

            $varValues[] = '(?, ?, ?, ?, ?, ?, ?)';
            $varParams[] = $jId;
            $varParams[] = $varName;
            $varParams[] = $normName;
            $varParams[] = $varType;
            $varParams[] = $status;
            $varParams[] = $createdAt;
            $varParams[] = $updatedAt;
            $varCount++;

            if (count($varValues) >= $batchSize) {
                $conn->executeStatement($varInsertSql . implode(', ', $varValues), $varParams);
                $varValues = [];
                $varParams = [];
            }
        }

        if (count($varValues) > 0) {
            $conn->executeStatement($varInsertSql . implode(', ', $varValues), $varParams);
        }

        $io->success(sprintf('%d variações/sinônimos de periódicos importados com sucesso.', $varCount));

        return Command::SUCCESS;
    }
}
