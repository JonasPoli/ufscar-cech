<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Entity\CapesAreaVariant;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * Tesauro de categorias de revistas: o que cada rótulo de categoria (ASJC, WoS, DOAJ, Latindex, SciELO, CNPq, CAPES ou
 * texto livre) significa em áreas CAPES. Os dados ficam na tabela `capes_area_variants` (entidade
 * {@see CapesAreaVariant}); este serviço lê a tabela, resolve texto composto e, em {@see self::sync()}, a semeia dos
 * arquivos de `config/journal_areas` e do uso real.
 *
 * Busca: {@see self::lookup()} compara pela forma do tesauro do rótulo (`StringNormalizer::normalizeString($rotulo, true)`,
 * a mesma de `journal_name_variants`) e devolve os slugs de UM vocabulário, o de maior prioridade, sem misturar:
 * linhas `manual` (a correção do admin) > capes > cnpq > wos > latindex > doaj > scielo_desc > scielo_area > asjc > livre.
 * Slug, nome e rótulo canônico da {@see CapesTaxonomy} são sempre reconhecidos, mesmo com a tabela vazia (valem como
 * linhas `capes`). Só linhas `active` e `ignored` entram na busca: o `pending` é um rótulo ainda sem decisão. Rótulo só
 * com dígitos é código ASJC, não texto, e não entra na busca. O índice é montado na primeira consulta e fica em memória
 * até {@see self::reset()}.
 *
 * Texto composto: {@see self::resolve()} desmonta `Mathematics, Applied | Mathematics` ou `Medicina, Salud pública`
 * (inteiro, depois as partes de `|` e `;`, depois as subpartes de `,`), porque `Mathematics, Applied` é um rótulo do WoS
 * inteiro, com vírgula.
 *
 * Os dados brutos nunca são alterados: o tesauro só lê `qualis_journals.area_raw`, `knowledge_areas` e
 * `journal_area_evidence`.
 */
class JournalAreaThesaurus
{
    /** Vocabulários cujos rótulos são confiáveis como texto de `area` original (as linhas `manual` também são). */
    public const TRUSTED_VOCABULARIES = [CapesAreaVariant::VOCABULARY_CAPES, CapesAreaVariant::VOCABULARY_CNPQ];

    /** Fonte da evidência => vocabulário. `ai`, `title_rule` e `legacy` não têm rótulo de categoria e ficam de fora. */
    private const EVIDENCE_VOCABULARY = [
        'openalex' => CapesAreaVariant::VOCABULARY_ASJC,
        'scopus' => CapesAreaVariant::VOCABULARY_ASJC,
        'wos' => CapesAreaVariant::VOCABULARY_WOS,
        'scielo_wos' => CapesAreaVariant::VOCABULARY_WOS,
        'doaj' => CapesAreaVariant::VOCABULARY_DOAJ,
        'latindex' => CapesAreaVariant::VOCABULARY_LATINDEX,
        'scielo_area' => CapesAreaVariant::VOCABULARY_SCIELO_AREA,
        'scielo_desc' => CapesAreaVariant::VOCABULARY_SCIELO_DESC,
    ];

    /** Linhas por INSERT. */
    private const INSERT_CHUNK = 200;

    /** Ids por `DELETE ... IN (...)`. */
    private const DELETE_CHUNK = 500;

    /** Trechos com menos letras que isto, ou com mais caracteres que {@see self::LABEL_MAX}, não viram pendentes. */
    private const MIN_LETTERS = 3;
    private const LABEL_MAX = 255;

    /** Pendentes listados no relatório do `sync`. */
    private const REPORT_PENDING_LIMIT = 50;

    /** @var array<string, int>|null vocabulário => posição na ordem de prioridade */
    private static ?array $priority = null;

    /**
     * @var array<string, array<string, array{vocabulary: string, manual: bool, slugs: list<string>, spellings: array<string, true>}>>|null
     *      forma do tesauro => grupo (vocabulário, ou `manual:` mais o vocabulário) => dados do grupo; `slugs` vazio é a
     *      decisão "sem área" (`ignored`)
     */
    private ?array $groups = null;

    /** @var array<string, array<string, true>>|null forma do tesauro => grafias confiáveis (sem caixa) do rótulo */
    private ?array $trustedSpellings = null;

    public function __construct(
        private readonly Connection $conn,
        private readonly CapesTaxonomy $taxonomy,
        private readonly AreaCrosswalk $crosswalk,
    ) {
    }

    // ------------------------------------------------------------------------------------------------------------
    // Consulta
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Esquece o índice em memória; a próxima consulta o monta de novo a partir da tabela.
     */
    public function reset(): void
    {
        $this->groups = null;
        $this->trustedSpellings = null;
    }

    /**
     * Slugs CAPES de um rótulo.
     *
     * @param bool $trustedOnly considera só as linhas `capes`, `cnpq` e `manual`
     *
     * @return list<string>|null os slugs do vocabulário de maior prioridade; `[]` quando a decisão é "sem área" (linha
     *                           `ignored`); null quando o rótulo é desconhecido
     */
    public function lookup(string $label, bool $trustedOnly = false): ?array
    {
        $normalized = StringNormalizer::normalizeString($label, true);
        if ($normalized === '' || self::isCode($normalized)) {
            return null;
        }

        return $this->bestGroup($normalized, $trustedOnly)['slugs'] ?? null;
    }

    /**
     * Resolve um texto livre, que pode ser composto: o texto inteiro; senão as partes separadas por `|` e `;`; e, de cada
     * parte, ela inteira ou, senão, as subpartes separadas por `,`.
     *
     * @return array{slugs: list<string>, unknown: list<string>} slugs únicos, na ordem em que aparecem, e os trechos
     *                                                           não reconhecidos como estão no texto (aparados); texto vazio
     *                                                           responde as duas listas vazias
     */
    public function resolve(?string $text): array
    {
        $walk = $this->walk($text);

        return ['slugs' => $walk['slugs'], 'unknown' => $walk['unknown']];
    }

    /**
     * Slug que o tesauro confiável dá ao texto de `area` original (`qualis_journals.area_raw`) para a evidência `legacy`,
     * ou null.
     *
     * Vale se `lookup($texto, trustedOnly: true)` devolve exatamente um slug e o texto é uma grafia do tesauro: está todo
     * em maiúsculas (como o Qualis grafa, às vezes sem acento: `SAUDE COLETIVA`) ou é, sem diferença de caixa, uma
     * grafia que o `capes`, o `cnpq` (inclusive os rótulos que o arquivo traz e a tabela junta pela forma do tesauro,
     * como `Lingüística`) ou uma linha `manual` conhece. Assim `Sociología` e `Psicología`, que são rótulos do Latindex e
     * já chegam pela própria fonte, não contam duas vezes, e nenhum rótulo em inglês entra a não ser por correção manual.
     */
    public function legacySlug(string $text): ?string
    {
        $text = trim($text);
        $slugs = $this->lookup($text, true);
        if ($slugs === null || count($slugs) !== 1) {
            return null;
        }

        if ($text === mb_strtoupper($text, 'UTF-8')) {
            return $slugs[0];
        }

        $spellings = $this->trustedSpellings()[StringNormalizer::normalizeString($text, true)] ?? [];

        return isset($spellings[self::caseKey($text)]) ? $slugs[0] : null;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Semente e uso
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Semeia e atualiza o tesauro. Idempotente: rodar de novo não muda nada. Tudo numa transação, em lotes; com
     * `$dryRun` tudo é feito e desfeito (os números são os da execução real).
     *
     * 1. Sementes: o nome e o rótulo canônico de cada área da taxonomia (vocabulário `capes`) e cada entrada dos arquivos
     *    de crosswalk, uma linha por slug (`seed`, `active`); o valor null do arquivo vira uma linha `ignored`, sem
     *    área. Rótulo que tem qualquer linha `manual` no mesmo vocabulário não é tocado. As linhas `seed` que saíram do
     *    arquivo são apagadas.
     * 2. `occurrences`: revistas distintas que usam o rótulo na evidência (OpenAlex e Scopus contam como `asjc`, o
     *    `scielo_wos` como `wos`; `ai`, `title_rule` e `legacy` ficam fora); registros de `knowledge_areas` no `cnpq`
     *    (colunas `area` e `major_area`); revistas cujo `area_raw` cita o rótulo, inteiro ou em parte, no `capes` e no
     *    `livre`.
     * 3. Pendentes: o rótulo usado e não reconhecido vira uma linha `pending`, sem área, com o total de usos: as partes de
     *    `area_raw` que {@see self::resolve()} não reconhece (`livre`), as áreas de `knowledge_areas` que {@see self::lookup()}
     *    não reconhece (`cnpq`) e os rótulos de evidência sem mapa no crosswalk (vocabulário da fonte). Trechos de texto
     *    com menos de 3 letras ou mais de 255 caracteres ficam de fora. O pendente que passou a ser reconhecido, ou que
     *    ninguém usa mais, é apagado.
     *
     * @return array{
     *     dry_run: bool,
     *     inserted: int,
     *     updated: int,
     *     deleted: int,
     *     protected: int,
     *     pending_inserted: int,
     *     pending_deleted: int,
     *     vocabularies: array<string, array{active: int, pending: int, ignored: int}>,
     *     pending: list<array{vocabulary: string, label: string, occurrences: int}>,
     *     pending_total: int,
     *     seconds: float
     * } `inserted` são as linhas de semente criadas, `deleted` as removidas (de semente, ou pendentes: veja
     *   `pending_deleted`) e `updated` as alteradas (rótulo ou `occurrences`, de qualquer origem e status);
     *   `protected` são os rótulos com correção manual; `pending_inserted` e `pending_deleted` são as linhas pendentes
     *   criadas e apagadas, já contadas à parte; `vocabularies` é a situação da tabela depois do `sync`, e `pending` os
     *   maiores pendentes (até 50, de `pending_total`)
     */
    public function sync(bool $dryRun = false): array
    {
        $startedAt = microtime(true);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stats = ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'protected' => 0, 'pending_inserted' => 0, 'pending_deleted' => 0];

        $this->conn->beginTransaction();
        try {
            $preexisting = $this->syncSeeds($now, $stats);

            // Daqui em diante "reconhecido" é o que a tabela já semeada reconhece
            $this->reset();
            $this->syncUsage($now, $stats, $preexisting);

            $vocabularies = $this->vocabularyReport();
            $pending = $this->pendingReport();

            if ($dryRun) {
                $this->conn->rollBack();
            } else {
                $this->conn->commit();
            }
        } catch (\Throwable $e) {
            if ($this->conn->isTransactionActive()) {
                $this->conn->rollBack();
            }

            throw $e;
        } finally {
            $this->reset();
        }

        return ['dry_run' => $dryRun] + $stats + [
            'vocabularies' => $vocabularies,
            'pending' => $pending['rows'],
            'pending_total' => $pending['total'],
            'seconds' => round(microtime(true) - $startedAt, 2),
        ];
    }

    /**
     * Passo 1: sementes. Insere o que falta, corrige o rótulo grafado de outro jeito e apaga o que saiu dos arquivos.
     *
     * @param array<string, int> $stats
     *
     * @return array<int, true> ids das linhas que já existiam antes do `sync`
     */
    private function syncSeeds(string $now, array &$stats): array
    {
        $desired = $this->desiredSeeds();
        $current = $this->loadRows();

        $manual = [];
        foreach ($current as $row) {
            if ($row['origin'] === CapesAreaVariant::ORIGIN_MANUAL) {
                $manual[self::labelKey($row['vocabulary'], $row['normalized_name'])] = true;
            }
        }
        $stats['protected'] = count($manual);

        $existing = [];
        $delete = [];
        foreach ($current as $row) {
            if ($row['origin'] !== CapesAreaVariant::ORIGIN_SEED || $row['status'] === CapesAreaVariant::STATUS_PENDING) {
                continue;
            }
            if (isset($manual[self::labelKey($row['vocabulary'], $row['normalized_name'])])) {
                continue;
            }

            $key = self::rowKey($row['vocabulary'], $row['normalized_name'], $row['area_id']);
            if (!isset($desired[$key]) || isset($existing[$key])) {
                // Saiu do arquivo, ou é a repetição de uma linha que já existe
                $delete[] = $row['id'];
                continue;
            }
            $existing[$key] = $row;
        }

        $insert = [];
        $update = [];
        foreach ($desired as $key => $seed) {
            if (isset($manual[self::labelKey($seed['vocabulary'], $seed['normalized'])])) {
                continue;
            }

            $row = $existing[$key] ?? null;
            if ($row === null) {
                $insert[] = $seed + ['origin' => CapesAreaVariant::ORIGIN_SEED, 'occurrences' => 0];
            } elseif ($row['variation_name'] !== $seed['variation']) {
                $update[] = ['id' => $row['id'], 'variation_name' => $seed['variation']];
            }
        }

        $this->deleteRows($delete);
        foreach ($update as $change) {
            $this->conn->executeStatement(
                'UPDATE capes_area_variants SET variation_name = ?, updated_at = ? WHERE id = ?',
                [$change['variation_name'], $now, $change['id']]
            );
        }
        $this->insertRows($insert, $now);

        $stats['inserted'] += count($insert);
        $stats['updated'] += count($update);
        $stats['deleted'] += count($delete);

        return array_fill_keys(array_column($current, 'id'), true);
    }

    /**
     * Linhas de semente que os arquivos pedem: chave da linha => dados.
     *
     * @return array<string, array{vocabulary: string, normalized: string, variation: string, area_id: int|null, status: string}>
     */
    private function desiredSeeds(): array
    {
        $seeds = [];
        $add = function (string $vocabulary, string $label, ?int $areaId) use (&$seeds): void {
            $label = trim($label);
            $normalized = mb_substr(StringNormalizer::normalizeString($label, true), 0, self::LABEL_MAX, 'UTF-8');
            if ($normalized === '') {
                return;
            }

            $seeds[self::rowKey($vocabulary, $normalized, $areaId)] ??= [
                'vocabulary' => $vocabulary,
                'normalized' => $normalized,
                'variation' => mb_substr($label, 0, self::LABEL_MAX, 'UTF-8'),
                'area_id' => $areaId,
                'status' => $areaId === null ? CapesAreaVariant::STATUS_IGNORED : CapesAreaVariant::STATUS_ACTIVE,
            ];
        };

        foreach ($this->taxonomy->all() as $area) {
            $add(CapesAreaVariant::VOCABULARY_CAPES, $area['name'], $area['id']);
            $add(CapesAreaVariant::VOCABULARY_CAPES, $this->taxonomy->areaLabel($area['slug']), $area['id']);
        }

        foreach (AreaCrosswalk::SOURCES as $source) {
            foreach ($this->crosswalk->fileEntries($source) as $entry) {
                if ($entry['slugs'] === null) {
                    $add($source, $entry['label'], null);
                    continue;
                }
                foreach ($entry['slugs'] as $slug) {
                    $areaId = $this->taxonomy->bySlug($slug)['id'] ?? null;
                    if ($areaId !== null) {
                        $add($source, $entry['label'], $areaId);
                    }
                }
            }
        }

        // Dois rótulos que só diferem no acento podem ficar um "sem área" e o outro com área: a área vence
        $withArea = [];
        foreach ($seeds as $seed) {
            if ($seed['area_id'] !== null) {
                $withArea[self::labelKey($seed['vocabulary'], $seed['normalized'])] = true;
            }
        }
        foreach ($seeds as $key => $seed) {
            if ($seed['area_id'] === null && isset($withArea[self::labelKey($seed['vocabulary'], $seed['normalized'])])) {
                unset($seeds[$key]);
            }
        }

        return $seeds;
    }

    /**
     * Passos 2 e 3: `occurrences` de todas as linhas e pendentes.
     *
     * @param array<string, int> $stats
     * @param array<int, true>   $preexisting ids das linhas que já existiam antes do `sync`: a linha criada agora já nasce
     *                                        com o número certo, e isso não conta como atualização
     */
    private function syncUsage(string $now, array &$stats, array $preexisting): void
    {
        $usage = $this->collectUsage();
        $rows = $this->loadRows();

        $recognized = [];
        $pendingRows = [];
        $changes = [];
        foreach ($rows as $row) {
            $labelKey = self::labelKey($row['vocabulary'], $row['normalized_name']);

            if ($row['status'] === CapesAreaVariant::STATUS_PENDING) {
                $pendingRows[$labelKey][] = $row;
                continue;
            }

            $recognized[$labelKey] = true;
            $wanted = $usage['counts'][$labelKey] ?? 0;
            if ($wanted !== $row['occurrences']) {
                $changes[] = ['id' => $row['id'], 'occurrences' => $wanted, 'variation_name' => null];
            }
        }

        $delete = [];
        $insert = [];
        foreach ($pendingRows as $labelKey => $group) {
            $candidate = $usage['candidates'][$labelKey] ?? null;
            foreach ($group as $position => $row) {
                if ($candidate === null || isset($recognized[$labelKey]) || $position > 0) {
                    $delete[] = $row['id'];
                    continue;
                }
                if ($row['occurrences'] !== $candidate['occurrences'] || $row['variation_name'] !== $candidate['label']) {
                    $changes[] = ['id' => $row['id'], 'occurrences' => $candidate['occurrences'], 'variation_name' => $candidate['label']];
                }
            }
        }
        foreach ($usage['candidates'] as $labelKey => $candidate) {
            if (isset($recognized[$labelKey]) || isset($pendingRows[$labelKey])) {
                continue;
            }
            $insert[] = [
                'vocabulary' => $candidate['vocabulary'],
                'normalized' => $candidate['normalized'],
                'variation' => $candidate['label'],
                'area_id' => null,
                'status' => CapesAreaVariant::STATUS_PENDING,
                'origin' => CapesAreaVariant::ORIGIN_SEED,
                'occurrences' => $candidate['occurrences'],
            ];
        }

        $this->deleteRows($delete);
        foreach ($changes as $change) {
            if ($change['variation_name'] === null) {
                $this->conn->executeStatement(
                    'UPDATE capes_area_variants SET occurrences = ?, updated_at = ? WHERE id = ?',
                    [$change['occurrences'], $now, $change['id']]
                );
            } else {
                $this->conn->executeStatement(
                    'UPDATE capes_area_variants SET occurrences = ?, variation_name = ?, updated_at = ? WHERE id = ?',
                    [$change['occurrences'], $change['variation_name'], $now, $change['id']]
                );
            }
        }
        $this->insertRows($insert, $now);

        $stats['updated'] += count(array_filter($changes, static fn (array $change): bool => isset($preexisting[$change['id']])));
        $stats['pending_inserted'] += count($insert);
        $stats['pending_deleted'] += count($delete);
    }

    /**
     * Quantos usos cada rótulo tem e quais rótulos usados não são reconhecidos.
     *
     * @return array{
     *     counts: array<string, int>,
     *     candidates: array<string, array{vocabulary: string, normalized: string, label: string, occurrences: int}>
     * } `counts` por chave do rótulo (vocabulário + forma do tesauro); `candidates` os rótulos desconhecidos
     */
    private function collectUsage(): array
    {
        $counts = [];
        $spellings = [];
        $vocabularies = [];

        $note = function (string $vocabulary, string $label, int $uses) use (&$spellings, &$vocabularies): ?string {
            $normalized = mb_substr(StringNormalizer::normalizeString($label, true), 0, self::LABEL_MAX, 'UTF-8');
            if ($normalized === '') {
                return null;
            }

            $labelKey = self::labelKey($vocabulary, $normalized);
            $spellings[$labelKey][$label] = ($spellings[$labelKey][$label] ?? 0) + $uses;
            $vocabularies[$labelKey] = [$vocabulary, $normalized];

            return $labelKey;
        };
        $unknown = [];

        // Evidência: revistas distintas por vocabulário e rótulo (grafia exata, para o crosswalk julgar cada uma)
        $sources = array_keys(self::EVIDENCE_VOCABULARY);
        $vocabularyCase = 'CASE source';
        foreach (self::EVIDENCE_VOCABULARY as $source => $vocabulary) {
            $vocabularyCase .= sprintf(" WHEN '%s' THEN '%s'", $source, $vocabulary);
        }
        $vocabularyCase .= ' END';
        $evidence = $this->conn->fetchAllAssociative(
            "SELECT $vocabularyCase AS vocab, label COLLATE utf8mb4_bin AS raw_label, COUNT(DISTINCT journal_id) AS journals
               FROM journal_area_evidence
              WHERE source IN (?)
              GROUP BY vocab, raw_label",
            [$sources],
            [ArrayParameterType::STRING]
        );
        foreach ($evidence as $row) {
            $vocabulary = (string) $row['vocab'];
            $label = (string) $row['raw_label'];
            $journals = (int) $row['journals'];

            $labelKey = $note($vocabulary, $label, $journals);
            if ($labelKey === null) {
                continue;
            }
            $counts[$labelKey] = ($counts[$labelKey] ?? 0) + $journals;

            if (!$this->crosswalk->isKnown($vocabulary, $label) && self::isUsableLabel($label, $vocabulary === CapesAreaVariant::VOCABULARY_ASJC)) {
                $unknown[$labelKey] = true;
            }
        }

        // Áreas do CNPq que vêm do Lattes (colunas `area` e `major_area`): registros por rótulo
        $knowledge = $this->conn->fetchAllAssociative(
            "SELECT label COLLATE utf8mb4_bin AS raw_label, COUNT(*) AS total
               FROM (
                   SELECT area AS label FROM knowledge_areas WHERE area IS NOT NULL AND area <> ''
                   UNION ALL
                   SELECT major_area AS label FROM knowledge_areas WHERE major_area IS NOT NULL AND major_area <> ''
               ) t
              GROUP BY raw_label"
        );
        foreach ($knowledge as $row) {
            $label = trim((string) $row['raw_label']);
            $total = (int) $row['total'];

            $labelKey = $note(CapesAreaVariant::VOCABULARY_CNPQ, $label, $total);
            if ($labelKey === null) {
                continue;
            }
            $counts[$labelKey] = ($counts[$labelKey] ?? 0) + $total;

            if ($this->lookup($label) === null && self::isUsableLabel($label, false)) {
                $unknown[$labelKey] = true;
            }
        }

        // Texto livre de `area_raw`: o texto repete muito (uma linha por revista), então conta por texto distinto
        $texts = array_count_values(array_map('strval', $this->conn->fetchFirstColumn(
            "SELECT area_raw FROM qualis_journals WHERE area_raw IS NOT NULL AND area_raw <> ''"
        )));
        $fragments = [];
        foreach ($texts as $text => $journals) {
            $seen = [];
            foreach ($this->walk((string) $text)['leaves'] as $leaf) {
                $normalized = mb_substr(StringNormalizer::normalizeString($leaf['text'], true), 0, self::LABEL_MAX, 'UTF-8');
                if ($normalized === '') {
                    continue;
                }

                if ($leaf['known']) {
                    if (!isset($seen[$normalized])) {
                        $seen[$normalized] = true;
                        $fragments[$normalized] = ($fragments[$normalized] ?? 0) + $journals;
                    }
                    continue;
                }

                if (!self::isUsableLabel($leaf['text'], false)) {
                    continue;
                }
                $labelKey = $note(CapesAreaVariant::VOCABULARY_LIVRE, $leaf['text'], $journals);
                if ($labelKey !== null && !isset($seen[$normalized])) {
                    $seen[$normalized] = true;
                    $counts[$labelKey] = ($counts[$labelKey] ?? 0) + $journals;
                    $unknown[$labelKey] = true;
                }
            }
        }
        // O texto livre é o universo dos vocabulários `capes` (nomes das áreas) e `livre` (sinônimos)
        foreach ($fragments as $normalized => $journals) {
            foreach ([CapesAreaVariant::VOCABULARY_CAPES, CapesAreaVariant::VOCABULARY_LIVRE] as $vocabulary) {
                $counts[self::labelKey($vocabulary, (string) $normalized)] = $journals;
            }
        }

        $candidates = [];
        foreach (array_keys($unknown) as $labelKey) {
            [$vocabulary, $normalized] = $vocabularies[$labelKey];
            $candidates[$labelKey] = [
                'vocabulary' => $vocabulary,
                'normalized' => $normalized,
                'label' => self::mostUsed($spellings[$labelKey]),
                'occurrences' => $counts[$labelKey] ?? 0,
            ];
        }

        return ['counts' => $counts, 'candidates' => $candidates];
    }

    /**
     * Trecho de texto que pode virar pendente: com pelo menos 3 letras e até 255 caracteres. O código ASJC (`1702`) não
     * tem letras e vale como está.
     */
    private static function isUsableLabel(string $label, bool $isCode): bool
    {
        if ($label === '' || mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
            return false;
        }

        return $isCode || preg_match_all('/\p{L}/u', $label) >= self::MIN_LETTERS;
    }

    /**
     * Grafia mais usada; no empate, a menor em ordem alfabética (resultado estável).
     *
     * @param array<int|string, int> $spellings grafia => usos
     */
    private static function mostUsed(array $spellings): string
    {
        $best = null;
        $bestUses = -1;
        foreach ($spellings as $spelling => $uses) {
            $spelling = (string) $spelling;
            if ($uses > $bestUses || ($uses === $bestUses && $best !== null && strcmp($spelling, $best) < 0)) {
                $best = $spelling;
                $bestUses = $uses;
            }
        }

        return $best ?? '';
    }

    // ------------------------------------------------------------------------------------------------------------
    // Banco
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @return list<array{id: int, area_id: int|null, vocabulary: string, normalized_name: string, variation_name: string, origin: string, status: string, occurrences: int}>
     */
    private function loadRows(): array
    {
        $rows = [];
        foreach ($this->conn->fetchAllAssociative(
            'SELECT id, area_id, vocabulary, normalized_name, variation_name, origin, status, occurrences FROM capes_area_variants ORDER BY id'
        ) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'area_id' => $row['area_id'] !== null ? (int) $row['area_id'] : null,
                'vocabulary' => (string) $row['vocabulary'],
                'normalized_name' => (string) $row['normalized_name'],
                'variation_name' => (string) $row['variation_name'],
                'origin' => (string) $row['origin'],
                'status' => (string) $row['status'],
                'occurrences' => (int) $row['occurrences'],
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{vocabulary: string, normalized: string, variation: string, area_id: int|null, status: string, origin: string, occurrences: int}> $rows
     */
    private function insertRows(array $rows, string $now): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    $row['area_id'],
                    $row['vocabulary'],
                    $row['variation'],
                    $row['normalized'],
                    $row['origin'],
                    $row['status'],
                    $row['occurrences'],
                    $now,
                    $now
                );
            }

            $this->conn->executeStatement(
                'INSERT INTO capes_area_variants (area_id, vocabulary, variation_name, normalized_name, origin, status, occurrences, created_at, updated_at) VALUES '
                . implode(', ', $placeholders),
                $params
            );
        }
    }

    /**
     * @param list<int> $ids
     */
    private function deleteRows(array $ids): void
    {
        foreach (array_chunk($ids, self::DELETE_CHUNK) as $chunk) {
            $this->conn->executeStatement(
                'DELETE FROM capes_area_variants WHERE id IN (?)',
                [$chunk],
                [ArrayParameterType::INTEGER]
            );
        }
    }

    /**
     * Linhas por vocabulário e status, na ordem de prioridade dos vocabulários.
     *
     * @return array<string, array{active: int, pending: int, ignored: int}>
     */
    private function vocabularyReport(): array
    {
        $report = [];
        foreach (CapesAreaVariant::VOCABULARIES as $vocabulary) {
            $report[$vocabulary] = [
                CapesAreaVariant::STATUS_ACTIVE => 0,
                CapesAreaVariant::STATUS_PENDING => 0,
                CapesAreaVariant::STATUS_IGNORED => 0,
            ];
        }

        foreach ($this->conn->fetchAllAssociative('SELECT vocabulary, status, COUNT(*) AS total FROM capes_area_variants GROUP BY vocabulary, status') as $row) {
            $report[(string) $row['vocabulary']][(string) $row['status']] = (int) $row['total'];
        }

        return $report;
    }

    /**
     * @return array{rows: list<array{vocabulary: string, label: string, occurrences: int}>, total: int}
     */
    private function pendingReport(): array
    {
        $rows = [];
        foreach ($this->conn->fetchAllAssociative(
            "SELECT vocabulary, variation_name, occurrences FROM capes_area_variants WHERE status = 'pending'
              ORDER BY occurrences DESC, vocabulary, variation_name, id LIMIT " . self::REPORT_PENDING_LIMIT
        ) as $row) {
            $rows[] = [
                'vocabulary' => (string) $row['vocabulary'],
                'label' => (string) $row['variation_name'],
                'occurrences' => (int) $row['occurrences'],
            ];
        }

        return [
            'rows' => $rows,
            'total' => (int) $this->conn->fetchOne("SELECT COUNT(*) FROM capes_area_variants WHERE status = 'pending'"),
        ];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Índice em memória
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Texto desmontado: slugs únicos e trechos desconhecidos (como em {@see self::resolve()}) e as folhas da
     * desmontagem, cada uma com o texto e se foi reconhecida.
     *
     * @return array{slugs: list<string>, unknown: list<string>, leaves: list<array{text: string, known: bool}>}
     */
    private function walk(?string $text): array
    {
        $slugs = [];
        $unknown = [];
        $leaves = [];

        $text = trim((string) $text);
        if ($text === '') {
            return ['slugs' => [], 'unknown' => [], 'leaves' => []];
        }

        // true se o trecho é reconhecido (as folhas guardam o resultado de qualquer jeito)
        $take = function (string $piece) use (&$slugs, &$leaves): bool {
            $found = $this->lookup($piece);
            $leaves[] = ['text' => $piece, 'known' => $found !== null];
            foreach ($found ?? [] as $slug) {
                $slugs[$slug] = $slug;
            }

            return $found !== null;
        };

        if ($take($text)) {
            return ['slugs' => array_values($slugs), 'unknown' => [], 'leaves' => $leaves];
        }
        // O texto inteiro não era um rótulo: sai das folhas, que passam a ser as partes
        $leaves = [];

        foreach (preg_split('/[|;]/u', $text) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if ($take($part)) {
                continue;
            }

            $subparts = array_values(array_filter(
                array_map('trim', explode(',', $part)),
                static fn (string $subpart): bool => $subpart !== ''
            ));
            if (count($subparts) < 2) {
                // Sem vírgula para desmontar: a própria parte (já anotada como folha desconhecida) é o trecho
                $unknown[] = $part;
                continue;
            }

            // A parte inteira não era um rótulo: sai das folhas, que passam a ser as subpartes
            array_pop($leaves);
            foreach ($subparts as $subpart) {
                if (!$take($subpart)) {
                    $unknown[] = $subpart;
                }
            }
        }

        return ['slugs' => array_values($slugs), 'unknown' => $unknown, 'leaves' => $leaves];
    }

    /**
     * Grupo de maior prioridade da forma do tesauro, ou null.
     *
     * @return array{vocabulary: string, manual: bool, slugs: list<string>, spellings: array<string, true>}|null
     */
    private function bestGroup(string $normalized, bool $trustedOnly): ?array
    {
        $best = null;
        $bestRank = PHP_INT_MAX;
        foreach ($this->index()[$normalized] ?? [] as $group) {
            if ($trustedOnly && !self::isTrusted($group)) {
                continue;
            }

            $rank = ($group['manual'] ? 0 : 100) + (self::priority()[$group['vocabulary']] ?? 99);
            if ($rank < $bestRank) {
                $best = $group;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * @param array{vocabulary: string, manual: bool} $group
     */
    private static function isTrusted(array $group): bool
    {
        return $group['manual'] || in_array($group['vocabulary'], self::TRUSTED_VOCABULARIES, true);
    }

    /**
     * Índice da busca: forma do tesauro => grupos. Um grupo reúne as linhas `active` (ou só `ignored`) de um vocabulário
     * e origem; as linhas `manual` formam grupos à parte. A taxonomia entra como linhas `capes` onde a tabela não tem o
     * rótulo.
     *
     * @return array<string, array<string, array{vocabulary: string, manual: bool, slugs: list<string>, spellings: array<string, true>}>>
     */
    private function index(): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        try {
            $rows = $this->conn->fetchAllAssociative(
                "SELECT v.vocabulary, v.normalized_name, v.variation_name, v.origin, v.status, a.slug
                   FROM capes_area_variants v
                   LEFT JOIN capes_areas a ON a.id = v.area_id
                  WHERE v.status IN ('active', 'ignored')
                  ORDER BY v.id"
            );
        } catch (TableNotFoundException) {
            // Banco ainda sem a migration do tesauro: valem só a taxonomia e os arquivos
            $rows = [];
        }

        $groups = [];
        foreach ($rows as $row) {
            $normalized = (string) $row['normalized_name'];
            if ($normalized === '' || self::isCode($normalized)) {
                continue;
            }

            $manual = $row['origin'] === CapesAreaVariant::ORIGIN_MANUAL;
            $vocabulary = (string) $row['vocabulary'];
            $group = &$groups[$normalized][($manual ? 'manual:' : '') . $vocabulary];
            $group ??= ['vocabulary' => $vocabulary, 'manual' => $manual, 'slugs' => [], 'spellings' => []];

            $slug = $row['slug'];
            if ($row['status'] === CapesAreaVariant::STATUS_ACTIVE && is_string($slug) && !in_array($slug, $group['slugs'], true)) {
                $group['slugs'][] = $slug;
            }
            $group['spellings'][self::caseKey((string) $row['variation_name'])] = true;
            unset($group);
        }

        // Slug, nome e rótulo canônico de cada área valem sempre; o primeiro que reivindica uma forma fica com ela
        $virtual = [];
        foreach ($this->taxonomy->all() as $area) {
            $slug = $area['slug'];
            foreach ([[$slug, false], [$area['name'], true], [$this->taxonomy->areaLabel($slug), true]] as [$text, $isSpelling]) {
                $normalized = StringNormalizer::normalizeString($text, true);
                if ($normalized === '' || (isset($groups[$normalized][CapesAreaVariant::VOCABULARY_CAPES]) && ($virtual[$normalized] ?? null) !== $slug)) {
                    continue;
                }

                $virtual[$normalized] = $slug;
                $group = &$groups[$normalized][CapesAreaVariant::VOCABULARY_CAPES];
                $group ??= ['vocabulary' => CapesAreaVariant::VOCABULARY_CAPES, 'manual' => false, 'slugs' => [$slug], 'spellings' => []];
                if ($isSpelling) {
                    $group['spellings'][self::caseKey($text)] = true;
                }
                unset($group);
            }
        }

        return $this->groups = $groups;
    }

    /**
     * Grafias que valem como texto de `area` original: nome e rótulo canônico das áreas, rótulos do arquivo do `cnpq` e
     * grafias das linhas `capes`, `cnpq` e `manual` da tabela. Forma do tesauro => grafias sem caixa.
     *
     * @return array<string, array<string, true>>
     */
    private function trustedSpellings(): array
    {
        if ($this->trustedSpellings !== null) {
            return $this->trustedSpellings;
        }

        $spellings = [];
        $add = static function (string $text) use (&$spellings): void {
            $normalized = StringNormalizer::normalizeString($text, true);
            if ($normalized !== '') {
                $spellings[$normalized][self::caseKey($text)] = true;
            }
        };

        foreach ($this->taxonomy->all() as $area) {
            $add($area['name']);
            $add($this->taxonomy->areaLabel($area['slug']));
        }
        foreach ($this->crosswalk->fileEntries(CapesAreaVariant::VOCABULARY_CNPQ) as $entry) {
            $add($entry['label']);
        }
        foreach ($this->index() as $normalized => $groups) {
            foreach ($groups as $group) {
                if (self::isTrusted($group)) {
                    $spellings[$normalized] = ($spellings[$normalized] ?? []) + $group['spellings'];
                }
            }
        }

        return $this->trustedSpellings = $spellings;
    }

    /**
     * @return array<string, int>
     */
    private static function priority(): array
    {
        return self::$priority ??= array_flip(CapesAreaVariant::VOCABULARIES);
    }

    /**
     * Rótulo só com dígitos (e espaços): código ASJC, que não é texto.
     */
    private static function isCode(string $normalized): bool
    {
        return preg_match('/^[\p{N}\s]+$/u', $normalized) === 1;
    }

    /**
     * Comparação de grafias: minúsculas, com acento, espaços repetidos colapsados.
     */
    private static function caseKey(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)), 'UTF-8');
    }

    /**
     * Chave de um rótulo: vocabulário e forma do tesauro (com `\0` porque a forma pode ser só dígitos e o PHP trocaria a
     * chave por inteiro).
     */
    private static function labelKey(string $vocabulary, string $normalized): string
    {
        return $vocabulary . "\0" . $normalized;
    }

    /**
     * Chave de uma linha: o rótulo e a área (`-` para a linha sem área).
     */
    private static function rowKey(string $vocabulary, string $normalized, ?int $areaId): string
    {
        return self::labelKey($vocabulary, $normalized) . "\0" . ($areaId ?? '-');
    }
}
