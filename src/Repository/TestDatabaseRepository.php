<?php

namespace App\Repository;

use App\Entity\TestDatabase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TestDatabase>
 */
class TestDatabaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TestDatabase::class);
    }

    /**
     * @return TestDatabase[]
     */
    public function search(?string $term, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.id', 'DESC')
            ->setMaxResults($limit);

        if ($term) {
            $qb
                ->andWhere('LOWER(t.name) LIKE :term')
                ->setParameter('term', '%'.mb_strtolower($term).'%');
        }

        return $qb->getQuery()->getResult();
    }
}
