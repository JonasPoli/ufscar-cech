<?php

namespace App\Service\Thesaurus;

use App\Entity\JournalArea;
use App\Repository\JournalAreaRepository;
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
     * Exports journals, their Qualis ratings, indexed databases, thesaurus variants and CAPES areas.
     *
     * As colunas de áreas CAPES (`area_capes_primary` e `areas_capes`) ficam no fim, depois das que já existiam, para
     * não quebrar quem consome o arquivo. No CSV, `areas_capes` traz os nomes separados por `;` (a principal primeiro);
     * no JSON, uma lista. Depois delas vêm `display_title` (o nome de exibição da família) e `canonical_journal_id` (a
     * linha canônica da família; vazio na própria canônica), que dizem a que revista cada linha pertence.
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

        // 2b. CAPES areas (principal e demais, por linha de periódico)
        /** @var JournalAreaRepository $areaRepo */
        $areaRepo = $this->em->getRepository(JournalArea::class);
        $areasByJournal = $areaRepo->namesByJournal();

        // 3. Build query for journals
        $sql = 'SELECT j.id, j.title, j.issn, j.normalized_issn, j.issn_imp, j.issn_e, j.issn_l, j.qualis, j.area, j.display_title, j.canonical_journal_id FROM qualis_journals j WHERE 1=1';
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
                $capes = $areasByJournal[$jid] ?? ['primary' => null, 'all' => []];

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
                    'area_capes_primary' => $capes['primary'],
                    'areas_capes' => $capes['all'],
                    'display_title' => $row['display_title'],
                    'canonical_journal_id' => $row['canonical_journal_id'] !== null ? (int)$row['canonical_journal_id'] : null,
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
            'area_capes_primary',
            'areas_capes',
            'display_title',
            'canonical_journal_id',
        ]);

        while ($row = $stmt->fetchAssociative()) {
            $jid = (int)$row['id'];
            $variants = $variantsByJournal[$jid] ?? [];
            $dbs = $databasesByJournal[$jid] ?? [];
            $capes = $areasByJournal[$jid] ?? ['primary' => null, 'all' => []];

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
                $capes['primary'] ?? '',
                implode(';', $capes['all']),
                $row['display_title'] ?? '',
                $row['canonical_journal_id'] ?? '',
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
     * Uma entrada de tesauro por família de periódicos (e não por linha do cadastro), para o `.the`, o CSV, o JSON e o XML
     * do admin: o cabeçalho é o nome de exibição da canônica, `COALESCE(display_title, title)`, e as variantes são todos os
     * outros nomes da família: os títulos das linhas e as variantes do tesauro de todas elas. Nomes que só diferem na
     * caixa ou nos acentos contam uma vez; o cabeçalho não se repete entre as variantes.
     *
     * @return list<array{header: string, preferred_name: string, variations: list<string>, variants: list<string>}>
     */
    public function familyThesaurusRecords(): array
    {
        $groups = [];
        foreach ($this->loadFamilies($this->em->getConnection(), null) as $family) {
            $headerKey = StringNormalizer::normalizeString($family['header'], true);
            $groupKey = $headerKey !== '' ? $headerKey : 'FAMILIA ' . $family['id'];

            $group = &$groups[$groupKey];
            $group ??= ['header' => $family['header'], 'names' => [], 'headerKey' => $headerKey];
            foreach ($family['names'] as $key => $name) {
                if ($key !== $headerKey) {
                    $group['names'][$key] ??= $name;
                }
            }
            unset($group);
        }

        $records = [];
        foreach (self::sortedByHeader($groups) as $group) {
            $variants = array_values($group['names']);
            $records[] = [
                'header' => $group['header'],
                'preferred_name' => $group['header'],
                'variations' => $variants,
                'variants' => $variants,
            ];
        }

        return $records;
    }

    /**
     * Streams a high-performance thesaurus export for all journals or filtered by an academic database.
     *
     * O tesauro é por família de periódicos: as linhas de uma mesma revista (impresso e online, por exemplo) formam uma
     * entrada só, com todos os seus nomes. O cabeçalho depende da chave:
     *  - `title`: o nome de exibição da canônica, `COALESCE(display_title, title)`;
     *  - `issn`: o ISSN principal da família (o da canônica; sem ele, o do primeiro membro que tem um);
     *  - `base`: o nome da base, com os nomes e ISSNs de toda a família de cada periódico.
     * As variantes são todas as da família: títulos das linhas, variantes do tesauro e, nas chaves `issn` e `title`, as
     * formas do ISSN de cada linha. No `.the` as variantes saem em minúsculas, que é o formato. Com `$databaseId`, entram
     * as famílias em que alguma linha está ligada à base (a presença numa base vale para a família toda).
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

            // Famílias (uma por revista) com todos os nomes e ISSNs das suas linhas
            $families = $this->loadFamilies($conn, $databaseId);

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
                foreach ($families as $family) {
                    foreach ($family['names'] as $name) {
                        $allVariants[$name] = true;
                    }
                    foreach ($family['issnForms'] as $form) {
                        $allVariants[$form] = true;
                    }
                }
                $writeItem($header, array_keys($allVariants), $isFirstJson);
            } else {
                // Uma entrada por família, agrupando as famílias que acabam com o mesmo cabeçalho
                $groups = [];
                foreach ($families as $family) {
                    if ($keyType === 'issn') {
                        if ($family['primaryIssn'] === null) {
                            if (!$includeWithoutIssn) {
                                continue;
                            }
                            $header = '[SEM ISSN] ' . $family['header'];
                        } else {
                            $header = $family['primaryIssn'];
                        }
                    } else {
                        $header = $family['header'];
                    }

                    $groupKey = StringNormalizer::normalizeString($header, true) ?: 'FAMILIA ' . $family['id'];
                    $group = &$groups[$groupKey];
                    $group ??= ['header' => $header, 'names' => [], 'headerKey' => $groupKey];
                    foreach ($family['names'] as $key => $name) {
                        $group['names'][$key] ??= $name;
                    }
                    foreach ($family['issnForms'] as $form) {
                        $group['names'][$form] ??= $form;
                    }
                    unset($group);
                }

                foreach (self::sortedByHeader($groups) as $group) {
                    $writeItem($group['header'], array_values($group['names']), $isFirstJson);
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

    /**
     * Lê o cadastro e junta as linhas em famílias (`COALESCE(canonical_journal_id, id)`), com os nomes e os ISSNs de todas.
     *
     * @param int|null $databaseId só as famílias em que alguma linha está ligada à base (null = todas)
     *
     * @return array<int, array{id: int, header: string, names: array<string, string>, issnForms: list<string>, primaryIssn: ?string}>
     *         por família: o nome de exibição da canônica (`header`); os nomes (`names`, forma do tesauro => grafia, sem
     *         repetição: o nome de exibição, os títulos das linhas e as variantes, a canônica primeiro); as formas dos
     *         ISSNs (com e sem hífen) e o ISSN principal formatado (o da canônica; sem ele, o do primeiro membro que tem)
     */
    private function loadFamilies(Connection $conn, ?int $databaseId): array
    {
        $variantsByRow = [];
        $stmt = $conn->executeQuery('SELECT journal_id, variation_name FROM journal_name_variants ORDER BY id ASC');
        while (($vr = $stmt->fetchAssociative()) !== false) {
            $variantsByRow[(int)$vr['journal_id']][] = (string)$vr['variation_name'];
        }

        $inBase = null;
        if ($databaseId !== null) {
            $inBase = [];
            foreach ($conn->fetchFirstColumn(
                'SELECT DISTINCT COALESCE(q.canonical_journal_id, q.id)
                   FROM qualis_journals q
                   JOIN qualis_journal_academic_database qb ON qb.qualis_journal_id = q.id
                  WHERE qb.academic_database_id = ?',
                [$databaseId]
            ) as $familyId) {
                $inBase[(int)$familyId] = true;
            }
        }

        // Linhas por família; a canônica é a de id igual ao da família
        $rowsByFamily = [];
        $stmt = $conn->executeQuery('SELECT id, canonical_journal_id, title, display_title, issn, issn_e, issn_l, issn_imp FROM qualis_journals ORDER BY id ASC');
        while (($row = $stmt->fetchAssociative()) !== false) {
            $id = (int)$row['id'];
            $familyId = $row['canonical_journal_id'] !== null ? (int)$row['canonical_journal_id'] : $id;
            if ($inBase !== null && !isset($inBase[$familyId])) {
                continue;
            }
            $rowsByFamily[$familyId][$id] = $row;
        }

        $families = [];
        foreach ($rowsByFamily as $familyId => $rows) {
            $head = $rows[$familyId] ?? reset($rows);
            $display = trim((string)($head['display_title'] ?? ''));
            $header = $display !== '' ? $display : trim((string)$head['title']);

            // A canônica primeiro, depois os membros por id
            $ordered = [$head['id'] => $head] + $rows;

            $names = [];
            $addName = static function (string $name) use (&$names): void {
                $name = trim($name);
                $key = StringNormalizer::normalizeString($name, true);
                if ($key !== '') {
                    $names[$key] ??= $name;
                }
            };
            // O nome de exibição também é um nome da revista (quem procura por ele precisa achá-la), mesmo quando não é
            // o título de nenhuma linha
            $addName($header);
            foreach ($ordered as $row) {
                $addName((string)$row['title']);
            }
            foreach ($ordered as $row) {
                foreach ($variantsByRow[(int)$row['id']] ?? [] as $variant) {
                    $addName($variant);
                }
            }

            $issnForms = [];
            $primaryIssn = null;
            foreach ($ordered as $row) {
                $rowPrimary = null;
                foreach (['issn', 'issn_l', 'issn_e', 'issn_imp'] as $column) {
                    $formatted = self::formatIssn($row[$column] ?? null);
                    if ($formatted === null) {
                        continue;
                    }
                    $rowPrimary ??= $formatted;
                    $issnForms[$formatted] = $formatted;
                    $raw = str_replace('-', '', $formatted);
                    $issnForms[$raw] = $raw;
                }
                $primaryIssn ??= $rowPrimary;
            }

            $families[(int)$familyId] = [
                'id' => (int)$familyId,
                'header' => $header,
                'names' => $names,
                'issnForms' => array_values($issnForms),
                'primaryIssn' => $primaryIssn,
            ];
        }

        return $families;
    }

    /**
     * Grupos em ordem alfabética do cabeçalho (sem caixa nem acento: o que as telas esperam de um tesauro).
     *
     * @param array<string, array{header: string, headerKey: string}> $groups
     *
     * @return list<array<string, mixed>>
     */
    private static function sortedByHeader(array $groups): array
    {
        $sorted = array_values($groups);
        usort($sorted, static fn (array $a, array $b): int => strnatcmp($a['headerKey'], $b['headerKey']) ?: strcmp($a['header'], $b['header']));

        return $sorted;
    }
}
