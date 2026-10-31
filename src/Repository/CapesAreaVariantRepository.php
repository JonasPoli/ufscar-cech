<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CapesArea;
use App\Entity\CapesAreaVariant;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositório do tesauro de categorias de revistas (tabela capes_area_variants).
 *
 * Além das consultas da tela do admin, é o dono das correções manuais: {@see self::defineArea()},
 * {@see self::ignore()}, {@see self::addSynonym()} e {@see self::deleteManual()}. Toda correção grava linhas
 * `origin = 'manual'`, que o comando `app:journals:areas:thesaurus` (JournalAreaThesaurus::sync) nunca apaga nem
 * recria. Definir a área ou ignorar um rótulo substitui todas as linhas do mesmo (`vocabulary`, `normalized_name`), as
 * da semente e as pendentes, e herda delas o `occurrences` (o número só é recalculado na próxima execução do comando).
 *
 * @extends ServiceEntityRepository<CapesAreaVariant>
 */
class CapesAreaVariantRepository extends ServiceEntityRepository
{
    /** Tamanho máximo do rótulo, o mesmo das colunas `variation_name` e `normalized_name`. */
    public const LABEL_MAX = 255;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CapesAreaVariant::class);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Consultas
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Quantas linhas há em cada status (`active`, `pending`, `ignored`); status sem linhas aparece com 0.
     *
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        $counts = [
            CapesAreaVariant::STATUS_ACTIVE => 0,
            CapesAreaVariant::STATUS_PENDING => 0,
            CapesAreaVariant::STATUS_IGNORED => 0,
        ];

        $rows = $this->createQueryBuilder('v')
            ->select('v.status AS status, COUNT(v.id) AS total')
            ->groupBy('v.status')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Quantas linhas são correções manuais.
     */
    public function countManual(): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.origin = :origin')
            ->setParameter('origin', CapesAreaVariant::ORIGIN_MANUAL)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Uma página das linhas de um status, por `occurrences` decrescente e depois pelo rótulo. A busca procura o texto no
     * rótulo e na forma do tesauro (contém; o `LIKE` do banco já ignora caixa e acentos). A página pedida é limitada ao
     * intervalo existente.
     *
     * @param string|null $vocabulary só as linhas deste vocabulário
     * @param CapesArea|null $area só as linhas desta área
     *
     * @return array{items: list<CapesAreaVariant>, total: int, page: int, totalPages: int}
     */
    public function paginate(string $status, string $search, ?string $vocabulary, ?CapesArea $area, int $page, int $perPage): array
    {
        $total = (int) $this->filtered($status, $search, $vocabulary, $area)
            ->select('COUNT(v.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);

        $items = $this->filtered($status, $search, $vocabulary, $area)
            ->leftJoin('v.area', 'a')->addSelect('a')
            ->leftJoin('a.parent', 'p')->addSelect('p')
            ->orderBy('v.occurrences', 'DESC')
            ->addOrderBy('v.variationName', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'totalPages' => $totalPages];
    }

    /**
     * Todas as linhas do tesauro com a área e a grande área já carregadas, para a exportação.
     *
     * @return list<CapesAreaVariant>
     */
    public function findAllForExport(): array
    {
        return $this->createQueryBuilder('v')
            ->leftJoin('v.area', 'a')->addSelect('a')
            ->leftJoin('a.parent', 'p')->addSelect('p')
            ->orderBy('v.vocabulary', 'ASC')
            ->addOrderBy('v.variationName', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // ------------------------------------------------------------------------------------------------------------
    // Correções manuais
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Define a área de um rótulo: o rótulo (`vocabulary` + `normalized_name` da linha dada) passa a ter uma única linha
     * `manual` e `active`, e as demais linhas dele (semente, pendente, ignorada) saem.
     */
    public function defineArea(CapesAreaVariant $variant, CapesArea $area): CapesAreaVariant
    {
        return $this->replaceLabel($variant->getVocabulary(), $variant->getNormalizedName(), $variant->getVariationName(), $area);
    }

    /**
     * Decide que o rótulo não diz nada sobre o assunto: uma única linha `manual` e `ignored`, sem área.
     */
    public function ignore(CapesAreaVariant $variant): CapesAreaVariant
    {
        return $this->replaceLabel($variant->getVocabulary(), $variant->getNormalizedName(), $variant->getVariationName(), null);
    }

    /**
     * Cadastra um sinônimo de texto livre (vocabulário `livre`, `manual`) para a área. Um mesmo rótulo pode ter vários
     * sinônimos, um por área. O rótulo que estava pendente ou ignorado deixa de estar. Devolve null se o sinônimo
     * (rótulo e área) já existe.
     *
     * @throws \InvalidArgumentException rótulo vazio, sem letras ou maior que {@see self::LABEL_MAX}
     */
    public function addSynonym(string $label, CapesArea $area): ?CapesAreaVariant
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        if ($label === '' || preg_match('/\p{L}/u', $label) !== 1) {
            throw new \InvalidArgumentException('Informe um rótulo com letras.');
        }
        if (mb_strlen($label, 'UTF-8') > self::LABEL_MAX) {
            throw new \InvalidArgumentException(sprintf('O rótulo pode ter no máximo %d caracteres.', self::LABEL_MAX));
        }

        $normalized = mb_substr(StringNormalizer::normalizeString($label, true), 0, self::LABEL_MAX, 'UTF-8');
        if ($normalized === '') {
            throw new \InvalidArgumentException('Informe um rótulo com letras.');
        }

        $em = $this->getEntityManager();

        return $em->wrapInTransaction(function () use ($em, $label, $normalized, $area): ?CapesAreaVariant {
            $occurrences = 0;
            $stale = [];
            foreach ($this->findBy(['vocabulary' => CapesAreaVariant::VOCABULARY_LIVRE, 'normalizedName' => $normalized]) as $row) {
                if ($row->getStatus() === CapesAreaVariant::STATUS_ACTIVE) {
                    if ($row->getArea()?->getId() === $area->getId()) {
                        return null;
                    }
                    continue;
                }
                $stale[] = $row;
            }

            foreach ($stale as $row) {
                $occurrences = max($occurrences, $row->getOccurrences());
                $em->remove($row);
            }
            $em->flush();

            $synonym = $this->newManual(CapesAreaVariant::VOCABULARY_LIVRE, $normalized, $label, $area, $occurrences);
            $em->persist($synonym);

            return $synonym;
        });
    }

    /**
     * Exclui uma correção manual. Linha da semente não se exclui (o comando a recriaria): devolve false e nada muda.
     * Depois de excluir, a próxima execução do comando recria a semente do rótulo, se houver.
     */
    public function deleteManual(CapesAreaVariant $variant): bool
    {
        if (!$variant->isManual()) {
            return false;
        }

        $em = $this->getEntityManager();
        $em->remove($variant);
        $em->flush();

        return true;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Infraestrutura
    // ------------------------------------------------------------------------------------------------------------

    private function filtered(string $status, string $search, ?string $vocabulary, ?CapesArea $area): QueryBuilder
    {
        $qb = $this->createQueryBuilder('v')
            ->andWhere('v.status = :status')
            ->setParameter('status', $status);

        if ($search !== '') {
            // Escapa os curingas do LIKE: a busca é texto, não padrão
            $or = $qb->expr()->orX('v.variationName LIKE :search');
            $qb->setParameter('search', '%' . addcslashes($search, '%_\\') . '%');

            $normalized = StringNormalizer::normalizeString($search, true);
            if ($normalized !== '') {
                $or->add('v.normalizedName LIKE :normalized');
                $qb->setParameter('normalized', '%' . addcslashes($normalized, '%_\\') . '%');
            }
            $qb->andWhere($or);
        }

        if ($vocabulary !== null) {
            $qb->andWhere('v.vocabulary = :vocabulary')->setParameter('vocabulary', $vocabulary);
        }

        if ($area !== null) {
            $qb->andWhere('v.area = :area')->setParameter('area', $area);
        }

        return $qb;
    }

    /**
     * Troca todas as linhas de (`vocabulary`, `normalized_name`) por uma só, manual: com a área, `active`; sem área,
     * `ignored`. O `occurrences` das linhas trocadas (o maior) passa para a nova.
     */
    private function replaceLabel(string $vocabulary, string $normalized, string $label, ?CapesArea $area): CapesAreaVariant
    {
        $em = $this->getEntityManager();

        return $em->wrapInTransaction(function () use ($em, $vocabulary, $normalized, $label, $area): CapesAreaVariant {
            $occurrences = 0;
            foreach ($this->findBy(['vocabulary' => $vocabulary, 'normalizedName' => $normalized]) as $row) {
                $occurrences = max($occurrences, $row->getOccurrences());
                $em->remove($row);
            }
            // Apaga antes de inserir: o índice único vale para a mesma área
            $em->flush();

            $manual = $this->newManual($vocabulary, $normalized, $label, $area, $occurrences);
            $em->persist($manual);

            return $manual;
        });
    }

    private function newManual(string $vocabulary, string $normalized, string $label, ?CapesArea $area, int $occurrences): CapesAreaVariant
    {
        return (new CapesAreaVariant())
            ->setVocabulary($vocabulary)
            ->setVariationName($label)
            ->setNormalizedName($normalized)
            ->setArea($area)
            ->setOrigin(CapesAreaVariant::ORIGIN_MANUAL)
            ->setStatus($area === null ? CapesAreaVariant::STATUS_IGNORED : CapesAreaVariant::STATUS_ACTIVE)
            ->setOccurrences($occurrences);
    }
}
