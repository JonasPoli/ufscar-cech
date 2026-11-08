<?php

declare(strict_types=1);

namespace App\Service\Research;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Panorama de um recorte da produção: publicações únicas, docentes, publicações compartilhadas, pares de coautoria,
 * série anual, tipos, assuntos em destaque e pontes entre departamentos e grandes áreas.
 *
 * Os resultados ficam no pool `cache.research` por filtro (um dia), limpo com o cache de páginas.
 */
final class ResearchMetricsService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CoauthorshipGraphService $graph,
        private readonly ResearchCatalogService $catalog,
        #[Autowire(service: 'cache.research')]
        private readonly CacheInterface $cache,
    ) {
    }

    /** @return array<string, mixed> */
    public function metrics(ResearchFilter $filter, bool $withBridges = true): array
    {
        $key = $filter->cacheKey('metrics' . ($withBridges ? '_b' : ''));

        $compute = function () use ($filter, $withBridges): array {
            $where = ResearchSql::where($filter);
            $workKey = ResearchSql::workKey();
            $base = "FROM production_items pi JOIN researchers r ON r.id = pi.researcher_id WHERE {$where['sql']}";

            $totals = $this->connection->fetchAssociative(
                "SELECT COUNT(DISTINCT {$workKey}) AS publications, COUNT(DISTINCT pi.researcher_id) AS researchers, MAX(pi.year) AS latest {$base}",
                $where['params'],
                $where['types']
            ) ?: [];

            $yearRows = $this->connection->fetchAllAssociative(
                "SELECT pi.year, COUNT(DISTINCT {$workKey}) AS n {$base} AND pi.year IS NOT NULL AND pi.year >= :mr_min AND pi.year <= :mr_max GROUP BY pi.year ORDER BY pi.year",
                $where['params'] + ['mr_min' => ResearchFilter::MIN_YEAR, 'mr_max' => (int)date('Y')],
                $where['types']
            );
            $years = [];
            if ($yearRows !== []) {
                $first = (int)$yearRows[0]['year'];
                $last = (int)$yearRows[count($yearRows) - 1]['year'];
                $byYear = array_column($yearRows, 'n', 'year');
                for ($y = $first; $y <= $last; $y++) {
                    $years[] = ['year' => $y, 'count' => (int)($byYear[$y] ?? 0)];
                }
            }

            $kindRows = $this->connection->fetchAllAssociative(
                "SELECT pi.item_type, COUNT(DISTINCT {$workKey}) AS n {$base} GROUP BY pi.item_type ORDER BY n DESC",
                $where['params'],
                $where['types']
            );
            $kinds = array_map(static fn($r) => [
                'type' => (string)$r['item_type'],
                'label' => ExpertSearchService::typeLabel((string)$r['item_type']),
                'count' => (int)$r['n'],
            ], $kindRows);

            $researcherIds = null;
            if (!$filter->isEmpty()) {
                $researcherIds = array_map('intval', $this->connection->fetchFirstColumn(
                    "SELECT DISTINCT pi.researcher_id {$base}",
                    $where['params'],
                    $where['types']
                ));
            }

            $pairs = $this->graph->pairs($filter);

            return [
                'publications' => (int)($totals['publications'] ?? 0),
                'researchers' => (int)($totals['researchers'] ?? 0),
                'latestYear' => isset($totals['latest']) ? (int)$totals['latest'] : null,
                'sharedWorks' => $this->graph->sharedWorks($filter),
                'pairs' => count($pairs),
                'years' => $years,
                'kinds' => $kinds,
                'topics' => $this->catalog->topicsForResearchers($researcherIds, 12, $filter->topicText ?: null),
                'bridges' => $withBridges ? $this->graph->bridges($filter) : null,
            ];
        };

        return $filter->isCacheable() ? $this->cache->get($key, $compute) : $compute();
    }
}
