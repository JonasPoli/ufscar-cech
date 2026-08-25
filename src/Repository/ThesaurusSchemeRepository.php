<?php

namespace App\Repository;

use App\Entity\ThesaurusScheme;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ThesaurusScheme>
 */
class ThesaurusSchemeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThesaurusScheme::class);
    }
}
