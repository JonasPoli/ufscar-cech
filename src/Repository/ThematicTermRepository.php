<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ThematicTerm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @extends ServiceEntityRepository<ThematicTerm>
 */
class ThematicTermRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ?CacheInterface $cache = null
    ) {
        parent::__construct($registry, ThematicTerm::class);
    }

    /**
     * Busca termos com base em texto parcial normalizado (autocomplete em tempo real).
     *
     * @return array<int, array{id: int, term: string, slug: string, totalOccurrences: int, researcherCount: int, weight: int}>
     */
    public function searchTerms(string $query, int $limit = 24): array
    {
        $normalized = $this->normalizeString($query);
        if (mb_strlen($normalized) < 2) {
            return [];
        }

        // Prioriza termos que começam com a busca, seguido por termos que contêm a busca
        $qb = $this->createQueryBuilder('t')
            ->select('t.id, t.term, t.slug, t.totalOccurrences, t.researcherCount')
            ->where('t.normalizedTerm LIKE :prefix OR t.normalizedTerm LIKE :contains')
            ->setParameter('prefix', $normalized . '%')
            ->setParameter('contains', '%' . $normalized . '%')
            ->orderBy('CASE WHEN t.normalizedTerm LIKE :exactPrefix THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('t.totalOccurrences', 'DESC')
            ->addOrderBy('t.researcherCount', 'DESC')
            ->setParameter('exactPrefix', $normalized . '%')
            ->setMaxResults($limit);

        $results = $qb->getQuery()->getArrayResult();

        return $this->attachWeights($results);
    }

    /**
     * Retorna os termos mais frequentes globais para a visão inicial.
     *
     * @return array<int, array{id: int, term: string, slug: string, totalOccurrences: int, researcherCount: int, weight: int}>
     */
    public function getTopFeaturedTerms(int $limit = 24): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.id, t.term, t.slug, t.totalOccurrences, t.researcherCount')
            ->where('t.totalOccurrences >= 2')
            ->orderBy('t.totalOccurrences', 'DESC')
            ->addOrderBy('t.researcherCount', 'DESC')
            ->setMaxResults($limit);

        $results = $qb->getQuery()->getArrayResult();

        return $this->attachWeights($results);
    }

    /**
     * Encontra um termo por slug amigável ou ID numérico.
     */
    public function findBySlugOrId(string $slugOrId): ?ThematicTerm
    {
        if (ctype_digit($slugOrId)) {
            return $this->find((int)$slugOrId);
        }

        return $this->findOneBy(['slug' => $slugOrId]);
    }

    /**
     * Retorna estatísticas gerais de termos.
     *
     * @return array{totalTerms: int, maxOccurrences: int}
     */
    public function getGlobalStats(): array
    {
        $total = (int)$this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $max = (int)$this->createQueryBuilder('t')
            ->select('MAX(t.totalOccurrences)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'totalTerms' => $total,
            'maxOccurrences' => $max,
        ];
    }

    /**
     * Atribui peso relativo (1 a 100) para gradiente de cores da lista.
     *
     * @param array<int, array{id: int, term: string, slug: string, totalOccurrences: int, researcherCount: int}> $items
     * @return array<int, array{id: int, term: string, slug: string, totalOccurrences: int, researcherCount: int, weight: int}>
     */
    private function attachWeights(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $maxCount = (int)($items[0]['totalOccurrences'] ?? 1);
        if ($maxCount <= 0) {
            $maxCount = 1;
        }

        foreach ($items as &$item) {
            $count = (int)($item['totalOccurrences'] ?? 0);
            $item['weight'] = (int)round(($count / $maxCount) * 100);
        }

        return $items;
    }

    /**
     * Retorna a linha do tempo ano a ano da evolução dos trabalhos que possuem este tema,
     * do primeiro ano registrado até o ano corrente.
     *
     * @return array{
     *     startYear: ?int,
     *     endYear: int,
     *     years: array<int>,
     *     totalWorks: array<int>,
     *     productions: array<int>,
     *     orientations: array<int>,
     *     peakYear: ?int,
     *     peakCount: int,
     *     grandTotal: int
     * }
     */
    public function getYearlyTimelineForTerm(ThematicTerm $term): array
    {
        $cacheKey = 'thematic_timeline_' . ($term->getSlug() ?: (string)$term->getId());

        if ($this->cache !== null) {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($term) {
                $item->expiresAfter(86400); // 24 horas
                return $this->computeYearlyTimeline($term);
            });
        }

        return $this->computeYearlyTimeline($term);
    }

    /**
     * @return array{
     *     startYear: ?int,
     *     endYear: int,
     *     years: array<int>,
     *     totalWorks: array<int>,
     *     productions: array<int>,
     *     orientations: array<int>,
     *     peakYear: ?int,
     *     peakCount: int,
     *     grandTotal: int
     * }
     */
    private function computeYearlyTimeline(ThematicTerm $term): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $currentYear = (int)date('Y');
        $termStr = $term->getTerm() ?? '';
        $normStr = $term->getNormalizedTerm() ?? '';
        $searchTerm = $normStr !== '' ? $normStr : $termStr;
        $termPattern = '%' . $searchTerm . '%';

        $sql = "
        SELECT year, SUM(total_works) as total_works, SUM(prods) as prods, SUM(orients) as orients FROM (
            SELECT year, COUNT(DISTINCT LOWER(TRIM(title))) as total_works, COUNT(DISTINCT LOWER(TRIM(title))) as prods, 0 as orients
            FROM production_items
            WHERE (title LIKE :termPattern OR CAST(extra_data AS CHAR) LIKE :termPattern)
              AND year IS NOT NULL AND year >= 1950 AND year <= :currentYear
            GROUP BY year
            UNION ALL
            SELECT year, COUNT(DISTINCT LOWER(TRIM(title))) as total_works, 0 as prods, COUNT(DISTINCT LOWER(TRIM(title))) as orients
            FROM orientations
            WHERE (title LIKE :termPattern OR alternative_title LIKE :termPattern OR keywords LIKE :termPattern)
              AND year IS NOT NULL AND year >= 1950 AND year <= :currentYear
            GROUP BY year
        ) combined
        GROUP BY year
        ORDER BY year ASC
        ";

        $rows = $conn->fetchAllAssociative($sql, [
            'termPattern' => $termPattern,
            'currentYear' => $currentYear,
        ]);

        if (empty($rows)) {
            return [
                'startYear' => null,
                'endYear' => $currentYear,
                'years' => [],
                'totalWorks' => [],
                'productions' => [],
                'orientations' => [],
                'peakYear' => null,
                'peakCount' => 0,
                'grandTotal' => 0,
            ];
        }

        $firstYear = (int)$rows[0]['year'];
        $byYear = [];
        foreach ($rows as $r) {
            $y = (int)$r['year'];
            $byYear[$y] = [
                'total' => (int)$r['total_works'],
                'prods' => (int)$r['prods'],
                'orients' => (int)$r['orients'],
            ];
        }

        $years = [];
        $totalWorks = [];
        $productions = [];
        $orientations = [];
        $peakYear = $firstYear;
        $peakCount = 0;
        $grandTotal = 0;

        for ($y = $firstYear; $y <= $currentYear; $y++) {
            $years[] = $y;
            $t = $byYear[$y]['total'] ?? 0;
            $p = $byYear[$y]['prods'] ?? 0;
            $o = $byYear[$y]['orients'] ?? 0;

            $totalWorks[] = $t;
            $productions[] = $p;
            $orientations[] = $o;
            $grandTotal += $t;

            if ($t > $peakCount) {
                $peakCount = $t;
                $peakYear = $y;
            }
        }

        return [
            'startYear' => $firstYear,
            'endYear' => $currentYear,
            'years' => $years,
            'totalWorks' => $totalWorks,
            'productions' => $productions,
            'orientations' => $orientations,
            'peakYear' => $peakYear,
            'peakCount' => $peakCount,
            'grandTotal' => $grandTotal,
        ];
    }

    private function normalizeString(string $str): string
    {
        $clean = mb_strtolower(trim($str), 'UTF-8');
        $clean = (string)preg_replace('/[áàãâä]/u', 'a', $clean);
        $clean = (string)preg_replace('/[éèêë]/u', 'e', $clean);
        $clean = (string)preg_replace('/[íìîï]/u', 'i', $clean);
        $clean = (string)preg_replace('/[óòõôö]/u', 'o', $clean);
        $clean = (string)preg_replace('/[úùûü]/u', 'u', $clean);
        $clean = (string)preg_replace('/[ç]/u', 'c', $clean);
        $clean = (string)preg_replace('/[ñ]/u', 'n', $clean);
        $clean = trim((string)preg_replace('/[^a-z0-9\s]/u', ' ', $clean));
        return (string)preg_replace('/\s+/', ' ', $clean);
    }
}
