<?php

namespace App\Service\Thesaurus;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JournalDatabaseExporterService
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    /**
     * Normalizes and formats an ISSN to the canonical format (XXXX-XXXX).
     */
    public static function formatIssn(?string $issn): ?string
    {
        if ($issn === null || trim($issn) === '') {
            return null;
        }
        $clean = strtoupper(trim(preg_replace('/[^a-zA-Z0-9]/', '', $issn)));
        if (strlen($clean) === 8) {
            return substr($clean, 0, 4) . '-' . substr($clean, 4, 4);
        }
        return $clean !== '' ? $clean : null;
    }

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

    /**
     * Streams a high-performance thesaurus export for all journals or filtered by an academic database.
     *
     * @param int|null $databaseId Academic Database ID (null for all journals)
     * @param string $keyType 'issn', 'title', or 'base'
     * @param string $format 'the', 'csv', or 'json'
     * @param bool $includeWithoutIssn Whether to include journals without ISSN when keyType is 'issn'
     * @param string|null $customFilename Optional custom filename
     * @return StreamedResponse
     */
    public function streamThesaurusExport(
        ?int $databaseId = null,
        string $keyType = 'issn',
        string $format = 'the',
        bool $includeWithoutIssn = false,
        ?string $customFilename = null
    ): StreamedResponse {
        /** @var Connection $conn */
        $conn = $this->em->getConnection();
        $format = strtolower($format);
        if (!in_array($format, ['the', 'csv', 'json'], true)) {
            $format = 'the';
        }
        $keyType = strtolower($keyType);
        if (!in_array($keyType, ['issn', 'title', 'base'], true)) {
            $keyType = 'issn';
        }

        // Fetch database info if filtered
        $dbName = null;
        $dbAcronym = null;
        if ($databaseId !== null) {
            $dbRow = $conn->executeQuery('SELECT name, acronym FROM academic_database WHERE id = ?', [$databaseId])->fetchAssociative();
            if ($dbRow) {
                $dbName = (string)$dbRow['name'];
                $dbAcronym = (string)$dbRow['acronym'];
            }
        }

        // Determine filename
        if ($customFilename) {
            $filename = $customFilename;
        } else {
            $prefix = $dbAcronym ? 'tesauro_' . $dbAcronym : 'tesauro_revistas';
            $filename = sprintf('%s_%s.%s', $prefix, $keyType, $format);
        }

        $response = new StreamedResponse(function () use ($conn, $databaseId, $dbName, $keyType, $format, $includeWithoutIssn) {
            @ini_set('memory_limit', '1024M');
            if (\function_exists('set_time_limit')) {
                @\set_time_limit(300);
            }

            $out = fopen('php://output', 'w');

            // 1. Fetch variations
            $varsByJournal = [];
            if ($databaseId !== null) {
                $vSql = 'SELECT v.journal_id, v.variation_name 
                         FROM journal_name_variants v 
                         INNER JOIN qualis_journal_academic_database qb ON qb.qualis_journal_id = v.journal_id 
                         WHERE qb.academic_database_id = ?';
                $vStmt = $conn->executeQuery($vSql, [$databaseId]);
            } else {
                $vStmt = $conn->executeQuery('SELECT journal_id, variation_name FROM journal_name_variants');
            }
            while ($vr = $vStmt->fetchAssociative()) {
                $varsByJournal[(int)$vr['journal_id']][] = $vr['variation_name'];
            }

            // 2. Build journals query according to keyType
            $params = [];
            if ($keyType === 'issn') {
                $sql = 'SELECT j.id, j.title, j.issn, j.issn_e, j.issn_l, j.issn_imp,
                               COALESCE(NULLIF(j.issn, ""), NULLIF(j.issn_l, ""), NULLIF(j.issn_e, ""), NULLIF(j.issn_imp, "")) as primary_issn
                        FROM qualis_journals j';
                if ($databaseId !== null) {
                    $sql .= ' INNER JOIN qualis_journal_academic_database qb ON qb.qualis_journal_id = j.id WHERE qb.academic_database_id = ?';
                    $params[] = $databaseId;
                    if (!$includeWithoutIssn) {
                        $sql .= ' AND COALESCE(NULLIF(j.issn, ""), NULLIF(j.issn_l, ""), NULLIF(j.issn_e, ""), NULLIF(j.issn_imp, "")) IS NOT NULL';
                    }
                } else {
                    if (!$includeWithoutIssn) {
                        $sql .= ' WHERE COALESCE(NULLIF(j.issn, ""), NULLIF(j.issn_l, ""), NULLIF(j.issn_e, ""), NULLIF(j.issn_imp, "")) IS NOT NULL';
                    }
                }
                $sql .= ' ORDER BY primary_issn ASC, j.title ASC';
            } else {
                // keyType 'title' or 'base'
                $sql = 'SELECT j.id, j.title, j.issn, j.issn_e, j.issn_l, j.issn_imp FROM qualis_journals j';
                if ($databaseId !== null) {
                    $sql .= ' INNER JOIN qualis_journal_academic_database qb ON qb.qualis_journal_id = j.id WHERE qb.academic_database_id = ?';
                    $params[] = $databaseId;
                }
                $sql .= ' ORDER BY j.title ASC';
            }

            $stmt = $conn->executeQuery($sql, $params);

            // Setup format output headers
            if ($format === 'csv') {
                fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
                fputcsv($out, ['preferred_name', 'variant_name'], ',', '"', "\\");
            } elseif ($format === 'json') {
                fwrite($out, "[\n");
            }

            $writeItem = function (string $header, array $variants, bool &$isFirstJson) use ($out, $format) {
                $header = trim($header);
                if ($header === '') {
                    return;
                }

                $cleanVariants = [];
                foreach ($variants as $v) {
                    $v = trim((string)$v);
                    if ($v !== '' && !isset($cleanVariants[$v])) {
                        $cleanVariants[$v] = true;
                    }
                }
                $varList = array_map('strval', array_keys($cleanVariants));

                if ($format === 'the') {
                    fwrite($out, "**#" . $header . "\r\n");
                    foreach ($varList as $v) {
                        fwrite($out, "100 1 ^" . mb_strtolower($v, 'UTF-8') . "$\r\n");
                    }
                } elseif ($format === 'csv') {
                    if (empty($varList)) {
                        fputcsv($out, [$header, ''], ',', '"', "\\");
                    } else {
                        foreach ($varList as $v) {
                            fputcsv($out, [$header, $v], ',', '"', "\\");
                        }
                    }
                } elseif ($format === 'json') {
                    $itemJson = json_encode([
                        'header' => $header,
                        'preferred_name' => $header,
                        'variants' => $varList,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    if (!$isFirstJson) {
                        fwrite($out, ",\n");
                    }
                    fwrite($out, '  ' . $itemJson);
                    $isFirstJson = false;
                }
            };

            $isFirstJson = true;

            if ($keyType === 'base') {
                // All journals under a single database header
                $header = $dbName ?: 'Base Acadêmica';
                $allVariants = [];
                while ($row = $stmt->fetchAssociative()) {
                    $allVariants[$row['title']] = true;
                    $jid = (int)$row['id'];
                    foreach ($varsByJournal[$jid] ?? [] as $v) {
                        $allVariants[$v] = true;
                    }
                    foreach (['issn', 'issn_e', 'issn_l', 'issn_imp'] as $f) {
                        if (!empty($row[$f])) {
                            $fmt = self::formatIssn($row[$f]);
                            if ($fmt) {
                                $allVariants[$fmt] = true;
                                $raw = str_replace('-', '', $fmt);
                                if ($raw !== $fmt) {
                                    $allVariants[$raw] = true;
                                }
                            }
                        }
                    }
                }
                $writeItem($header, array_keys($allVariants), $isFirstJson);
            } else {
                // Grouping by ISSN or Title
                $currentGroupKey = null;
                $currentVariants = [];

                while ($row = $stmt->fetchAssociative()) {
                    if ($keyType === 'issn') {
                        $rawPrimary = $row['primary_issn'] ?? null;
                        $formattedIssn = self::formatIssn($rawPrimary);
                        if ($formattedIssn === null) {
                            if (!$includeWithoutIssn) {
                                continue;
                            }
                            $groupKey = '[SEM ISSN] ' . $row['title'];
                        } else {
                            $groupKey = $formattedIssn;
                        }
                    } else {
                        $groupKey = trim($row['title']);
                    }

                    if ($currentGroupKey !== null && $groupKey !== $currentGroupKey) {
                        $writeItem($currentGroupKey, array_keys($currentVariants), $isFirstJson);
                        $currentVariants = [];
                    }
                    $currentGroupKey = $groupKey;

                    // Collect variants for this journal
                    // 1. Title (crucial when key is ISSN)
                    $currentVariants[$row['title']] = true;

                    // 2. Name variations from journal_name_variants
                    $jid = (int)$row['id'];
                    foreach ($varsByJournal[$jid] ?? [] as $v) {
                        $currentVariants[$v] = true;
                    }

                    // 3. Alternative ISSNs
                    foreach (['issn', 'issn_e', 'issn_l', 'issn_imp'] as $f) {
                        if (!empty($row[$f])) {
                            $fmt = self::formatIssn($row[$f]);
                            if ($fmt) {
                                $currentVariants[$fmt] = true;
                                $raw = str_replace('-', '', $fmt);
                                if ($raw !== $fmt) {
                                    $currentVariants[$raw] = true;
                                }
                            }
                        }
                    }
                }

                if ($currentGroupKey !== null) {
                    $writeItem($currentGroupKey, array_keys($currentVariants), $isFirstJson);
                }
            }

            if ($format === 'json') {
                fwrite($out, "\n]\n");
            }

            fclose($out);
        });

        $contentType = match ($format) {
            'csv' => 'text/csv; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            default => 'text/plain; charset=utf-8',
        };

        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
