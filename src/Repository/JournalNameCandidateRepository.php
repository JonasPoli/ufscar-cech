<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JournalNameCandidate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositório da fila de revisão de nomes de periódicos (tabela journal_name_candidates).
 *
 * @extends ServiceEntityRepository<JournalNameCandidate>
 */
class JournalNameCandidateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalNameCandidate::class);
    }

    /**
     * Quantos nomes estão no status dado (pending, ambiguous, accepted ou rejected).
     */
    public function countByStatus(string $status): int
    {
        return (int)$this->filtered($status, '')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Uma página da fila de um status, em ordem de `articles_count` decrescente. A busca procura em `raw_name`
     * (contém, sem diferenciar maiúsculas nem acentos). A página pedida é limitada ao intervalo existente.
     *
     * @return array{items: list<JournalNameCandidate>, total: int, page: int, totalPages: int}
     */
    public function paginateByStatus(string $status, string $search, int $page, int $perPage): array
    {
        $total = (int)$this->filtered($status, $search)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);

        $items = $this->filtered($status, $search)
            ->leftJoin('c.suggestedJournal', 's')->addSelect('s')
            ->leftJoin('c.resolvedJournal', 'r')->addSelect('r')
            ->orderBy('c.articlesCount', 'DESC')
            ->addOrderBy('c.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'totalPages' => $totalPages];
    }

    private function filtered(string $status, string $search): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.status = :status')
            ->setParameter('status', $status);

        if ($search !== '') {
            // Escapa os curingas do LIKE: a busca é texto, não padrão
            $qb->andWhere('c.rawName LIKE :search')
                ->setParameter('search', '%' . addcslashes($search, '%_\\') . '%');
        }

        return $qb;
    }
}
