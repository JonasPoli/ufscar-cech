<?php

namespace App\Repository;

use App\Entity\Researcher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Researcher>
 *
 * Repository for Researcher entities providing advanced search, pagination,
 * and statistical aggregations for faculty and departments.
 */
class ResearcherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Researcher::class);
    }

    /**
     * Finds researchers by query, department, and pagination.
     *
     * @param string|null $query Search term for name, citation names, Lattes ID, or ORCID
     * @param string|null $department Filter by department name or code
     * @param int $page Current page (1-indexed)
     * @param int $limit Items per page
     * @return Researcher[] List of matching Researcher entities
     */
    public function searchResearchers(?string $query = null, ?string $department = null, int $page = 1, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.fullName', 'ASC');

        if ($query !== null && $query !== '') {
            $qb->andWhere('r.fullName LIKE :query OR r.citationNames LIKE :query OR r.idLattes LIKE :query OR r.orcid LIKE :query')
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
     * Counts the total number of matching researchers for search filters.
     */
    public function countSearchResearchers(?string $query = null, ?string $department = null): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)');

        if ($query !== null && $query !== '') {
            $qb->andWhere('r.fullName LIKE :query OR r.citationNames LIKE :query OR r.idLattes LIKE :query OR r.orcid LIKE :query')
               ->setParameter('query', '%' . $query . '%');
        }

        if ($department !== null && $department !== '' && $department !== 'all') {
            $qb->andWhere('r.department = :dept OR r.departmentCode = :dept')
               ->setParameter('dept', $department);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Efficiently retrieves all researchers with their pre-aggregated production and orientation counts.
     * Uses getArrayResult() to avoid hydrating 100,000+ objects into Doctrine UnitOfWork.
     *
     * @return array<int, array{id: int, idLattes: string, fullName: string, slug: string, orcid: ?string, department: ?string, departmentCode: ?string, photoUrl: ?string, totalProductions: int, totalOrientations: int}>
     */
    public function findResearchersWithCounts(?string $department = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.id', 'r.idLattes', 'r.fullName', 'r.slug', 'r.orcid', 'r.department', 'r.departmentCode', 'r.photoUrl', 'r.admissionYear', 'r.leaveYear', 'r.status')
            ->addSelect('COUNT(DISTINCT p.id) AS totalProductions')
            ->addSelect('COUNT(DISTINCT o.id) AS totalOrientations')
            ->leftJoin('r.productions', 'p')
            ->leftJoin('r.orientations', 'o')
            ->groupBy('r.id')
            ->orderBy('r.fullName', 'ASC');

        if ($department !== null && $department !== '' && $department !== 'all') {
            $qb->andWhere('r.department = :dept OR r.departmentCode = :dept')
               ->setParameter('dept', $department);
        }

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Retrieves top departments ordered by number of researchers.
     *
     * @param int $limit Max departments to return
     * @return array Array of associative rows with department, departmentCode, totalFaculty, totalProductions
     */
    public function findTopDepartments(int $limit = 8): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = "
            SELECT 
                r.department,
                r.department_code AS departmentCode,
                COUNT(DISTINCT r.id) AS totalFaculty,
                COUNT(p.id) AS totalProductions
            FROM researchers r
            LEFT JOIN production_items p ON p.researcher_id = r.id
            WHERE r.department IS NOT NULL AND r.department != ''
            GROUP BY r.department, r.department_code
            ORDER BY totalFaculty DESC, totalProductions DESC
            LIMIT " . (int)$limit;

        return $conn->fetchAllAssociative($sql);
    }

    /**
     * Finds featured researchers (most productive).
     *
     * @param int $limit Number of researchers
     * @return Researcher[]
     */
    public function findFeaturedResearchers(int $limit = 8): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.productions', 'p')
            ->addSelect('COUNT(p.id) AS HIDDEN prodCount')
            ->groupBy('r.id')
            ->orderBy('prodCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds researchers by department code or name.
     *
     * @param string $codeOrName Department code (e.g. 'LE') or full name
     * @return Researcher[]
     */
    public function findByDepartmentCodeOrName(string $codeOrName): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.departmentCode = :val OR r.department = :val')
            ->setParameter('val', $codeOrName)
            ->orderBy('r.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns a list of all distinct departments.
     *
     * @return array Array with code and name
     */
    public function findAllDistinctDepartments(): array
    {
        return $this->createQueryBuilder('r')
            ->select('DISTINCT r.departmentCode as code, r.department as name, COUNT(r.id) as facultyCount')
            ->where('r.department IS NOT NULL AND r.department != \'\'')
            ->groupBy('r.departmentCode, r.department')
            ->orderBy('r.department', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Efficiently loads a Researcher with productions and production authors to eliminate N+1 queries.
     */
    public function findWithAllDetails(string|int $slugOrId): ?Researcher
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.productions', 'p')->addSelect('p')
            ->leftJoin('p.authors', 'pa')->addSelect('pa');

        if (is_numeric($slugOrId)) {
            $qb->where('r.id = :id OR r.idLattes = :lattes')
               ->setParameter('id', (int)$slugOrId)
               ->setParameter('lattes', (string)$slugOrId);
        } else {
            $qb->where('r.slug = :slug OR r.idLattes = :slug')
               ->setParameter('slug', (string)$slugOrId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}

