<?php

namespace App\Service\Thesaurus;

use App\Entity\QualisJournal;
use Doctrine\ORM\EntityManagerInterface;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Serviço responsável por resolver a classificação Qualis (A1 a C) e a entidade QualisJournal canônica.
 *
 * Utiliza estratégias em cascata com cache:
 * 1. Resolução exata por ISSN normalizado.
 * 2. Correspondência exata por título normalizado.
 * 3. Correspondência sem termos entre parênteses.
 * 4. Correspondência por palavras-chave relevantes (removendo stop words).
 * 5. Correspondência por variações cadastradas no tesauro (journal_name_variants).
 */
class JournalResolverService
{
    /** Chave de cache do índice do tesauro de periódicos */
    public const CACHE_KEY = 'thesaurus_journal_index_v2';

    /**
     * Mapeamento de títulos normalizados para estrato Qualis.
     * @var array<string, string>|null
     */
    private ?array $titleMap = null;

    /**
     * Mapeamento de palavras-chave limpas para estrato Qualis.
     * @var array<string, string>|null
     */
    private ?array $keywordMap = null;

    /**
     * Mapeamento de ISSNs limpos para estrato Qualis.
     * @var array<string, string>|null
     */
    private ?array $issnMap = null;

    /**
     * @param EntityManagerInterface $em Gerenciador de entidades do Doctrine
     * @param CacheInterface|null $cache Serviço opcional de cache do Symfony
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?CacheInterface $cache = null
    ) {}

    /**
     * Invalida as estruturas em memória e o cache persistido do tesauro de periódicos.
     */
    public function clearCache(): void
    {
        $this->titleMap = null;
        $this->keywordMap = null;
        $this->issnMap = null;
        if ($this->cache !== null) {
            $this->cache->delete(self::CACHE_KEY);
        }
    }

    /**
     * Normaliza uma string removendo entidades HTML, acentuação (ASCII translit),
     * pontuação e caracteres não alfanuméricos em minúsculas.
     *
     * @param string|null $str String original do nome do periódico
     * @return string String normalizada
     */
    public static function normalizeString(?string $str): string
    {
        if ($str === null || $str === '') return '';
        $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $str = trim(preg_replace('/\s+/', ' ', mb_strtolower($str, 'UTF-8')));
        $transl = @iconv('UTF-8', 'ASCII//TRANSLIT', $str);
        if ($transl !== false) {
            $str = $transl;
        }
        return trim(preg_replace('/[^a-z0-9]/', ' ', $str));
    }

    /**
     * Extrai apenas as palavras-chave relevantes removendo preposições e termos genéricos de periódicos
     * (ex: 'journal', 'revista', 'cadernos', 'online', 'capes').
     *
     * @param string|null $str Título ou variação
     * @return string String contendo apenas palavras-chave significativas
     */
    public static function cleanKeywords(?string $str): string
    {
        $norm = self::normalizeString($str);
        if ($norm === '') return '';

        $words = explode(' ', $norm);
        $stop = [
            'do', 'da', 'de', 'dos', 'das', 'e', 'em', 'para', 'com', 'o', 'a', 'os', 'as', 'um', 'uma',
            'online', 'impresso', 'ranqueado', 'pela', 'capes', 'journal', 'of', 'the', 'revista', 'cadernos', 'boletim'
        ];

        $filtered = array_filter($words, fn($w) => !in_array($w, $stop, true) && mb_strlen($w) > 1);
        return implode(' ', $filtered);
    }

    private function initCache(): void
    {
        if ($this->titleMap !== null) return;

        if ($this->cache !== null) {
            [$this->titleMap, $this->keywordMap, $this->issnMap] = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) {
                $item->expiresAfter(86400 * 30);
                return $this->buildCacheData();
            });
            return;
        }

        [$this->titleMap, $this->keywordMap, $this->issnMap] = $this->buildCacheData();
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>}
     */
    private function buildCacheData(): array
    {
        $titleMap = [];
        $keywordMap = [];
        $issnMap = [];

        $conn = $this->em->getConnection();

        // 1. Load canonical journals using streaming cursor
        $stmt = $conn->executeQuery('SELECT title, normalized_issn, qualis FROM qualis_journals WHERE qualis IS NOT NULL AND qualis != ""');
        while ($r = $stmt->fetchAssociative()) {
            $qualis = strtoupper(trim($r['qualis']));
            if ($qualis === '') continue;

            $normTitle = self::normalizeString($r['title']);
            if ($normTitle !== '') {
                $titleMap[$normTitle] = $qualis;
            }

            // Clean without parentheses
            $cleanTitle = self::normalizeString(preg_replace('/\([^)]+\)/', '', $r['title']));
            if ($cleanTitle !== '' && !isset($titleMap[$cleanTitle])) {
                $titleMap[$cleanTitle] = $qualis;
            }

            // Keyword map
            $kw = self::cleanKeywords($r['title']);
            if ($kw !== '' && !isset($keywordMap[$kw])) {
                $keywordMap[$kw] = $qualis;
            }
            $cleanKw = self::cleanKeywords($cleanTitle);
            if ($cleanKw !== '' && !isset($keywordMap[$cleanKw])) {
                $keywordMap[$cleanKw] = $qualis;
            }

            // ISSN map
            if (!empty($r['normalized_issn'])) {
                $issnMap[$r['normalized_issn']] = $qualis;
            }
        }

        // 2. Load thesaurus variations / aliases
        $varStmt = $conn->executeQuery('SELECT v.normalized_name, q.qualis FROM journal_name_variants v JOIN qualis_journals q ON v.journal_id = q.id WHERE q.qualis IS NOT NULL AND q.qualis != ""');
        while ($vr = $varStmt->fetchAssociative()) {
            $qualis = strtoupper(trim($vr['qualis']));
            if ($qualis === '') continue;

            $normVar = self::normalizeString($vr['normalized_name']);
            if ($normVar !== '') {
                $titleMap[$normVar] = $qualis;
            }
            $varKw = self::cleanKeywords($vr['normalized_name']);
            if ($varKw !== '' && !isset($keywordMap[$varKw])) {
                $keywordMap[$varKw] = $qualis;
            }
        }

        return [$titleMap, $keywordMap, $issnMap];
    }

    /**
     * Resolve a classificação Qualis CAPES (ex: 'A1', 'A2', 'A3', 'A4', 'B1', 'B2', 'B3', 'B4', 'C')
     * para um determinado nome de periódico ou ISSN.
     *
     * @param string|null $journalName Nome original do periódico no Lattes
     * @param string|null $issn Código ISSN (com ou sem hífen)
     * @return string|null Estrato Qualis resolvido ou null caso não localizado
     */
    public function resolveQualis(?string $journalName, ?string $issn = null): ?string
    {
        $this->initCache();

        // 1. Try ISSN if provided
        if ($issn) {
            $normIssn = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $issn));
            if (isset($this->issnMap[$normIssn])) {
                return $this->issnMap[$normIssn];
            }
        }

        if (!$journalName || trim($journalName) === '') {
            return null;
        }

        // 2. Exact normalized title match
        $normName = self::normalizeString($journalName);
        if (isset($this->titleMap[$normName])) {
            return $this->titleMap[$normName];
        }

        // 3. Normalized without parenthetical notes
        $cleanName = self::normalizeString(preg_replace('/\([^)]+\)/', '', $journalName));
        if ($cleanName !== '' && isset($this->titleMap[$cleanName])) {
            return $this->titleMap[$cleanName];
        }

        // 4. Clean keywords match
        $kw = self::cleanKeywords($journalName);
        if ($kw !== '' && isset($this->keywordMap[$kw])) {
            return $this->keywordMap[$kw];
        }

        $cleanKw = self::cleanKeywords($cleanName);
        if ($cleanKw !== '' && isset($this->keywordMap[$cleanKw])) {
            return $this->keywordMap[$cleanKw];
        }

        return null;
    }

    /**
     * Resolve a entidade canônica QualisJournal a partir do nome ou ISSN do periódico.
     *
     * @param string|null $journalName Nome do periódico
     * @param string|null $issn Código ISSN
     * @return QualisJournal|null Entidade encontrada ou null
     */
    public function resolveJournal(?string $journalName, ?string $issn = null): ?QualisJournal
    {
        $repo = $this->em->getRepository(QualisJournal::class);

        if ($issn) {
            $normIssn = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $issn));
            $found = $repo->findOneBy(['normalizedIssn' => $normIssn]);
            if ($found) return $found;
        }

        if (!$journalName || trim($journalName) === '') {
            return null;
        }

        $norm = self::normalizeString($journalName);

        // Check canonical title
        $found = $repo->findOneBy(['title' => $journalName]);
        if ($found) return $found;

        // Check variations
        $var = $this->em->createQuery('
            SELECT j FROM App\Entity\QualisJournal j
            JOIN j.variations v
            WHERE v.normalizedName = :norm OR v.variationName = :name
        ')->setParameter('norm', $norm)
          ->setParameter('name', $journalName)
          ->setMaxResults(1)
          ->getOneOrNullResult();

        if ($var) return $var;

        return null;
    }
}
