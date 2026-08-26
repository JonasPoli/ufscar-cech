<?php

namespace App\Repository;

use App\Entity\AcademicDatabase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AcademicDatabase>
 */
class AcademicDatabaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcademicDatabase::class);
    }

    public function findByAcronym(string $acronym): ?AcademicDatabase
    {
        return $this->findOneBy(['acronym' => strtolower(trim($acronym))]);
    }
}
