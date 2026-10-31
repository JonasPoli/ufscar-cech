<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Entity\JournalArea;
use App\Service\Thesaurus\StringNormalizer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Lotes de classificação de área por IA (docs/evolutions/periodicos-por-base.md, A7 e T15).
 *
 * export() grava em JSONL as famílias visíveis (com artigos) que precisam de revisão: sem área, ou com confiança
 * `low` (`medium` com a opção). exportUnclassified() faz a segunda passada: todas as famílias sem área, só com título
 * e país. import() lê as respostas (`.out.jsonl`) e grava cada linha válida como evidência `ai` da família.
 *
 * Não escreve em journal_areas: a área continua sendo decidida pelo classificador, que lê essa evidência.
 */
final class JournalAiBatchService
{
    public const MODEL_DEFAULT = 'claude-sonnet';

    /** Fonte das evidências gravadas pela importação (journal_area_evidence.source). */
    public const SOURCE = 'ai';

    /** Texto do Apêndice D de A7, gravado como PROMPT.md na pasta de saída. */
    public const PROMPT = <<<'TXT'
Você classifica periódicos científicos nas áreas de avaliação da CAPES. Leia o arquivo JSONL indicado; cada linha descreve um periódico (título, outros títulos, ISSNs, editora, país, bases em que está indexado, categorias já conhecidas em "evidence", classificação automática atual em "current" e até 5 títulos de artigos de docentes do CECH/UFSCar publicados nele).

Para cada linha escreva UMA linha JSON no arquivo de saída <mesmo nome>.out.jsonl:
{"key": "<copie key>", "primary": "<slug>", "secondary": ["<slug>"], "confidence": 0.0-1.0, "note": "<até 120 caracteres>"}

Slugs permitidos (use exatamente): ciencias-agrarias, ciencia-de-alimentos, ciencias-agrarias-i, medicina-veterinaria, zootecnia-recursos-pesqueiros, ciencias-biologicas, biodiversidade, ciencias-biologicas-i, ciencias-biologicas-ii, ciencias-biologicas-iii, ciencias-da-saude, educacao-fisica, enfermagem, farmacia, medicina-i, medicina-ii, medicina-iii, nutricao, odontologia, saude-coletiva, ciencias-humanas, antropologia-arqueologia, ciencia-politica-relacoes-internacionais, ciencias-da-religiao-teologia, educacao, filosofia, geografia, historia, psicologia, sociologia, ciencias-sociais-aplicadas, administracao-contabeis-turismo, arquitetura-urbanismo-design, comunicacao-informacao-museologia, direito, economia, planejamento-urbano-regional-demografia, servico-social, linguistica-letras-artes, artes, linguistica-literatura, ciencias-exatas-terra, astronomia-fisica, computacao, geociencias, matematica-estatistica, quimica, engenharias, engenharias-i, engenharias-ii, engenharias-iii, engenharias-iv, multidisciplinar, biotecnologia, ciencias-ambientais, ciencias-humanidades-educacao-basica, ensino, interdisciplinar, materiais.

Regras:
1. "primary" é a área que melhor descreve o ESCOPO DO PERIÓDICO (não o artigo do docente). Os títulos de artigos são só contexto.
2. "secondary": 0 a 2 áreas realmente cobertas pelo periódico; vazio se não houver.
3. Use "interdisciplinar" quando o escopo cobre três ou mais áreas de grandes áreas diferentes (ex.: revista geral de uma universidade). Use "ensino" para periódicos de ensino de ciências, matemática, física etc.; "educacao" para educação em geral. Use "comunicacao-informacao-museologia" para ciência da informação, biblioteconomia, comunicação, jornalismo, museologia.
4. Quando só dá para dizer a grande área, use o slug dela (ciencias-humanas, ciencias-da-saude, engenharias...).
5. "confidence": 0.9 se o título/escopo é inequívoco; 0.7 se provável; 0.5 se é palpite; 0.3 se quase não há informação (mantenha a linha mesmo assim).
6. Não invente ISSNs nem altere "key". Não pule linhas. Não escreva nada além do JSONL no arquivo de saída.
7. Boletins, jornais e revistas de divulgação recebem a área do assunto que tratam (ex.: boletim de uma sociedade de psicologia → psicologia).
TXT;

    /** Parágrafo que A7 acrescenta ao prompt da segunda passada (só título e país). */
    public const PROMPT_UNCLASSIFIED = 'Há apenas título e país; escolha a área pelo título; `confidence` no máximo 0,6; sem segurança, use `primary: null`.';

    /** Títulos de artigos por família em cada linha exportada (A7: os 5 mais recentes). */
    private const SAMPLE_SIZE = 5;

    /** A evidência de Scopus são códigos ASJC; A7 os exporta sob a chave `asjc`. */
    private const EVIDENCE_NAMES = ['scopus' => 'asjc'];

    /** Chaves de `evidence` que toda linha exportada traz, mesmo vazias. */
    private const EVIDENCE_STANDARD = ['wos', 'asjc', 'doaj', 'latindex', 'openalex', 'title_rule'];

    /** Colunas de ISSN de qualis_journals, na ordem de preferência. */
    private const ISSN_COLUMNS = ['issn', 'issn_e', 'issn_imp', 'issn_l'];

    /** Tamanho máximo de journal_area_evidence.record_key; chaves maiores são gravadas como md5. */
    private const RECORD_KEY_MAX = 100;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** Artigos distintos por família: a regra de A1.3, sem filtro de base nem de área. */
    private const SQL_ARTICLES = <<<'SQL'
        SELECT f.fid,
               COUNT(DISTINCT CASE WHEN pi.doi IS NOT NULL AND TRIM(pi.doi) <> ''
                                   THEN CONCAT('DOI:', LOWER(TRIM(pi.doi)))
                                   ELSE CONCAT('TITLE:', COALESCE(pi.year, ''), ':', LOWER(TRIM(pi.title))) END) AS n_articles
        FROM production_items pi
        JOIN (SELECT id, COALESCE(canonical_journal_id, id) AS fid FROM qualis_journals) f ON f.id = pi.qualis_journal_id
        WHERE pi.item_type = 'ARTIGO'
        GROUP BY f.fid
        SQL;

    private const SQL_TITLES = <<<'SQL'
        SELECT f.fid, pi.title, pi.year
        FROM production_items pi
        JOIN (SELECT id, COALESCE(canonical_journal_id, id) AS fid FROM qualis_journals) f ON f.id = pi.qualis_journal_id
        WHERE pi.item_type = 'ARTIGO' AND TRIM(pi.title) <> ''
        SQL;

    private const SQL_BASES = <<<'SQL'
        SELECT qb.qualis_journal_id AS jid, ad.acronym
        FROM qualis_journal_academic_database qb
        JOIN academic_database ad ON ad.id = qb.academic_database_id
        SQL;

    private const SQL_AREAS = <<<'SQL'
        SELECT ja.journal_id AS jid, ca.slug, ja.is_primary, ja.confidence
        FROM journal_areas ja
        JOIN capes_areas ca ON ca.id = ja.area_id
        ORDER BY ja.journal_id, ja.is_primary DESC, ca.slug
        SQL;

    private const SQL_VARIANTS = <<<'SQL'
        SELECT v.journal_id AS jid, v.variation_name
        FROM journal_name_variants v
        WHERE v.status = 1
        ORDER BY v.id
        SQL;

    /** Evidências agregadas por família e rótulo, sem as da própria IA para não realimentar a classificação. */
    private const SQL_EVIDENCE = <<<'SQL'
        SELECT COALESCE(q.canonical_journal_id, q.id) AS fid, e.source, e.label,
               SUM(e.weight) AS total, AVG(e.weight) AS avg_weight
        FROM journal_area_evidence e
        JOIN qualis_journals q ON q.id = e.journal_id
        WHERE e.source <> 'ai'
        GROUP BY COALESCE(q.canonical_journal_id, q.id), e.source, e.label
        ORDER BY fid, e.source, total DESC, e.label
        SQL;

    /** Editora e país trazidos pelo payload de qualquer evidência (menos a da IA); só essas duas chaves são lidas. */
    private const SQL_PAYLOAD_META = <<<'SQL'
        SELECT COALESCE(q.canonical_journal_id, q.id) AS fid,
               JSON_EXTRACT(e.payload, '$.publisher') AS publisher,
               JSON_EXTRACT(e.payload, '$.country') AS country
        FROM journal_area_evidence e
        JOIN qualis_journals q ON q.id = e.journal_id
        WHERE e.source <> 'ai'
          AND (JSON_EXTRACT(e.payload, '$.publisher') IS NOT NULL OR JSON_EXTRACT(e.payload, '$.country') IS NOT NULL)
        ORDER BY e.id
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly CapesTaxonomy $taxonomy,
    ) {
    }

    /**
     * Exporta as famílias visíveis (com ≥ 1 artigo) cuja canônica não tem área ou tem confiança `low`
     * (`medium` também, com $includeMedium). Lotes ordenados por número de artigos, do maior para o menor.
     *
     * @return array{journals: int, batches: int}
     */
    public function export(string $outDir, int $batchSize = 40, bool $includeMedium = false): array
    {
        $this->assertBatchSize($batchSize);
        $ctx = $this->snapshot();

        $targets = [JournalArea::CONFIDENCE_LOW];
        if ($includeMedium) {
            $targets[] = JournalArea::CONFIDENCE_MEDIUM;
        }

        $items = [];
        foreach ($ctx['articles'] as $fid => $articles) {
            if (!isset($ctx['journals'][$fid])) {
                continue;
            }
            $areas = $ctx['areas'][$fid] ?? [];
            if ($areas !== [] && !$this->hasConfidence($areas, $targets)) {
                continue;
            }
            $line = $this->familyLine($fid, $ctx);
            if ($line !== null) {
                $items[] = ['articles' => $articles, 'name' => $line['title'], 'fid' => $fid, 'line' => $line];
            }
        }

        return $this->writeExport($outDir, 'batch', $this->linesOf($items), $batchSize, self::PROMPT . "\n");
    }

    /**
     * Segunda passada: todas as famílias sem nenhuma linha em journal_areas (visíveis ou não), em linhas só com
     * título e país. O PROMPT.md ganha o parágrafo de A7 para títulos.
     *
     * @return array{journals: int, batches: int}
     */
    public function exportUnclassified(string $outDir, int $batchSize = 250): array
    {
        $this->assertBatchSize($batchSize);
        $ctx = $this->snapshot();

        $items = [];
        foreach ($ctx['families'] as $fid => $members) {
            if (!isset($ctx['journals'][$fid]) || $this->familyHasAreas($members, $ctx['areas'])) {
                continue;
            }
            $line = $this->compactLine($fid, $ctx);
            if ($line !== null) {
                $items[] = ['articles' => $ctx['articles'][$fid] ?? 0, 'name' => $line['title'], 'fid' => $fid, 'line' => $line];
            }
        }

        $prompt = self::PROMPT . "\n\n" . self::PROMPT_UNCLASSIFIED . "\n";

        return $this->writeExport($outDir, 'title', $this->linesOf($items), $batchSize, $prompt);
    }

    /**
     * Lê um arquivo `.out.jsonl` e grava cada linha válida como evidência `ai` da família (canônica). Antes apaga as
     * evidências `ai` do mesmo lote (nome do arquivo), então reimportar substitui o lote sem duplicar. Linhas inválidas
     * vão para `skipped` e não interrompem a importação.
     *
     * @return array{lines: int, imported: int, skipped: list<array{line: int, key: ?string, reason: string}>}
     */
    public function import(string $file, string $model = self::MODEL_DEFAULT): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException(sprintf('Arquivo não encontrado: %s', $file));
        }
        $content = (string) file_get_contents($file);
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $journals = $this->loadJournals()['journals'];
        $index = $this->familyIndex($journals);
        $batch = basename($file);

        $lines = 0;
        $skipped = [];
        $valid = [];
        $familiesSeen = [];
        foreach (preg_split('/\R/', $content) ?: [] as $position => $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            ++$lines;
            $number = $position + 1;

            $parsed = $this->validateLine($raw);
            if (isset($parsed['error'])) {
                $skipped[] = ['line' => $number, 'key' => $parsed['key'], 'reason' => $parsed['error']];
                continue;
            }

            $fid = $this->resolveFamily($parsed['key'], $index, $journals);
            if (is_string($fid)) {
                $skipped[] = ['line' => $number, 'key' => $parsed['key'], 'reason' => $fid];
                continue;
            }
            if (isset($familiesSeen[$fid])) {
                $skipped[] = ['line' => $number, 'key' => $parsed['key'], 'reason' => 'duplicate_family'];
                continue;
            }
            $familiesSeen[$fid] = true;
            $parsed['fid'] = $fid;
            $valid[] = $parsed;
        }

        $this->connection->transactional(function (Connection $tx) use ($batch, $valid, $model): void {
            $tx->executeStatement(
                'DELETE FROM journal_area_evidence WHERE source = ? AND batch = ?',
                [self::SOURCE, $batch]
            );
            $createdAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach ($valid as $entry) {
                $roles = [['primary', $entry['primary'], '1.0000']];
                foreach ($entry['secondary'] as $slug) {
                    $roles[] = ['secondary', $slug, '0.5000'];
                }
                foreach ($roles as [$role, $slug, $weight]) {
                    $tx->insert('journal_area_evidence', [
                        'journal_id' => $entry['fid'],
                        'source' => self::SOURCE,
                        'record_key' => $this->recordKey($entry['key']),
                        'label' => $slug,
                        'weight' => $weight,
                        'source_title' => null,
                        'batch' => $batch,
                        'payload' => json_encode([
                            'linha' => $entry['row'],
                            'role' => $role,
                            'confidence' => $entry['confidence'],
                            'model' => $model,
                        ], self::JSON_FLAGS),
                        'created_at' => $createdAt,
                    ]);
                }
            }
        });

        return ['lines' => $lines, 'imported' => count($valid), 'skipped' => $skipped];
    }

    /**
     * Lê as tabelas uma vez e monta os índices de export. Ver loadFamilyData() para o que cada chave significa.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $base = $this->loadJournals();

        return $base + $this->loadFamilyData($base['journals']);
    }

    /**
     * Linhas de qualis_journals e as famílias (COALESCE(canonical_journal_id, id) → ids das linhas).
     *
     * @return array{journals: array<int, array<string, mixed>>, families: array<int, list<int>>}
     */
    private function loadJournals(): array
    {
        $journals = [];
        $families = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, canonical_journal_id AS canonical, title, display_title, normalized_title, issn, issn_e, issn_imp, issn_l '
            . 'FROM qualis_journals ORDER BY id'
        );
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $canonical = $row['canonical'] === null ? null : (int) $row['canonical'];
            $journals[$id] = [
                'id' => $id,
                'canonical' => $canonical,
                'title' => (string) $row['title'],
                'display' => $this->nonEmpty($row['display_title']),
                'norm' => $this->nonEmpty($row['normalized_title']),
                'issns' => $this->issnsOf($row),
            ];
            $families[$canonical ?? $id][] = $id;
        }

        return ['journals' => $journals, 'families' => $families];
    }

    /**
     * @param array<int, array<string, mixed>> $journals
     * @return array{articles: array<int, int>, samples: array<int, list<string>>, bases: array<int, list<string>>, areas: array<int, list<array{slug: string, primary: bool, confidence: string}>>, variants: array<int, list<string>>, evidence: array<int, array<string, list<array{label: string, total: float, avg: float}>>>, meta: array<int, array{publisher: ?string, country: ?string}>}
     *         Chaves: família (fid) para articles, samples, bases, variants, evidence e meta; id da linha para areas.
     */
    private function loadFamilyData(array $journals): array
    {
        $articles = [];
        foreach ($this->connection->fetchAllAssociative(self::SQL_ARTICLES) as $row) {
            $articles[(int) $row['fid']] = (int) $row['n_articles'];
        }

        $titles = [];
        foreach ($this->connection->fetchAllAssociative(self::SQL_TITLES) as $row) {
            $titles[(int) $row['fid']][] = [
                'title' => trim((string) $row['title']),
                'year' => $row['year'] === null ? null : (int) $row['year'],
            ];
        }
        $samples = [];
        foreach ($titles as $fid => $items) {
            $samples[$fid] = $this->recentTitles($items);
        }

        $bases = [];
        foreach ($this->connection->fetchAllAssociative(self::SQL_BASES) as $row) {
            $bases[$this->familyOf((int) $row['jid'], $journals)][] = (string) $row['acronym'];
        }
        foreach ($bases as $fid => $list) {
            $list = array_values(array_unique($list));
            sort($list);
            $bases[$fid] = $list;
        }

        $areas = [];
        foreach ($this->connection->fetchAllAssociative(self::SQL_AREAS) as $row) {
            $areas[(int) $row['jid']][] = [
                'slug' => (string) $row['slug'],
                'primary' => (bool) (int) $row['is_primary'],
                'confidence' => (string) $row['confidence'],
            ];
        }

        $variants = [];
        foreach ($this->connection->fetchAllAssociative(self::SQL_VARIANTS) as $row) {
            $variants[$this->familyOf((int) $row['jid'], $journals)][] = (string) $row['variation_name'];
        }

        $evidence = [];
        foreach ($this->connection->fetchAllAssociative(self::SQL_EVIDENCE) as $row) {
            $evidence[(int) $row['fid']][(string) $row['source']][] = [
                'label' => (string) $row['label'],
                'total' => (float) $row['total'],
                'avg' => (float) $row['avg_weight'],
            ];
        }

        $meta = [];
        foreach ($this->connection->fetchAllAssociative(self::SQL_PAYLOAD_META) as $row) {
            $fid = (int) $row['fid'];
            $meta[$fid]['publisher'] ??= $this->jsonString($row['publisher']);
            $meta[$fid]['country'] ??= $this->jsonString($row['country']);
        }

        return [
            'articles' => $articles,
            'samples' => $samples,
            'bases' => $bases,
            'areas' => $areas,
            'variants' => $variants,
            'evidence' => $evidence,
            'meta' => $meta,
        ];
    }

    /**
     * Linha completa de uma família para o lote visível, ou null quando a família não tem chave.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>|null
     */
    private function familyLine(int $fid, array $ctx): ?array
    {
        $key = $this->keyOf($fid, $ctx['journals']);
        if ($key === null) {
            return null;
        }

        $journal = $ctx['journals'][$fid];
        $title = $journal['display'] ?? $journal['title'];
        $members = array_values(array_unique([$fid, ...($ctx['families'][$fid] ?? [])]));

        $issns = [];
        $titles = [];
        foreach ($members as $memberId) {
            $member = $ctx['journals'][$memberId];
            foreach ($member['issns'] as $n8) {
                $issns[] = $n8;
            }
            $titles[] = $member['title'];
            if ($member['display'] !== null) {
                $titles[] = $member['display'];
            }
        }
        foreach ($ctx['variants'][$fid] ?? [] as $variant) {
            $titles[] = $variant;
        }

        $areas = $ctx['areas'][$fid] ?? [];
        $primary = null;
        $confidence = $areas[0]['confidence'] ?? null;
        foreach ($areas as $area) {
            if ($area['primary']) {
                $primary = $area['slug'];
                $confidence = $area['confidence'];
                break;
            }
        }

        return [
            'key' => $key,
            'title' => $title,
            'other_titles' => $this->otherTitles($title, $titles),
            'issns' => array_map([Issn::class, 'format'], array_values(array_unique($issns))),
            'publisher' => $ctx['meta'][$fid]['publisher'] ?? null,
            'country' => $ctx['meta'][$fid]['country'] ?? null,
            'bases' => $ctx['bases'][$fid] ?? [],
            'articles' => $ctx['articles'][$fid] ?? 0,
            'evidence' => $this->evidenceOf($fid, $ctx['evidence']),
            'current' => ['primary' => $primary, 'confidence' => $confidence],
            'sample_articles' => $ctx['samples'][$fid] ?? [],
        ];
    }

    /**
     * Linha compacta do escopo unclassified: chave, título e país.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>|null
     */
    private function compactLine(int $fid, array $ctx): ?array
    {
        $key = $this->keyOf($fid, $ctx['journals']);
        if ($key === null) {
            return null;
        }
        $journal = $ctx['journals'][$fid];

        return [
            'key' => $key,
            'title' => $journal['display'] ?? $journal['title'],
            'country' => $ctx['meta'][$fid]['country'] ?? null,
        ];
    }

    /**
     * Evidências da família agrupadas por fonte, com os rótulos crus (OpenAlex: código e participação).
     *
     * @param array<int, array<string, list<array{label: string, total: float, avg: float}>>> $evidence
     * @return array<string, list<mixed>>
     */
    private function evidenceOf(int $fid, array $evidence): array
    {
        $grouped = array_fill_keys(self::EVIDENCE_STANDARD, []);
        foreach ($evidence[$fid] ?? [] as $source => $labels) {
            $name = self::EVIDENCE_NAMES[$source] ?? $source;
            $grouped[$name] = $source === 'openalex'
                ? array_map(static fn (array $e): array => ['code' => $e['label'], 'share' => round($e['avg'], 2)], $labels)
                : array_map(static fn (array $e): string => $e['label'], $labels);
        }

        return $grouped;
    }

    /**
     * Títulos alternativos sem repetição (na forma do tesauro), sem o título exibido.
     *
     * @param list<string> $candidates
     * @return list<string>
     */
    private function otherTitles(string $title, array $candidates): array
    {
        $seen = [StringNormalizer::normalizeString($title, true) => true];
        $others = [];
        foreach ($candidates as $candidate) {
            $norm = StringNormalizer::normalizeString($candidate, true);
            if ($norm === '' || isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $others[] = trim($candidate);
        }

        return $others;
    }

    /**
     * Até SAMPLE_SIZE títulos distintos, dos mais recentes para os mais antigos.
     *
     * @param list<array{title: string, year: ?int}> $items
     * @return list<string>
     */
    private function recentTitles(array $items): array
    {
        usort(
            $items,
            static fn (array $a, array $b): int => [$b['year'] ?? PHP_INT_MIN, $a['title']] <=> [$a['year'] ?? PHP_INT_MIN, $b['title']]
        );

        $seen = [];
        $titles = [];
        foreach ($items as $item) {
            $norm = mb_strtolower($item['title']);
            if (isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $titles[] = $item['title'];
            if (count($titles) === self::SAMPLE_SIZE) {
                break;
            }
        }

        return $titles;
    }

    /**
     * Chave da família: `issn:` com o primeiro ISSN válido da canônica; sem ISSN, `title:` com a forma do tesauro.
     *
     * @param array<int, array<string, mixed>> $journals
     */
    private function keyOf(int $fid, array $journals): ?string
    {
        if (!isset($journals[$fid])) {
            return null;
        }
        $journal = $journals[$fid];
        if ($journal['issns'] !== []) {
            return 'issn:' . $journal['issns'][0];
        }
        $title = $this->titleKey($journal);

        return $title === null ? null : 'title:' . $title;
    }

    /**
     * Forma do tesauro do título: a coluna normalized_title quando preenchida, senão calculada do título.
     *
     * @param array<string, mixed> $journal
     */
    private function titleKey(array $journal): ?string
    {
        $norm = $journal['norm'] ?? StringNormalizer::normalizeString($journal['title'], true);

        return $norm === '' ? null : $norm;
    }

    /**
     * Índice chave → famílias: `issn:` com qualquer linha que tenha o ISSN; `title:` só com as canônicas.
     *
     * @param array<int, array<string, mixed>> $journals
     * @return array<string, array<int, true>>
     */
    private function familyIndex(array $journals): array
    {
        $index = [];
        foreach ($journals as $id => $journal) {
            $fid = $journal['canonical'] ?? $id;
            foreach ($journal['issns'] as $n8) {
                $index['issn:' . $n8][$fid] = true;
            }
            if ($journal['canonical'] === null) {
                $title = $this->titleKey($journal);
                if ($title !== null) {
                    $index['title:' . $title][$fid] = true;
                }
            }
        }

        return $index;
    }

    /**
     * Família de uma chave. Havendo mais de uma com o mesmo ISSN, prefere a cuja chave exportada é esta; se ainda
     * houver mais de uma, a linha é ambígua.
     *
     * @param array<string, array<int, true>> $index
     * @param array<int, array<string, mixed>> $journals
     * @return int|string id da família, ou o motivo da rejeição
     */
    private function resolveFamily(string $key, array $index, array $journals): int|string
    {
        $fids = array_keys($index[$key] ?? []);
        if (count($fids) > 1) {
            $exact = array_values(array_filter($fids, fn (int $fid): bool => $this->keyOf($fid, $journals) === $key));
            if (count($exact) === 1) {
                $fids = $exact;
            }
        }
        if ($fids === []) {
            return 'unknown_family';
        }
        if (count($fids) > 1) {
            return 'ambiguous_family';
        }

        return $fids[0];
    }

    /**
     * Valida uma linha de resposta. Devolve ['error' => motivo, 'key' => ?string] ou os campos validados.
     *
     * @return array<string, mixed>
     */
    private function validateLine(string $raw): array
    {
        $row = json_decode($raw, true);
        if (!is_array($row)) {
            return ['error' => 'invalid_json', 'key' => null];
        }

        $key = is_string($row['key'] ?? null) ? trim($row['key']) : '';
        if ($key === '') {
            return ['error' => 'missing_key', 'key' => null];
        }

        if (array_key_exists('primary', $row) && $row['primary'] === null) {
            return ['error' => 'unclassifiable', 'key' => $key];
        }
        $primary = $row['primary'] ?? null;
        if (!is_string($primary) || !$this->taxonomy->isValidSlug($primary)) {
            return ['error' => 'invalid_primary', 'key' => $key];
        }

        $listed = $row['secondary'] ?? [];
        if (!is_array($listed) || !array_is_list($listed)) {
            return ['error' => 'invalid_secondary', 'key' => $key];
        }
        $secondary = [];
        foreach ($listed as $slug) {
            if (!is_string($slug) || !$this->taxonomy->isValidSlug($slug)) {
                return ['error' => 'invalid_secondary', 'key' => $key];
            }
            if ($slug !== $primary && !in_array($slug, $secondary, true)) {
                $secondary[] = $slug;
            }
        }
        if (count($secondary) > 2) {
            return ['error' => 'invalid_secondary', 'key' => $key];
        }

        $confidence = $row['confidence'] ?? null;
        if ((!is_int($confidence) && !is_float($confidence)) || $confidence < 0 || $confidence > 1) {
            return ['error' => 'invalid_confidence', 'key' => $key];
        }

        return [
            'key' => $key,
            'primary' => $primary,
            'secondary' => $secondary,
            'confidence' => $confidence,
            'row' => $row,
        ];
    }

    /**
     * @param list<array{articles: int, name: string, fid: int, line: array<string, mixed>}> $items
     * @return list<array<string, mixed>>
     */
    private function linesOf(array $items): array
    {
        usort(
            $items,
            static fn (array $a, array $b): int => [$b['articles'], mb_strtolower($a['name']), $a['fid']]
                <=> [$a['articles'], mb_strtolower($b['name']), $b['fid']]
        );

        return array_column($items, 'line');
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{journals: int, batches: int}
     */
    private function writeExport(string $outDir, string $prefix, array $lines, int $batchSize, string $prompt): array
    {
        $outDir = rtrim($outDir, '/');
        $this->ensureDirectory($outDir);

        $batches = 0;
        foreach (array_chunk($lines, $batchSize) as $index => $chunk) {
            $content = '';
            foreach ($chunk as $line) {
                $content .= json_encode($line, self::JSON_FLAGS) . "\n";
            }
            $this->writeFile(sprintf('%s/%s_%03d.jsonl', $outDir, $prefix, $index + 1), $content);
            ++$batches;
        }
        $this->writeFile($outDir . '/PROMPT.md', $prompt);

        return ['journals' => count($lines), 'batches' => $batches];
    }

    /**
     * @param list<array{slug: string, primary: bool, confidence: string}> $areas áreas de uma linha
     * @param list<string> $targets confianças que selecionam a família
     */
    private function hasConfidence(array $areas, array $targets): bool
    {
        foreach ($areas as $area) {
            if (in_array($area['confidence'], $targets, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $members ids das linhas da família
     * @param array<int, list<array{slug: string, primary: bool, confidence: string}>> $areas por id da linha
     */
    private function familyHasAreas(array $members, array $areas): bool
    {
        foreach ($members as $id) {
            if (!empty($areas[$id])) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $journals */
    private function familyOf(int $id, array $journals): int
    {
        return $journals[$id]['canonical'] ?? $id;
    }

    /** @param array<string, mixed> $row linha de qualis_journals */
    private function issnsOf(array $row): array
    {
        $found = [];
        foreach (self::ISSN_COLUMNS as $column) {
            foreach (Issn::extractAll($row[$column] ?? null) as $n8) {
                $found[] = $n8;
            }
        }

        return array_values(array_unique($found));
    }

    private function jsonString(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $value = json_decode($raw, true);
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nonEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function recordKey(string $key): string
    {
        return strlen($key) <= self::RECORD_KEY_MAX ? $key : md5($key);
    }

    private function assertBatchSize(int $batchSize): void
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('O tamanho do lote deve ser pelo menos 1.');
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Não foi possível criar a pasta "%s".', $dir));
        }
    }

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException(sprintf('Não foi possível gravar "%s".', $path));
        }
    }
}
