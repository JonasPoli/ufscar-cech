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
}
