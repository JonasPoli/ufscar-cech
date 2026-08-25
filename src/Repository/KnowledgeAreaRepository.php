<?php

namespace App\Repository;

use App\Entity\KnowledgeArea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KnowledgeArea>
 */
class KnowledgeAreaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KnowledgeArea::class);
    }

    /**
     * Get knowledge area distribution
     */
    public function getMajorAreaDistribution(): array
    {
        return $this->createQueryBuilder('k')
            ->select('k.majorArea, COUNT(k.id) as total')
            ->where('k.majorArea IS NOT NULL AND k.majorArea != \'\'')
            ->groupBy('k.majorArea')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
