<?php

namespace App\Service\Thesaurus;

use App\Entity\AcademicDatabase;
use App\Entity\QualisJournal;
use App\Service\Journal\JournalFamilyBuilder;
use App\Service\Journal\JournalVariantConflictException;
use App\Service\Journal\JournalVariantGuard;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Serviço de importação inteligente de listas de periódicos de bases de indexação acadêmica
 * (Scopus, Web of Science, DOAJ, Latindex, SciELO, PubMed, OpenAlex, etc.).
 *
 * Realiza UPSERT comparando todos os 3 campos de ISSN (issn_e, issn_l, issn_imp)
 * e o tesauro de variações de títulos, mantendo o banco sincronizado sem duplicatas.
 *
 * Como cada linha acha o periódico (as mesmas regras do resolver de periódicos, docs/evolutions/periodicos-por-base.md, A5.2):
 *  - pelo ISSN, em qualquer das quatro colunas; se vários periódicos têm o mesmo ISSN, vale o de menor id;
 *  - sem ISSN conhecido, pelo nome: o título da linha, e depois o título sem os trechos entre parênteses, contra os
 *    títulos e as variantes do cadastro. Um nome que pertence a mais de uma família é AMBÍGUO: a linha não é atribuída a
 *    nenhum periódico (nada é atualizado, inserido nem ligado à base) e entra na contagem `ambiguous`.
 *
 * Variantes de nome (abreviações, títulos alternativos) passam pela trava do tesauro
 * ({@see JournalVariantGuard::addVariant()}): só entram nomes que nenhuma família conhece, gravados na canônica da família
 * do periódico, e o que pertence a outra família é contado em `variationConflicts`. Como as listas têm dezenas de milhares
 * de linhas, os nomes que o cadastro já conhece ficam num mapa em memória e a trava (que consulta o banco) só é chamada
 * para os realmente novos; as variantes novas são gravadas em lotes.
 *
 * A presença numa base vale para a família toda: ao fim da importação os vínculos com as bases são replicados em todas as
 * linhas de cada família ({@see JournalFamilyBuilder::propagateBaseLinks()}), porque os indicadores contam os artigos pela
 * linha a que cada um está ligado.
 *
 * O texto da coluna de área da lista é o dado bruto e vai para `area_raw`; `area` recebe o rótulo canônico da primeira área
 * CAPES que o tesauro de categorias reconhece nesse texto ou, se não reconhece, o próprio texto. As duas colunas só são
 * preenchidas quando estão vazias.
 */
class JournalDatabaseImporterService
{
    /** Variantes novas acumuladas antes de gravar: a trava confere cada nome contra elas, então o lote precisa ser curto. */
    private const VARIANT_FLUSH_EVERY = 200;

    /** Tamanho das colunas `normalized_name` e `variation_name` (a trava corta a chave nesse tamanho). */
    private const MAX_NAME_LENGTH = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalFileDetectorService $detector,
        private readonly JournalVariantGuard $variantGuard,
        private readonly JournalAreaLabeler $areaLabeler,
        private readonly JournalFamilyBuilder $familyBuilder
    ) {}

    /**
     * Importa uma lista de periódicos para uma base específica ou com auto-detecção.
     *
     * @param string $filePath Caminho absoluto do arquivo (.csv, .xlsx, .txt, .json)
     * @param string|null $targetDatabaseAcronym Sigla da base de destino (ex: 'scopus', 'wos', 'latindex', 'doaj')
     * @param callable|null $progressCallback Callback opcional: fn(int $current, int $total, string $msg)
     * @return array{success: bool, database: ?string, databaseName: ?string, totalRead: int, inserted: int, updated: int, linksCreated: int, variationsAdded: int, variationsSkipped: int, variationConflicts: int, ambiguous: int, baseLinksReplicated: int, errors: array<string>}
     *         `variationsSkipped`: nomes que não entraram por já serem conhecidos (da própria família ou de outra) ou por
     *         serem código; `variationConflicts`: dos ignorados, os que pertencem a outra família; `ambiguous`: linhas
     *         ignoradas por um nome que casa com mais de uma família; `baseLinksReplicated`: vínculos criados nas demais
     *         linhas das famílias
     */
    public function import(string $filePath, ?string $targetDatabaseAcronym = null, ?callable $progressCallback = null): array
    {
        @ini_set('memory_limit', '1024M');
        if (\function_exists('set_time_limit')) {
            @\set_time_limit(600);
        }

        if (!file_exists($filePath)) {
            return self::failure($targetDatabaseAcronym, null, 'Arquivo não encontrado: ' . $filePath);
        }

        // 1. Detect database if not provided
        $dbAcronym = $targetDatabaseAcronym;
        $dbEntity = null;

        if ($dbAcronym) {
            $dbEntity = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => $dbAcronym]);
        }

        if (!$dbEntity) {
            $detection = $this->detector->detect($filePath);
            if ($detection['acronym']) {
                $dbAcronym = $detection['acronym'];
                $dbEntity = $detection['database'] ?? $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => $dbAcronym]);
            }
        }

        // Fallback: create database record if acronym was specified but not in DB
        if (!$dbEntity && $dbAcronym) {
            $dbEntity = new AcademicDatabase();
            $dbEntity->setName(strtoupper($dbAcronym));
            $dbEntity->setAcronym(strtolower($dbAcronym));
            $this->em->persist($dbEntity);
            $this->em->flush();
        }

        if (!$dbEntity) {
            return self::failure(null, null, 'Não foi possível identificar a Base de Indexação. Por favor selecione a base manualmente.');
        }

        $dbId = $dbEntity->getId();
        $dbAcronym = $dbEntity->getAcronym();
        $dbName = $dbEntity->getName();

        // 2. Parse file(s) into standardized rows
        $filesToProcess = [];
        if (is_dir($filePath)) {
            $filesToProcess = glob($filePath . '/*.{json,csv,xlsx,xls,txt}', GLOB_BRACE) ?: [];
            sort($filesToProcess);
        } else {
            $filesToProcess = [$filePath];
        }

        if (empty($filesToProcess)) {
            return self::failure($dbAcronym, $dbName, 'Nenhum arquivo válido encontrado para processar.');
        }

        $rows = [];
        foreach ($filesToProcess as $fileItem) {
            $ext = strtolower(pathinfo($fileItem, PATHINFO_EXTENSION));
            if ($ext === 'xlsx' || $ext === 'xls') {
                $subRows = $this->extractFromExcel($fileItem);
            } elseif ($ext === 'txt') {
                $subRows = $this->extractFromText($fileItem);
            } elseif ($ext === 'json') {
                $subRows = $this->extractFromJson($fileItem);
            } else {
                // Default fallback to CSV parser (for .csv or temp files without extension)
                $subRows = $this->extractFromCsv($fileItem);
            }
            foreach ($subRows as $sr) {
                $rows[] = $sr;
            }
        }

        $totalRows = count($rows);
        if ($totalRows === 0) {
            return self::failure($dbAcronym, $dbName, 'Nenhum registro encontrado nos arquivos processados.');
        }

        /** @var Connection $conn */
        $conn = $this->em->getConnection();

        // 3. Mapas em memória do cadastro: ISSNs, nomes (para achar o periódico) e nomes já conhecidos (para as variantes)
        $catalog = $this->loadCatalog($conn);
        $issnToId = $catalog['issn'];
        $nameToId = $catalog['names'];
        $known = $catalog['known'];
        $family = $catalog['family'];
        // id => título normalizado do próprio periódico, só para quem ainda não tem normalized_title (preenchido no UPDATE)
        $normTitleToFill = $catalog['titleToFill'];
        unset($catalog);

        // Map of already existing links for this academic database
        $existingLinks = [];
        $lStmt = $conn->executeQuery('SELECT qualis_journal_id FROM qualis_journal_academic_database WHERE academic_database_id = ?', [$dbId]);
        while ($lr = $lStmt->fetchAssociative()) {
            $existingLinks[(int)$lr['qualis_journal_id']] = true;
        }

        // 4. Process UPSERT
        $inserted = 0;
        $updated = 0;
        $linksCreated = 0;
        $variationsAdded = 0;
        $variationsSkipped = 0;
        $variationConflicts = 0;
        $ambiguous = 0;
        $errors = [];

        $batchSize = 250;
        $pendingLinkInserts = []; // array of journalIds
        $pendingVariants = 0;

        foreach ($rows as $index => $row) {
            $title = trim((string)($row['title'] ?? ''));
            $issnImp = trim((string)($row['issn_imp'] ?? ''));
            $issnE = trim((string)($row['issn_e'] ?? ''));
            $issnL = trim((string)($row['issn_l'] ?? ''));
            $issnGen = trim((string)($row['issn'] ?? ''));
            $area = trim((string)($row['area'] ?? ''));
            $rowVariations = $row['variations'] ?? [];

            if ($title === '' && $issnImp === '' && $issnE === '' && $issnL === '' && $issnGen === '') {
                continue;
            }

            $normImp = $this->normalizeIssn($issnImp);
            $normE = $this->normalizeIssn($issnE);
            $normL = $this->normalizeIssn($issnL);
            $normGen = $this->normalizeIssn($issnGen);

            $primaryNorm = $normImp ?: ($normE ?: ($normL ?: $normGen));
            $displayIssn = $issnImp ?: ($issnE ?: ($issnL ?: $issnGen));

            $normTitle = self::nameKey($title);

            // O texto bruto da área vai para area_raw; area recebe o rótulo canônico (se o tesauro reconhece) ou o texto
            [$areaValue, $areaRaw] = $this->areaLabeler->columnsFor($area);

            // Find if journal exists by checking ALL 3 ISSN fields + general ISSN + title
            $journalId = null;

            $candidateNorms = array_filter([$normImp, $normE, $normL, $normGen]);
            foreach ($candidateNorms as $cand) {
                if (isset($issnToId[$cand])) {
                    $journalId = $issnToId[$cand];
                    break;
                }
            }

            if (!$journalId && $normTitle !== '') {
                $hit = $nameToId[$normTitle] ?? null;
                if ($hit === null) {
                    $cleanTitleNorm = self::nameKey((string)preg_replace('/\([^)]+\)/', '', $title));
                    if ($cleanTitleNorm !== '') {
                        $hit = $nameToId[$cleanTitleNorm] ?? null;
                    }
                }

                // Nome de mais de uma família: não há como saber qual é o periódico, e escolher um (o primeiro, o último)
                // misturaria revistas diferentes. A linha, as variantes e o vínculo com a base ficam de fora.
                if (is_array($hit)) {
                    $ambiguous++;
                    continue;
                }
                $journalId = $hit;
            }

            if ($journalId) {
                // Update existing: fill missing ISSN fields & area
                $updated++;
                $conn->executeStatement(
                    'UPDATE qualis_journals SET 
                        issn_imp = COALESCE(NULLIF(issn_imp, ""), ?),
                        normalized_issn_imp = COALESCE(NULLIF(normalized_issn_imp, ""), ?),
                        issn_e = COALESCE(NULLIF(issn_e, ""), ?),
                        normalized_issn_e = COALESCE(NULLIF(normalized_issn_e, ""), ?),
                        issn_l = COALESCE(NULLIF(issn_l, ""), ?),
                        normalized_issn_l = COALESCE(NULLIF(normalized_issn_l, ""), ?),
                        issn = COALESCE(NULLIF(issn, ""), ?),
                        normalized_issn = COALESCE(NULLIF(normalized_issn, ""), ?),
                        area = COALESCE(NULLIF(area, ""), ?),
                        area_raw = COALESCE(NULLIF(area_raw, ""), ?),
                        normalized_title = COALESCE(NULLIF(normalized_title, ""), ?)
                     WHERE id = ?',
                    [
                        $issnImp ?: null,
                        $normImp ?: null,
                        $issnE ?: null,
                        $normE ?: null,
                        $issnL ?: null,
                        $normL ?: null,
                        $displayIssn ?: null,
                        $primaryNorm ?: null,
                        $areaValue,
                        $areaRaw,
                        $normTitleToFill[$journalId] ?? null,
                        $journalId,
                    ]
                );
                unset($normTitleToFill[$journalId]);

                // Register candidate norms into lookup map (o ISSN que já era de outro periódico continua dele)
                foreach ($candidateNorms as $cand) {
                    $issnToId[$cand] ??= $journalId;
                }

                // Link with Academic Database
                if (!isset($existingLinks[$journalId])) {
                    $pendingLinkInserts[] = $journalId;
                    $existingLinks[$journalId] = true;
                    $linksCreated++;
                }
            } else {
                // Insert new journal
                if ($title === '') {
                    $title = 'Periódico ISSN ' . ($displayIssn ?: 'desconhecido');
                }
                $normTitleDb = mb_substr(StringNormalizer::normalizeString($title, true), 0, 500, 'UTF-8') ?: null;
                $conn->executeStatement(
                    'INSERT INTO qualis_journals (title, normalized_title, issn, normalized_issn, issn_imp, normalized_issn_imp, issn_e, normalized_issn_e, issn_l, normalized_issn_l, area, area_raw, qualis) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        mb_substr($title, 0, 500, 'UTF-8'),
                        $normTitleDb,
                        $displayIssn ?: null,
                        $primaryNorm ?: null,
                        $issnImp ?: null,
                        $normImp ?: null,
                        $issnE ?: null,
                        $normE ?: null,
                        $issnL ?: null,
                        $normL ?: null,
                        $areaValue,
                        $areaRaw,
                        null,
                    ]
                );
                $journalId = (int)$conn->lastInsertId();
                $inserted++;
                $family[$journalId] = $journalId;

                foreach ($candidateNorms as $cand) {
                    $issnToId[$cand] ??= $journalId;
                }
                if ($normTitle !== '') {
                    self::addToNameMap($nameToId, $normTitle, $journalId, $journalId, $family);
                    self::addKnown($known, $normTitle, $journalId);
                }

                if (!isset($existingLinks[$journalId])) {
                    $pendingLinkInserts[] = $journalId;
                    $existingLinks[$journalId] = true;
                    $linksCreated++;
                }
            }

            // Sync variations (synonyms / abbreviations) into thesaurus, pela trava de variantes
            if ($journalId && !empty($rowVariations)) {
                $journal = null;
                foreach ($rowVariations as $varName) {
                    $varName = trim((string)$varName);
                    if ($varName === '' || mb_strlen($varName) < 2) continue;
                    $normVar = self::nameKey($varName);
                    if ($normVar === '') continue;

                    // Nome que o cadastro já conhece (título ou variante de alguma família): pula sem consultar o banco.
                    // Se é de outra família é um conflito, e o nome continua com o dono dele.
                    if (isset($known[$normVar])) {
                        $variationsSkipped++;
                        if ($known[$normVar] !== $family[$journalId]) {
                            $variationConflicts++;
                        }
                        continue;
                    }

                    // Nome realmente novo: só a trava grava variante (recusa código e confere as outras famílias)
                    $journal ??= $this->em->find(QualisJournal::class, $journalId);
                    if ($journal === null) {
                        break;
                    }
                    try {
                        $variation = $this->variantGuard->addVariant($journal, $varName, 'alternative');
                    } catch (JournalVariantConflictException) {
                        $known[$normVar] = 0;
                        $variationsSkipped++;
                        $variationConflicts++;
                        continue;
                    }

                    if ($variation === null) {
                        // Não é um nome (HTML, pilha de erro...) ou a família já o tinha
                        $variationsSkipped++;
                        continue;
                    }

                    self::addKnown($known, $normVar, $family[$journalId]);
                    self::addToNameMap($nameToId, $normVar, $family[$journalId], $journalId, $family);
                    $variationsAdded++;
                    $pendingVariants++;
                }

                // Grava as variantes novas em lotes: a trava confere cada nome também contra as ainda não gravadas
                if ($pendingVariants >= self::VARIANT_FLUSH_EVERY) {
                    $this->flushVariants();
                    $pendingVariants = 0;
                }
            }

            // Flush links in batches
            if (count($pendingLinkInserts) >= $batchSize) {
                $this->insertLinks($conn, $pendingLinkInserts, $dbId);
                $pendingLinkInserts = [];
            }

            if ($progressCallback && ($index % 1000 === 0 || $index === $totalRows - 1)) {
                $progressCallback($index + 1, $totalRows, sprintf('Processando %d/%d (%d novos, %d atualizados, %d vínculos)', $index + 1, $totalRows, $inserted, $updated, $linksCreated));
            }
        }

        // Flush remaining variants and links
        if ($pendingVariants > 0) {
            $this->flushVariants();
        }
        if (count($pendingLinkInserts) > 0) {
            $this->insertLinks($conn, $pendingLinkInserts, $dbId);
        }

        // A presença numa base vale para a família toda: replica os vínculos em todas as linhas de cada família (também
        // quando esta lista não criou vínculo novo: uma família que já estava fora de sincronia é acertada)
        $baseLinksReplicated = $this->familyBuilder->propagateBaseLinks();

        return [
            'success' => true,
            'database' => $dbAcronym,
            'databaseName' => $dbName,
            'totalRead' => $totalRows,
            'inserted' => $inserted,
            'updated' => $updated,
            'linksCreated' => $linksCreated,
            'variationsAdded' => $variationsAdded,
            'variationsSkipped' => $variationsSkipped,
            'variationConflicts' => $variationConflicts,
            'ambiguous' => $ambiguous,
            'baseLinksReplicated' => $baseLinksReplicated,
            'errors' => $errors,
        ];
    }

    /**
     * Resultado de uma importação que não chegou a processar linhas.
     *
     * @return array{success: false, database: ?string, databaseName: ?string, totalRead: 0, inserted: 0, updated: 0, linksCreated: 0, variationsAdded: 0, variationsSkipped: 0, variationConflicts: 0, ambiguous: 0, baseLinksReplicated: 0, errors: array<string>}
     */
    private static function failure(?string $database, ?string $databaseName, string $error): array
    {
        return [
            'success' => false,
            'database' => $database,
            'databaseName' => $databaseName,
            'totalRead' => 0,
            'inserted' => 0,
            'updated' => 0,
            'linksCreated' => 0,
            'variationsAdded' => 0,
            'variationsSkipped' => 0,
            'variationConflicts' => 0,
            'ambiguous' => 0,
            'baseLinksReplicated' => 0,
            'errors' => [$error],
        ];
    }

    /**
     * Lê o cadastro uma vez, para a memória.
     *
     * @return array{
     *     issn: array<string, int>,
     *     names: array<string, int|array<int, int>>,
     *     known: array<string, int>,
     *     family: array<int, int>,
     *     titleToFill: array<int, ?string>
     * } `issn`: ISSN normalizado => id da linha de menor id (o mesmo critério do resolver de periódicos); `names`: nome
     *   (título ou variante) => id da linha, ou família => id quando o nome é de mais de uma família (ambíguo);
     *   `known`: nome => id da família dona, ou 0 quando é de mais de uma (as variantes de qualquer status contam, porque o
     *   índice único do tesauro vale para todas); `family`: id da linha => id da família; `titleToFill`: título normalizado
     *   de quem ainda não tem `normalized_title`
     */
    private function loadCatalog(Connection $conn): array
    {
        $issnToId = [];
        $names = [];
        $known = [];
        $family = [];
        $titleToFill = [];

        $stmt = $conn->executeQuery('SELECT id, canonical_journal_id, normalized_issn, normalized_issn_e, normalized_issn_l, normalized_issn_imp, title, normalized_title FROM qualis_journals ORDER BY id');
        while ($r = $stmt->fetchAssociative()) {
            $jid = (int)$r['id'];
            $family[$jid] = $r['canonical_journal_id'] !== null ? (int)$r['canonical_journal_id'] : $jid;

            // As linhas chegam em ordem de id: o primeiro a chegar a um ISSN é a linha de menor id, e ela fica com ele
            foreach (['normalized_issn', 'normalized_issn_e', 'normalized_issn_l', 'normalized_issn_imp'] as $col) {
                if (!empty($r[$col])) {
                    $issnToId[$r[$col]] ??= $jid;
                }
            }

            $normTitle = self::nameKey((string)$r['title']);
            if ($normTitle !== '') {
                self::addToNameMap($names, $normTitle, $family[$jid], $jid, $family);
                self::addKnown($known, $normTitle, $family[$jid]);
            }
            if (empty($r['normalized_title'])) {
                $titleToFill[$jid] = $normTitle !== '' ? $normTitle : null;
            }
        }

        // Variantes do tesauro: depois dos títulos, de qualquer status
        $vStmt = $conn->executeQuery('SELECT journal_id, normalized_name FROM journal_name_variants');
        while ($vr = $vStmt->fetchAssociative()) {
            $jid = (int)$vr['journal_id'];
            $normVar = (string)$vr['normalized_name'];
            if ($normVar === '' || !isset($family[$jid])) {
                continue;
            }
            self::addToNameMap($names, $normVar, $family[$jid], $jid, $family);
            self::addKnown($known, $normVar, $family[$jid]);
        }

        return ['issn' => $issnToId, 'names' => $names, 'known' => $known, 'family' => $family, 'titleToFill' => $titleToFill];
    }

    /**
     * Forma do tesauro de um nome, no tamanho que cabe nas colunas (a trava de variantes usa a mesma chave).
     */
    private static function nameKey(string $name): string
    {
        return mb_substr(StringNormalizer::normalizeString($name), 0, self::MAX_NAME_LENGTH, 'UTF-8');
    }

    /**
     * Guarda a linha $jid sob o nome $key. O nome guarda um id enquanto todas as linhas que o têm são da mesma família (vale
     * a primeira, de menor id) ou, quando chega uma linha de outra família, o mapa família => id (ambíguo).
     *
     * @param array<string, int|array<int, int>> $map
     * @param array<int, int> $family id da linha => família
     */
    private static function addToNameMap(array &$map, string $key, int $familyId, int $jid, array $family): void
    {
        $current = $map[$key] ?? null;
        if ($current === null) {
            $map[$key] = $jid;

            return;
        }

        if (is_int($current)) {
            $currentFamily = $family[$current] ?? $current;
            if ($currentFamily !== $familyId) {
                $map[$key] = [$currentFamily => $current, $familyId => $jid];
            }

            return;
        }

        $map[$key][$familyId] ??= $jid;
    }

    /**
     * Registra a família dona do nome (0 quando o nome já era de outra família).
     *
     * @param array<string, int> $known
     */
    private static function addKnown(array &$known, string $key, int $familyId): void
    {
        if (!isset($known[$key])) {
            $known[$key] = $familyId;
        } elseif ($known[$key] !== $familyId) {
            $known[$key] = 0;
        }
    }

    /**
     * Grava as variantes novas persistidas e solta as entidades (a lista de insertions que a trava varre volta a ser curta).
     */
    private function flushVariants(): void
    {
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * @param list<int> $journalIds
     */
    private function insertLinks(Connection $conn, array $journalIds, int $dbId): void
    {
        $valPlaceholders = [];
        $valParams = [];
        foreach ($journalIds as $pJournalId) {
            $valPlaceholders[] = '(?, ?)';
            $valParams[] = $pJournalId;
            $valParams[] = $dbId;
        }
        $conn->executeStatement(
            'INSERT IGNORE INTO qualis_journal_academic_database (qualis_journal_id, academic_database_id) VALUES ' . implode(', ', $valPlaceholders),
            $valParams
        );
    }

    /**
     * Extracts normalized row array from CSV file.
     *
     * @return array<array{title: string, issn: string, issn_imp: string, issn_e: string, issn_l: string, area: string, variations: array<string>}>
     */
    private function extractFromCsv(string $filePath): array
    {
        $csv = Reader::createFromPath($filePath, 'r');
        
        // Detect delimiter (;, ,, \t, |)
        $firstLine = '';
        $fp = @fopen($filePath, 'r');
        if ($fp) {
            $firstLine = fgets($fp) ?: '';
            fclose($fp);
        }
        if (str_contains($firstLine, ';') && substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
            $csv->setDelimiter(';');
        } elseif (str_contains($firstLine, "\t")) {
            $csv->setDelimiter("\t");
        }

        $csv->setHeaderOffset(0);

        $headers = $csv->getHeader();
        $colMap = $this->resolveColumnPositions($headers);

        $rows = [];
        foreach ($csv->getRecords() as $record) {
            $title = $this->getVal($record, $colMap['title']);
            $issnImp = $this->getVal($record, $colMap['issn_imp']);
            $issnE = $this->getVal($record, $colMap['issn_e']);
            $issnL = $this->getVal($record, $colMap['issn_l']);
            $issn = $this->getVal($record, $colMap['issn']);
            $area = $this->getVal($record, $colMap['area']);
            $var = $this->getVal($record, $colMap['variation']);

            $variations = [];
            if ($var !== '') {
                $variations[] = $var;
            }

            if ($title !== '' || $issnImp !== '' || $issnE !== '' || $issnL !== '' || $issn !== '') {
                $rows[] = [
                    'title' => $title,
                    'issn' => $issn,
                    'issn_imp' => $issnImp,
                    'issn_e' => $issnE,
                    'issn_l' => $issnL,
                    'area' => $area,
                    'variations' => $variations,
                ];
            }
        }

        return $rows;
    }

    /**
     * Extracts normalized row array from Excel file.
     *
     * @return array<array{title: string, issn: string, issn_imp: string, issn_e: string, issn_l: string, area: string, variations: array<string>}>
     */
    private function extractFromExcel(string $filePath): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        $sheetNames = [];
        if (method_exists($reader, 'listWorksheetNames')) {
            $sheetNames = $reader->listWorksheetNames($filePath);
        }

        $targetSheet = null;
        if (!empty($sheetNames)) {
            $targetSheet = $sheetNames[0];
            foreach ($sheetNames as $sName) {
                $lower = strtolower($sName);
                if (str_contains($lower, 'sources') || str_contains($lower, 'journal') || str_contains($lower, 'titles')) {
                    $targetSheet = $sName;
                    break;
                }
            }
            $reader->setLoadSheetsOnly([$targetSheet]);
        }

        $spreadsheet = $reader->load($filePath);
        $sheet = $targetSheet ? $spreadsheet->getSheetByName($targetSheet) : $spreadsheet->getActiveSheet();

        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();

        // Header row
        $headerRow = $sheet->rangeToArray('A1:' . $highestColumn . '1', null, true, true, false)[0] ?? [];
        $colMap = $this->resolveColumnPositions(array_map('strval', $headerRow));

        $rows = [];
        for ($r = 2; $r <= $highestRow; $r++) {
            $rowValues = $sheet->rangeToArray('A' . $r . ':' . $highestColumn . $r, null, true, true, false)[0] ?? [];
            
            $title = $this->getValIndexed($rowValues, $colMap['title_idx']);
            $issnImp = $this->getValIndexed($rowValues, $colMap['issn_imp_idx']);
            $issnE = $this->getValIndexed($rowValues, $colMap['issn_e_idx']);
            $issnL = $this->getValIndexed($rowValues, $colMap['issn_l_idx']);
            $issn = $this->getValIndexed($rowValues, $colMap['issn_idx']);
            $area = $this->getValIndexed($rowValues, $colMap['area_idx']);
            $var = $this->getValIndexed($rowValues, $colMap['variation_idx']);

            $variations = [];
            if ($var !== '') {
                $variations[] = $var;
            }

            if ($title !== '' || $issnImp !== '' || $issnE !== '' || $issnL !== '' || $issn !== '') {
                $rows[] = [
                    'title' => $title,
                    'issn' => $issn,
                    'issn_imp' => $issnImp,
                    'issn_e' => $issnE,
                    'issn_l' => $issnL,
                    'area' => $area,
                    'variations' => $variations,
                ];
            }
        }

        return $rows;
    }

    /**
     * Extracts rows from PubMed text format (J_Medline.txt).
     *
     * @return array<array{title: string, issn: string, issn_imp: string, issn_e: string, issn_l: string, area: string, variations: array<string>}>
     */
    private function extractFromText(string $filePath): array
    {
        $rows = [];
        $fp = fopen($filePath, 'r');
        if (!$fp) return $rows;

        $current = ['title' => '', 'issn_imp' => '', 'issn_e' => '', 'issn_l' => '', 'issn' => '', 'area' => '', 'variations' => []];
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if (str_starts_with($line, '---')) {
                if ($current['title'] !== '' || $current['issn_imp'] !== '' || $current['issn_e'] !== '') {
                    $rows[] = $current;
                }
                $current = ['title' => '', 'issn_imp' => '', 'issn_e' => '', 'issn_l' => '', 'issn' => '', 'area' => '', 'variations' => []];
                continue;
            }

            if (str_starts_with($line, 'JournalTitle:')) {
                $current['title'] = trim(substr($line, strlen('JournalTitle:')));
            } elseif (str_starts_with($line, 'MedAbbr:')) {
                $medAbbr = trim(substr($line, strlen('MedAbbr:')));
                if ($medAbbr !== '') {
                    $current['variations'][] = $medAbbr;
                }
            } elseif (str_starts_with($line, 'IsoAbbr:')) {
                $isoAbbr = trim(substr($line, strlen('IsoAbbr:')));
                if ($isoAbbr !== '') {
                    $current['variations'][] = $isoAbbr;
                }
            } elseif (str_starts_with($line, 'ISSN (Print):')) {
                $current['issn_imp'] = trim(substr($line, strlen('ISSN (Print):')));
            } elseif (str_starts_with($line, 'ISSN (Online):')) {
                $current['issn_e'] = trim(substr($line, strlen('ISSN (Online):')));
            }
        }
        if ($current['title'] !== '' || $current['issn_imp'] !== '' || $current['issn_e'] !== '') {
            $rows[] = $current;
        }
        fclose($fp);

        return $rows;
    }

    /**
     * Extracts rows from OpenAlex JSON files.
     *
     * @return array<array{title: string, issn: string, issn_imp: string, issn_e: string, issn_l: string, area: string, variations: array<string>}>
     */
    private function extractFromJson(string $filePath): array
    {
        $rows = [];
        $data = json_decode(file_get_contents($filePath), true);
        if (!is_array($data)) return $rows;

        $items = $data['results'] ?? ($data['items'] ?? ($data['data'] ?? (isset($data[0]) ? $data : [$data])));
        if (!is_array($items)) return $rows;

        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $title = $item['display_name'] ?? ($item['title'] ?? ($item['name'] ?? ''));
            $issnL = $item['issn_l'] ?? '';
            $issnList = $item['issn'] ?? [];
            if (!is_array($issnList)) $issnList = [$issnList];

            $issnImp = $issnList[0] ?? '';
            $issnE = $issnList[1] ?? '';

            $variations = [];
            if (!empty($item['alternate_titles']) && is_array($item['alternate_titles'])) {
                foreach ($item['alternate_titles'] as $alt) {
                    if (is_string($alt) && trim($alt) !== '') {
                        $variations[] = trim($alt);
                    }
                }
            }

            $areaParts = [];
            if (!empty($item['topics']) && is_array($item['topics'])) {
                foreach ($item['topics'] as $topic) {
                    if (is_array($topic) && !empty($topic['display_name'])) {
                        $areaParts[] = $topic['display_name'];
                    }
                }
            }
            $area = implode('; ', array_slice($areaParts, 0, 5));

            if ($title !== '' || $issnL !== '' || !empty($issnList)) {
                $rows[] = [
                    'title' => (string)$title,
                    'issn' => '',
                    'issn_imp' => (string)$issnImp,
                    'issn_e' => (string)$issnE,
                    'issn_l' => (string)$issnL,
                    'area' => $area,
                    'variations' => $variations,
                ];
            }
        }

        return $rows;
    }

    /**
     * Maps column headers to standardized keys for all 3 ISSN fields and variations.
     *
     * @param array<string> $headers
     * @return array{title: ?string, issn: ?string, issn_imp: ?string, issn_e: ?string, issn_l: ?string, area: ?string, variation: ?string, title_idx: ?int, issn_idx: ?int, issn_imp_idx: ?int, issn_e_idx: ?int, issn_l_idx: ?int, area_idx: ?int, variation_idx: ?int}
     */
    private function resolveColumnPositions(array $headers): array
    {
        $map = [
            'title' => null,
            'issn' => null,
            'issn_imp' => null,
            'issn_e' => null,
            'issn_l' => null,
            'area' => null,
            'variation' => null,
            'title_idx' => null,
            'issn_idx' => null,
            'issn_imp_idx' => null,
            'issn_e_idx' => null,
            'issn_l_idx' => null,
            'area_idx' => null,
            'variation_idx' => null,
        ];

        foreach ($headers as $idx => $header) {
            $norm = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', (string)$header)));

            // Title
            if ($map['title'] === null && (
                $norm === 'sourcetitle' ||
                $norm === 'journaltitle' ||
                $norm === 'fulltitle' ||
                $norm === 'title' ||
                $norm === 'titpropio' ||
                $norm === 'displayname' ||
                $norm === 'nome' ||
                $norm === 'periodico' ||
                $norm === 'itemtitle'
            )) {
                $map['title'] = $header;
                $map['title_idx'] = $idx;
            }

            // ISSN Eletrônico / Online (issn_e)
            if ($map['issn_e'] === null && (
                $norm === 'issne' ||
                $norm === 'eissn' ||
                $norm === 'electronicissn' ||
                $norm === 'issnelectronic' ||
                $norm === 'journalissnonlineonline' ||
                $norm === 'issnonline' ||
                $norm === 'journaleissnonlineversion' ||
                $norm === 'issneletronico'
            )) {
                $map['issn_e'] = $header;
                $map['issn_e_idx'] = $idx;
            }

            // ISSN Linking (issn_l)
            if ($map['issn_l'] === null && (
                $norm === 'issnl' ||
                $norm === 'linkingissn' ||
                $norm === 'issnlinking'
            )) {
                $map['issn_l'] = $header;
                $map['issn_l_idx'] = $idx;
            }

            // ISSN Impresso (issn_imp)
            if ($map['issn_imp'] === null && (
                $norm === 'issnimp' ||
                $norm === 'printissn' ||
                $norm === 'issnprint' ||
                $norm === 'journalissnprintprint' ||
                $norm === 'journalissnprintversion' ||
                $norm === 'pissn' ||
                $norm === 'issnimpresso'
            )) {
                $map['issn_imp'] = $header;
                $map['issn_imp_idx'] = $idx;
            }

            // Generic ISSN
            if ($map['issn'] === null && $norm === 'issn') {
                $map['issn'] = $header;
                $map['issn_idx'] = $idx;
            }

            // Area / Categories / Subtemas (ASJC do Scopus). A coluna "Top level: Social Sciences" é só um sinalizador e não entra.
            if ($map['area'] === null && (
                $norm === 'webofsciencecategories' ||
                $norm === 'asjcclassificationcodes' ||
                $norm === 'allsciencejournalclassificationcodesasjc' ||
                $norm === 'asjc' ||
                $norm === 'subjectarea' ||
                $norm === 'area' ||
                $norm === 'subjects' ||
                $norm === 'subtemas'
            )) {
                $map['area'] = $header;
                $map['area_idx'] = $idx;
            }

            // Variations / Abbreviations
            if ($map['variation'] === null && (
                $norm === 'abbreviation' ||
                $norm === 'alternativetitle' ||
                $norm === 'alternatetitle' ||
                $norm === 'medabbr' ||
                $norm === 'isoabbr' ||
                $norm === 'titulovariante' ||
                $norm === 'tituloabreviado'
            )) {
                $map['variation'] = $header;
                $map['variation_idx'] = $idx;
            }
        }

        // Fallback: if issn is set but not issn_imp, use as issn_imp
        if ($map['issn_imp'] === null && $map['issn'] !== null) {
            $map['issn_imp'] = $map['issn'];
            $map['issn_imp_idx'] = $map['issn_idx'];
        }

        return $map;
    }

    private function getVal(array $record, ?string $key): string
    {
        if ($key === null || !array_key_exists($key, $record)) {
            return '';
        }
        return trim((string)$record[$key]);
    }

    private function getValIndexed(array $rowValues, ?int $idx): string
    {
        if ($idx === null || !array_key_exists($idx, $rowValues)) {
            return '';
        }
        return trim((string)$rowValues[$idx]);
    }

    private function normalizeIssn(?string $issn): ?string
    {
        if ($issn === null || $issn === '') return null;
        $norm = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $issn));
        return $norm !== '' ? $norm : null;
    }
}
