<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ThematicTerm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ThematicTerm>
 */
class ThematicTermRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThematicTerm::class);
    }

    /**
     * Busca termos com base em texto parcial normalizado (autocomplete em tempo real).
     *
     * @return array<int, array{id: int, term: string, slug: string, totalOccurrences: int, researcherCount: int, weight: int}>
     */
    public function searchTerms(string $query, int $limit = 24): array
    {
        $normalized = $this->normalizeString($query);
        if (mb_strlen($normalized) < 2) {
            return [];
        }

        // Prioriza termos que começam com a busca, seguido por termos que contêm a busca
        $qb = $this->createQueryBuilder('t')
            ->select('t.id, t.term, t.slug, t.totalOccurrences, t.researcherCount')
            ->where('t.normalizedTerm LIKE :prefix OR t.normalizedTerm LIKE :contains')
            ->setParameter('prefix', $normalized . '%')
            ->setParameter('contains', '%' . $normalized . '%')
            ->orderBy('CASE WHEN t.normalizedTerm LIKE :exactPrefix THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('t.totalOccurrences', 'DESC')
            ->addOrderBy('t.researcherCount', 'DESC')
            ->setParameter('exactPrefix', $normalized . '%')
            ->setMaxResults($limit);

        $results = $qb->getQuery()->getArrayResult();

        return $this->attachWeights($results);
    }

    /**
     * Retorna os termos mais frequentes globais para a visão inicial.
     *
     * @return array<int, array{id: int, term: string, slug: string, totalOccurrences: int, researcherCount: int, weight: int}>
     */
    public function getTopFeaturedTerms(int $limit = 24): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.id, t.term, t.slug, t.totalOccurrences, t.researcherCount')
            ->where('t.totalOccurrences >= 2')
            ->orderBy('t.totalOccurrences', 'DESC')
            ->addOrderBy('t.researcherCount', 'DESC')
            ->setMaxResults($limit);

        $results = $qb->getQuery()->getArrayResult();

        return $this->attachWeights($results);
    }

    /**
     * Encontra um termo por slug amigável ou ID numérico.
     */
    public function findBySlugOrId(string $slugOrId): ?ThematicTerm
    {
        if (ctype_digit($slugOrId)) {
            return $this->find((int)$slugOrId);
        }

        return $this->findOneBy(['slug' => $slugOrId]);
    }

    /**
     * Retorna estatísticas gerais de termos.
     *
     * @return array{totalTerms: int, maxOccurrences: int}
     */
    public function getGlobalStats(): array
    {
        $total = (int)$this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $max = (int)$this->createQueryBuilder('t')
            ->select('MAX(t.totalOccurrences)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'totalTerms' => $total,
            'maxOccurrences' => $max,
        ];
    }

    /**
     * Atribui peso relativo (1 a 100) para gradiente de cores da lista.
     *
     * @param array<int, array{id: int, term: string, slug: string, totalOccurrences: int, researcherCount: int}> $items
     * @return array<int, array{id: int, term: string, slug: string, totalOccurrences: int, researcherCount: int, weight: int}>
     */
    private function attachWeights(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $maxCount = (int)($items[0]['totalOccurrences'] ?? 1);
        if ($maxCount <= 0) {
            $maxCount = 1;
        }

        foreach ($items as &$item) {
            $count = (int)($item['totalOccurrences'] ?? 0);
            $item['weight'] = (int)round(($count / $maxCount) * 100);
        }

        return $items;
    }

    private function normalizeString(string $str): string
    {
        $clean = mb_strtolower(trim($str), 'UTF-8');
        $clean = (string)preg_replace('/[áàãâä]/u', 'a', $clean);
        $clean = (string)preg_replace('/[éèêë]/u', 'e', $clean);
        $clean = (string)preg_replace('/[íìîï]/u', 'i', $clean);
        $clean = (string)preg_replace('/[óòõôö]/u', 'o', $clean);
        $clean = (string)preg_replace('/[úùûü]/u', 'u', $clean);
        $clean = (string)preg_replace('/[ç]/u', 'c', $clean);
        $clean = (string)preg_replace('/[ñ]/u', 'n', $clean);
        $clean = trim((string)preg_replace('/[^a-z0-9\s]/u', ' ', $clean));
        return (string)preg_replace('/\s+/', ' ', $clean);
    }
}
