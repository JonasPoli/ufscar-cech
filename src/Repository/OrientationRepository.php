<?php

namespace App\Repository;

use App\Entity\Orientation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Orientation>
 */
class OrientationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Orientation::class);
    }

    /**
     * Get orientation evolution by year
     */
    public function getOrientationEvolution(?int $researcherId = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->select('o.year, o.orientationType, o.nature, COUNT(o.id) as total')
            ->where('o.year IS NOT NULL AND o.year >= 1970 AND o.year <= :curYear')
            ->setParameter('curYear', (int) date('Y') + 1)
            ->groupBy('o.year, o.orientationType, o.nature')
            ->orderBy('o.year', 'ASC');

        if ($researcherId) {
            $qb->andWhere('o.researcher = :resId')
               ->setParameter('resId', $researcherId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Searches orientations by title, student name, alternative title, or keywords.
     *
     * @return Orientation[]
     */
    public function searchOrientations(?string $query = null, ?string $department = null, int $page = 1, int $limit = 15): array
    {
        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.researcher', 'r')
            ->addSelect('r')
            ->orderBy('o.year', 'DESC')
            ->addOrderBy('o.id', 'DESC');

        if ($query !== null && $query !== '') {
            $qb->andWhere('o.title LIKE :query OR o.studentName LIKE :query OR o.keywords LIKE :query OR o.alternativeTitle LIKE :query')
               ->setParameter('query', '%' . $query . '%');
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
     * Counts total search results for orientations.
     */
    public function countSearchOrientations(?string $query = null, ?string $department = null): int
    {
        $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->leftJoin('o.researcher', 'r');

        if ($query !== null && $query !== '') {
            $qb->andWhere('o.title LIKE :query OR o.studentName LIKE :query OR o.keywords LIKE :query OR o.alternativeTitle LIKE :query')
               ->setParameter('query', '%' . $query . '%');
        }

        if ($department !== null && $department !== '' && $department !== 'all') {
            $depts = ResearcherRepository::getDepartmentFilterValues($department);
            $qb->andWhere('r.department IN (:depts) OR r.departmentCode IN (:depts)')
               ->setParameter('depts', $depts);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
