<?php

namespace App\Repository;

use App\Entity\ProductionItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
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
     * Counts total productions for a given department code or name.
     */
    public function countByDepartment(string $deptCode, string $deptName): int
    {
        $conn = $this->getEntityManager()->getConnection();
        return (int)$conn->fetchOne(
            'SELECT COUNT(pi.id) FROM production_items pi JOIN researchers r ON pi.researcher_id = r.id WHERE r.department_code = :code OR r.department = :name',
            ['code' => $deptCode, 'name' => $deptName]
        );
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
     * @param list<int> $journalRowIds Linhas de `qualis_journals` das famílias cujo nome contém o termo
     *        (`QualisJournalRepository::findFamilyRowIdsByName`): os artigos ligados a elas também entram na busca
     * @return ProductionItem[]
     */
    public function searchProductions(?string $query = null, ?string $department = null, int $page = 1, int $limit = 20, array $journalRowIds = []): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.researcher', 'r')
            ->addSelect('r')
            ->orderBy('p.year', 'DESC')
            ->addOrderBy('p.id', 'DESC');

        if ($query !== null && $query !== '') {
            $this->applyTextFilter($qb, $query, $journalRowIds);
        }

        if ($department !== null && $department !== '' && $department !== 'all') {
            $depts = ResearcherRepository::getDepartmentFilterValues($department);
            $qb->andWhere('r.department IN (:depts) OR r.departmentCode IN (:depts)')
               ->setParameter('depts', $depts);
        }

        $qb->setFirstResult(($page - 1) * $limit)
           ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Counts total search results for production items.
     *
     * @param list<int> $journalRowIds Os mesmos de {@see searchProductions()}
     */
    public function countSearchProductions(?string $query = null, ?string $department = null, array $journalRowIds = []): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->leftJoin('p.researcher', 'r');

        if ($query !== null && $query !== '') {
            $this->applyTextFilter($qb, $query, $journalRowIds);
        }

        if ($department !== null && $department !== '' && $department !== 'all') {
            $depts = ResearcherRepository::getDepartmentFilterValues($department);
            $qb->andWhere('r.department IN (:depts) OR r.departmentCode IN (:depts)')
               ->setParameter('depts', $depts);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Filtro de texto da busca: título, periódico (como o docente escreveu), evento ou DOI contendo o termo.
     *
     * Com `$journalRowIds` acrescenta, em OR, os artigos ligados a esses periódicos, para a revista ser achada por
     * qualquer nome (título, nome de exibição ou variante da família), e não só pela grafia do Lattes. Sem ids, o
     * filtro é o de sempre. Só artigo entra por vínculo: o resto não tem periódico.
     *
     * @param list<int> $journalRowIds
     */
    private function applyTextFilter(QueryBuilder $qb, string $query, array $journalRowIds): void
    {
        $conditions = 'p.title LIKE :query OR p.journalName LIKE :query OR p.eventName LIKE :query OR p.doi LIKE :query';

        if ($journalRowIds !== []) {
            $conditions .= ' OR (p.itemType = :articleType AND IDENTITY(p.qualisJournal) IN (:journalRowIds))';
            $qb->setParameter('articleType', ProductionItem::TYPE_ARTIGO)
               ->setParameter('journalRowIds', array_values($journalRowIds), ArrayParameterType::INTEGER);
        }

        $qb->andWhere($conditions)
           ->setParameter('query', '%' . $query . '%');
    }

    /**
     * Nome usual do periódico de cada artigo ligado a um periódico de um docente: o nome de exibição da canônica da
     * família (`COALESCE(display_title, title)`), em uma só consulta para a página do docente, sem N+1.
     *
     * O `qualis_journal_id` do artigo aponta para a linha que o resolver escolheu, não para a canônica; o `journal_name`
     * do Lattes não entra e não é alterado. Artigo sem vínculo não aparece no resultado.
     *
     * @return array<int, string> id do item => nome usual
     */
    public function getUsualJournalNamesForResearcher(int $researcherId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT pi.id, COALESCE(NULLIF(c.display_title, ''), c.title) AS name
               FROM production_items pi
               JOIN qualis_journals q ON q.id = pi.qualis_journal_id
               JOIN qualis_journals c ON c.id = COALESCE(q.canonical_journal_id, q.id)
              WHERE pi.researcher_id = :researcherId AND pi.item_type = :articleType",
            ['researcherId' => $researcherId, 'articleType' => ProductionItem::TYPE_ARTIGO]
        );

        $names = [];
        foreach ($rows as $row) {
            $name = trim((string)$row['name']);
            if ($name !== '') {
                $names[(int)$row['id']] = $name;
            }
        }

        return $names;
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
