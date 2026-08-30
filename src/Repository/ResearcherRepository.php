<?php

namespace App\Repository;

use App\Entity\ProductionItem;
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
    public const DEPARTMENT_MAP = [
        'AC' => 'Departamento de Artes e Comunicação',
        'DAC' => 'Departamento de Artes e Comunicação',
        'CA' => 'Departamento de Ciências Ambientais',
        'DCA' => 'Departamento de Ciências Ambientais',
        'DCAm' => 'Departamento de Ciências Ambientais',
        'CI' => 'Departamento de Ciência da Informação',
        'DCI' => 'Departamento de Ciência da Informação',
        'CS' => 'Departamento de Ciências Sociais',
        'DCSo' => 'Departamento de Ciências Sociais',
        'DCS' => 'Departamento de Ciências Sociais',
        'ED' => 'Departamento de Educação',
        'DEd' => 'Departamento de Educação',
        'DEC' => 'Departamento de Educação e Comunicação',
        'FI' => 'Departamento de Filosofia',
        'FIL' => 'Departamento de Filosofia',
        'DFil' => 'Departamento de Filosofia',
        'IFD' => 'Departamento de Metodologia de Ensino / Formação Docente',
        'DME' => 'Departamento de Metodologia de Ensino',
        'DMTE' => 'Departamento de Metodologia e Teoria da Educação',
        'DTE' => 'Departamento de Teoria e Prática da Educação',
        'LE' => 'Departamento de Letras',
        'DL' => 'Departamento de Letras',
        'PS' => 'Departamento de Psicologia',
        'DPsi' => 'Departamento de Psicologia',
        'SO' => 'Departamento de Sociologia',
        'DSo' => 'Departamento de Sociologia',
        'TPP' => 'Departamento de Teoria e Prática Pedagógica',
        'DTPP' => 'Departamento de Teoria e Prática Pedagógica',
        'DEF' => 'Departamento de Educação Física',
        'DAD' => 'Departamento de Administração',
        'DART' => 'Departamento de Artes',
        'DMUS' => 'Departamento de Música',
        'GEO' => 'Departamento de Geografia',
        'HIS' => 'Departamento de História',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Researcher::class);
    }

    /**
     * Resolves aliases for a given department code or name to match all variations.
     */
    public static function getDepartmentFilterValues(string $department): array
    {
        $map = [
            'CS' => ['CS', 'DCSo', 'DCS', 'Departamento de Ciências Sociais'],
            'DCSo' => ['CS', 'DCSo', 'DCS', 'Departamento de Ciências Sociais'],
            'Departamento de Ciências Sociais' => ['CS', 'DCSo', 'DCS', 'Departamento de Ciências Sociais'],
            'AC' => ['AC', 'DAC', 'Departamento de Artes e Comunicação'],
            'DAC' => ['AC', 'DAC', 'Departamento de Artes e Comunicação'],
            'Departamento de Artes e Comunicação' => ['AC', 'DAC', 'Departamento de Artes e Comunicação'],
            'CA' => ['CA', 'DCA', 'DCAm', 'Departamento de Ciências Ambientais'],
            'CI' => ['CI', 'DCI', 'Departamento de Ciência da Informação'],
            'ED' => ['ED', 'DEd', 'DEC', 'Departamento de Educação'],
            'FI' => ['FI', 'FIL', 'DFil', 'Departamento de Filosofia'],
            'FIL' => ['FI', 'FIL', 'DFil', 'Departamento de Filosofia'],
            'IFD' => ['IFD', 'DME', 'DMTE', 'Departamento de Metodologia de Ensino / Formação Docente', 'Departamento de Metodologia de Ensino'],
            'LE' => ['LE', 'DL', 'Departamento de Letras'],
            'PS' => ['PS', 'DPsi', 'Departamento de Psicologia'],
            'SO' => ['SO', 'DSo', 'Departamento de Sociologia'],
            'TPP' => ['TPP', 'DTPP', 'DTE', 'Departamento de Teoria e Prática Pedagógica'],
        ];

        return $map[$department] ?? [$department];
    }

    /**
     * Searches researchers by full name, citation names, Lattes ID, ORCID, or department.
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
            $depts = self::getDepartmentFilterValues($department);
            $qb->andWhere('r.department IN (:depts) OR r.departmentCode IN (:depts)')
               ->setParameter('depts', $depts);
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
            $depts = self::getDepartmentFilterValues($department);
            $qb->andWhere('r.department IN (:depts) OR r.departmentCode IN (:depts)')
               ->setParameter('depts', $depts);
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
        return $this->searchResearchersWithCounts(null, $department, 1, 10000);
    }

    /**
     * Efficiently retrieves researchers with counts, query filtering and pagination.
     */
    public function searchResearchersWithCounts(?string $query = null, ?string $department = null, int $page = 1, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.id', 'r.idLattes', 'r.fullName', 'r.slug', 'r.orcid', 'r.department', 'r.departmentCode', 'r.photoUrl', 'r.admissionYear', 'r.leaveYear', 'r.status')
            ->addSelect('COUNT(DISTINCT p.id) AS totalProductions')
            ->addSelect('COUNT(DISTINCT o.id) AS totalOrientations')
            ->leftJoin('r.productions', 'p')
            ->leftJoin('r.orientations', 'o')
            ->groupBy('r.id')
            ->orderBy('r.fullName', 'ASC');

        if ($query !== null && $query !== '') {
            $qb->andWhere('r.fullName LIKE :query OR r.citationNames LIKE :query OR r.idLattes LIKE :query OR r.orcid LIKE :query')
               ->setParameter('query', '%' . $query . '%');
        }

        if ($department !== null && $department !== '' && $department !== 'all') {
            $depts = self::getDepartmentFilterValues($department);
            $qb->andWhere('r.department IN (:depts) OR r.departmentCode IN (:depts)')
               ->setParameter('depts', $depts);
        }

        $qb->setFirstResult(($page - 1) * $limit)
           ->setMaxResults($limit);

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
                COALESCE(NULLIF(r.department_code, ''), r.department, 'Outros') AS departmentCode,
                r.department,
                COUNT(DISTINCT r.id) AS totalFaculty,
                COUNT(p.id) AS totalProductions
            FROM researchers r
            LEFT JOIN production_items p ON p.researcher_id = r.id
            WHERE r.status = 1 AND ((r.department IS NOT NULL AND r.department != '') OR (r.department_code IS NOT NULL AND r.department_code != ''))
            GROUP BY departmentCode, r.department";

        $rows = $conn->fetchAllAssociative($sql);
        $aggregated = [];
        foreach ($rows as $r) {
            $code = trim((string)($r['departmentCode'] ?? ''));
            if ($code === 'DCSo') {
                $code = 'CS';
            }
            if ($code === '') {
                $code = 'Outros';
            }

            $rawName = trim((string)($r['department'] ?? ''));
            $cleanName = self::DEPARTMENT_MAP[$code] ?? $rawName;
            if (preg_match('/^Departamento \(([A-Za-z]+)\)$/', $cleanName, $m)) {
                $cleanName = self::DEPARTMENT_MAP[$m[1]] ?? $cleanName;
            }

            if (!isset($aggregated[$code])) {
                $aggregated[$code] = [
                    'departmentCode' => $code,
                    'department' => $cleanName,
                    'totalFaculty' => 0,
                    'totalProductions' => 0,
                ];
            }
            $aggregated[$code]['totalFaculty'] += (int)$r['totalFaculty'];
            $aggregated[$code]['totalProductions'] += (int)$r['totalProductions'];
        }

        usort($aggregated, function($a, $b) {
            if ($a['totalFaculty'] !== $b['totalFaculty']) {
                return $b['totalFaculty'] <=> $a['totalFaculty'];
            }
            return $b['totalProductions'] <=> $a['totalProductions'];
        });

        return array_slice(array_values($aggregated), 0, $limit);
    }

    /**
     * Finds featured researchers (most productive).
     *
     * @param int $limit Number of researchers
     * @return Researcher[]
     */
    public function findFeaturedResearchers(int $limit = 8): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r AS researcher', 'COUNT(DISTINCT p.id) AS a1Count')
            ->leftJoin('r.productions', 'p', 'WITH', 'p.itemType = :artigoType AND p.qualis = :qualisA1')
            ->leftJoin('r.productions', 'pAll')
            ->setParameter('artigoType', ProductionItem::TYPE_ARTIGO)
            ->setParameter('qualisA1', 'A1')
            ->groupBy('r.id')
            ->orderBy('a1Count', 'DESC')
            ->addOrderBy('COUNT(DISTINCT pAll.id)', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(function ($row) {
            $researcher = $row['researcher'];
            $researcher->a1Count = (int)$row['a1Count'];
            return $researcher;
        }, $rows);
    }

    /**
     * Finds researchers by department code or name.
     *
     * @param string $codeOrName Department code (e.g. 'LE') or full name
     * @return Researcher[]
     */
    public function findByDepartmentCodeOrName(string $codeOrName): array
    {
        $depts = self::getDepartmentFilterValues($codeOrName);

        return $this->createQueryBuilder('r')
            ->where('r.departmentCode IN (:depts) OR r.department IN (:depts)')
            ->setParameter('depts', $depts)
            ->orderBy('r.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns a list of all distinct departments normalized and cleaned.
     *
     * @return array Array with code, name, and facultyCount
     */
    public function findAllDistinctDepartments(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.departmentCode as code, r.department as name, COUNT(r.id) as facultyCount')
            ->where('r.status = 1 AND ((r.department IS NOT NULL AND r.department != \'\') OR (r.departmentCode IS NOT NULL AND r.departmentCode != \'\'))')
            ->groupBy('r.departmentCode, r.department')
            ->getQuery()
            ->getResult();

        $aggregated = [];
        foreach ($rows as $r) {
            $code = trim((string)($r['code'] ?? ''));
            if ($code === 'DCSo') {
                $code = 'CS';
            }
            if ($code === '') {
                $code = 'Outros';
            }

            $rawName = trim((string)($r['name'] ?? ''));
            $cleanName = self::DEPARTMENT_MAP[$code] ?? $rawName;
            if (preg_match('/^Departamento \(([A-Za-z]+)\)$/', $cleanName, $m)) {
                $cleanName = self::DEPARTMENT_MAP[$m[1]] ?? $cleanName;
            }

            if (!isset($aggregated[$code])) {
                $aggregated[$code] = [
                    'code' => $code,
                    'name' => $cleanName,
                    'facultyCount' => 0,
                ];
            }
            $aggregated[$code]['facultyCount'] += (int)$r['facultyCount'];
        }

        usort($aggregated, fn($a, $b) => strcoll($a['name'], $b['name']));

        return array_values($aggregated);
    }

    /**
     * Efficiently loads a Researcher with productions, production authors, matched researchers and author identities to eliminate N+1 queries and lazy proxy overhead.
     */
    public function findWithAllDetails(string|int $slugOrId): ?Researcher
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.productions', 'p')->addSelect('p')
            ->leftJoin('p.authors', 'pa')->addSelect('pa')
            ->leftJoin('pa.matchedResearcher', 'pmr')->addSelect('pmr')
            ->leftJoin('pa.authorIdentity', 'pai')->addSelect('pai');

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

