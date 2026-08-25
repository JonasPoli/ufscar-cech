<?php

namespace App\Repository;

use App\Entity\ProductionItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductionItem>
 *
 * Repository for ProductionItem entities providing advanced aggregation,
 * bibliometric queries, Qualis distribution, and search methods.
 */
class ProductionItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductionItem::class);
    }

    /**
     * Gets total counts of publications grouped by year between given range.
     *
     * @param int $startYear
     * @param int $endYear
     * @return array Map of year => count
     */
    public function getProductionCountByYear(int $startYear = 2015, int $endYear = 2026): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = "
            SELECT `year`, COUNT(*) as total
            FROM production_items
            WHERE `year` >= :startYear AND `year` <= :endYear
            GROUP BY `year`
            ORDER BY `year` ASC
        ";

        $rows = $conn->fetchAllAssociative($sql, ['startYear' => $startYear, 'endYear' => $endYear]);
        $result = [];
        foreach ($rows as $r) {
            $result[(int)$r['year']] = (int)$r['total'];
        }
        return $result;
    }

    /**
     * Gets total counts of publications grouped by item type.
     *
     * @return array Map of itemType => count
     */
    public function getProductionCountByType(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = "
            SELECT item_type AS itemType, COUNT(*) as total
            FROM production_items
            GROUP BY item_type
            ORDER BY total DESC
        ";

        return $conn->fetchAllAssociative($sql);
    }

    /**
     * Gets distribution of journal articles by Qualis stratum (A1, A2, A3, A4, B1, B2, B3, B4, C, Non-classified).
     *
     * @return array Map of qualis => count
     */
    public function getQualisDistribution(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = "
            SELECT 
                COALESCE(NULLIF(qualis, ''), 'Sem Qualis') AS qualisStr,
                COUNT(*) as total
            FROM production_items
            WHERE item_type = 'ARTIGO'
            GROUP BY qualisStr
            ORDER BY total DESC
        ";

        return $conn->fetchAllAssociative($sql);
    }

    /**
     * Searches production items with query string and optional department filter.
     *
     * @param string|null $query Search term in title, journal, event, or author name
     * @param string|null $department Filter by researcher's department
     * @param int $page Current page (1-indexed)
     * @param int $limit Items per page
     * @return ProductionItem[]
     */
    public function searchProductions(?string $query = null, ?string $department = null, int $page = 1, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.researcher', 'r')
            ->addSelect('r')
            ->orderBy('p.year', 'DESC')
            ->addOrderBy('p.id', 'DESC');

        if ($query !== null && $query !== '') {
            $qb->andWhere('p.title LIKE :query OR p.journalName LIKE :query OR p.eventName LIKE :query OR p.doi LIKE :query')
               ->setParameter('query', '%' . $query . '%');
        }

        if ($department !== null && $department !== '' && $department !== 'all') {
            $qb->andWhere('r.department = :dept OR r.departmentCode = :dept')
               ->setParameter('dept', $department);
        }

        $qb->setFirstResult(($page - 1) * $limit)
           ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Counts total search results for production items.
     */
    public function countSearchProductions(?string $query = null, ?string $department = null): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->leftJoin('p.researcher', 'r');

        if ($query !== null && $query !== '') {
            $qb->andWhere('p.title LIKE :query OR p.journalName LIKE :query OR p.eventName LIKE :query OR p.doi LIKE :query')
               ->setParameter('query', '%' . $query . '%');
        }

        if ($department !== null && $department !== '' && $department !== 'all') {
            $qb->andWhere('r.department = :dept OR r.departmentCode = :dept')
               ->setParameter('dept', $department);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Counts production items for a specific array of researcher IDs.
     */
    public function countByResearcherIds(array $ids): int
    {
        if (empty($ids)) return 0;

        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.researcher IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Gets yearly production for a specific array of researcher IDs.
     */
    public function getProductionCountByYearForResearchers(array $ids, int $startYear = 2015, int $endYear = 2026): array
    {
        if (empty($ids)) return [];

        $conn = $this->getEntityManager()->getConnection();
        $idList = implode(',', array_map('intval', $ids));
        $sql = "
            SELECT `year`, COUNT(*) as total
            FROM production_items
            WHERE researcher_id IN ({$idList}) AND `year` >= :startYear AND `year` <= :endYear
            GROUP BY `year`
            ORDER BY `year` ASC
        ";

        $rows = $conn->fetchAllAssociative($sql, ['startYear' => $startYear, 'endYear' => $endYear]);
        $result = [];
        foreach ($rows as $r) {
            $result[(int)$r['year']] = (int)$r['total'];
        }
        return $result;
    }

    /**
     * Gets Qualis distribution for a specific array of researcher IDs.
     */
    public function getQualisDistributionForResearchers(array $ids): array
    {
        if (empty($ids)) return [];

        $conn = $this->getEntityManager()->getConnection();
        $idList = implode(',', array_map('intval', $ids));
        $sql = "
            SELECT 
                COALESCE(NULLIF(qualis, ''), 'Sem Qualis') AS qualisStr,
                COUNT(*) as total
            FROM production_items
            WHERE researcher_id IN ({$idList}) AND item_type = 'ARTIGO'
            GROUP BY qualisStr
            ORDER BY total DESC
        ";

        return $conn->fetchAllAssociative($sql);
    }
}
