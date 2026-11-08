<?php

namespace App\Repository;

use Doctrine\ORM\QueryBuilder;
use App\Service\Research\ResearchSql;
use App\Service\Research\ResearchFilter;
use App\Service\Research\MajorAreaCatalog;
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
            // Situação efetiva: concluída no Lattes ou catalogada no Repositório UFSCar (Orientation::isConcluded)
            ->select("o.year, o.orientationType, CASE WHEN o.nature = 'CONCLUIDA' OR o.handle IS NOT NULL OR o.repositoryUuid IS NOT NULL THEN 'CONCLUIDA' ELSE o.nature END AS nature, COUNT(o.id) as total")
            ->where('o.year IS NOT NULL AND o.year >= 1970 AND o.year <= :curYear')
            ->setParameter('curYear', (int) date('Y') + 1)
            ->groupBy('o.year, o.orientationType, nature')
            ->orderBy('o.year', 'ASC');

        if ($researcherId) {
            $qb->andWhere('o.researcher = :resId')
               ->setParameter('resId', $researcherId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Orientações (teses, dissertações e demais) no recorte do filtro da busca: texto em título, aluno, palavras-chave
     * ou título alternativo; tema; departamento, grande área, área e vínculo do docente; período pelo ano. O tipo de
     * publicação não se aplica a orientações.
     *
     * @return Orientation[]
     */
    public function searchOrientations(ResearchFilter $filter, int $page = 1, int $limit = 15): array
    {
        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.researcher', 'r')
            ->addSelect('r')
            ->orderBy('o.year', 'DESC')
            ->addOrderBy('o.id', 'DESC');
        $this->applyFilter($qb, $filter);

        return $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();
    }

    /** Total de {@see searchOrientations()}. */
    public function countSearchOrientations(ResearchFilter $filter): int
    {
        $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->leftJoin('o.researcher', 'r');
        $this->applyFilter($qb, $filter);

        return (int)$qb->getQuery()->getSingleScalarResult();
    }

    private function applyFilter(QueryBuilder $qb, ResearchFilter $filter): void
    {
        $textFields = 'o.title LIKE %1$s OR o.studentName LIKE %1$s OR o.keywords LIKE %1$s OR o.alternativeTitle LIKE %1$s';
        if ($filter->q !== '') {
            $qb->andWhere(sprintf($textFields, ':query'))->setParameter('query', ResearchSql::likePattern($filter->q));
        }
        if ($filter->topicText !== '') {
            $qb->andWhere(sprintf($textFields, ':topic'))->setParameter('topic', ResearchSql::likePattern($filter->topicText));
        }
        if ($filter->department !== '') {
            $qb->andWhere('r.department IN (:depts) OR r.departmentCode IN (:depts)')
               ->setParameter('depts', ResearcherRepository::getDepartmentFilterValues($filter->department));
        }
        if ($filter->majorArea !== '') {
            $qb->andWhere('EXISTS (SELECT 1 FROM App\Entity\KnowledgeArea ka1 WHERE ka1.researcher = r AND ka1.majorArea IN (:majors))')
               ->setParameter('majors', MajorAreaCatalog::storedValues($filter->majorArea));
        }
        if ($filter->area !== '') {
            $qb->andWhere('EXISTS (SELECT 1 FROM App\Entity\KnowledgeArea ka2 WHERE ka2.researcher = r AND ka2.area = :area)')
               ->setParameter('area', $filter->area);
        }
        if ($filter->affiliation !== '') {
            $qb->andWhere('r.status = :active')->setParameter('active', $filter->affiliation === 'vigente');
        }
        if ($filter->from !== null) {
            $qb->andWhere('o.year >= :from')->setParameter('from', $filter->from);
        }
        if ($filter->to !== null) {
            $qb->andWhere('o.year <= :to')->setParameter('to', $filter->to);
        }
    }
}
