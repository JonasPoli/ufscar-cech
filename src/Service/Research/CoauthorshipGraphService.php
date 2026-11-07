<?php

declare(strict_types=1);

namespace App\Service\Research;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Rede de coautoria entre docentes do CECH.
 *
 * Uma ligação existe quando dois docentes aparecem como autores casados (`production_authors.matched_researcher_id`)
 * na mesma publicação. O peso é o número de publicações únicas do par ({@see ResearchSql::workKey()}): o mesmo
 * artigo declarado no currículo dos dois conta uma vez. O filtro recorta as publicações (texto, tema, período, tipo)
 * e o docente dono do currículo (departamento, área, vínculo).
 */
final class CoauthorshipGraphService
{
    public const DEFAULT_MAX_EDGES = 120;

    public function __construct(
        private readonly Connection $connection,
        private readonly ExpertSearchService $search,
        #[Autowire(service: 'cache.research')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Todos os pares do recorte, do mais forte ao mais fraco.
     *
     * @return list<array{a: int, b: int, weight: int}>
     */
    public function pairs(ResearchFilter $filter): array
    {
        return $this->cache->get($filter->cacheKey('coauthor_pairs'), function () use ($filter): array {
            $where = ResearchSql::where($filter);
            $workKey = ResearchSql::workKey();
            $rows = $this->connection->fetchAllAssociative(
                "SELECT pa1.matched_researcher_id AS a, pa2.matched_researcher_id AS b, COUNT(DISTINCT {$workKey}) AS weight
                   FROM production_authors pa1
                   JOIN production_authors pa2
                     ON pa2.production_item_id = pa1.production_item_id
                    AND pa1.matched_researcher_id < pa2.matched_researcher_id
                   JOIN production_items pi ON pi.id = pa1.production_item_id
                   JOIN researchers r ON r.id = pi.researcher_id
                  WHERE {$where['sql']}
                  GROUP BY pa1.matched_researcher_id, pa2.matched_researcher_id
                  ORDER BY weight DESC, a, b",
                $where['params'],
                $where['types']
            );

            return array_map(static fn(array $r) => ['a' => (int)$r['a'], 'b' => (int)$r['b'], 'weight' => (int)$r['weight']], $rows);
        });
    }

    /** Publicações únicas assinadas por pelo menos dois docentes do CECH, no recorte. */
    public function sharedWorks(ResearchFilter $filter): int
    {
        return $this->cache->get($filter->cacheKey('coauthor_shared'), function () use ($filter): int {
            $where = ResearchSql::where($filter);
            $workKey = ResearchSql::workKey();

            return (int)$this->connection->fetchOne(
                "SELECT COUNT(DISTINCT {$workKey})
                   FROM production_authors pa1
                   JOIN production_authors pa2
                     ON pa2.production_item_id = pa1.production_item_id
                    AND pa1.matched_researcher_id < pa2.matched_researcher_id
                   JOIN production_items pi ON pi.id = pa1.production_item_id
                   JOIN researchers r ON r.id = pi.researcher_id
                  WHERE {$where['sql']}",
                $where['params'],
                $where['types']
            );
        });
    }

    /**
     * Rede pronta para desenhar. Sem foco, as `$maxEdges` ligações mais fortes (0 = todas); com foco, a vizinhança
     * do docente: as ligações dele e as ligações entre os vizinhos.
     *
     * @return array<string, mixed>
     */
    public function graph(ResearchFilter $filter, ?int $focusId = null, int $maxEdges = self::DEFAULT_MAX_EDGES): array
    {
        $all = $this->pairs($filter);
        $people = [];
        foreach ($all as $pair) {
            $people[$pair['a']] = true;
            $people[$pair['b']] = true;
        }

        if ($focusId !== null) {
            $neighbors = [$focusId => true];
            foreach ($all as $pair) {
                if ($pair['a'] === $focusId) {
                    $neighbors[$pair['b']] = true;
                } elseif ($pair['b'] === $focusId) {
                    $neighbors[$pair['a']] = true;
                }
            }
            $edges = array_values(array_filter($all, static fn($p) => isset($neighbors[$p['a']], $neighbors[$p['b']])));
        } else {
            $edges = $maxEdges > 0 ? array_slice($all, 0, $maxEdges) : $all;
        }

        $nodeIds = [];
        $degree = [];
        $strength = [];
        foreach ($edges as $edge) {
            foreach (['a', 'b'] as $end) {
                $id = $edge[$end];
                $nodeIds[$id] = true;
                $degree[$id] = ($degree[$id] ?? 0) + 1;
                $strength[$id] = ($strength[$id] ?? 0) + $edge['weight'];
            }
        }
        if ($focusId !== null) {
            $nodeIds[$focusId] = true;
        }

        $cards = $this->search->loadResearchers(array_keys($nodeIds));
        $nodes = [];
        $departments = [];
        foreach ($cards as $id => $card) {
            $code = $card['departmentCode'] ?: 'Outros';
            $departments[$code] = $card['department'] ?: $code;
            $nodes[] = [
                'id' => (string)$id,
                'name' => $card['name'],
                'slug' => $card['slug'],
                'department' => $code,
                'affiliation' => $card['affiliation'],
                'degree' => $degree[$id] ?? 0,
                'strength' => $strength[$id] ?? 0,
                'focus' => $id === $focusId,
            ];
        }
        ksort($departments);

        return [
            'nodes' => $nodes,
            'edges' => array_map(static fn($e) => ['source' => (string)$e['a'], 'target' => (string)$e['b'], 'weight' => $e['weight']], $edges),
            'departments' => $departments,
            'totalPeople' => count($people),
            'totalPairs' => count($all),
            'shownPeople' => count($nodes),
            'shownPairs' => count($edges),
            'focus' => $focusId !== null && isset($cards[$focusId]) ? $cards[$focusId] : null,
        ];
    }

    /**
     * Os pares mais fortes, com os dados dos dois docentes.
     *
     * @return list<array{a: array<string, mixed>, b: array<string, mixed>, weight: int}>
     */
    public function topPairs(ResearchFilter $filter, int $limit = 6, ?int $focusId = null): array
    {
        $pairs = $this->pairs($filter);
        if ($focusId !== null) {
            $pairs = array_values(array_filter($pairs, static fn($p) => $p['a'] === $focusId || $p['b'] === $focusId));
        }
        $pairs = array_slice($pairs, 0, $limit);

        $ids = [];
        foreach ($pairs as $p) {
            $ids[] = $p['a'];
            $ids[] = $p['b'];
        }
        $cards = $this->search->loadResearchers($ids);

        $out = [];
        foreach ($pairs as $p) {
            if (isset($cards[$p['a']], $cards[$p['b']])) {
                // Na vizinhança, o docente em foco fica sempre à esquerda
                [$left, $right] = ($focusId !== null && $p['b'] === $focusId) ? [$p['b'], $p['a']] : [$p['a'], $p['b']];
                $out[] = ['a' => $cards[$left], 'b' => $cards[$right], 'weight' => $p['weight']];
            }
        }

        return $out;
    }

    /**
     * Publicações únicas em que os dois docentes aparecem como autores, no recorte.
     *
     * @return array{a: ?array<string, mixed>, b: ?array<string, mixed>, count: int, results: list<array<string, mixed>>}
     */
    public function pairWorks(int $a, int $b, ResearchFilter $filter, int $limit = 200): array
    {
        $where = ResearchSql::where($filter);
        $workKey = ResearchSql::workKey();
        $params = $where['params'] + ['pair_a' => $a, 'pair_b' => $b];

        $rows = $this->connection->fetchAllAssociative(
            "SELECT {$workKey} AS wk, MIN(pi.id) AS rep, MAX(pi.year) AS year
               FROM production_items pi
               JOIN researchers r ON r.id = pi.researcher_id
              WHERE {$where['sql']}
                AND EXISTS (SELECT 1 FROM production_authors x WHERE x.production_item_id = pi.id AND x.matched_researcher_id = :pair_a)
                AND EXISTS (SELECT 1 FROM production_authors y WHERE y.production_item_id = pi.id AND y.matched_researcher_id = :pair_b)
              GROUP BY wk
              ORDER BY year DESC, rep DESC
              LIMIT {$limit}",
            $params,
            $where['types']
        );

        $works = $this->search->loadWorks(array_map(static fn($r) => (int)$r['rep'], $rows));
        $cards = $this->search->loadResearchers([$a, $b]);

        return [
            'a' => $cards[$a] ?? null,
            'b' => $cards[$b] ?? null,
            'count' => count($rows),
            'results' => array_values(array_filter(array_map(static fn($r) => $works[(int)$r['rep']] ?? null, $rows))),
        ];
    }

    /**
     * Coautores do CECH e publicações compartilhadas de um docente (todas as publicações, sem recorte).
     *
     * @return array{coauthors: int, sharedWorks: int}
     */
    public function researcherNetworkStats(int $researcherId): array
    {
        $workKey = ResearchSql::workKey();
        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(DISTINCT pa2.matched_researcher_id) AS coauthors, COUNT(DISTINCT {$workKey}) AS shared
               FROM production_authors pa1
               JOIN production_authors pa2
                 ON pa2.production_item_id = pa1.production_item_id
                AND pa2.matched_researcher_id IS NOT NULL
                AND pa2.matched_researcher_id != pa1.matched_researcher_id
               JOIN production_items pi ON pi.id = pa1.production_item_id
              WHERE pa1.matched_researcher_id = :rid",
            ['rid' => $researcherId]
        ) ?: [];

        return ['coauthors' => (int)($row['coauthors'] ?? 0), 'sharedWorks' => (int)($row['shared'] ?? 0)];
    }

    /**
     * Pares de docentes que publicam juntos, cruzando os departamentos e as grandes áreas de cada um.
     *
     * @return array{departments: array{labels: list<string>, names: list<string>, values: list<list<int>>}, majorAreas: array{labels: list<string>, names: list<string>, values: list<list<int>>}}
     */
    public function bridges(ResearchFilter $filter): array
    {
        $pairs = $this->pairs($filter);
        $ids = [];
        foreach ($pairs as $p) {
            $ids[$p['a']] = true;
            $ids[$p['b']] = true;
        }

        $cards = $this->search->loadResearchers(array_keys($ids));
        $major = $this->primaryMajorAreas(array_keys($ids));

        $deptOf = static fn(int $id) => ($cards[$id]['departmentCode'] ?? '') ?: 'Outros';
        $majorOf = static fn(int $id) => $major[$id] ?? 'OUTROS';

        $deptNames = [];
        foreach ($cards as $card) {
            $code = $card['departmentCode'] ?: 'Outros';
            $deptNames[$code] = $card['department'] ?: $code;
        }

        $majorMatrix = $this->matrix($pairs, $majorOf, MajorAreaCatalog::LABELS);
        $majorMatrix['short'] = array_map(static fn($code) => MajorAreaCatalog::SHORT[$code] ?? $code, $majorMatrix['labels']);

        return [
            'departments' => $this->matrix($pairs, $deptOf, $deptNames),
            'majorAreas' => $majorMatrix,
        ];
    }

    /**
     * @param list<array{a: int, b: int, weight: int}> $pairs
     * @param array<string, string> $names código => nome
     */
    private function matrix(array $pairs, callable $groupOf, array $names): array
    {
        $counts = [];
        foreach ($pairs as $p) {
            $ga = $groupOf($p['a']);
            $gb = $groupOf($p['b']);
            $counts[$ga][$gb] = ($counts[$ga][$gb] ?? 0) + 1;
            if ($ga !== $gb) {
                $counts[$gb][$ga] = ($counts[$gb][$ga] ?? 0) + 1;
            }
        }

        $labels = array_keys($counts);
        sort($labels);
        $values = [];
        foreach ($labels as $row) {
            $line = [];
            foreach ($labels as $col) {
                $line[] = $counts[$row][$col] ?? 0;
            }
            $values[] = $line;
        }

        return [
            'labels' => $labels,
            'names' => array_map(static fn($l) => $names[$l] ?? $l, $labels),
            'values' => $values,
        ];
    }

    /**
     * Grande área predominante de cada docente (a mais frequente nas áreas de atuação do Lattes).
     *
     * @param list<int> $ids
     * @return array<int, string> id => código da grande área
     */
    private function primaryMajorAreas(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT researcher_id, major_area, COUNT(*) AS n FROM knowledge_areas
              WHERE researcher_id IN (:ids) AND major_area IS NOT NULL AND major_area != \'\'
              GROUP BY researcher_id, major_area',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $best = [];
        foreach ($rows as $row) {
            $id = (int)$row['researcher_id'];
            $code = MajorAreaCatalog::normalizeCode((string)$row['major_area']) ?: 'OUTROS';
            $n = (int)$row['n'];
            if (!isset($best[$id]) || $n > $best[$id]['n']) {
                $best[$id] = ['code' => $code, 'n' => $n];
            }
        }

        return array_map(static fn($b) => $b['code'], $best);
    }
}
