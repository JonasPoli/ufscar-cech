<?php

namespace App\Repository;

use App\Entity\QualisJournal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QualisJournal>
 */
class QualisJournalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QualisJournal::class);
    }

    /**
     * Localiza um periódico buscando em qualquer um dos 3 campos de ISSN (issn_e, issn_l, issn_imp) ou o issn geral.
     */
    public function findByAnyIssn(?string $issn): ?QualisJournal
    {
        if (!$issn) return null;
        $norm = QualisJournal::normalizeIssnString($issn);
        if (!$norm) return null;

        return $this->createQueryBuilder('q')
            ->where('q.normalizedIssn = :norm')
            ->orWhere('q.normalizedIssnE = :norm')
            ->orWhere('q.normalizedIssnL = :norm')
            ->orWhere('q.normalizedIssnImp = :norm')
            ->setParameter('norm', $norm)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Localiza periódicos que correspondam a qualquer um dos ISSNs fornecidos.
     *
     * @param array<string> $issns
     * @return array<QualisJournal>
     */
    public function findAllByAnyIssns(array $issns): array
    {
        $norms = [];
        foreach ($issns as $issn) {
            $norm = QualisJournal::normalizeIssnString($issn);
            if ($norm) {
                $norms[$norm] = true;
            }
        }

        if (empty($norms)) {
            return [];
        }

        $normList = array_keys($norms);

        return $this->createQueryBuilder('q')
            ->where('q.normalizedIssn IN (:norms)')
            ->orWhere('q.normalizedIssnE IN (:norms)')
            ->orWhere('q.normalizedIssnL IN (:norms)')
            ->orWhere('q.normalizedIssnImp IN (:norms)')
            ->setParameter('norms', $normList)
            ->getQuery()
            ->getResult();
    }
}
