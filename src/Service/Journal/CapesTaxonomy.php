<?php

declare(strict_types=1);

namespace App\Service\Journal;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Taxonomia de áreas CAPES (grandes áreas e áreas de avaliação) usada na classificação dos periódicos.
 *
 * Serviço sem estado mutável: lê `config/journal_areas/taxonomy.json` uma única vez (carga
 * preguiçosa, na primeira consulta) e responde a partir de índices em memória. O mesmo conteúdo é
 * semeado na tabela `capes_areas` pela migration, com os mesmos ids; `CapesTaxonomyTest` garante
 * a igualdade entre o JSON e a tabela.
 *
 * Pode ser instanciado direto (`new CapesTaxonomy()`, usa o JSON do projeto) ou injetado pelo container.
 *
 * @phpstan-type Area array{id: int, parent: int|null, level: int, slug: string, name: string, college: string}
 */
class CapesTaxonomy
{
    /** Grande área (ex.: Ciências Humanas). */
    public const LEVEL_GRANDE_AREA = 1;

    /** Área de avaliação (ex.: Educação), filha de uma grande área. */
    public const LEVEL_AREA = 2;

    /** Separador entre a área e a sua grande área no rótulo canônico. */
    public const LABEL_SEPARATOR = ' / ';

    /** @var list<Area>|null */
    private ?array $areas = null;

    /** @var array<string, Area> */
    private array $bySlug = [];

    /** @var array<int, Area> */
    private array $byId = [];

    /** @var array<int, list<Area>> áreas filhas, indexadas pelo id da grande área */
    private array $childrenByParentId = [];

    /** @var array<string, true> rótulos canônicos de todas as áreas */
    private array $canonicalLabels = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/journal_areas/taxonomy.json')]
        private readonly ?string $path = null,
    ) {
    }

    /**
     * Todas as áreas, na ordem do arquivo (grande área seguida das suas áreas de avaliação).
     *
     * @return list<Area>
     */
    public function all(): array
    {
        $this->load();

        return $this->areas;
    }

    /**
     * @return Area|null
     */
    public function bySlug(string $slug): ?array
    {
        $this->load();

        return $this->bySlug[$slug] ?? null;
    }

    /**
     * @return Area|null
     */
    public function byId(int $id): ?array
    {
        $this->load();

        return $this->byId[$id] ?? null;
    }

    public function isValidSlug(string $slug): bool
    {
        $this->load();

        return isset($this->bySlug[$slug]);
    }

    /**
     * Slug da grande área à qual a área pertence; null para uma grande área ou para um slug desconhecido
     * (use isValidSlug() quando precisar distinguir os dois casos).
     */
    public function parentOf(string $slug): ?string
    {
        $this->load();

        $area = $this->bySlug[$slug] ?? null;
        if ($area === null || $area['parent'] === null) {
            return null;
        }

        return $this->byId[$area['parent']]['slug'];
    }

    /**
     * Nível da área: 1 (grande área) ou 2 (área de avaliação).
     *
     * @throws \InvalidArgumentException se o slug não existir
     */
    public function levelOf(string $slug): int
    {
        return $this->requireBySlug($slug)['level'];
    }

    /**
     * Áreas de avaliação de uma grande área, na ordem do arquivo. Vazio para uma área de nível 2 ou
     * para um slug desconhecido.
     *
     * @return list<Area>
     */
    public function childrenOf(string $slug): array
    {
        $this->load();

        $area = $this->bySlug[$slug] ?? null;

        return $area === null ? [] : ($this->childrenByParentId[$area['id']] ?? []);
    }

    /**
     * Grandes áreas com as suas áreas de avaliação, para montar selects e listas de checkboxes.
     * A chave é o slug da grande área e a ordem é a do arquivo.
     *
     * @return array<string, array{area: Area, children: list<Area>}>
     */
    public function grouped(): array
    {
        $this->load();

        $groups = [];
        foreach ($this->areas as $area) {
            if ($area['level'] !== self::LEVEL_GRANDE_AREA) {
                continue;
            }

            $groups[$area['slug']] = [
                'area' => $area,
                'children' => $this->childrenByParentId[$area['id']] ?? [],
            ];
        }

        return $groups;
    }

    /**
     * Rótulo canônico gravado em qualis_journals.area: nome da área e da sua grande área, em maiúsculas
     * (`EDUCAÇÃO / CIÊNCIAS HUMANAS`); uma grande área sozinha tem só o próprio nome (`CIÊNCIAS HUMANAS`).
     *
     * @throws \InvalidArgumentException se o slug não existir
     */
    public function areaLabel(string $slug): string
    {
        return $this->labelOf($this->requireBySlug($slug));
    }

    /**
     * Diz se o texto é exatamente (ignorando espaços nas pontas) um dos rótulos gerados por areaLabel().
     * Usado para não confundir um rótulo já canônico com o texto original do campo `area`.
     */
    public function isCanonicalLabel(string $text): bool
    {
        $this->load();

        return isset($this->canonicalLabels[trim($text)]);
    }

    /**
     * @param Area $area
     */
    private function labelOf(array $area): string
    {
        $label = mb_strtoupper($area['name'], 'UTF-8');

        if ($area['parent'] !== null) {
            $label .= self::LABEL_SEPARATOR . mb_strtoupper($this->byId[$area['parent']]['name'], 'UTF-8');
        }

        return $label;
    }

    /**
     * @return Area
     *
     * @throws \InvalidArgumentException se o slug não existir
     */
    private function requireBySlug(string $slug): array
    {
        $this->load();

        return $this->bySlug[$slug]
            ?? throw new \InvalidArgumentException(sprintf('Slug de área CAPES desconhecido: "%s".', $slug));
    }

    private function load(): void
    {
        if ($this->areas !== null) {
            return;
        }

        $path = $this->path ?? dirname(__DIR__, 3) . '/config/journal_areas/taxonomy.json';

        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new \RuntimeException(sprintf('Não foi possível ler a taxonomia CAPES em "%s".', $path));
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('JSON inválido na taxonomia CAPES "%s": %s', $path, $e->getMessage()), 0, $e);
        }

        if (!is_array($data) || !isset($data['areas']) || !is_array($data['areas'])) {
            throw new \RuntimeException(sprintf('A taxonomia CAPES "%s" deve ter a chave "areas" com a lista de áreas.', $path));
        }

        $areas = [];
        $bySlug = [];
        $byId = [];

        foreach ($data['areas'] as $position => $raw) {
            $area = $this->validateArea($raw, $position, $path);

            if (isset($bySlug[$area['slug']]) || isset($byId[$area['id']])) {
                throw new \RuntimeException(sprintf(
                    'Taxonomia CAPES "%s": id ou slug repetido na posição %s (id %d, slug "%s").',
                    $path,
                    (string) $position,
                    $area['id'],
                    $area['slug']
                ));
            }

            $areas[] = $area;
            $bySlug[$area['slug']] = $area;
            $byId[$area['id']] = $area;
        }

        $childrenByParentId = [];
        foreach ($areas as $area) {
            if ($area['parent'] === null) {
                continue;
            }

            if (!isset($byId[$area['parent']])) {
                throw new \RuntimeException(sprintf(
                    'Taxonomia CAPES "%s": a área "%s" aponta para o pai inexistente %d.',
                    $path,
                    $area['slug'],
                    $area['parent']
                ));
            }

            $childrenByParentId[$area['parent']][] = $area;
        }

        // Só publica o estado depois de validar tudo: uma falha deixa o serviço sem carga parcial.
        $this->bySlug = $bySlug;
        $this->byId = $byId;
        $this->childrenByParentId = $childrenByParentId;
        $this->canonicalLabels = [];
        foreach ($areas as $area) {
            $this->canonicalLabels[$this->labelOf($area)] = true;
        }
        $this->areas = $areas;
    }

    /**
     * @return Area
     */
    private function validateArea(mixed $raw, int|string $position, string $path): array
    {
        $valid = is_array($raw)
            && isset($raw['id']) && is_int($raw['id'])
            && array_key_exists('parent', $raw) && ($raw['parent'] === null || is_int($raw['parent']))
            && isset($raw['level']) && in_array($raw['level'], [self::LEVEL_GRANDE_AREA, self::LEVEL_AREA], true)
            && isset($raw['slug']) && is_string($raw['slug']) && $raw['slug'] !== ''
            && isset($raw['name']) && is_string($raw['name']) && $raw['name'] !== ''
            && isset($raw['college']) && is_string($raw['college']) && $raw['college'] !== '';

        if (!$valid) {
            throw new \RuntimeException(sprintf(
                'Taxonomia CAPES "%s": item inválido na posição %s (esperado id, parent, level, slug, name e college).',
                $path,
                (string) $position
            ));
        }

        return [
            'id' => $raw['id'],
            'parent' => $raw['parent'],
            'level' => $raw['level'],
            'slug' => $raw['slug'],
            'name' => $raw['name'],
            'college' => $raw['college'],
        ];
    }
}
