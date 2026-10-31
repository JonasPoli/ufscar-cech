<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Entity\ProductionItem;
use App\Service\Thesaurus\JournalResolverService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Carga das evidências de área dos periódicos (A6.3 e A5.5 de `docs/evolutions/periodicos-por-base.md`).
 *
 * `load()` lê o artefato (`journal_evidence.jsonl.gz`, ver {@see JournalEvidenceArtifactReader}) e grava em
 * `journal_area_evidence` um rótulo bruto de fonte por linha. Para cada registro as linhas-alvo são as do cadastro
 * que têm qualquer ISSN válido do registro; se não houver nenhuma e o registro tem título, as linhas SEM ISSN válido
 * cujo `normalized_title` (forma do tesauro) é igual ao título do registro. O artefato não traz ids: o casamento é
 * sempre por ISSN ou título.
 *
 * Pesos: `1/n` para as fontes de rótulo simples (as `n` frações têm 4 casas e somam exatamente 1,0000) e `share` para
 * o OpenAlex, que só grava rótulos com `share >= 0,10`, sem renormalizar (o classificador renormaliza). O SciELO vira
 * três fontes de evidência: `scielo_area` (v441), `scielo_wos` (v854) e `scielo_desc` (v440).
 *
 * Para não inflar a tabela, `source_title` e `payload` (`extra` sem `alt_titles`, mais `issns`) só vão na PRIMEIRA linha
 * de evidência de cada par (registro, linha do cadastro); nas demais ficam NULL. Exceção: toda linha do OpenAlex traz ao
 * menos `{"works": N}`, porque o classificador lê `payload.works` por linha.
 *
 * Idempotência: antes de inserir, apaga as evidências das fontes declaradas em `_meta.sources` (fonte pulada na
 * construção não aparece lá e, portanto, não é apagada). Nunca apaga `ai`, `legacy` nem `title_rule`: cada um tem a
 * própria recarga (`loadLegacy()`, `loadTitleRules()` e o importador de lotes de IA). A carga inteira é uma transação.
 *
 * `qualis_journals.area` e os demais dados brutos do cadastro nunca são alterados; só se criam linhas novas
 * (`createMissingForArticles`).
 */
final class JournalEvidenceLoader
{
    /** Linhas por INSERT em `journal_area_evidence`. */
    private const BATCH_SIZE = 500;

    /** A cada quantos registros lidos o andamento é informado. */
    private const PROGRESS_EVERY = 25000;

    /** Rótulos do OpenAlex com participação menor que esta ficam fora da carga (A6.6). */
    private const OPENALEX_MIN_SHARE = 0.10;

    /** Fontes do artefato que o SciELO desdobra em três fontes de evidência. */
    private const SCIELO_SOURCES = ['scielo_area', 'scielo_wos', 'scielo_desc'];

    /** Fontes cujo registro cria o vínculo com a base na página pública (as outras criam só a linha). */
    private const BASE_SOURCES = ['scopus', 'wos', 'pubmed', 'scielo', 'latindex'];

    /** Ordem de prioridade do título de uma fonte (A4.6): a primeira que tiver título vence. */
    private const TITLE_SOURCE_ORDER = ['scielo', 'doaj', 'latindex', 'scopus', 'wos', 'openalex', 'pubmed'];

    private const LABEL_MAX = 255;
    private const RECORD_KEY_MAX = 100;
    private const TITLE_MAX = 500;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalEvidenceArtifactReader $reader,
        private readonly AreaCrosswalk $crosswalk,
        private readonly CapesTaxonomy $taxonomy,
    ) {
    }

    /**
     * Carrega o artefato e, conforme as opções, cria as linhas faltantes, as evidências legadas e as de regras de título.
     *
     * Sem `$dryRun` a carga é gravada ao final; com `$dryRun` tudo é feito e desfeito (os números são os da carga real).
     *
     * Chaves do resultado, além das seis da especificação:
     *  - `by_source`: linhas de evidência gravadas por fonte (`wos`, `scopus`, ..., `scielo_area`, `scielo_wos`,
     *    `scielo_desc` e, quando pedidas, `legacy` e `title_rule`); a soma é `evidence_rows + legacy_rows + title_rule_rows`;
     *  - `records_by_source`: registros lidos por fonte do artefato;
     *  - `deleted`: evidências da carga anterior removidas; `records_unmatched`: registros sem linha-alvo;
     *    `records_skipped`: registros de fonte não declarada em `_meta.sources`;
     *  - `missing_issns`, `missing_without_record`, `created_links`, `created_rows`: detalhes de `$createMissing`;
     *  - `batch`, `built_at`, `dry_run`, `seconds`.
     *
     * @param (callable(string): void)|null $progress recebe mensagens de andamento
     *
     * @return array{
     *     records: int,
     *     evidence_rows: int,
     *     journals_hit: int,
     *     created: int,
     *     legacy_rows: int,
     *     title_rule_rows: int,
     *     by_source: array<string, int>,
     *     records_by_source: array<string, int>,
     *     deleted: int,
     *     records_unmatched: int,
     *     records_skipped: int,
     *     missing_issns: int,
     *     missing_without_record: int,
     *     created_links: int,
     *     created_rows: list<array{id: int, title: string, issn_imp: ?string, issn_e: ?string, issn_l: ?string, source: string, bases: list<string>}>,
     *     batch: string,
     *     built_at: ?string,
     *     dry_run: bool,
     *     seconds: float
     * }
     *
     * @throws \RuntimeException artefato ausente, ilegível, com linha que não é JSON ou sem `_meta.sources`; um erro do
     *                           banco (DBAL) também propaga. Em qualquer falha a transação é desfeita e nada fica gravado
     */
    public function load(
        string $artifact,
        bool $createMissing = false,
        bool $legacy = false,
        bool $titleRules = false,
        ?callable $progress = null,
        bool $dryRun = false,
    ): array {
        $startedAt = microtime(true);
        $notify = static function (string $message) use ($progress): void {
            if ($progress !== null) {
                $progress($message);
            }
        };

        // Falha antes de qualquer acesso ao banco quando o arquivo não existe ou não começa com `_meta`.
        $meta = $this->reader->meta($artifact);
        $declared = $this->declaredSources($meta, $notify);
        $builtAt = isset($meta['built_at']) && is_string($meta['built_at']) ? $meta['built_at'] : null;
        $batch = $this->batchOf($builtAt);
        $expected = $this->expectedRecords($meta, $declared);

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $result = $this->run($conn, $artifact, $declared, $batch, $expected, $createMissing, $legacy, $titleRules, $notify);

            if ($dryRun) {
                $conn->rollBack();
            } else {
                $conn->commit();
            }
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }

        ksort($result['by_source'], SORT_STRING);
        ksort($result['records_by_source'], SORT_STRING);

        return $result + [
            'batch' => $batch,
            'built_at' => $builtAt,
            'dry_run' => $dryRun,
            'seconds' => round(microtime(true) - $startedAt, 2),
        ];
    }

    /**
     * Evidência `legacy`: o valor original de `area` (`COALESCE(NULLIF(area_raw,''), area)`) que é o nome de uma área
     * ou grande área CAPES. `label` = valor bruto, `weight` 1, `record_key` 'area'. Recarregável (apaga as `legacy`
     * antes). Não altera `area`.
     *
     * Comparação: sem diferença de caixa e de espaços; sem acento só quando o valor está todo em maiúsculas, que é
     * como o Qualis grafa as áreas (às vezes sem acento: "SAUDE COLETIVA"). Rótulos em outra caixa, como os do Latindex
     * ("Sociología", "Psicología"), já chegam pela própria fonte e contariam duas vezes. Ficam de fora "Social Sciences"
     * (lixo do importador), categorias WoS com `|` e o rótulo canônico que o classificador grava em `area` quando o
     * original estava vazio (não pode realimentar a classificação).
     *
     * Com `$dryRun` faz tudo e desfaz (devolve a contagem que seria gravada).
     *
     * @return int linhas de evidência gravadas
     */
    public function loadLegacy(bool $dryRun = false): int
    {
        return $this->inTransaction(fn (Connection $conn): int => $this->writeLegacy($conn, date('Y-m-d'), $this->now()), $dryRun);
    }

    /**
     * Evidência `title_rule`: regras de título (Apêndice C.6, {@see AreaCrosswalk::matchTitle()}) aplicadas ao
     * `display_title` e aos títulos de todas as linhas da família (`COALESCE(canonical_journal_id, id)`). A evidência
     * vai na linha canônica: `label` = slug, `weight` = 1/n (n = slugs distintos da família), `record_key` 'title'.
     * Recarregável (apaga as `title_rule` antes). Precisa de `canonical_journal_id` e `display_title` já gravados, por
     * isso roda depois de `app:journals:families`; sem famílias cada linha é a sua própria família.
     *
     * Com `$dryRun` faz tudo e desfaz (devolve a contagem que seria gravada).
     *
     * @return int linhas de evidência gravadas
     */
    public function loadTitleRules(bool $dryRun = false): int
    {
        return $this->inTransaction(fn (Connection $conn): int => $this->writeTitleRules($conn, date('Y-m-d'), $this->now()), $dryRun);
    }

    /**
     * A5.5: cria a linha do cadastro para cada ISSN válido de artigo que não existe em nenhuma linha e que consta em
     * algum registro do artefato.
     *
     * A linha leva o título do registro de maior prioridade (scielo, doaj, latindex, scopus, wos, openalex, pubmed; só
     * conta quem tem título) em `title` e `display_title` (com a fonte em `display_title_source`), os ISSNs desse
     * registro (`issn_imp` e `issn_e` pelo `issn_types` do SciELO ou, sem tipo, pela 1.ª e 2.ª posição) e
     * `normalized_title`; fica sem `qualis` e sem `area`. Um ISSN de artigo que sobraria (3.º ISSN do registro) vai em
     * `issn_l`, para o artigo achar a linha. Para cada fonte entre scopus, wos, pubmed, scielo e latindex que lista os
     * ISSNs da linha, cria o vínculo em `qualis_journal_academic_database` (base localizada por acrônimo; base ausente
     * não cria vínculo). Dois ISSNs do mesmo registro geram uma linha só.
     *
     * @param array<string, list<array<string, mixed>>> $recordsByIssn registros do artefato (`src`, `rec`, `title`,
     *                                                                  `issns`, `extra.issn_types`) indexados pelo ISSN de 8 caracteres
     *
     * @return int linhas criadas
     */
    public function createMissingForArticles(array $recordsByIssn): int
    {
        return $this->inTransaction(function (Connection $conn) use ($recordsByIssn): int {
            [$issnMap] = $this->loadLookups($conn);
            $missing = $this->missingArticleIssns($conn, $issnMap);

            return $this->createMissingRows($conn, $recordsByIssn, $missing, $issnMap)['created'];
        });
    }

    // ------------------------------------------------------------------------------------------------------------
    // Carga do artefato
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param list<string>              $declared fontes do artefato presentes em `_meta.sources`
     * @param callable(string): void    $notify
     *
     * @return array<string, mixed>
     */
    private function run(
        Connection $conn,
        string $artifact,
        array $declared,
        string $batch,
        int $expected,
        bool $createMissing,
        bool $legacy,
        bool $titleRules,
        callable $notify,
    ): array {
        $declaredSet = array_fill_keys($declared, true);
        $now = $this->now();

        [$issnMap, $titleMap] = $this->loadLookups($conn);
        $notify(sprintf(
            'Cadastro: %s ISSNs distintos, %s títulos de linhas sem ISSN.',
            number_format(count($issnMap), 0, ',', '.'),
            number_format(count($titleMap), 0, ',', '.')
        ));

        $created = ['created' => 0, 'links' => 0, 'missing' => 0, 'without_record' => 0, 'rows' => []];
        if ($createMissing) {
            $missing = $this->missingArticleIssns($conn, $issnMap);
            $notify(sprintf('Artigos: %s ISSNs válidos sem linha no cadastro.', number_format(count($missing), 0, ',', '.')));
            $recordsByIssn = $missing === [] ? [] : $this->collectRecordsOf($artifact, $declaredSet, $missing);
            $created = $this->createMissingRows($conn, $recordsByIssn, $missing, $issnMap);
            $notify(sprintf(
                'Linhas criadas: %d (%d vínculos com bases); %d ISSNs sem registro nas fontes.',
                $created['created'],
                $created['links'],
                $created['without_record']
            ));
        }

        $deleted = $conn->executeStatement(
            'DELETE FROM journal_area_evidence WHERE source IN (?)',
            [$this->evidenceSourcesOf($declared)],
            [ArrayParameterType::STRING]
        );
        $notify(sprintf('Evidências anteriores removidas (%s): %s.', implode(', ', $declared), number_format($deleted, 0, ',', '.')));

        // Linhas criadas por --create-missing-for-articles: posição em $created['rows'] e vínculos já feitos. Fonte que lista
        // só o ISSN-irmão (não o do artigo) também vincula a base, o que a coleta por ISSN faltante não enxerga.
        $createdAt = [];
        $linked = [];
        foreach ($created['rows'] as $position => $row) {
            $createdAt[$row['id']] = $position;
            $linked[$row['id']] = array_fill_keys($row['bases'], true);
        }
        $bases = $createdAt === [] ? [] : $this->baseIdsByAcronym($conn);
        $extraLinks = 0;

        $bySource = [];
        $recordsBySource = [];
        $hit = [];
        $buffer = [];
        $records = 0;
        $unmatched = 0;
        $skipped = 0;
        $evidenceRows = 0;

        foreach ($this->reader->read($artifact) as $record) {
            $src = (string) ($record['src'] ?? '');
            if (!isset($declaredSet[$src])) {
                ++$skipped;
                continue;
            }

            ++$records;
            $recordsBySource[$src] = ($recordsBySource[$src] ?? 0) + 1;
            if ($records % self::PROGRESS_EVERY === 0) {
                $notify(sprintf(
                    'Registros: %s de %s; %s linhas de evidência.',
                    number_format($records, 0, ',', '.'),
                    number_format($expected, 0, ',', '.'),
                    number_format($evidenceRows, 0, ',', '.')
                ));
            }

            $issns = $this->recordIssns($record);
            $title = $this->cleanTitle($record['title'] ?? null);

            $targets = $this->targetsOf($issns, $title, $issnMap, $titleMap);
            if ($targets === []) {
                ++$unmatched;
                continue;
            }

            if ($createdAt !== [] && isset($bases[$src])) {
                foreach ($targets as $journalId) {
                    if (isset($createdAt[$journalId]) && !isset($linked[$journalId][$src])) {
                        $extraLinks += $conn->executeStatement(
                            'INSERT IGNORE INTO qualis_journal_academic_database (qualis_journal_id, academic_database_id) VALUES (?, ?)',
                            [$journalId, $bases[$src]]
                        );
                        $linked[$journalId][$src] = true;
                        $created['rows'][$createdAt[$journalId]]['bases'][] = $src;
                    }
                }
            }

            $groups = $this->labelGroups($src, $record);
            if ($groups === []) {
                continue;
            }

            $recordKey = trim((string) ($record['rec'] ?? ''));
            if ($recordKey === '') {
                $recordKey = md5(implode(',', $issns) . $title);
            }
            $recordKey = mb_substr($recordKey, 0, self::RECORD_KEY_MAX, 'UTF-8');
            $sourceTitle = $title === '' ? null : mb_substr($title, 0, self::TITLE_MAX, 'UTF-8');
            $fullPayload = $this->fullPayload($record, $issns);
            $worksPayload = $src === 'openalex' ? $this->worksPayload($record) : null;

            foreach ($targets as $journalId) {
                $first = true;
                foreach ($groups as $source => $pairs) {
                    foreach ($pairs as [$label, $weight]) {
                        if ($first) {
                            $rowTitle = $sourceTitle;
                            $rowPayload = $fullPayload;
                            $first = false;
                        } else {
                            $rowTitle = null;
                            $rowPayload = $worksPayload;
                        }

                        $buffer[] = [$journalId, (string) $source, $recordKey, $label, $weight, $rowTitle, $batch, $rowPayload, $now];
                        $bySource[$source] = ($bySource[$source] ?? 0) + 1;
                        ++$evidenceRows;
                        if (count($buffer) >= self::BATCH_SIZE) {
                            $this->flushRows($conn, $buffer);
                        }
                    }
                }
                $hit[$journalId] = true;
            }
        }
        $this->flushRows($conn, $buffer);
        $notify(sprintf(
            'Registros lidos: %s; linhas de evidência: %s; linhas do cadastro atingidas: %s.',
            number_format($records, 0, ',', '.'),
            number_format($evidenceRows, 0, ',', '.'),
            number_format(count($hit), 0, ',', '.')
        ));

        foreach ($created['rows'] as $position => $row) {
            sort($row['bases'], SORT_STRING);
            $created['rows'][$position]['bases'] = $row['bases'];
        }

        $legacyRows = 0;
        if ($legacy) {
            $legacyRows = $this->writeLegacy($conn, date('Y-m-d'), $now);
            if ($legacyRows > 0) {
                $bySource['legacy'] = $legacyRows;
            }
            $notify(sprintf('Evidências legadas (area original): %s.', number_format($legacyRows, 0, ',', '.')));
        }

        $titleRuleRows = 0;
        if ($titleRules) {
            $titleRuleRows = $this->writeTitleRules($conn, date('Y-m-d'), $now);
            if ($titleRuleRows > 0) {
                $bySource['title_rule'] = $titleRuleRows;
            }
            $notify(sprintf('Evidências de regras de título: %s.', number_format($titleRuleRows, 0, ',', '.')));
        }

        return [
            'records' => $records,
            'evidence_rows' => $evidenceRows,
            'journals_hit' => count($hit),
            'created' => $created['created'],
            'legacy_rows' => $legacyRows,
            'title_rule_rows' => $titleRuleRows,
            'by_source' => $bySource,
            'records_by_source' => $recordsBySource,
            'deleted' => $deleted,
            'records_unmatched' => $unmatched,
            'records_skipped' => $skipped,
            'missing_issns' => $created['missing'],
            'missing_without_record' => $created['without_record'],
            'created_links' => $created['links'] + $extraLinks,
            'created_rows' => $created['rows'],
        ];
    }

    /**
     * Fontes do artefato que a carga conhece e que `_meta.sources` declara.
     *
     * @param array<string, mixed>   $meta
     * @param callable(string): void $notify
     *
     * @return list<string>
     */
    private function declaredSources(array $meta, callable $notify): array
    {
        $sources = is_array($meta['sources'] ?? null) ? array_map('strval', array_keys($meta['sources'])) : [];

        $unknown = array_values(array_diff($sources, JournalEvidenceArtifactBuilder::SOURCES));
        if ($unknown !== []) {
            $notify('Fontes de _meta.sources desconhecidas, ignoradas: ' . implode(', ', $unknown) . '.');
        }

        $declared = array_values(array_intersect(JournalEvidenceArtifactBuilder::SOURCES, $sources));
        if ($declared === []) {
            // Sem a lista de fontes não há como apagar a carga anterior, e recarregar duplicaria as linhas.
            throw new \RuntimeException('O artefato não declara nenhuma fonte conhecida em "_meta.sources"; recarregá-lo duplicaria evidências.');
        }

        return $declared;
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<string>         $declared
     */
    private function expectedRecords(array $meta, array $declared): int
    {
        $total = 0;
        foreach ($declared as $src) {
            $info = $meta['sources'][$src] ?? null;
            $total += is_array($info) ? (int) ($info['records'] ?? 0) : 0;
        }

        return $total;
    }

    /**
     * Valores de `source` que o artefato produz (o SciELO vira três).
     *
     * @param list<string> $declared
     *
     * @return list<string>
     */
    private function evidenceSourcesOf(array $declared): array
    {
        $sources = [];
        foreach ($declared as $src) {
            if ($src === 'scielo') {
                array_push($sources, ...self::SCIELO_SOURCES);
            } else {
                $sources[] = $src;
            }
        }

        return $sources;
    }

    /**
     * Data (AAAA-MM-DD) de `_meta.built_at`, como está escrita no artefato; hoje se faltar.
     */
    private function batchOf(?string $builtAt): string
    {
        if ($builtAt !== null && preg_match('/^(\d{4}-\d{2}-\d{2})/', $builtAt, $match) === 1) {
            return $match[1];
        }

        return date('Y-m-d');
    }

    /**
     * Mapas de casamento com o cadastro: ISSN válido (das 4 colunas) => ids, e `normalized_title` => ids só das linhas
     * sem ISSN válido. `normalized_title` vazio (antes de `app:journals:normalize-titles`) é calculado do `title`.
     *
     * @return array{0: array<string, list<int>>, 1: array<string, list<int>>}
     */
    private function loadLookups(Connection $conn): array
    {
        $issnMap = [];
        $titleMap = [];

        $result = $conn->executeQuery('SELECT id, title, normalized_title, issn, issn_imp, issn_e, issn_l FROM qualis_journals');
        while (($row = $result->fetchAssociative()) !== false) {
            $id = (int) $row['id'];
            $hasIssn = false;
            foreach (['issn', 'issn_imp', 'issn_e', 'issn_l'] as $column) {
                foreach (Issn::extractAll($row[$column] !== null ? (string) $row[$column] : null) as $issn) {
                    $hasIssn = true;
                    if (!in_array($id, $issnMap[$issn] ?? [], true)) {
                        $issnMap[$issn][] = $id;
                    }
                }
            }
            if ($hasIssn) {
                continue;
            }

            $normalized = (string) ($row['normalized_title'] ?? '');
            if ($normalized === '') {
                $normalized = mb_substr(StringNormalizer::normalizeString((string) $row['title'], true), 0, self::TITLE_MAX, 'UTF-8');
            }
            if ($normalized !== '') {
                $titleMap[$normalized][] = $id;
            }
        }

        return [$issnMap, $titleMap];
    }

    /**
     * ISSNs do registro na forma normalizada (8 caracteres minúsculos, só os de dígito verificador válido).
     *
     * @param array<string, mixed> $record
     *
     * @return list<string>
     */
    private function recordIssns(array $record): array
    {
        $raw = [];
        foreach (is_array($record['issns'] ?? null) ? $record['issns'] : [] as $issn) {
            if (is_string($issn) || is_int($issn)) {
                $raw[] = (string) $issn;
            }
        }

        return Issn::extractAll(implode(' ', $raw));
    }

    private function cleanTitle(mixed $title): string
    {
        if (!is_string($title)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $title));
    }

    /**
     * Linhas-alvo do registro: as que têm qualquer ISSN do registro; se nenhuma e há título, as linhas sem ISSN com o
     * mesmo `normalized_title`.
     *
     * @param list<string>             $issns
     * @param array<string, list<int>> $issnMap
     * @param array<string, list<int>> $titleMap
     *
     * @return list<int> ids em ordem crescente
     */
    private function targetsOf(array $issns, string $title, array $issnMap, array $titleMap): array
    {
        $targets = [];
        foreach ($issns as $issn) {
            foreach ($issnMap[$issn] ?? [] as $id) {
                $targets[$id] = $id;
            }
        }

        if ($targets === [] && $title !== '') {
            $normalized = mb_substr(StringNormalizer::normalizeString($title, true), 0, self::TITLE_MAX, 'UTF-8');
            foreach ($titleMap[$normalized] ?? [] as $id) {
                $targets[$id] = $id;
            }
        }

        ksort($targets);

        return array_values($targets);
    }

    /**
     * Rótulos do registro agrupados pela fonte de evidência, cada um com o seu peso (string de 4 casas).
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    private function labelGroups(string $src, array $record): array
    {
        $extra = is_array($record['extra'] ?? null) ? $record['extra'] : [];
        $labels = $record['labels'] ?? [];

        if ($src === 'openalex') {
            $pairs = [];
            $seen = [];
            foreach (is_array($labels) ? $labels : [] as $item) {
                if (!is_array($item) || !isset($item['code'], $item['share']) || !is_numeric($item['share'])) {
                    continue;
                }
                $code = is_string($item['code']) || is_int($item['code']) ? trim((string) $item['code']) : '';
                $share = round((float) $item['share'], 4);
                if ($code === '' || $share < self::OPENALEX_MIN_SHARE || in_array($code, $seen, true)) {
                    continue;
                }
                $seen[] = $code;
                $pairs[] = [mb_substr($code, 0, self::LABEL_MAX, 'UTF-8'), number_format($share, 4, '.', '')];
            }

            return $pairs === [] ? [] : ['openalex' => $pairs];
        }

        if ($src === 'scielo') {
            $groups = [
                'scielo_area' => $this->simpleLabels($labels),
                'scielo_wos' => $this->simpleLabels($extra['wos'] ?? []),
                'scielo_desc' => $this->simpleLabels($extra['desc'] ?? []),
            ];

            return array_filter($groups, static fn (array $pairs): bool => $pairs !== []);
        }

        $pairs = $this->simpleLabels($labels);

        return $pairs === [] ? [] : [$src => $pairs];
    }

    /**
     * Rótulos sem repetição, cada um com peso 1/n.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function simpleLabels(mixed $labels): array
    {
        $unique = [];
        foreach (is_array($labels) ? $labels : [] as $label) {
            if (!is_string($label) && !is_int($label)) {
                continue;
            }
            $label = mb_substr(trim((string) $label), 0, self::LABEL_MAX, 'UTF-8');
            if ($label !== '' && !in_array($label, $unique, true)) {
                $unique[] = $label;
            }
        }

        $weights = self::splitWeights(count($unique));
        $pairs = [];
        foreach ($unique as $position => $label) {
            $pairs[] = [$label, $weights[$position]];
        }

        return $pairs;
    }

    /**
     * Divide 1 em `$n` frações de 4 casas que somam exatamente 1,0000 (o resto vai para os primeiros).
     *
     * @return list<string>
     */
    private static function splitWeights(int $n): array
    {
        if ($n <= 0) {
            return [];
        }

        $base = intdiv(10000, $n);
        $rest = 10000 - $base * $n;
        $weights = [];
        for ($i = 0; $i < $n; ++$i) {
            $units = $base + ($i < $rest ? 1 : 0);
            $weights[] = sprintf('%d.%04d', intdiv($units, 10000), $units % 10000);
        }

        return $weights;
    }

    /**
     * Payload completo: `extra` sem `alt_titles` e mais `issns`.
     *
     * @param array<string, mixed> $record
     * @param list<string>         $issns
     */
    private function fullPayload(array $record, array $issns): string
    {
        $payload = is_array($record['extra'] ?? null) ? $record['extra'] : [];
        unset($payload['alt_titles']);
        if (array_key_exists('issn_types', $payload) && $payload['issn_types'] === []) {
            $payload['issn_types'] = new \stdClass();
        }
        $payload['issns'] = $issns;

        return json_encode($payload, self::JSON_FLAGS);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function worksPayload(array $record): string
    {
        $extra = is_array($record['extra'] ?? null) ? $record['extra'] : [];

        return json_encode(['works' => (int) ($extra['works'] ?? 0)], self::JSON_FLAGS);
    }

    /**
     * @param list<array<int, mixed>> $buffer linhas na ordem das colunas do INSERT; é esvaziado
     */
    private function flushRows(Connection $conn, array &$buffer): void
    {
        if ($buffer === []) {
            return;
        }

        $placeholders = [];
        $params = [];
        foreach ($buffer as $row) {
            $placeholders[] = '(?,?,?,?,?,?,?,?,?)';
            array_push($params, ...$row);
        }

        $sql = 'INSERT INTO journal_area_evidence (journal_id, source, record_key, label, weight, source_title, batch, payload, created_at) VALUES '
            . implode(',', $placeholders);

        // Direto no PDO (mesma conexão e mesma transação): em dev o middleware de debug do DBAL guarda o SQL e todos os
        // parâmetros de cada consulta até o fim do processo, e uma carga de milhões de linhas estouraria a memória.
        $native = $conn->getNativeConnection();
        if ($native instanceof \PDO) {
            $statement = $native->prepare($sql);
            if ($statement === false || !$statement->execute($params)) {
                throw new \RuntimeException('Falha ao gravar as evidências em journal_area_evidence.');
            }
        } else {
            $conn->executeStatement($sql, $params);
        }
        $buffer = [];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Linhas faltantes (A5.5)
    // ------------------------------------------------------------------------------------------------------------

    /**
     * ISSNs válidos de artigos que nenhuma linha do cadastro tem. Um artigo só conta quando nenhum dos seus ISSNs
     * válidos existe no cadastro (basta um para o vínculo por ISSN funcionar).
     *
     * @param array<string, list<int>> $issnMap
     *
     * @return array<string, true>
     */
    private function missingArticleIssns(Connection $conn, array $issnMap): array
    {
        $missing = [];
        $raws = $conn->fetchFirstColumn(
            "SELECT DISTINCT issn FROM production_items WHERE item_type = ? AND issn IS NOT NULL AND issn <> ''",
            [ProductionItem::TYPE_ARTIGO]
        );
        foreach ($raws as $raw) {
            $valid = Issn::extractAll((string) $raw);
            if ($valid === []) {
                continue;
            }
            foreach ($valid as $issn) {
                if (isset($issnMap[$issn])) {
                    continue 2;
                }
            }
            foreach ($valid as $issn) {
                $missing[$issn] = true;
            }
        }

        return $missing;
    }

    /**
     * Passada sobre o artefato que guarda, de forma enxuta (sem rótulos), os registros que trazem algum dos ISSNs faltantes.
     *
     * @param array<string, true> $declaredSet
     * @param array<string, true> $missing
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function collectRecordsOf(string $artifact, array $declaredSet, array $missing): array
    {
        $recordsByIssn = [];
        foreach ($this->reader->read($artifact) as $record) {
            $src = (string) ($record['src'] ?? '');
            if (!isset($declaredSet[$src])) {
                continue;
            }

            $issns = $this->recordIssns($record);
            $wanted = array_values(array_filter($issns, static fn (string $issn): bool => isset($missing[$issn])));
            if ($wanted === []) {
                continue;
            }

            $extra = is_array($record['extra'] ?? null) ? $record['extra'] : [];
            $slim = [
                'src' => $src,
                'rec' => (string) ($record['rec'] ?? ''),
                'title' => $this->cleanTitle($record['title'] ?? null),
                'issns' => $issns,
                'extra' => ['issn_types' => is_array($extra['issn_types'] ?? null) ? $extra['issn_types'] : []],
            ];
            foreach ($wanted as $issn) {
                $recordsByIssn[$issn][] = $slim;
            }
        }

        return $recordsByIssn;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $recordsByIssn
     * @param array<string, true>                       $missing
     * @param array<string, list<int>>                  $issnMap       recebe as linhas criadas
     *
     * @return array{
     *     created: int,
     *     links: int,
     *     missing: int,
     *     without_record: int,
     *     rows: list<array{id: int, title: string, issn_imp: ?string, issn_e: ?string, issn_l: ?string, source: string, bases: list<string>}>
     * }
     */
    private function createMissingRows(Connection $conn, array $recordsByIssn, array $missing, array &$issnMap): array
    {
        $issns = array_map('strval', array_keys($missing));
        sort($issns, SORT_STRING);

        $bases = $this->baseIdsByAcronym($conn);
        $covered = [];
        $rows = [];
        $links = 0;
        $withoutRecord = 0;

        foreach ($issns as $issn) {
            if (isset($covered[$issn])) {
                continue;
            }

            $records = $recordsByIssn[$issn] ?? [];
            if ($records === []) {
                ++$withoutRecord;
                continue;
            }

            $chosen = $this->chooseRecord($records);
            $title = (string) ($chosen['title'] ?? '');
            $source = $title !== '' ? (string) $chosen['src'] : null;
            if ($title === '') {
                $title = 'Periódico ISSN ' . Issn::format($issn);
            }
            $title = mb_substr($title, 0, self::TITLE_MAX, 'UTF-8');

            [$print, $online, $linking] = $this->assignIssns($chosen, $issn, $missing);
            $stored = array_values(array_filter([$print, $online, $linking], static fn (?string $value): bool => $value !== null));
            $display = $stored[0];

            $conn->executeStatement(
                'INSERT INTO qualis_journals (title, normalized_title, issn, normalized_issn, issn_imp, normalized_issn_imp, issn_e, normalized_issn_e, issn_l, normalized_issn_l, display_title, display_title_source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $title,
                    mb_substr(StringNormalizer::normalizeString($title, true), 0, self::TITLE_MAX, 'UTF-8') ?: null,
                    Issn::format($display),
                    $display,
                    $print !== null ? Issn::format($print) : null,
                    $print,
                    $online !== null ? Issn::format($online) : null,
                    $online,
                    $linking !== null ? Issn::format($linking) : null,
                    $linking,
                    $source !== null ? $title : null,
                    $source,
                ]
            );
            $journalId = (int) $conn->lastInsertId();

            // Bases das fontes que listam algum ISSN da linha, só entre as cinco da página.
            $acronyms = [];
            foreach ($stored as $storedIssn) {
                $covered[$storedIssn] = true;
                $issnMap[$storedIssn][] = $journalId;
                foreach ($recordsByIssn[$storedIssn] ?? [] as $record) {
                    if (isset($bases[$record['src']])) {
                        $acronyms[$record['src']] = true;
                    }
                }
            }
            $acronyms = array_map('strval', array_keys($acronyms));
            sort($acronyms, SORT_STRING);
            foreach ($acronyms as $acronym) {
                $links += $conn->executeStatement(
                    'INSERT IGNORE INTO qualis_journal_academic_database (qualis_journal_id, academic_database_id) VALUES (?, ?)',
                    [$journalId, $bases[$acronym]]
                );
            }

            $rows[] = [
                'id' => $journalId,
                'title' => $title,
                'issn_imp' => $print !== null ? Issn::format($print) : null,
                'issn_e' => $online !== null ? Issn::format($online) : null,
                'issn_l' => $linking !== null ? Issn::format($linking) : null,
                'source' => $source ?? 'issn',
                'bases' => $acronyms,
            ];
        }

        return [
            'created' => count($rows),
            'links' => $links,
            'missing' => count($missing),
            'without_record' => $withoutRecord,
            'rows' => $rows,
        ];
    }

    /**
     * Ids das bases da página pública por acrônimo (scopus, wos, pubmed, scielo, latindex), só das que existem.
     * O acrônimo é a chave estável entre ambientes; o id pode divergir em produção.
     *
     * @return array<string, int>
     */
    private function baseIdsByAcronym(Connection $conn): array
    {
        $bases = [];
        foreach ($conn->fetchAllKeyValue('SELECT acronym, id FROM academic_database') as $acronym => $id) {
            $acronym = strtolower((string) $acronym);
            if (in_array($acronym, self::BASE_SOURCES, true)) {
                $bases[$acronym] = (int) $id;
            }
        }

        return $bases;
    }

    /**
     * Registro que dá título e ISSNs à linha nova: quem tem título primeiro; entre esses, a ordem de A4.6; depois `rec`.
     *
     * @param list<array<string, mixed>> $records
     *
     * @return array<string, mixed>
     */
    private function chooseRecord(array $records): array
    {
        $order = array_flip(self::TITLE_SOURCE_ORDER);
        usort($records, static function (array $a, array $b) use ($order): int {
            $keyA = [($a['title'] ?? '') === '' ? 1 : 0, $order[$a['src'] ?? ''] ?? 99, (string) ($a['rec'] ?? '')];
            $keyB = [($b['title'] ?? '') === '' ? 1 : 0, $order[$b['src'] ?? ''] ?? 99, (string) ($b['rec'] ?? '')];

            return $keyA <=> $keyB;
        });

        return $records[0];
    }

    /**
     * Distribui os ISSNs do registro: impresso e online pelo `issn_types` do SciELO (PRINT, ONLIN) e, sem tipo, pela
     * 1.ª e 2.ª posição livre. O que sobra só é guardado (como ISSN-L) quando é ISSN de artigo sem linha.
     *
     * @param array<string, mixed> $record
     * @param array<string, true>  $missing
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} [impresso, online, linking]
     */
    private function assignIssns(array $record, string $trigger, array $missing): array
    {
        $issns = $this->recordIssns($record);
        $extra = is_array($record['extra'] ?? null) ? $record['extra'] : [];
        $types = is_array($extra['issn_types'] ?? null) ? $extra['issn_types'] : [];

        $print = null;
        $online = null;
        foreach ($issns as $issn) {
            $type = strtoupper((string) ($types[$issn] ?? ''));
            if ($type === 'PRINT' && $print === null) {
                $print = $issn;
            } elseif (in_array($type, ['ONLIN', 'ONLINE'], true) && $online === null) {
                $online = $issn;
            }
        }

        $rest = array_values(array_filter($issns, static fn (string $issn): bool => $issn !== $print && $issn !== $online));
        if ($print === null && $rest !== []) {
            $print = array_shift($rest);
        }
        if ($online === null && $rest !== []) {
            $online = array_shift($rest);
        }

        $linking = null;
        if (in_array($trigger, $rest, true)) {
            $linking = $trigger;
        } else {
            foreach ($rest as $issn) {
                if (isset($missing[$issn])) {
                    $linking = $issn;
                    break;
                }
            }
        }

        // O ISSN que motivou a linha está sempre entre os guardados
        if ($print === null && $online === null && $linking === null) {
            $print = $trigger;
        }

        return [$print, $online, $linking];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Legado e regras de título
    // ------------------------------------------------------------------------------------------------------------

    private function writeLegacy(Connection $conn, string $batch, string $now): int
    {
        $conn->executeStatement('DELETE FROM journal_area_evidence WHERE source = ?', ['legacy']);

        $byCase = [];
        $byPlain = [];
        foreach ($this->taxonomy->all() as $area) {
            $byCase[self::caseKey($area['name'])] = true;
            $byPlain[StringNormalizer::normalizeString($area['name'], false)] = true;
        }

        $result = $conn->executeQuery(
            "SELECT id, COALESCE(NULLIF(area_raw, ''), area) AS original, (area_raw IS NULL OR area_raw = '') AS from_area
             FROM qualis_journals
             WHERE COALESCE(NULLIF(area_raw, ''), area) IS NOT NULL
               AND COALESCE(NULLIF(area_raw, ''), area) <> ''
               AND COALESCE(NULLIF(area_raw, ''), area) NOT LIKE '%|%'
               AND CHAR_LENGTH(COALESCE(NULLIF(area_raw, ''), area)) <= 150
             ORDER BY id"
        );

        $buffer = [];
        $rows = 0;
        while (($row = $result->fetchAssociative()) !== false) {
            $original = trim((string) $row['original']);
            if (!$this->isCapesAreaName($original, $byCase, $byPlain)) {
                continue;
            }
            // Com `area_raw` vazio o valor vem de `area`; se for o rótulo canônico, é saída do classificador, não legado.
            if ((int) $row['from_area'] === 1 && $this->taxonomy->isCanonicalLabel($original)) {
                continue;
            }

            $buffer[] = [(int) $row['id'], 'legacy', 'area', mb_substr($original, 0, self::LABEL_MAX, 'UTF-8'), '1.0000', null, $batch, null, $now];
            ++$rows;
            if (count($buffer) >= self::BATCH_SIZE) {
                $this->flushRows($conn, $buffer);
            }
        }
        $this->flushRows($conn, $buffer);

        return $rows;
    }

    /**
     * @param array<string, true> $byCase  nomes das áreas sem diferença de caixa (com acento)
     * @param array<string, true> $byPlain nomes das áreas sem acento, minúsculos
     */
    private function isCapesAreaName(string $value, array $byCase, array $byPlain): bool
    {
        if (isset($byCase[self::caseKey($value)])) {
            return true;
        }

        return $value === mb_strtoupper($value, 'UTF-8')
            && isset($byPlain[StringNormalizer::normalizeString($value, false)]);
    }

    private static function caseKey(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    }

    private function writeTitleRules(Connection $conn, string $batch, string $now): int
    {
        $conn->executeStatement('DELETE FROM journal_area_evidence WHERE source = ?', ['title_rule']);

        // família => títulos (display_title e títulos das linhas), na ordem das linhas
        $families = [];
        $result = $conn->executeQuery('SELECT id, canonical_journal_id, title, display_title FROM qualis_journals ORDER BY id');
        while (($row = $result->fetchAssociative()) !== false) {
            $familyId = (int) ($row['canonical_journal_id'] ?? 0) ?: (int) $row['id'];
            foreach ([$row['display_title'], $row['title']] as $candidate) {
                $candidate = $this->cleanTitle($candidate);
                if ($candidate !== '' && !in_array($candidate, $families[$familyId] ?? [], true)) {
                    $families[$familyId][] = $candidate;
                }
            }
        }
        ksort($families);

        $memo = [];
        $buffer = [];
        $rows = 0;
        foreach ($families as $familyId => $titles) {
            $slugs = [];
            $sourceTitle = null;
            foreach ($titles as $title) {
                $key = JournalResolverService::normalizeString($title);
                $memo[$key] ??= $key === '' ? [] : $this->crosswalk->matchTitle($key);
                if ($memo[$key] === []) {
                    continue;
                }
                $sourceTitle ??= mb_substr($title, 0, self::TITLE_MAX, 'UTF-8');
                foreach ($memo[$key] as $slug) {
                    if (!in_array($slug, $slugs, true)) {
                        $slugs[] = $slug;
                    }
                }
            }
            if ($slugs === []) {
                continue;
            }

            $weights = self::splitWeights(count($slugs));
            foreach ($slugs as $position => $slug) {
                $buffer[] = [(int) $familyId, 'title_rule', 'title', $slug, $weights[$position], $position === 0 ? $sourceTitle : null, $batch, null, $now];
                ++$rows;
                if (count($buffer) >= self::BATCH_SIZE) {
                    $this->flushRows($conn, $buffer);
                }
            }
        }
        $this->flushRows($conn, $buffer);

        return $rows;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Infraestrutura
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @template T
     *
     * @param callable(Connection): T $work
     *
     * @return T
     */
    private function inTransaction(callable $work, bool $dryRun = false): mixed
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $result = $work($conn);
            if ($dryRun) {
                $conn->rollBack();
            } else {
                $conn->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
