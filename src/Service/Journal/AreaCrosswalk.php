<?php

declare(strict_types=1);

namespace App\Service\Journal;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Crosswalks dos rótulos externos (ASJC, WoS, DOAJ, Latindex, SciELO) para os slugs da taxonomia CAPES,
 * e as regras de título.
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
 */
class AreaCrosswalk
{
    /** Fontes aceitas. scopus e openalex usam 'asjc'; scielo_wos usa 'wos'. */
    public const SOURCES = ['asjc', 'wos', 'doaj', 'latindex', 'scielo_area', 'scielo_desc'];

    private const FILE_PREFIX = 'crosswalk_';
    private const FILE_SUFFIX = '.json';
    private const TITLE_RULES_NAME = 'title_rules';

    /** @var array<string, array<string, list<string>>>|null fonte => rótulo normalizado => slugs */
    private ?array $index = null;

    /** @var array<string, list<string>> fonte => rótulos como estão no arquivo, na ordem dele */
    private array $labels = [];

    /** @var list<array{pattern: string, slug: string}> */
    private array $rules = [];

    private readonly string $directory;

    private readonly CapesTaxonomy $taxonomy;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/journal_areas')]
        ?string $directory = null,
        ?CapesTaxonomy $taxonomy = null,
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

            [$index[$name], $labels[$name]] = $this->loadCrosswalk($name, $path);
        }

        // Só publica o estado depois de validar tudo: uma falha não deixa carga parcial.
        $this->index = $index;
        $this->labels = $labels;
        $this->rules = $rules;
    }

    /**
     * @return array{0: array<string, list<string>>, 1: list<string>}
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
        }

        return [$index, $labels];
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
