<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductionItem;
use App\Entity\ThematicTerm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @extends ServiceEntityRepository<ThematicTerm>
 */
class ThematicTermRepository extends ServiceEntityRepository
{
    /**
     * Prefixo (com a versão do formato) da chave de cache da análise editorial. Mude a versão quando o formato do
     * resultado mudar: o cache de 24 horas não pode servir o formato antigo.
     */
    private const EDITORIAL_CACHE_PREFIX = 'thematic_editorial_v2_';

    /**
     * Chave de artigo distinto: DOI normalizado ou ano + título, a mesma de `/periodicos`
     * (`JournalsByDatabaseService::ARTICLE_KEY`) e de `StatisticsService`, de modo que coautorias entre docentes do
     * Centro contam uma vez.
     */
    private const ARTICLE_KEY = "CASE WHEN pi.doi IS NOT NULL AND TRIM(pi.doi) <> '' "
        . "THEN CONCAT('DOI:', LOWER(TRIM(pi.doi))) "
        . "ELSE CONCAT('TITLE:', COALESCE(pi.year, ''), ':', LOWER(TRIM(pi.title))) END";

    public function __construct(
        ManagerRegistry $registry,
        private readonly ?CacheInterface $cache = null
    ) {
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

    /**
     * Retorna a linha do tempo ano a ano da evolução dos trabalhos que possuem este tema,
     * do primeiro ano registrado até o ano corrente.
     *
     * @return array{
     *     startYear: ?int,
     *     endYear: int,
     *     years: array<int>,
     *     totalWorks: array<int>,
     *     productions: array<int>,
     *     orientations: array<int>,
     *     peakYear: ?int,
     *     peakCount: int,
     *     grandTotal: int
     * }
     */
    public function getYearlyTimelineForTerm(ThematicTerm $term): array
    {
        $cacheKey = 'thematic_timeline_' . ($term->getSlug() ?: (string)$term->getId());

        if ($this->cache !== null) {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($term) {
                $item->expiresAfter(86400); // 24 horas
                return $this->computeYearlyTimeline($term);
            });
        }

        return $this->computeYearlyTimeline($term);
    }

    /**
     * @return array{
     *     startYear: ?int,
     *     endYear: int,
     *     years: array<int>,
     *     totalWorks: array<int>,
     *     productions: array<int>,
     *     orientations: array<int>,
     *     peakYear: ?int,
     *     peakCount: int,
     *     grandTotal: int
     * }
     */
    private function computeYearlyTimeline(ThematicTerm $term): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $currentYear = (int)date('Y');
        $termStr = $term->getTerm() ?? '';
        $normStr = $term->getNormalizedTerm() ?? '';
        $searchTerm = $normStr !== '' ? $normStr : $termStr;
        $termPattern = '%' . $searchTerm . '%';

        $sql = "
        SELECT year, SUM(total_works) as total_works, SUM(prods) as prods, SUM(orients) as orients FROM (
            SELECT year, COUNT(DISTINCT LOWER(TRIM(title))) as total_works, COUNT(DISTINCT LOWER(TRIM(title))) as prods, 0 as orients
            FROM production_items
            WHERE (title LIKE :termPattern OR CAST(extra_data AS CHAR) LIKE :termPattern)
              AND year IS NOT NULL AND year >= 1950 AND year <= :currentYear
            GROUP BY year
            UNION ALL
            SELECT year, COUNT(DISTINCT LOWER(TRIM(title))) as total_works, 0 as prods, COUNT(DISTINCT LOWER(TRIM(title))) as orients
            FROM orientations
            WHERE (title LIKE :termPattern OR alternative_title LIKE :termPattern OR keywords LIKE :termPattern)
              AND year IS NOT NULL AND year >= 1950 AND year <= :currentYear
            GROUP BY year
        ) combined
        GROUP BY year
        ORDER BY year ASC
        ";

        $rows = $conn->fetchAllAssociative($sql, [
            'termPattern' => $termPattern,
            'currentYear' => $currentYear,
        ]);

        if (empty($rows)) {
            return [
                'startYear' => null,
                'endYear' => $currentYear,
                'years' => [],
                'totalWorks' => [],
                'productions' => [],
                'orientations' => [],
                'peakYear' => null,
                'peakCount' => 0,
                'grandTotal' => 0,
            ];
        }

        $firstYear = (int)$rows[0]['year'];
        $byYear = [];
        foreach ($rows as $r) {
            $y = (int)$r['year'];
            $byYear[$y] = [
                'total' => (int)$r['total_works'],
                'prods' => (int)$r['prods'],
                'orients' => (int)$r['orients'],
            ];
        }

        $years = [];
        $totalWorks = [];
        $productions = [];
        $orientations = [];
        $peakYear = $firstYear;
        $peakCount = 0;
        $grandTotal = 0;

        for ($y = $firstYear; $y <= $currentYear; $y++) {
            $years[] = $y;
            $t = $byYear[$y]['total'] ?? 0;
            $p = $byYear[$y]['prods'] ?? 0;
            $o = $byYear[$y]['orients'] ?? 0;

            $totalWorks[] = $t;
            $productions[] = $p;
            $orientations[] = $o;
            $grandTotal += $t;

            if ($t > $peakCount) {
                $peakCount = $t;
                $peakYear = $y;
            }
        }

        return [
            'startYear' => $firstYear,
            'endYear' => $currentYear,
            'years' => $years,
            'totalWorks' => $totalWorks,
            'productions' => $productions,
            'orientations' => $orientations,
            'peakYear' => $peakYear,
            'peakCount' => $peakCount,
            'grandTotal' => $grandTotal,
        ];
    }

    /**
     * Retorna os conceitos e palavras-chave que mais co-ocorrem com o termo selecionado.
     *
     * @return array<int, array{id: ?int, term: string, slug: ?string, count: int}>
     */
    public function getRelatedConcepts(ThematicTerm $term, int $limit = 14): array
    {
        $cacheKey = 'thematic_related_' . ($term->getSlug() ?: (string)$term->getId());

        if ($this->cache !== null) {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($term, $limit) {
                $item->expiresAfter(86400);
                return $this->computeRelatedConcepts($term, $limit);
            });
        }

        return $this->computeRelatedConcepts($term, $limit);
    }

    /**
     * @return array<int, array{id: ?int, term: string, slug: ?string, count: int}>
     */
    private function computeRelatedConcepts(ThematicTerm $term, int $limit = 14): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $termStr = $term->getTerm() ?? '';
        $normStr = $term->getNormalizedTerm() ?? '';
        $searchTerm = $normStr !== '' ? $normStr : $termStr;
        $termPattern = '%' . $searchTerm . '%';
        $selfNorm = $this->normalizeString($searchTerm);

        // 1. Palavras-chave das produções que casam com o termo
        $stmtProd = $conn->executeQuery(
            "SELECT extra_data FROM production_items WHERE (title LIKE :termPattern OR CAST(extra_data AS CHAR) LIKE :termPattern) AND extra_data LIKE '%keywords%'",
            ['termPattern' => $termPattern]
        );

        $kwCounts = [];

        while ($row = $stmtProd->fetchAssociative()) {
            $extra = json_decode((string)$row['extra_data'], true);
            if (!empty($extra['keywords']) && is_array($extra['keywords'])) {
                foreach ($extra['keywords'] as $kw) {
                    if (!is_string($kw)) continue;
                    $parts = preg_split('/[;,]/u', $kw) ?: [$kw];
                    foreach ($parts as $p) {
                        $pClean = trim(trim($p), " \t\n\r\0\x0B.,;:-/()[]{}\"'");
                        $pNorm = $this->normalizeString($pClean);
                        if (mb_strlen($pNorm) < 3 || $pNorm === $selfNorm) continue;
                        if (!isset($kwCounts[$pNorm])) {
                            $kwCounts[$pNorm] = ['canonical' => mb_convert_case($pClean, MB_CASE_TITLE, 'UTF-8'), 'count' => 0];
                        }
                        $kwCounts[$pNorm]['count']++;
                    }
                }
            }
        }
        unset($stmtProd);

        // 2. Palavras-chave das orientações que casam com o termo
        $stmtOrient = $conn->executeQuery(
            "SELECT keywords FROM orientations WHERE (title LIKE :termPattern OR alternative_title LIKE :termPattern OR keywords LIKE :termPattern) AND keywords IS NOT NULL AND keywords != ''",
            ['termPattern' => $termPattern]
        );

        while ($row = $stmtOrient->fetchAssociative()) {
            $kwStr = (string)$row['keywords'];
            $parts = preg_split('/[;,]/u', $kwStr) ?: [];
            foreach ($parts as $p) {
                $pClean = trim(trim($p), " \t\n\r\0\x0B.,;:-/()[]{}\"'");
                $pNorm = $this->normalizeString($pClean);
                if (mb_strlen($pNorm) < 3 || $pNorm === $selfNorm) continue;
                if (!isset($kwCounts[$pNorm])) {
                    $kwCounts[$pNorm] = ['canonical' => mb_convert_case($pClean, MB_CASE_TITLE, 'UTF-8'), 'count' => 0];
                }
                $kwCounts[$pNorm]['count']++;
            }
        }
        unset($stmtOrient);

        if (empty($kwCounts)) {
            return [];
        }

        uasort($kwCounts, fn($a, $b) => $b['count'] <=> $a['count']);
        $topList = array_slice($kwCounts, 0, $limit, true);

        // 3. Buscar correspondência em thematic_terms para obter slugs oficiais e IDs
        $normalizedList = array_keys($topList);
        $matchedTerms = $conn->fetchAllAssociative(
            'SELECT id, term, slug, normalized_term FROM thematic_terms WHERE normalized_term IN (?)',
            [$normalizedList],
            [ArrayParameterType::STRING]
        );

        $matchedMap = [];
        foreach ($matchedTerms as $mt) {
            $matchedMap[$mt['normalized_term']] = [
                'id' => (int)$mt['id'],
                'term' => $mt['term'],
                'slug' => $mt['slug'],
            ];
        }

        $result = [];
        foreach ($topList as $norm => $item) {
            $found = $matchedMap[$norm] ?? null;
            $result[] = [
                'id' => $found['id'] ?? null,
                'term' => $found['term'] ?? $item['canonical'],
                'slug' => $found['slug'] ?? null,
                'count' => (int)$item['count'],
            ];
        }

        return $result;
    }

    /**
     * Retorna a distribuição Qualis CAPES dos artigos e o ranking dos principais periódicos do tema.
     *
     * Os periódicos são famílias (`COALESCE(canonical_journal_id, id)`), não grafias do Lattes: só artigos entram, as
     * grafias de uma mesma revista viram uma linha, o nome é o de exibição da canônica e o estrato é o melhor dos artigos.
     *
     * @return array{
     *     qualisDistribution: array<string, int>,
     *     totalArticlesWithQualis: int,
     *     topQualisPercentage: float,
     *     topJournals: array<int, array{journalName: string, qualis: ?string, count: int}>
     * }
     */
    public function getEditorialAnalytics(ThematicTerm $term, int $limitJournals = 6): array
    {
        $cacheKey = self::EDITORIAL_CACHE_PREFIX . ($term->getSlug() ?: (string)$term->getId());

        if ($this->cache !== null) {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($term, $limitJournals) {
                $item->expiresAfter(86400);
                return $this->computeEditorialAnalytics($term, $limitJournals);
            });
        }

        return $this->computeEditorialAnalytics($term, $limitJournals);
    }

    /**
     * @return array{
     *     qualisDistribution: array<string, int>,
     *     totalArticlesWithQualis: int,
     *     topQualisPercentage: float,
     *     topJournals: array<int, array{journalName: string, qualis: ?string, count: int}>
     * }
     */
    private function computeEditorialAnalytics(ThematicTerm $term, int $limitJournals = 6): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $termStr = $term->getTerm() ?? '';
        $normStr = $term->getNormalizedTerm() ?? '';
        $searchTerm = $normStr !== '' ? $normStr : $termStr;
        $termPattern = '%' . $searchTerm . '%';

        // 1. Distribuição Qualis dos artigos
        $sqlQualis = "
        SELECT COALESCE(qualis, 'Sem Qualis') as stratum, COUNT(*) as total
        FROM production_items
        WHERE (title LIKE :termPattern OR CAST(extra_data AS CHAR) LIKE :termPattern)
          AND item_type = 'artigo'
        GROUP BY stratum
        ORDER BY total DESC
        ";

        $rowsQualis = $conn->fetchAllAssociative($sqlQualis, ['termPattern' => $termPattern]);

        $order = ['A1', 'A2', 'A3', 'A4', 'B1', 'B2', 'B3', 'B4', 'C', 'Sem Qualis'];
        $qualisMap = array_fill_keys($order, 0);
        $totalWithQualis = 0;
        $topCount = 0;

        foreach ($rowsQualis as $r) {
            $s = (string)$r['stratum'];
            $c = (int)$r['total'];
            if (isset($qualisMap[$s])) {
                $qualisMap[$s] = $c;
            } else {
                $qualisMap['Sem Qualis'] += $c;
            }
            if ($s !== 'Sem Qualis') {
                $totalWithQualis += $c;
            }
            if ($s === 'A1' || $s === 'A2') {
                $topCount += $c;
            }
        }

        // Remove estratos com 0 para o Donut Chart ficar limpo
        $qualisDistribution = array_filter($qualisMap, fn($cnt) => $cnt > 0);

        $topQualisPercentage = $totalWithQualis > 0 ? round(($topCount / $totalWithQualis) * 100, 1) : 0.0;

        // 2. Top Periódicos
        $topJournals = $this->computeTopJournals($termPattern, $limitJournals);

        return [
            'qualisDistribution' => $qualisDistribution,
            'totalArticlesWithQualis' => $totalWithQualis,
            'topQualisPercentage' => $topQualisPercentage,
            'topJournals' => $topJournals,
        ];
    }

    /**
     * Periódicos com mais artigos distintos do tema, por família.
     *
     * Só artigos (`item_type = ARTIGO`; capítulo e evento têm o título do livro ou do congresso em `journal_name`).
     * O artigo ligado a um periódico agrupa pela família do vínculo (`qualis_journal_id` aponta para a linha que o
     * resolver escolheu, não para a canônica) e leva o nome de exibição da canônica; o artigo sem vínculo agrupa por
     * `journal_name_normalized` e leva a grafia do Lattes mais frequente do grupo. O estrato é o melhor entre os artigos
     * do grupo (o menor em ordem textual: A1 antes de A2, B1 antes de C), ignorando os vazios.
     *
     * @return list<array{journalName: string, qualis: ?string, count: int}>
     */
    private function computeTopJournals(string $termPattern, int $limit): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $key = self::ARTICLE_KEY;

        $rows = $conn->fetchAllAssociative(
            <<<SQL
            SELECT g.fid, g.nn, g.total, g.qualis, c.display_title, c.title
            FROM (
                SELECT COALESCE(q.canonical_journal_id, q.id) AS fid,
                       CASE WHEN q.id IS NULL THEN pi.journal_name_normalized END AS nn,
                       COUNT(DISTINCT {$key}) AS total,
                       MIN(NULLIF(pi.qualis, '')) AS qualis
                FROM production_items pi
                LEFT JOIN qualis_journals q ON q.id = pi.qualis_journal_id
                WHERE pi.item_type = :articleType
                  AND (pi.title LIKE :termPattern OR CAST(pi.extra_data AS CHAR) LIKE :termPattern)
                  AND (q.id IS NOT NULL OR (pi.journal_name_normalized IS NOT NULL AND pi.journal_name_normalized <> ''))
                GROUP BY fid, nn
            ) g
            LEFT JOIN qualis_journals c ON c.id = g.fid
            ORDER BY g.total DESC, COALESCE(NULLIF(c.display_title, ''), c.title, g.nn) ASC
            LIMIT :limit
            SQL,
            ['articleType' => ProductionItem::TYPE_ARTIGO, 'termPattern' => $termPattern, 'limit' => $limit],
            ['limit' => \PDO::PARAM_INT]
        );

        $spellings = $this->mostFrequentSpellings(
            $termPattern,
            array_values(array_filter(array_map(
                static fn (array $row): ?string => $row['fid'] === null ? (string)$row['nn'] : null,
                $rows
            )))
        );

        $topJournals = [];
        foreach ($rows as $row) {
            if ($row['fid'] !== null) {
                $name = trim((string)$row['display_title']) !== '' ? trim((string)$row['display_title']) : trim((string)$row['title']);
            } else {
                $name = $spellings[(string)$row['nn']] ?? (string)$row['nn'];
            }

            $topJournals[] = [
                'journalName' => $name,
                'qualis' => $row['qualis'] !== null && $row['qualis'] !== '' ? (string)$row['qualis'] : null,
                'count' => (int)$row['total'],
            ];
        }

        return $topJournals;
    }

    /**
     * Grafia do Lattes mais frequente (empate: a menor em ordem de bytes) de cada nome normalizado entre os artigos
     * do tema sem vínculo com periódico.
     *
     * @param list<string> $normalizedNames
     * @return array<string, string> nome normalizado => grafia
     */
    private function mostFrequentSpellings(string $termPattern, array $normalizedNames): array
    {
        if ($normalizedNames === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT pi.journal_name_normalized AS nn, pi.journal_name AS spelling
               FROM production_items pi
              WHERE pi.item_type = :articleType
                AND pi.qualis_journal_id IS NULL
                AND pi.journal_name_normalized IN (:names)
                AND (pi.title LIKE :termPattern OR CAST(pi.extra_data AS CHAR) LIKE :termPattern)',
            ['articleType' => ProductionItem::TYPE_ARTIGO, 'termPattern' => $termPattern, 'names' => $normalizedNames],
            ['names' => ArrayParameterType::STRING]
        );

        $counts = [];
        foreach ($rows as $row) {
            $spelling = trim((string)$row['spelling']);
            if ($spelling !== '') {
                $name = (string)$row['nn'];
                $counts[$name][$spelling] = ($counts[$name][$spelling] ?? 0) + 1;
            }
        }

        $best = [];
        foreach ($counts as $name => $bySpelling) {
            $winner = null;
            $winnerCount = 0;
            foreach ($bySpelling as $spelling => $count) {
                $spelling = (string)$spelling;
                if ($count > $winnerCount || ($count === $winnerCount && strcmp($spelling, (string)$winner) < 0)) {
                    $winner = $spelling;
                    $winnerCount = $count;
                }
            }
            $best[(string)$name] = (string)$winner;
        }

        return $best;
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
