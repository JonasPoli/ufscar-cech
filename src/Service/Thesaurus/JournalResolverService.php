<?php

namespace App\Entity; // Just to reference QualisJournal namespace
namespace App\Service\Thesaurus;

use App\Entity\QualisJournal;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Serviço de resolução de Periódicos, Estratos Qualis CAPES e Bases de Indexação Científica.
 *
 * Utiliza arquitetura dual:
 * 1. Resolução On-Demand via SQL indexado de alta velocidade + Memoização em memória (consumo < 100KB de RAM por request HTTP).
 * 2. Modo Lote (Full In-Memory) para indexação massiva (CLI/Admin) sem overhead de serialização Symfony Cache.
 */
class JournalResolverService
{
    /** @var array<string, array{q: ?string, j: int}|null> */
    private array $memoLookup = [];

    /** @var array<int, array<array{id: int, name: string, acronym: string, logo: ?string, url: ?string, color: string}>> */
    private array $memoDatabases = [];

    /** @var array<int, array{id: int, name: string, acronym: string, logo: ?string, url: ?string, color: string}>|null */
    private ?array $dbDefinitions = null;

    /** @var bool */
    private bool $isFullLoaded = false;

    /** @var array<string, array{q: ?string, j: int}> */
    private array $titleMap = [];

    /** @var array<string, array{q: ?string, j: int}> */
    private array $keywordMap = [];

    /** @var array<string, array{q: ?string, j: int}> */
    private array $issnMap = [];

    /** @var array<int, int[]> */
    private array $journalDbIds = [];

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    public function clearCache(): void
    {
        $this->memoLookup = [];
        $this->memoDatabases = [];
        $this->dbDefinitions = null;
        $this->isFullLoaded = false;
        $this->titleMap = [];
        $this->keywordMap = [];
        $this->issnMap = [];
        $this->journalDbIds = [];
    }

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

    /**
     * Carrega as definições globais das bases acadêmicas em dicionário leve.
     * @return array<int, array{id: int, name: string, acronym: string, logo: ?string, url: ?string, color: string}>
     */
    private function getDbDefinitions(): array
    {
        if ($this->dbDefinitions !== null) {
            return $this->dbDefinitions;
        }

        $colorMap = [
            'Scopus' => '#ea580c',
            'scopus' => '#ea580c',
            'WoS' => '#7c3aed',
            'wos' => '#7c3aed',
            'Web of Science' => '#7c3aed',
            'SciELO' => '#e11d48',
            'scielo' => '#e11d48',
            'PubMed' => '#2563eb',
            'pubmed' => '#2563eb',
            'DOAJ' => '#d97706',
            'doaj' => '#d97706',
            'Latindex' => '#0d9488',
            'latindex' => '#0d9488',
            'OpenAlex' => '#6366f1',
            'openalex' => '#6366f1',
            'Crossref' => '#0284c7',
            'crossref' => '#0284c7',
        ];

        $conn = $this->em->getConnection();
        $stmt = $conn->executeQuery('SELECT id, name, acronym, logo, url FROM academic_database ORDER BY name ASC');
        $this->dbDefinitions = [];
        while ($r = $stmt->fetchAssociative()) {
            $id = (int)$r['id'];
            $acronym = $r['acronym'] ?: $r['name'];
            $this->dbDefinitions[$id] = [
                'id' => $id,
                'name' => $r['name'],
                'acronym' => $acronym,
                'logo' => $r['logo'],
                'url' => $r['url'],
                'color' => $colorMap[$acronym] ?? $colorMap[$r['name']] ?? '#64748b',
            ];
        }

        return $this->dbDefinitions;
    }

    /**
     * Carrega os mapas completos na memória para operações batch/CLI.
     */
    public function loadFullMaps(): void
    {
        if ($this->isFullLoaded) {
            return;
        }

        $conn = $this->em->getConnection();
        $this->getDbDefinitions();

        // 1. Relações periódicos <-> bases
        $relStmt = $conn->executeQuery('SELECT qualis_journal_id, academic_database_id FROM qualis_journal_academic_database');
        while ($rel = $relStmt->fetchAssociative()) {
            $jid = (int)$rel['qualis_journal_id'];
            $dbId = (int)$rel['academic_database_id'];
            $this->journalDbIds[$jid][] = $dbId;
        }

        // 2. Periódicos canônicos
        $stmt = $conn->executeQuery('SELECT id, title, normalized_issn, normalized_issn_e, normalized_issn_l, normalized_issn_imp, qualis FROM qualis_journals');
        while ($r = $stmt->fetchAssociative()) {
            $jid = (int)$r['id'];
            $qualis = strtoupper(trim((string)$r['qualis']));
            $entry = [
                'q' => $qualis !== '' ? $qualis : null,
                'j' => $jid,
            ];

            $normTitle = self::normalizeString($r['title']);
            if ($normTitle !== '') {
                $this->titleMap[$normTitle] = $entry;
            }

            $cleanTitle = self::normalizeString(preg_replace('/\([^)]+\)/', '', $r['title']));
            if ($cleanTitle !== '' && !isset($this->titleMap[$cleanTitle])) {
                $this->titleMap[$cleanTitle] = $entry;
            }

            $kw = self::cleanKeywords($r['title']);
            if ($kw !== '' && !isset($this->keywordMap[$kw])) {
                $this->keywordMap[$kw] = $entry;
            }
            $cleanKw = self::cleanKeywords($cleanTitle);
            if ($cleanKw !== '' && !isset($this->keywordMap[$cleanKw])) {
                $this->keywordMap[$cleanKw] = $entry;
            }

            foreach (['normalized_issn', 'normalized_issn_e', 'normalized_issn_l', 'normalized_issn_imp'] as $col) {
                if (!empty($r[$col])) {
                    $this->issnMap[$r[$col]] = $entry;
                }
            }
        }

        // 3. Variações do tesauro
        $varStmt = $conn->executeQuery('SELECT v.journal_id, v.normalized_name, q.qualis FROM journal_name_variants v JOIN qualis_journals q ON v.journal_id = q.id');
        while ($vr = $varStmt->fetchAssociative()) {
            $jid = (int)$vr['journal_id'];
            $qualis = strtoupper(trim((string)$vr['qualis']));
            $entry = [
                'q' => $qualis !== '' ? $qualis : null,
                'j' => $jid,
            ];

            $normVar = self::normalizeString($vr['normalized_name']);
            if ($normVar !== '' && !isset($this->titleMap[$normVar])) {
                $this->titleMap[$normVar] = $entry;
            }

            $varKw = self::cleanKeywords($vr['normalized_name']);
            if ($varKw !== '' && !isset($this->keywordMap[$varKw])) {
                $this->keywordMap[$varKw] = $entry;
            }
        }

        $this->isFullLoaded = true;
    }

    /**
     * Busca a entrada no tesauro (utiliza memória se carregada ou consulta SQL direta indexada).
     * @return array{q: ?string, j: int}|null
     */
    private function lookup(?string $journalName, ?string $issn = null): ?array
    {
        $memoKey = ($issn ?? '') . '|' . ($journalName ?? '');
        if (array_key_exists($memoKey, $this->memoLookup)) {
            return $this->memoLookup[$memoKey];
        }

        // Se os mapas completos já estiverem na memória (modo batch), usa lookup $O(1)$
        if ($this->isFullLoaded) {
            $res = $this->lookupInFullMaps($journalName, $issn);
            $this->memoLookup[$memoKey] = $res;
            return $res;
        }

        // Modo On-Demand: Consulta SQL direta ultra-rápida (0.0001s e 0KB de RAM)
        $res = $this->lookupInDatabase($journalName, $issn);
        $this->memoLookup[$memoKey] = $res;
        return $res;
    }

    /**
     * Lookup em memória (quando loadFullMaps foi executado).
     */
    private function lookupInFullMaps(?string $journalName, ?string $issn = null): ?array
    {
        if ($issn) {
            $normIssn = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $issn));
            if ($normIssn !== '' && isset($this->issnMap[$normIssn])) {
                return $this->issnMap[$normIssn];
            }
        }

        if (!$journalName || trim($journalName) === '') {
            return null;
        }

        $normName = self::normalizeString($journalName);
        if (isset($this->titleMap[$normName])) {
            return $this->titleMap[$normName];
        }

        $cleanName = self::normalizeString(preg_replace('/\([^)]+\)/', '', $journalName));
        if ($cleanName !== '' && isset($this->titleMap[$cleanName])) {
            return $this->titleMap[$cleanName];
        }

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
     * Lookup direto no MySQL via índices existentes.
     */
    private function lookupInDatabase(?string $journalName, ?string $issn = null): ?array
    {
        $conn = $this->em->getConnection();

        // 1. Busca por ISSN
        if ($issn) {
            $normIssn = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $issn));
            if ($normIssn !== '') {
                $row = $conn->fetchAssociative("
                    SELECT id, qualis 
                    FROM qualis_journals 
                    WHERE normalized_issn = :issn 
                       OR normalized_issn_e = :issn 
                       OR normalized_issn_l = :issn 
                       OR normalized_issn_imp = :issn 
                    LIMIT 1
                ", ['issn' => $normIssn]);

                if ($row) {
                    $q = strtoupper(trim((string)$row['qualis']));
                    return ['j' => (int)$row['id'], 'q' => $q !== '' ? $q : null];
                }
            }
        }

        if (!$journalName || trim($journalName) === '') {
            return null;
        }

        // 2. Busca exata por título canônico
        $row = $conn->fetchAssociative("
            SELECT id, qualis 
            FROM qualis_journals 
            WHERE title = :title 
            LIMIT 1
        ", ['title' => trim($journalName)]);

        if ($row) {
            $q = strtoupper(trim((string)$row['qualis']));
            return ['j' => (int)$row['id'], 'q' => $q !== '' ? $q : null];
        }

        // 3. Busca por variação cadastrada no tesauro
        $normName = self::normalizeString($journalName);
        if ($normName !== '') {
            $row = $conn->fetchAssociative("
                SELECT q.id, q.qualis 
                FROM journal_name_variants v 
                JOIN qualis_journals q ON q.id = v.journal_id 
                WHERE v.normalized_name = :norm 
                LIMIT 1
            ", ['norm' => $normName]);

            if ($row) {
                $q = strtoupper(trim((string)$row['qualis']));
                return ['j' => (int)$row['id'], 'q' => $q !== '' ? $q : null];
            }
        }

        // 4. Busca por prefixo/título limpo
        $cleanTitle = trim(preg_replace('/\([^)]+\)/', '', $journalName));
        if ($cleanTitle !== '' && $cleanTitle !== $journalName) {
            $row = $conn->fetchAssociative("
                SELECT id, qualis 
                FROM qualis_journals 
                WHERE title = :title 
                LIMIT 1
            ", ['title' => $cleanTitle]);

            if ($row) {
                $q = strtoupper(trim((string)$row['qualis']));
                return ['j' => (int)$row['id'], 'q' => $q !== '' ? $q : null];
            }
        }

        // 5. Busca por palavras-chave principais
        $kw = self::cleanKeywords($journalName);
        if ($kw !== '') {
            $row = $conn->fetchAssociative("
                SELECT id, qualis 
                FROM qualis_journals 
                WHERE title LIKE :kw 
                LIMIT 1
            ", ['kw' => '%' . $kw . '%']);

            if ($row) {
                $q = strtoupper(trim((string)$row['qualis']));
                return ['j' => (int)$row['id'], 'q' => $q !== '' ? $q : null];
            }
        }

        return null;
    }

    /**
     * Resolve a classificação Qualis CAPES (ex: 'A1', 'A2', 'B1', etc.)
     */
    public function resolveQualis(?string $journalName, ?string $issn = null): ?string
    {
        $entry = $this->lookup($journalName, $issn);
        return $entry ? $entry['q'] : null;
    }

    /**
     * Resolve o ID do periódico canônico e o estrato Qualis simultaneamente.
     * @return array{id: int, qualis: ?string}|null
     */
    public function resolveJournalIdAndQualis(?string $journalName, ?string $issn = null): ?array
    {
        $entry = $this->lookup($journalName, $issn);
        if (!$entry) {
            return null;
        }

        return [
            'id' => $entry['j'],
            'qualis' => $entry['q'],
        ];
    }

    /**
     * Resolve a lista de Bases de Indexação Científica do periódico.
     * @return array<array{id: int, name: string, acronym: string, logo: ?string, url: ?string, color: string}>
     */
    public function resolveDatabases(?string $journalName, ?string $issn = null): array
    {
        $entry = $this->lookup($journalName, $issn);
        if (!$entry) {
            return [];
        }

        return $this->resolveDatabasesForJournalId($entry['j']);
    }

    /**
     * Retorna as bases indexadoras de um periódico a partir do seu ID.
     * @return array<array{id: int, name: string, acronym: string, logo: ?string, url: ?string, color: string}>
     */
    public function resolveDatabasesForJournalId(int $journalId): array
    {
        if (isset($this->memoDatabases[$journalId])) {
            return $this->memoDatabases[$journalId];
        }

        $allDbs = $this->getDbDefinitions();

        // Se full loaded, usa array
        if ($this->isFullLoaded) {
            $dbIds = $this->journalDbIds[$journalId] ?? [];
            $res = [];
            foreach ($dbIds as $dbId) {
                if (isset($allDbs[$dbId])) {
                    $res[] = $allDbs[$dbId];
                }
            }
            $this->memoDatabases[$journalId] = $res;
            return $res;
        }

        // On-demand via SQL
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT academic_database_id FROM qualis_journal_academic_database WHERE qualis_journal_id = :jid',
            ['jid' => $journalId]
        );

        $res = [];
        foreach ($rows as $r) {
            $dbId = (int)$r['academic_database_id'];
            if (isset($allDbs[$dbId])) {
                $res[] = $allDbs[$dbId];
            }
        }

        $this->memoDatabases[$journalId] = $res;
        return $res;
    }

    /**
     * Resolve a entidade canônica QualisJournal a partir do nome ou ISSN do periódico.
     */
    public function resolveJournal(?string $journalName, ?string $issn = null): ?QualisJournal
    {
        $entry = $this->lookup($journalName, $issn);
        if ($entry) {
            return $this->em->find(QualisJournal::class, $entry['j']);
        }

        return null;
    }

    /**
     * Alias retrocompatível para resolveDatabases.
     */
    public function resolveIndexedDatabases(?string $journalName, ?string $issn = null): array
    {
        return $this->resolveDatabases($journalName, $issn);
    }
}
