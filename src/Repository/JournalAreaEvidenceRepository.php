<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JournalAreaEvidence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositório das evidências de área dos periódicos (tabela journal_area_evidence).
 *
 * @extends ServiceEntityRepository<JournalAreaEvidence>
 */
class JournalAreaEvidenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalAreaEvidence::class);
    }
}
