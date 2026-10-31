<?php

namespace App\Repository;

use App\Entity\QualisJournal;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QualisJournal>
 */
class QualisJournalRepository extends ServiceEntityRepository
{
    /** Menor quantidade de letras do termo para a busca por nome: um `%ab%` casaria com metade do cadastro. */
    private const MIN_NAME_LETTERS = 3;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QualisJournal::class);
    }

    /**
     * Ids de todas as linhas das famílias de periódicos cujo nome contém o termo.
     *
     * Família é `COALESCE(canonical_journal_id, id)`. O termo, na forma do tesauro (`StringNormalizer::normalizeString($s, true)`:
     * maiúsculas, sem acento nem pontuação), é procurado por LIKE em `normalized_title`, em `display_title` e nas variantes
     * ativas (`journal_name_variants.normalized_name`); a família entra se qualquer linha dela casa, e o resultado traz
     * todas as linhas da família, porque `production_items.qualis_journal_id` aponta para a linha que o resolver escolheu
     * (a do ISSN ou a do título), não para a canônica.
     *
     * As famílias seguem por relevância (nome igual ao termo, nome que começa por ele, nome que o contém; empate pelo id) e
     * `$limit` corta a quantidade de famílias, não de linhas. Os ids saem agrupados por família, na ordem das famílias, com
     * a linha canônica primeiro. Termo com menos de 3 letras devolve vazio.
     *
     * @return list<int>
     */
    public function findFamilyRowIdsByName(string $term, int $limit = 300): array
    {
        $needle = StringNormalizer::normalizeString($term, true);
        if ($limit < 1 || (int) preg_match_all('/\p{L}/u', $needle) < self::MIN_NAME_LETTERS) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        // O LIKE de `display_title` usa a collation do banco (utf8mb4_unicode_ci), que ignora caixa e acento
        $familyIds = array_map('intval', $conn->fetchFirstColumn(
            <<<SQL
            SELECT m.fid
            FROM (
                SELECT COALESCE(q.canonical_journal_id, q.id) AS fid,
                       CASE WHEN q.normalized_title = :exact THEN 0 WHEN q.normalized_title LIKE :prefix THEN 1 ELSE 2 END AS score
                FROM qualis_journals q
                WHERE q.normalized_title LIKE :contains OR q.display_title LIKE :contains
                UNION ALL
                SELECT COALESCE(q.canonical_journal_id, q.id) AS fid,
                       CASE WHEN v.normalized_name = :exact THEN 0 WHEN v.normalized_name LIKE :prefix THEN 1 ELSE 2 END AS score
                FROM journal_name_variants v
                JOIN qualis_journals q ON q.id = v.journal_id
                WHERE v.status = 1 AND v.normalized_name LIKE :contains
            ) m
            GROUP BY m.fid
            ORDER BY MIN(m.score), m.fid
            LIMIT :limit
            SQL,
            ['exact' => $needle, 'prefix' => $needle . '%', 'contains' => '%' . $needle . '%', 'limit' => $limit],
            ['limit' => ParameterType::INTEGER]
        ));
        if ($familyIds === []) {
            return [];
        }

        $rowsByFamily = [];
        foreach ($conn->fetchAllAssociative(
            'SELECT id, COALESCE(canonical_journal_id, id) AS fid FROM qualis_journals WHERE id IN (:fids) OR canonical_journal_id IN (:fids)',
            ['fids' => $familyIds],
            ['fids' => ArrayParameterType::INTEGER]
        ) as $row) {
            $rowsByFamily[(int) $row['fid']][] = (int) $row['id'];
        }

        $ids = [];
        foreach ($familyIds as $familyId) {
            $rows = $rowsByFamily[$familyId] ?? [];
            // A canônica primeiro (id = família), depois os membros por id
            usort($rows, static fn (int $a, int $b): int => [$a !== $familyId, $a] <=> [$b !== $familyId, $b]);
            array_push($ids, ...$rows);
        }

        return $ids;
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
