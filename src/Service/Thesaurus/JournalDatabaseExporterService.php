<?php

namespace App\Service\Thesaurus;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Writer;

class JournalDatabaseExporterService
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    /**
     * Exports journals, their Qualis ratings, indexed databases, and thesaurus variants.
     *
     * @param string|null $qualisFilter Optional Qualis filter (e.g. 'A1', 'B2', 'ALL')
     * @param string|null $databaseFilter Optional Academic Database acronym (e.g. 'scopus', 'wos')
     * @param string $format 'csv' or 'json'
     * @param string|null $outputPath Optional destination file path
     * @return array{totalExported: int, filePath: ?string, content: ?string}
     */
    public function export(?string $qualisFilter = null, ?string $databaseFilter = null, string $format = 'csv', ?string $outputPath = null): array
    {
        /** @var Connection $conn */
        $conn = $this->em->getConnection();

        // 1. Fetch variations grouped by journal_id
        $variantsByJournal = [];
        $varStmt = $conn->executeQuery('SELECT journal_id, variation_name FROM journal_name_variants ORDER BY id ASC');
        while ($vr = $varStmt->fetchAssociative()) {
            $jid = (int)$vr['journal_id'];
            $variantsByJournal[$jid][] = $vr['variation_name'];
        }

        // 2. Fetch academic databases mapped to journals
        $databasesByJournal = [];
        $dbStmt = $conn->executeQuery('
            SELECT qb.qualis_journal_id, ad.name, ad.acronym 
            FROM qualis_journal_academic_database qb 
            INNER JOIN academic_database ad ON ad.id = qb.academic_database_id
            ORDER BY ad.name ASC
        ');
        while ($dbr = $dbStmt->fetchAssociative()) {
            $jid = (int)$dbr['qualis_journal_id'];
            $databasesByJournal[$jid][] = $dbr['name'];
        }

        // 3. Build query for journals
        $sql = 'SELECT j.id, j.title, j.issn, j.normalized_issn, j.issn_imp, j.issn_e, j.issn_l, j.qualis, j.area FROM qualis_journals j WHERE 1=1';
        $params = [];

        if ($qualisFilter && $qualisFilter !== 'ALL' && $qualisFilter !== '') {
            if ($qualisFilter === 'EMPTY') {
                $sql .= ' AND (j.qualis IS NULL OR j.qualis = "")';
            } else {
                $sql .= ' AND j.qualis = ?';
                $params[] = strtoupper(trim($qualisFilter));
            }
        }

        if ($databaseFilter && $databaseFilter !== 'ALL' && $databaseFilter !== '') {
            $sql .= ' AND j.id IN (SELECT qb.qualis_journal_id FROM qualis_journal_academic_database qb INNER JOIN academic_database ad ON ad.id = qb.academic_database_id WHERE ad.acronym = ?)';
            $params[] = strtolower(trim($databaseFilter));
        }

        $sql .= ' ORDER BY j.title ASC';

        $stmt = $conn->executeQuery($sql, $params);
        $totalExported = 0;

        if ($format === 'json') {
            $data = [];
            while ($row = $stmt->fetchAssociative()) {
                $jid = (int)$row['id'];
                $variants = $variantsByJournal[$jid] ?? [];
                $dbs = $databasesByJournal[$jid] ?? [];

                $data[] = [
                    'id' => $jid,
                    'title' => $row['title'],
                    'issn' => $row['issn'],
                    'issn_imp' => $row['issn_imp'],
                    'issn_e' => $row['issn_e'],
                    'issn_l' => $row['issn_l'],
                    'normalized_issn' => $row['normalized_issn'],
                    'qualis' => $row['qualis'],
                    'area' => $row['area'],
                    'databases' => $dbs,
                    'thesaurus_variants_count' => count($variants),
                    'thesaurus_variants' => $variants,
                ];
                $totalExported++;
            }

            $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($outputPath) {
                file_put_contents($outputPath, $jsonContent);
                return [
                    'totalExported' => $totalExported,
                    'filePath' => $outputPath,
                    'content' => null,
                ];
            }

            return [
                'totalExported' => $totalExported,
                'filePath' => null,
                'content' => $jsonContent,
            ];
        }

        // CSV format
        $csv = Writer::createFromString();
        $csv->setDelimiter(',');
        $csv->insertOne([
            'ID',
            'Título do Periódico',
            'ISSN',
            'ISSN Impresso',
            'ISSN Eletrônico',
            'ISSN Linking',
            'ISSN Normalizado',
            'Qualis CAPES',
            'Área',
            'Bases de Indexação',
            'Total Variantes Tesauro',
            'Variantes Tesauro (separadas por |)',
        ]);

        while ($row = $stmt->fetchAssociative()) {
            $jid = (int)$row['id'];
            $variants = $variantsByJournal[$jid] ?? [];
            $dbs = $databasesByJournal[$jid] ?? [];

            $csv->insertOne([
                $jid,
                $row['title'],
                $row['issn'] ?? '',
                $row['issn_imp'] ?? '',
                $row['issn_e'] ?? '',
                $row['issn_l'] ?? '',
                $row['normalized_issn'] ?? '',
                $row['qualis'] ?? '',
                $row['area'] ?? '',
                implode('; ', $dbs),
                count($variants),
                implode(' | ', $variants),
            ]);
            $totalExported++;
        }

        $csvContent = $csv->toString();

        if ($outputPath) {
            file_put_contents($outputPath, $csvContent);
            return [
                'totalExported' => $totalExported,
                'filePath' => $outputPath,
                'content' => null,
            ];
        }

        return [
            'totalExported' => $totalExported,
            'filePath' => null,
            'content' => $csvContent,
        ];
    }
}
