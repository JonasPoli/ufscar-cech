<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Support\LattesText;
use App\Repository\ResearcherRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Opções dos filtros e catálogos do "Explorar pesquisa": departamentos, grandes áreas, áreas de atuação (como vêm
 * do Lattes, em `knowledge_areas`) e assuntos do índice temático.
 *
 * Os assuntos do catálogo são os termos vindos de palavras-chave (`keyword`/`hybrid`) presentes na produção de
 * vários docentes; os termos só minerados dos títulos ficam de fora, porque trazem muito ruído.
 */
final class ResearchCatalogService
{
    public const TOPIC_MIN_RESEARCHERS = 5;
    public const TOPIC_MIN_OCCURRENCES = 40;

    public function __construct(
        private readonly Connection $connection,
        private readonly ResearcherRepository $researcherRepository,
        #[Autowire(service: 'cache.research')]
        private readonly CacheInterface $cache,
    ) {
    }

    /** @return list<array{code: string, name: string, facultyCount: int}> */
    public function departments(): array
    {
        return $this->researcherRepository->findAllDistinctDepartments();
    }

    /**
     * Grandes áreas presentes nos currículos, com docentes e publicações únicas.
     *
     * @return list<array{code: string, label: string, slug: string, researchers: int, publications: int}>
     */
    public function majorAreas(): array
    {
        return $this->cache->get('catalog_major_areas', function (ItemInterface $item): array {
            $workKey = ResearchSql::workKey();

            // O Lattes grava a grande área ora pelo código (CIENCIAS_HUMANAS), ora pelo nome (Ciências Humanas). As
            // contagens são feitas por grande área com todas as grafias juntas: somar grafia por grafia contaria duas
            // vezes o docente que tem as duas.
            $spellings = [];
            foreach ($this->connection->fetchFirstColumn("SELECT DISTINCT major_area FROM knowledge_areas WHERE major_area IS NOT NULL AND major_area != ''") as $raw) {
                $code = MajorAreaCatalog::normalizeCode((string)$raw);
                if ($code !== '') {
                    $spellings[$code][] = (string)$raw;
                }
            }

            $byCode = [];
            foreach ($spellings as $code => $values) {
                $params = ['vals' => $values];
                $types = ['vals' => ArrayParameterType::STRING];
                $byCode[$code] = [
                    'code' => $code,
                    'label' => MajorAreaCatalog::LABELS[$code],
                    'slug' => MajorAreaCatalog::slug($code),
                    'researchers' => (int)$this->connection->fetchOne(
                        'SELECT COUNT(DISTINCT researcher_id) FROM knowledge_areas WHERE major_area IN (:vals)',
                        $params,
                        $types
                    ),
                    'publications' => (int)$this->connection->fetchOne(
                        "SELECT COUNT(DISTINCT {$workKey}) FROM production_items pi
                          WHERE pi.researcher_id IN (SELECT k2.researcher_id FROM knowledge_areas k2 WHERE k2.major_area IN (:vals))",
                        $params,
                        $types
                    ),
                ];
            }

            uasort($byCode, static fn($a, $b) => $b['publications'] <=> $a['publications']);

            return array_values($byCode);
        });
    }

    /**
     * Áreas de atuação com a grande área, docentes e publicações únicas.
     *
     * @return list<array{area: string, slug: string, majorCode: string, majorLabel: string, researchers: int, publications: int}>
     */
    public function areas(): array
    {
        return $this->cache->get('catalog_areas', function (ItemInterface $item): array {
            $workKey = ResearchSql::workKey();
            $rows = $this->connection->fetchAllAssociative(
                "SELECT ka.area, MIN(ka.major_area) AS major_area, COUNT(DISTINCT ka.researcher_id) AS researchers,
                        COUNT(DISTINCT {$workKey}) AS publications
                   FROM knowledge_areas ka
                   LEFT JOIN production_items pi ON pi.researcher_id = ka.researcher_id
                  WHERE ka.area IS NOT NULL AND TRIM(ka.area) != ''
                  GROUP BY ka.area
                  ORDER BY ka.area"
            );

            $out = [];
            foreach ($rows as $row) {
                $area = trim((string)$row['area']);
                $code = MajorAreaCatalog::normalizeCode((string)$row['major_area']);
                $out[] = [
                    'area' => (string)LattesText::display($area),
                    'slug' => \App\Service\Thesaurus\StringNormalizer::slugify($area),
                    'majorCode' => $code,
                    'majorLabel' => $code !== '' ? MajorAreaCatalog::LABELS[$code] : 'Outras',
                    'researchers' => (int)$row['researchers'],
                    'publications' => (int)$row['publications'],
                ];
            }

            return $out;
        });
    }

    /** Área de atuação pelo slug, com a grafia gravada (é ela que filtra). */
    public function findArea(string $slug): ?array
    {
        foreach ($this->areas() as $area) {
            if ($area['slug'] === $slug) {
                return $area;
            }
        }

        return null;
    }

    /**
     * Assuntos do catálogo, do mais frequente ao menos.
     *
     * @return list<array{id: int, term: string, slug: string, occurrences: int, researchers: int}>
     */
    public function topics(int $limit = 300): array
    {
        return $this->cache->get('catalog_topics_' . $limit, function (ItemInterface $item) use ($limit): array {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, term, slug, total_occurrences, researcher_count
                   FROM thematic_terms
                  WHERE source_type IN ('keyword', 'hybrid')
                    AND researcher_count >= :minResearchers AND total_occurrences >= :minOccurrences
                  ORDER BY total_occurrences DESC, term ASC
                  LIMIT {$limit}",
                ['minResearchers' => self::TOPIC_MIN_RESEARCHERS, 'minOccurrences' => self::TOPIC_MIN_OCCURRENCES]
            );

            return array_map(static fn(array $r) => [
                'id' => (int)$r['id'],
                'term' => (string)LattesText::display((string)$r['term']),
                'slug' => (string)($r['slug'] ?: $r['id']),
                'occurrences' => (int)$r['total_occurrences'],
                'researchers' => (int)$r['researcher_count'],
            ], $rows);
        });
    }

    /** Total de assuntos que entram no catálogo. */
    public function topicCount(): int
    {
        return $this->cache->get('catalog_topic_count', fn() => (int)$this->connection->fetchOne(
            "SELECT COUNT(*) FROM thematic_terms
              WHERE source_type IN ('keyword', 'hybrid') AND researcher_count >= :r AND total_occurrences >= :o",
            ['r' => self::TOPIC_MIN_RESEARCHERS, 'o' => self::TOPIC_MIN_OCCURRENCES]
        ));
    }

    /**
     * Assuntos mais presentes na produção de um conjunto de docentes (soma das ocorrências no índice temático).
     *
     * @param list<int>|null $researcherIds null = todos
     * @return list<array{term: string, slug: string, count: int}>
     */
    public function topicsForResearchers(?array $researcherIds, int $limit = 12, ?string $excludeTerm = null): array
    {
        if ($researcherIds === []) {
            return [];
        }

        $params = ['minResearchers' => 2];
        $types = [];
        $restrict = '';
        if ($researcherIds !== null) {
            $restrict = 'AND ttr.researcher_id IN (:ids)';
            $params['ids'] = array_values($researcherIds);
            $types['ids'] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }
        if ($excludeTerm !== null && $excludeTerm !== '') {
            $restrict .= ' AND t.term != :exclude';
            $params['exclude'] = $excludeTerm;
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.term, t.slug, t.id, SUM(ttr.occurrences) AS total
               FROM thematic_term_researchers ttr
               JOIN thematic_terms t ON t.id = ttr.term_id
              WHERE t.source_type IN ('keyword', 'hybrid') AND t.researcher_count >= :minResearchers {$restrict}
              GROUP BY t.id, t.term, t.slug
              ORDER BY total DESC
              LIMIT {$limit}",
            $params,
            $types
        );

        return array_map(static fn(array $r) => [
            'term' => (string)LattesText::display((string)$r['term']),
            'slug' => (string)($r['slug'] ?: $r['id']),
            'count' => (int)$r['total'],
        ], $rows);
    }
}
