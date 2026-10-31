<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CapesArea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositório das áreas da taxonomia CAPES (tabela capes_areas, semeada pela migration).
 *
 * @extends ServiceEntityRepository<CapesArea>
 */
class CapesAreaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CapesArea::class);
    }
}
