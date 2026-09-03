<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ThematicTerm;
use App\Entity\ThematicTermResearcher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ThematicTermResearcher>
 */
class ThematicTermResearcherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThematicTermResearcher::class);
    }

    /**
     * Retorna os pesquisadores vinculados ao termo paginados de 10 em 10.
     *
     * @return array<int, array{
     *     researcherId: int,
     *     fullName: string,
     *     slug: ?string,
     *     idLattes: ?string,
     *     photoUrl: ?string,
     *     department: ?string,
     *     admissionYear: ?int,
     *     isActiveInCech: bool,
     *     occurrences: int,
     *     sampleTitles: array<string>
     * }>
     */
    public function getResearchersForTerm(ThematicTerm $term, int $offset = 0, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('tr')
            ->select(
                'r.id as researcherId',
                'r.fullName',
                'r.slug',
                'r.idLattes',
                'r.photoUrl',
                'r.department',
                'r.admissionYear',
                'r.leaveYear',
                'tr.occurrences',
                'tr.sampleTitles'
            )
            ->join('tr.researcher', 'r')
            ->where('tr.term = :term')
            ->setParameter('term', $term)
            ->orderBy('tr.occurrences', 'DESC')
            ->addOrderBy('r.fullName', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Conta o total de pesquisadores associados ao termo.
     */
    public function countResearchersForTerm(ThematicTerm $term): int
    {
        return (int)$this->createQueryBuilder('tr')
            ->select('COUNT(tr.id)')
            ->where('tr.term = :term')
            ->setParameter('term', $term)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retorna a distribuição por departamento dos docentes associados ao termo.
     *
     * @return array<int, array{department: string, total: int}>
     */
    public function getTopDepartmentsForTerm(ThematicTerm $term, int $limit = 5): array
    {
        return $this->createQueryBuilder('tr')
            ->select('r.department, COUNT(DISTINCT r.id) as total')
            ->join('tr.researcher', 'r')
            ->where('tr.term = :term')
            ->andWhere('r.department IS NOT NULL AND r.department != \'\'')
            ->setParameter('term', $term)
            ->groupBy('r.department')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }
}
