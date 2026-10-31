<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Service\Thesaurus\StringNormalizer;

/**
 * Pontuação das áreas CAPES de uma família de periódicos (A6.4 e A6.6 de `docs/evolutions/periodicos-por-base.md`).
 *
 * Função pura: recebe as evidências já lidas (as de todas as linhas da família) e devolve a decisão, sem acesso a
 * banco. Quem lê e grava é o {@see JournalAreaClassifier}.
 *
 * Cálculo:
 *  1. Deduplica por (`source`, `record_key`, `label`): a mesma evidência replicada nas linhas da família conta uma vez.
 *  2. Cada fonte tem um peso `w` (tabela de A6.4). Os rótulos viram slugs pelo {@see AreaCrosswalk} (um rótulo pode dar
 *     um ou dois slugs, com o peso dividido; `null` no crosswalk ignora o rótulo). `f[a]` é a soma dos pesos dos
 *     rótulos que caem em `a` dividida pela soma dos pesos dos rótulos mapeados da fonte, portanto soma 1.
 *     `share[a] = Σ w·f[a] / Σ w`; fonte sem rótulo mapeado não entra em nenhuma das somas.
 *  3. Decisão em sete passos (principal, secundárias, confiança), com os limiares em constantes abaixo.
 *
 * Decisões que A6.4 deixa em aberto:
 *  - uma fonte com vários registros na família (dois registros Scopus, por exemplo) é uma só fonte: os rótulos de todos
 *    os registros entram juntos em `f` e `w` conta uma vez. No OpenAlex o `works` usado em `w` é o maior entre os
 *    registros; o corte `share < 0,10` já vem da carga e é repetido aqui, e a renormalização é a própria soma 1 de `f`;
 *  - `ai` não é uma fonte única: cada linha de evidência `ai` é um rótulo de peso próprio, `0,9 × confidence` para o
 *    papel `primary` e `0,45 × confidence` para `secondary`, inteiramente no slug da linha. Assim uma principal com
 *    duas secundárias dá exatamente 0,50, 0,25 e 0,25 e as secundárias chegam ao limiar de 0,25. Linha sem
 *    `payload.confidence` válido é ignorada; sem `payload.role`, vale `primary` se `weight >= 0,75`;
 *  - o par `1710` (Information Systems) + `3309` (Library and Information Sciences) de A6.6 vale para a família inteira:
 *    se qualquer rótulo ASJC das fontes `scopus`/`openalex` é `1710` e outro é `3309`, o `1710` vai para
 *    `comunicacao-informacao-museologia` em vez de `computacao`;
 *  - o passo 4 (espalhamento) fixa a confiança em `medium` (interdisciplinar) ou `low` (maior bucket) e o passo 6 não a
 *    recalcula; os dois casos de fonte única (só `title_rule`, só `ai`) valem também para o passo 4. O `share` da
 *    principal `interdisciplinar` por espalhamento é o do bucket (0 se nenhuma fonte a citou);
 *  - em A6.4 a confiança olha o `share` do bucket da principal; quando a principal é um filho escolhido pela regra do
 *    passo 3 (share entre 0,20 e 0,34), isso resulta sempre em `low`;
 *  - nível 2 preferido nas secundárias: os candidatos de nível 2 vêm antes dos de nível 1, e cada grupo segue
 *    `share` decrescente e depois o slug em ordem alfabética. Empate de `share` é empate com tolerância de 1e-9.
 *
 * Sem nenhum rótulo mapeado devolve `primary` nulo e `confidence` `none`: o fallback PubMed e a ausência de área são
 * decisões do classificador.
 */
final class JournalAreaScorer
{
    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';
    /** Sem evidência mapeada: não há principal. */
    public const CONFIDENCE_NONE = 'none';

    /** `share` mínimo da principal (passos 2 e 3) e da confiança `medium`. */
    private const MIN_PRIMARY = 0.34;
    /** `share` mínimo de um filho da grande área vencedora para virar principal (passo 3). */
    private const MIN_CHILD = 0.20;
    /** `share` mínimo dos buckets que contam para o espalhamento (passo 4). */
    private const MIN_SPREAD = 0.15;
    /** `share` mínimo de uma secundária (passo 5). */
    private const MIN_SECONDARY = 0.25;
    private const MAX_SECONDARY = 2;
    /** Confiança `high`: `share` e soma dos pesos mínimos. */
    private const HIGH_SHARE = 0.50;
    private const HIGH_WEIGHT = 1.0;
    /** Confiança `medium`: soma dos pesos mínima. */
    private const MEDIUM_WEIGHT = 0.5;
    /** Só rótulos do OpenAlex com participação a partir daqui (a carga já filtra; repetido por segurança). */
    private const OPENALEX_MIN_SHARE = 0.10;
    private const OPENALEX_WORKS_FOR_FULL_WEIGHT = 3.0;
    private const AI_PRIMARY_FACTOR = 0.9;
    private const AI_SECONDARY_FACTOR = 0.45;
    /** Só `ai` com `confidence` a partir daqui chega a `medium`. */
    private const AI_MEDIUM_CONFIDENCE = 0.8;
    private const EPSILON = 1e-9;

    private const SPREAD_SLUG = 'interdisciplinar';

    /** Par de A6.6: com os dois códigos ASJC presentes, o primeiro é reatribuído ao slug abaixo. */
    private const PAIR_REASSIGNED_CODE = '1710';
    private const PAIR_TRIGGER_CODE = '3309';
    private const PAIR_SLUG = 'comunicacao-informacao-museologia';

    /** Fontes cujos rótulos passam por um crosswalk: fonte de evidência => fonte do {@see AreaCrosswalk}. */
    private const CROSSWALK_SOURCE = [
        'wos' => 'wos',
        'scielo_wos' => 'wos',
        'scopus' => 'asjc',
        'openalex' => 'asjc',
        'doaj' => 'doaj',
        'latindex' => 'latindex',
        'scielo_area' => 'scielo_area',
        'scielo_desc' => 'scielo_desc',
    ];

    /** Peso `w` das fontes de peso fixo (A6.4); OpenAlex e `ai` têm cálculo próprio. */
    private const FIXED_WEIGHT = [
        'wos' => 1.0,
        'scopus' => 1.0,
        'scielo_wos' => 1.0,
        'scielo_desc' => 0.9,
        'legacy' => 0.8,
        'doaj' => 0.8,
        'latindex' => 0.8,
        'scielo_area' => 0.5,
        'title_rule' => 0.5,
    ];

    /** Fontes que, sozinhas, bastam para a confiança `high`. */
    private const STRONG_SOURCES = ['wos', 'scopus', 'scielo_wos', 'legacy'];

    /** @var array<string, array<string, list<string>>> memória dos rótulos já mapeados: fonte do crosswalk => rótulo => slugs */
    private array $labelMemo = [];

    /** @var array<string, string>|null nome da área sem acento e sem caixa => slug */
    private ?array $namesToSlug = null;

    private readonly CapesTaxonomy $taxonomy;

    private readonly AreaCrosswalk $crosswalk;

    /**
     * Pode ser instanciado direto (`new JournalAreaScorer()`, com os arquivos de `config/journal_areas`) ou injetado
     * pelo container, que passa os serviços. Sem o tesauro de categorias, a evidência `legacy` só reconhece o nome de uma
     * área CAPES.
     */
    public function __construct(
        ?AreaCrosswalk $crosswalk = null,
        ?CapesTaxonomy $taxonomy = null,
        private readonly ?JournalAreaThesaurus $thesaurus = null,
    ) {
        $this->taxonomy = $taxonomy ?? new CapesTaxonomy();
        $this->crosswalk = $crosswalk ?? new AreaCrosswalk(null, $this->taxonomy);
    }

    /**
     * @param list<array{source: string, record_key?: string, label: string|int, weight?: float|int|string, payload?: array<string, mixed>|null}> $evidence
     *        evidências da família; `payload.works` (openalex) e `payload.confidence`/`payload.role` (ai) são lidos
     *
     * @return array{
     *     primary: array{slug: string, share: float}|null,
     *     secondary: list<array{slug: string, share: float}>,
     *     confidence: string,
     *     shares: array<string, float>,
     *     total_weight: float,
     *     sources: array<string, float>
     * } `shares` em ordem decrescente de `share` (empate: slug); `sources` traz o peso de cada fonte que contribuiu
     */
    public function score(array $evidence): array
    {
        $bySource = $this->groupBySource($evidence);
        $pair = $this->hasInformationSciencePair($bySource);

        $contributions = [];
        foreach ($bySource as $source => $rows) {
            if ($source === 'ai') {
                foreach ($this->aiContributions($rows) as $contribution) {
                    $contributions[] = $contribution;
                }
                continue;
            }

            $contribution = $this->contributionOf($source, $rows, $pair);
            if ($contribution !== null) {
                $contributions[] = $contribution;
            }
        }

        if ($contributions === []) {
            return self::noResult();
        }

        $totalWeight = 0.0;
        $sources = [];
        $sums = [];
        foreach ($contributions as $contribution) {
            $totalWeight += $contribution['w'];
            $sources[$contribution['source']] = ($sources[$contribution['source']] ?? 0.0) + $contribution['w'];
            foreach ($contribution['f'] as $slug => $fraction) {
                $sums[$slug] = ($sums[$slug] ?? 0.0) + $contribution['w'] * $fraction;
            }
        }
        ksort($sources, SORT_STRING);

        $shares = [];
        foreach ($sums as $slug => $sum) {
            $shares[$slug] = $sum / $totalWeight;
        }
        $ranked = $this->rank(array_keys($shares), $shares);

        [$primary, $fixedConfidence] = $this->choosePrimary($ranked, $shares);
        $secondary = $this->chooseSecondary($ranked, $shares, $primary);
        $confidence = $this->confidenceOf(
            $shares[$primary] ?? 0.0,
            $totalWeight,
            array_keys($sources),
            $fixedConfidence,
            $this->aiConfidence($contributions)
        );

        $rounded = [];
        foreach ($ranked as $slug) {
            $rounded[$slug] = round($shares[$slug], 6);
        }

        return [
            'primary' => ['slug' => $primary, 'share' => round($shares[$primary] ?? 0.0, 6)],
            'secondary' => array_map(
                static fn (string $slug): array => ['slug' => $slug, 'share' => round($shares[$slug], 6)],
                $secondary
            ),
            'confidence' => $confidence,
            'shares' => $rounded,
            'total_weight' => round($totalWeight, 6),
            'sources' => array_map(static fn (float $weight): float => round($weight, 6), $sources),
        ];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Entrada: deduplicação e contribuições de cada fonte
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Deduplica por (fonte, registro, rótulo) e agrupa por fonte; fonte desconhecida (ou rótulo vazio) fica de fora.
     *
     * @param list<array<string, mixed>> $evidence
     *
     * @return array<string, list<array{label: string, weight: float, payload: array<string, mixed>}>>
     */
    private function groupBySource(array $evidence): array
    {
        $seen = [];
        $bySource = [];
        foreach ($evidence as $row) {
            $source = (string) ($row['source'] ?? '');
            if (!isset(self::FIXED_WEIGHT[$source]) && $source !== 'openalex' && $source !== 'ai') {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $key = $source . "\0" . (string) ($row['record_key'] ?? '') . "\0" . $label;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $payload = $row['payload'] ?? null;
            $bySource[$source][] = [
                'label' => $label,
                'weight' => (float) ($row['weight'] ?? 0.0),
                'payload' => is_array($payload) ? $payload : [],
            ];
        }

        return $bySource;
    }

    /**
     * Contribuição de uma fonte de rótulos (tudo menos `ai`): peso `w` e fração `f` de cada slug.
     *
     * @param list<array{label: string, weight: float, payload: array<string, mixed>}> $rows
     *
     * @return array{source: string, w: float, f: array<string, float>}|null null se nenhum rótulo mapeia ou se `w` é zero
     */
    private function contributionOf(string $source, array $rows, bool $pair): ?array
    {
        $weights = [];
        $mapped = 0.0;
        foreach ($this->usableRows($source, $rows) as $row) {
            $slugs = $this->slugsOf($source, $row['label'], $pair);
            if ($slugs === []) {
                continue;
            }

            $part = $row['weight'] / count($slugs);
            foreach ($slugs as $slug) {
                $weights[$slug] = ($weights[$slug] ?? 0.0) + $part;
            }
            $mapped += $row['weight'];
        }

        if ($mapped <= 0.0) {
            return null;
        }

        $w = $source === 'openalex' ? $this->openAlexWeight($rows) : self::FIXED_WEIGHT[$source];
        if ($w <= 0.0) {
            return null;
        }

        $fractions = [];
        foreach ($weights as $slug => $weight) {
            $fractions[$slug] = $weight / $mapped;
        }

        return ['source' => $source, 'w' => $w, 'f' => $fractions];
    }

    /**
     * Linhas com peso positivo; no OpenAlex, só as com participação de pelo menos 0,10.
     *
     * @param list<array{label: string, weight: float, payload: array<string, mixed>}> $rows
     *
     * @return list<array{label: string, weight: float, payload: array<string, mixed>}>
     */
    private function usableRows(string $source, array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['weight'] > 0.0
                && ($source !== 'openalex' || $row['weight'] + self::EPSILON >= self::OPENALEX_MIN_SHARE)
        ));
    }

    /**
     * `w` do OpenAlex: `min(1, log10(max(works, 1)) / 3)`, com o maior `works` entre os registros da família
     * (1.000 works dão 1,0; 100, 0,67; 10, 0,33; 1, 0).
     *
     * @param list<array{label: string, weight: float, payload: array<string, mixed>}> $rows
     */
    private function openAlexWeight(array $rows): float
    {
        $works = 1;
        foreach ($this->usableRows('openalex', $rows) as $row) {
            $works = max($works, (int) ($row['payload']['works'] ?? 0));
        }

        return min(1.0, log10($works) / self::OPENALEX_WORKS_FOR_FULL_WEIGHT);
    }

    /**
     * Cada linha `ai` é um rótulo de peso próprio, inteiro no seu slug (ver a descrição da classe).
     *
     * @param list<array{label: string, weight: float, payload: array<string, mixed>}> $rows
     *
     * @return list<array{source: string, w: float, f: array<string, float>, primary: bool, confidence: float}>
     */
    private function aiContributions(array $rows): array
    {
        $contributions = [];
        foreach ($rows as $row) {
            if (!$this->taxonomy->isValidSlug($row['label'])) {
                continue;
            }

            $confidence = $row['payload']['confidence'] ?? null;
            if (!is_numeric($confidence) || (float) $confidence <= 0.0) {
                continue;
            }
            $confidence = min(1.0, (float) $confidence);

            $role = $row['payload']['role'] ?? null;
            $primary = is_string($role) && in_array($role, ['primary', 'secondary'], true)
                ? $role === 'primary'
                : $row['weight'] >= 0.75;

            $contributions[] = [
                'source' => 'ai',
                'w' => ($primary ? self::AI_PRIMARY_FACTOR : self::AI_SECONDARY_FACTOR) * $confidence,
                'f' => [$row['label'] => 1.0],
                'primary' => $primary,
                'confidence' => $confidence,
            ];
        }

        return $contributions;
    }

    /**
     * Confiança da IA para a regra "só `ai`": a maior entre as linhas principais, ou entre todas se nenhuma é principal.
     *
     * @param list<array<string, mixed>> $contributions
     */
    private function aiConfidence(array $contributions): float
    {
        $primary = 0.0;
        $any = 0.0;
        foreach ($contributions as $contribution) {
            if ($contribution['source'] !== 'ai') {
                continue;
            }
            $any = max($any, (float) $contribution['confidence']);
            if ($contribution['primary']) {
                $primary = max($primary, (float) $contribution['confidence']);
            }
        }

        return $primary > 0.0 ? $primary : $any;
    }

    /**
     * Regra do par de A6.6: a família tem `1710` e `3309` em rótulos ASJC de uma fonte que contribui.
     *
     * @param array<string, list<array{label: string, weight: float, payload: array<string, mixed>}>> $bySource
     */
    private function hasInformationSciencePair(array $bySource): bool
    {
        $codes = [];
        foreach (['scopus', 'openalex'] as $source) {
            $rows = $bySource[$source] ?? [];
            if ($rows === [] || ($source === 'openalex' && $this->openAlexWeight($rows) <= 0.0)) {
                continue;
            }
            foreach ($this->usableRows($source, $rows) as $row) {
                $codes[$row['label']] = true;
            }
        }

        return isset($codes[self::PAIR_REASSIGNED_CODE], $codes[self::PAIR_TRIGGER_CODE]);
    }

    /**
     * Slugs de um rótulo; vazio se ele não mapeia para área nenhuma.
     *
     * @return list<string>
     */
    private function slugsOf(string $source, string $label, bool $pair): array
    {
        if ($source === 'legacy') {
            $slug = $this->legacySlug($label);

            return $slug === null ? [] : [$slug];
        }

        if ($source === 'title_rule') {
            return $this->taxonomy->isValidSlug($label) ? [$label] : [];
        }

        $crosswalk = self::CROSSWALK_SOURCE[$source];
        if ($pair && $crosswalk === 'asjc' && $label === self::PAIR_REASSIGNED_CODE) {
            return [self::PAIR_SLUG];
        }

        if (!isset($this->labelMemo[$crosswalk][$label])) {
            $slugs = [];
            foreach ($this->crosswalk->map($crosswalk, $label) as $slug) {
                if ($this->taxonomy->isValidSlug($slug) && !in_array($slug, $slugs, true)) {
                    $slugs[] = $slug;
                }
            }
            $this->labelMemo[$crosswalk][$label] = $slugs;
        }

        return $this->labelMemo[$crosswalk][$label];
    }

    /**
     * `legacy` traz o texto de `area` original que o {@see JournalEvidenceLoader} reconheceu: o nome de uma área ou grande
     * área CAPES, que aqui se compara com a taxonomia sem acento e sem caixa, ou, havendo o tesauro de categorias, um
     * rótulo que o `capes`, o `cnpq` ou uma linha manual dão a exatamente uma área (`Lingüística`, um sinônimo corrigido
     * no admin). O texto já passou pela regra de grafia do carregador; aqui só se descobre o slug.
     */
    private function legacySlug(string $label): ?string
    {
        if ($this->namesToSlug === null) {
            $this->namesToSlug = [];
            foreach ($this->taxonomy->all() as $area) {
                $this->namesToSlug[StringNormalizer::normalizeString($area['name'], false)] = $area['slug'];
            }
        }

        $slug = $this->namesToSlug[StringNormalizer::normalizeString($label, false)] ?? null;
        if ($slug !== null || $this->thesaurus === null) {
            return $slug;
        }

        $slugs = $this->thesaurus->lookup($label, true);

        return $slugs !== null && count($slugs) === 1 ? $slugs[0] : null;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Decisão (A6.4, passos 1 a 6)
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Passos 1 a 4.
     *
     * @param list<string>          $ranked slugs por `share` decrescente
     * @param array<string, float>  $shares
     *
     * @return array{0: string, 1: string|null} principal e, no espalhamento, a confiança já decidida
     */
    private function choosePrimary(array $ranked, array $shares): array
    {
        $level2 = [];
        $level1 = [];
        foreach ($ranked as $slug) {
            if ($this->taxonomy->levelOf($slug) === CapesTaxonomy::LEVEL_AREA) {
                $level2[] = $slug;
            } else {
                $level1[] = $slug;
            }
        }
        $top2 = $level2[0] ?? null;
        $top1 = $level1[0] ?? null;

        // Passo 2
        if ($top2 !== null && $this->atLeast($shares[$top2], self::MIN_PRIMARY)) {
            return [$top2, null];
        }

        // Passo 3: grande área com evidência suficiente; um filho com pelo menos 0,20 vira a principal
        if ($top1 !== null && $this->atLeast($shares[$top1], self::MIN_PRIMARY)) {
            foreach ($level2 as $slug) {
                if ($this->taxonomy->parentOf($slug) === $top1 && $this->atLeast($shares[$slug], self::MIN_CHILD)) {
                    return [$slug, null];
                }
            }

            return [$top1, null];
        }

        // Passo 4: espalhamento
        $grandes = [];
        foreach ($ranked as $slug) {
            if (!$this->atLeast($shares[$slug], self::MIN_SPREAD)) {
                break;
            }
            $grandes[$this->taxonomy->parentOf($slug) ?? $slug] = true;
        }

        if (count($grandes) >= 2 && $this->taxonomy->isValidSlug(self::SPREAD_SLUG)) {
            return [self::SPREAD_SLUG, self::CONFIDENCE_MEDIUM];
        }

        return [$ranked[0], self::CONFIDENCE_LOW];
    }

    /**
     * Passo 5: até duas outras áreas com `share >= 0,25`, nível 2 antes de nível 1; uma grande área só entra se
     * nenhum filho dela estiver entre os buckets (a principal conta como presente).
     *
     * @param list<string>         $ranked
     * @param array<string, float> $shares
     *
     * @return list<string>
     */
    private function chooseSecondary(array $ranked, array $shares, string $primary): array
    {
        $present = $shares;
        $present[$primary] = 0.0;

        $level2 = [];
        $level1 = [];
        foreach ($ranked as $slug) {
            if ($slug === $primary || !$this->atLeast($shares[$slug], self::MIN_SECONDARY)) {
                continue;
            }

            if ($this->taxonomy->levelOf($slug) === CapesTaxonomy::LEVEL_AREA) {
                $level2[] = $slug;
                continue;
            }

            foreach ($this->taxonomy->childrenOf($slug) as $child) {
                if (isset($present[$child['slug']])) {
                    continue 2;
                }
            }
            $level1[] = $slug;
        }

        return array_slice(array_merge($level2, $level1), 0, self::MAX_SECONDARY);
    }

    /**
     * Passo 6.
     *
     * @param list<string> $sources fontes que contribuíram
     */
    private function confidenceOf(float $share, float $totalWeight, array $sources, ?string $fixed, float $aiConfidence): string
    {
        if ($fixed !== null) {
            $confidence = $fixed;
        } else {
            $strong = count($sources) >= 2 || in_array($sources[0], self::STRONG_SOURCES, true);
            if ($this->atLeast($share, self::HIGH_SHARE) && $this->atLeast($totalWeight, self::HIGH_WEIGHT) && $strong) {
                $confidence = self::CONFIDENCE_HIGH;
            } elseif ($this->atLeast($share, self::MIN_PRIMARY) && $this->atLeast($totalWeight, self::MEDIUM_WEIGHT)) {
                $confidence = self::CONFIDENCE_MEDIUM;
            } else {
                $confidence = self::CONFIDENCE_LOW;
            }
        }

        if ($sources === ['title_rule']) {
            return self::CONFIDENCE_LOW;
        }

        if ($sources === ['ai']) {
            return $this->atLeast($aiConfidence, self::AI_MEDIUM_CONFIDENCE) && $confidence !== self::CONFIDENCE_LOW
                ? self::CONFIDENCE_MEDIUM
                : self::CONFIDENCE_LOW;
        }

        return $confidence;
    }

    /**
     * Ordena por `share` decrescente e, nos empates, pelo slug em ordem alfabética.
     *
     * @param list<string>         $slugs
     * @param array<string, float> $shares
     *
     * @return list<string>
     */
    private function rank(array $slugs, array $shares): array
    {
        usort($slugs, static function (string $a, string $b) use ($shares): int {
            if (abs($shares[$a] - $shares[$b]) > self::EPSILON) {
                return $shares[$b] <=> $shares[$a];
            }

            return strcmp($a, $b);
        });

        return $slugs;
    }

    /**
     * `>=` com a tolerância de ponto flutuante (0,25 chega como 0,2499999999).
     */
    private function atLeast(float $value, float $threshold): bool
    {
        return $value + self::EPSILON >= $threshold;
    }

    /**
     * @return array{primary: null, secondary: list<never>, confidence: string, shares: array<never>, total_weight: float, sources: array<never>}
     */
    private static function noResult(): array
    {
        return [
            'primary' => null,
            'secondary' => [],
            'confidence' => self::CONFIDENCE_NONE,
            'shares' => [],
            'total_weight' => 0.0,
            'sources' => [],
        ];
    }
}
