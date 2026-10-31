<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Crosswalks dos rótulos externos (ASJC, WoS, DOAJ, Latindex, SciELO e as áreas do CNPq) para os slugs da taxonomia
 * CAPES, e as regras de título.
 *
 * Na primeira consulta (carga preguiçosa) lê `config/journal_areas/crosswalk_<fonte>.json`, no formato
 * `{"_meta": {...}, "map": {"<rótulo>": "slug" | ["slug", ...] | null}}`, e `crosswalk_title_rules.json`,
 * no formato `{"rules": [{"pattern": "<regex>", "slug": "<slug>"}]}`. Arquivo ausente é tolerado: a fonte
 * responde sem mapeamento. Um arquivo `crosswalk_*.json` de fonte não aceita é erro. Todo slug é validado
 * contra CapesTaxonomy ao carregar; uma falha não deixa carga parcial.
 *
 * Rótulos são comparados sem diferença de caixa e com espaços repetidos colapsados (no arquivo e na
 * consulta). Um valor null é decisão explícita (rótulo sem área temática): map() responde [] e isKnown()
 * responde true.
 *
 * Correções manuais: com uma conexão, a carga aplica por cima dos arquivos as linhas `origin = 'manual'` do tesauro de
 * categorias (tabela `capes_area_variants`, ver {@see JournalAreaThesaurus}) das fontes aceitas. Uma linha `active`
 * substitui o mapa do rótulo pelos slugs das linhas dele; uma `ignored` o transforma em decisão explícita sem área
 * (`[]`, e isKnown() responde true). O tesauro compara pela forma dele (sem acento nem pontuação), este serviço pela
 * caixa e pelos espaços: a correção vale para o rótulo (`variation_name`) e para todo rótulo do arquivo da mesma fonte
 * que tenha a mesma forma do tesauro (como `HISTÓRIA` e `HISTORIA`). Sem conexão, ou com a tabela ainda inexistente, só
 * os arquivos respondem.
 */
class AreaCrosswalk
{
    /** Fontes aceitas. scopus e openalex usam 'asjc'; scielo_wos usa 'wos'; 'cnpq' são as áreas do CNPq do Lattes. */
    public const SOURCES = ['asjc', 'wos', 'doaj', 'latindex', 'scielo_area', 'scielo_desc', 'cnpq'];

    private const FILE_PREFIX = 'crosswalk_';
    private const FILE_SUFFIX = '.json';
    private const TITLE_RULES_NAME = 'title_rules';

    /** @var array<string, array<string, list<string>>>|null fonte => rótulo normalizado => slugs */
    private ?array $index = null;

    /** @var array<string, list<string>> fonte => rótulos como estão no arquivo, na ordem dele (e, no fim, os só manuais) */
    private array $labels = [];

    /** @var array<string, list<array{label: string, slugs: list<string>|null}>> fonte => o que o arquivo traz, sem as correções manuais */
    private array $fileEntries = [];

    /** @var list<array{pattern: string, slug: string}> */
    private array $rules = [];

    private readonly string $directory;

    private readonly CapesTaxonomy $taxonomy;

    /**
     * @param Connection|null $conn conexão para ler as correções manuais do tesauro; sem ela só os arquivos respondem
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/journal_areas')]
        ?string $directory = null,
        ?CapesTaxonomy $taxonomy = null,
        private readonly ?Connection $conn = null,
    ) {
        $this->directory = $directory ?? dirname(__DIR__, 3) . '/config/journal_areas';
        $this->taxonomy = $taxonomy ?? new CapesTaxonomy();
    }

    /**
     * Slugs CAPES de um rótulo externo, na ordem do arquivo. Vazio para rótulo nulo, desconhecido ou
     * fonte sem arquivo.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException se a fonte não for aceita
     */
    public function map(string $source, string $label): array
    {
        self::requireSource($source);
        $this->load();

        return $this->index[$source][self::normalizeLabel($label)] ?? [];
    }

    /**
     * Diz se o rótulo existe no crosswalk da fonte, mesmo que o valor seja null.
     *
     * @throws \InvalidArgumentException se a fonte não for aceita
     */
    public function isKnown(string $source, string $label): bool
    {
        self::requireSource($source);
        $this->load();

        return array_key_exists(self::normalizeLabel($label), $this->index[$source]);
    }

    /**
     * Rótulos da fonte, como estão no arquivo e na ordem dele. Vazio se a fonte não tem arquivo.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException se a fonte não for aceita
     */
    public function knownLabels(string $source): array
    {
        self::requireSource($source);
        $this->load();

        return $this->labels[$source];
    }

    /**
     * O que o arquivo da fonte traz, na ordem dele e sem as correções manuais: o rótulo como está no arquivo e os seus
     * slugs (`null` é a decisão explícita de rótulo sem área). É o que o tesauro de categorias semeia. Lista de pares,
     * e não um mapa rótulo => slugs, porque o PHP transformaria o rótulo `1702` em chave inteira.
     *
     * @return list<array{label: string, slugs: list<string>|null}>
     *
     * @throws \InvalidArgumentException se a fonte não for aceita
     */
    public function fileEntries(string $source): array
    {
        self::requireSource($source);
        $this->load();

        return $this->fileEntries[$source];
    }

    /**
     * Regras de título, na ordem do arquivo.
     *
     * @return list<array{pattern: string, slug: string}>
     */
    public function titleRules(): array
    {
        $this->load();

        return $this->rules;
    }

    /**
     * Slugs de todas as regras que casam com o título. O título deve estar na forma do resolver
     * (minúsculas, sem acento). Os slugs vêm sem repetição, na ordem das regras.
     *
     * @return list<string>
     */
    public function matchTitle(string $normalizedTitle): array
    {
        $this->load();

        $slugs = [];
        foreach ($this->rules as $rule) {
            if (preg_match(self::titleRegex($rule['pattern']), $normalizedTitle) === 1) {
                $slugs[] = $rule['slug'];
            }
        }

        return array_values(array_unique($slugs));
    }

    private function load(): void
    {
        if ($this->index !== null) {
            return;
        }

        $index = array_fill_keys(self::SOURCES, []);
        $labels = array_fill_keys(self::SOURCES, []);
        $entries = array_fill_keys(self::SOURCES, []);
        $rules = [];

        $files = glob($this->directory . '/' . self::FILE_PREFIX . '*' . self::FILE_SUFFIX) ?: [];
        foreach ($files as $path) {
            $name = substr(basename($path), strlen(self::FILE_PREFIX), -strlen(self::FILE_SUFFIX));

            if ($name === self::TITLE_RULES_NAME) {
                $rules = $this->loadTitleRules($path);
                continue;
            }

            if (!in_array($name, self::SOURCES, true)) {
                throw new \RuntimeException(sprintf('Crosswalk "%s": a fonte "%s" não é aceita.', $path, $name));
            }

            [$index[$name], $labels[$name], $entries[$name]] = $this->loadCrosswalk($name, $path);
        }

        $this->applyManualCorrections($index, $labels);

        // Só publica o estado depois de validar tudo: uma falha não deixa carga parcial.
        $this->index = $index;
        $this->labels = $labels;
        $this->fileEntries = $entries;
        $this->rules = $rules;
    }

    /**
     * Aplica por cima dos arquivos as correções manuais do tesauro (ver a descrição da classe).
     *
     * @param array<string, array<string, list<string>>> $index
     * @param array<string, list<string>>                $labels
     */
    private function applyManualCorrections(array &$index, array &$labels): void
    {
        $corrections = $this->manualCorrections();
        if ($corrections === []) {
            return;
        }

        // Forma do tesauro dos rótulos dos arquivos, só das fontes que têm correção
        $byNormalized = [];
        foreach (array_keys($corrections) as $source) {
            foreach ($labels[$source] as $label) {
                $byNormalized[$source][StringNormalizer::normalizeString($label, true)][] = $label;
            }
        }

        foreach ($corrections as $source => $bySource) {
            foreach ($bySource as $normalized => $correction) {
                $targets = array_merge($correction['labels'], $byNormalized[$source][(string) $normalized] ?? []);
                foreach ($targets as $label) {
                    $key = self::normalizeLabel($label);
                    $index[$source][$key] = $correction['slugs'];
                    if (!in_array($label, $labels[$source], true)) {
                        $labels[$source][] = $label;
                    }
                }
            }
        }
    }

    /**
     * Linhas manuais `active` e `ignored` do tesauro das fontes aceitas, agrupadas por fonte e forma do tesauro: os
     * rótulos (`variation_name`) e os slugs, sem repetição, na ordem das linhas (vazia se só há `ignored`).
     *
     * @return array<string, array<string, array{labels: list<string>, slugs: list<string>}>>
     */
    private function manualCorrections(): array
    {
        if ($this->conn === null) {
            return [];
        }

        try {
            $rows = $this->conn->fetchAllAssociative(
                "SELECT v.vocabulary, v.variation_name, v.normalized_name, v.status, a.slug
                   FROM capes_area_variants v
                   LEFT JOIN capes_areas a ON a.id = v.area_id
                  WHERE v.origin = 'manual' AND v.status IN ('active', 'ignored') AND v.vocabulary IN (?)
                  ORDER BY v.id",
                [self::SOURCES],
                [ArrayParameterType::STRING]
            );
        } catch (TableNotFoundException) {
            // Banco ainda sem a migration do tesauro: respondem só os arquivos
            return [];
        }

        $corrections = [];
        foreach ($rows as $row) {
            $group = &$corrections[(string) $row['vocabulary']][(string) $row['normalized_name']];
            $group ??= ['labels' => [], 'slugs' => []];

            $label = (string) $row['variation_name'];
            if (!in_array($label, $group['labels'], true)) {
                $group['labels'][] = $label;
            }

            $slug = $row['slug'];
            if ($row['status'] === 'active' && is_string($slug) && $this->taxonomy->isValidSlug($slug) && !in_array($slug, $group['slugs'], true)) {
                $group['slugs'][] = $slug;
            }
            unset($group);
        }

        return $corrections;
    }

    /**
     * @return array{0: array<string, list<string>>, 1: list<string>, 2: list<array{label: string, slugs: list<string>|null}>}
     */
    private function loadCrosswalk(string $source, string $path): array
    {
        $data = self::readJson($path);

        $meta = $data['_meta'] ?? [];
        if (!is_array($meta) || (isset($meta['source']) && $meta['source'] !== $source)) {
            throw new \RuntimeException(sprintf('Crosswalk "%s": "_meta.source" deve ser "%s".', $path, $source));
        }

        $map = $data['map'] ?? null;
        if (!is_array($map)) {
            throw new \RuntimeException(sprintf('Crosswalk "%s" deve ter a chave "map" com os rótulos.', $path));
        }

        $index = [];
        $labels = [];
        $entries = [];
        foreach ($map as $rawLabel => $value) {
            $label = (string) $rawLabel;
            $key = self::normalizeLabel($label);
            if (array_key_exists($key, $index)) {
                throw new \RuntimeException(sprintf(
                    'Crosswalk "%s": o rótulo "%s" repete outro depois de normalizado.',
                    $path,
                    $label
                ));
            }

            $index[$key] = $this->slugsOf($value, $label, $path);
            $labels[] = $label;
            $entries[] = ['label' => $label, 'slugs' => $value === null ? null : $index[$key]];
        }

        return [$index, $labels, $entries];
    }

    /**
     * @return list<array{pattern: string, slug: string}>
     *
     * @throws \InvalidArgumentException se o slug de uma regra não existir na taxonomia
     */
    private function loadTitleRules(string $path): array
    {
        $data = self::readJson($path);

        if (!isset($data['rules']) || !is_array($data['rules'])) {
            throw new \RuntimeException(sprintf('"%s" deve ter a chave "rules" com a lista de regras.', $path));
        }

        $rules = [];
        foreach ($data['rules'] as $position => $rule) {
            $pattern = is_array($rule) ? ($rule['pattern'] ?? null) : null;
            $slug = is_array($rule) ? ($rule['slug'] ?? null) : null;
            if (!is_string($pattern) || $pattern === '' || !is_string($slug)) {
                throw new \RuntimeException(sprintf(
                    '"%s": a regra na posição %s deve ter "pattern" e "slug" em texto.',
                    $path,
                    (string) $position
                ));
            }

            if (str_contains($pattern, '~') || @preg_match(self::titleRegex($pattern), '') === false) {
                throw new \RuntimeException(sprintf(
                    '"%s": a regra "%s" não é uma expressão regular válida (sem delimitadores; o serviço usa ~...~u).',
                    $path,
                    $pattern
                ));
            }

            if (!$this->taxonomy->isValidSlug($slug)) {
                throw new \InvalidArgumentException(sprintf(
                    '"%s": a regra "%s" aponta para o slug inválido "%s".',
                    $path,
                    $pattern,
                    $slug
                ));
            }

            $rules[] = ['pattern' => $pattern, 'slug' => $slug];
        }

        return $rules;
    }

    /**
     * @return list<string>
     *
     * @throws \InvalidArgumentException se um slug não existir na taxonomia
     */
    private function slugsOf(mixed $value, string $label, string $path): array
    {
        if ($value === null) {
            return [];
        }

        $slugs = is_string($value) ? [$value] : $value;
        if (!is_array($slugs) || $slugs === [] || !array_is_list($slugs)) {
            throw new \RuntimeException(sprintf(
                'Crosswalk "%s": o rótulo "%s" deve ter um slug, uma lista de slugs ou null.',
                $path,
                $label
            ));
        }

        foreach ($slugs as $slug) {
            if (!is_string($slug) || !$this->taxonomy->isValidSlug($slug)) {
                throw new \InvalidArgumentException(sprintf(
                    'Crosswalk "%s": o rótulo "%s" aponta para um slug inválido (%s).',
                    $path,
                    $label,
                    is_string($slug) ? $slug : get_debug_type($slug)
                ));
            }
        }

        return $slugs;
    }

    /**
     * @return array<mixed>
     */
    private static function readJson(string $path): array
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new \RuntimeException(sprintf('Não foi possível ler o crosswalk "%s".', $path));
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('JSON inválido em "%s": %s', $path, $e->getMessage()), 0, $e);
        }

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('"%s" deve conter um objeto JSON.', $path));
        }

        return $data;
    }

    private static function requireSource(string $source): void
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException(sprintf('Fonte de crosswalk desconhecida: "%s".', $source));
        }
    }

    /**
     * Forma de comparação dos rótulos: minúsculas, espaços repetidos colapsados e aparados.
     */
    private static function normalizeLabel(string $label): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $label) ?? $label;

        return mb_strtolower(trim($collapsed), 'UTF-8');
    }

    private static function titleRegex(string $pattern): string
    {
        return '~' . $pattern . '~u';
    }
}
