<?php

namespace App\Service\Thesaurus;

use App\Entity\QualisJournal;
use App\Service\Journal\Issn;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Serviço de resolução de Periódicos, Estratos Qualis CAPES e Bases de Indexação Científica.
 *
 * Utiliza arquitetura dual:
 * 1. Resolução On-Demand via SQL indexado de alta velocidade + Memoização em memória (consumo < 100KB de RAM por request HTTP).
 * 2. Modo Lote (Full In-Memory) para indexação massiva (CLI/Admin) sem overhead de serialização Symfony Cache.
 *
 * Os dois modos seguem as mesmas etapas (docs/evolutions/periodicos-por-base.md, A5.2) e dão o mesmo resultado.
 * "Família" de um periódico = COALESCE(canonical_journal_id, id).
 *
 *  1. ISSN: cada ISSN extraído do artigo é procurado nas 4 colunas normalizadas; o primeiro acerto vence
 *     (várias linhas com o mesmo ISSN: a de menor id).
 *  2. Nome exato: chave do resolver do nome contra `normalized_title`, contra o título do cadastro sem parênteses
 *     e contra as variantes ativas do tesauro.
 *  3. Nome sem parênteses: a mesma busca com o nome do artigo sem os trechos entre parênteses.
 *  4. Chave compacta: sem os conectivos (a, o, de, e, the, of...) dos dois lados; só vale com 2 ou mais palavras.
 *  Nas etapas 2 a 4, se o nome pertence a mais de uma família ele é AMBÍGUO: não resolve (retorna null) e fica
 *  registrado para a fila de revisão (consumeAmbiguous/isAmbiguousName).
 *  Palavras-chave nunca geram vínculo: servem só de sugestão (suggestByKeywords).
 *
 * Limites do modo on-demand (o modo lote não os tem): depende de `qualis_journals.normalized_title` preenchido
 * (app:journals:normalize-titles; o modo lote calcula a chave a partir do título quando a coluna está vazia) e não
 * alcança títulos do cadastro com parênteses no começo ou no meio («(En)clave Comahue») nem com dois ou mais
 * conectivos antes da primeira palavra, porque a busca por SQL usa o prefixo do título normalizado.
 */
class JournalResolverService
{
    /** Conectivos ignorados na chave compacta (etapa 4). */
    private const COMPACT_STOPWORDS = ['a', 'o', 'as', 'os', 'e', 'de', 'da', 'do', 'das', 'dos', 'em', 'the', 'of', 'and', 'y', 'la', 'el', 'los', 'las', 'del'];

    /** @var array<string, array{q: ?string, j: int}|null> */
    private array $memoLookup = [];

    /** @var array<string, array{entry: ?array{q: ?string, j: int}, ambiguous: bool}> */
    private array $memoByName = [];

    /** @var list<string> Nomes (como recebidos) que não resolveram por pertencer a mais de uma família. */
    private array $ambiguousHits = [];

    /** @var array<int, array<array{id: int, name: string, acronym: string, logo: ?string, url: ?string, color: string}>> */
    private array $memoDatabases = [];

    /** @var array<int, array{id: int, name: string, acronym: string, logo: ?string, url: ?string, color: string}>|null */
    private ?array $dbDefinitions = null;

    /** @var bool */
    private bool $isFullLoaded = false;

    /**
     * Modo lote: chave do resolver (título, título sem parênteses, variante) → id da linha, se todas as linhas
     * encontradas são da mesma família; senão array família => id da linha (ambíguo).
     *
     * @var array<string, int|array<int, int>>
     */
    private array $titleMap = [];

    /**
     * Modo lote: chave compacta (títulos, com e sem parênteses) → mesma estrutura de $titleMap.
     *
     * @var array<string, int|array<int, int>>
     */
    private array $compactMap = [];

    /** @var array<string, int> Modo lote: ISSN normalizado (8 caracteres) → id da linha de menor id. */
    private array $issnMap = [];

    /** @var array<int, ?string> Modo lote: id da linha → estrato Qualis. */
    private array $rowQualis = [];

    /** @var array<int, int> Modo lote: id da linha → id da família. */
    private array $rowFamily = [];

    /** @var array<int, array{q: string, j: int}> Modo lote: id da família → linha que dá o estrato às linhas sem Qualis. */
    private array $familyQualis = [];

    /**
     * Palavras-chave do título (e variantes) → id do primeiro periódico. Serve só para suggestByKeywords().
     *
     * @var array<string, int>|null
     */
    private ?array $keywordMap = null;

    /** @var array<int, int[]> */
    private array $journalDbIds = [];

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    public function clearCache(): void
    {
        $this->memoLookup = [];
        $this->memoByName = [];
        $this->ambiguousHits = [];
        $this->memoDatabases = [];
        $this->dbDefinitions = null;
        $this->isFullLoaded = false;
        $this->titleMap = [];
        $this->compactMap = [];
        $this->issnMap = [];
        $this->rowQualis = [];
        $this->rowFamily = [];
        $this->familyQualis = [];
        $this->keywordMap = null;
        $this->journalDbIds = [];
    }

    public static function normalizeString(?string $str): string
    {
        if ($str === null || trim($str) === '') {
            return '';
        }
        $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $str = StringNormalizer::normalizeString($str, false);   // Transliterator: acentos fora, pontuação → espaço, espaços colapsados, minúsculas
        $str = preg_replace('/[^a-z0-9]+/', ' ', $str);
        return trim(preg_replace('/\s+/', ' ', $str));
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
     * Remove os trechos entre parênteses: «Educação & Sociedade (Campinas)» → «Educação & Sociedade ».
     */
    public static function stripParentheses(?string $str): string
    {
        return $str === null ? '' : (string) preg_replace('/\([^)]*\)/', '', $str);
    }

    /**
     * Chave compacta (etapa 4): a chave do resolver sem os conectivos («educacao e sociedade» → «educacao sociedade»).
     * Retorna null quando restam menos de 2 palavras.
     */
    public static function compactKey(string $normalizedKey): ?string
    {
        $tokens = [];
        foreach (explode(' ', $normalizedKey) as $token) {
            if ($token !== '' && !in_array($token, self::COMPACT_STOPWORDS, true)) {
                $tokens[] = $token;
            }
        }

        return count($tokens) >= 2 ? implode(' ', $tokens) : null;
    }

    /**
     * Palpite por palavras-chave. NUNCA vira vínculo: só alimenta a fila de revisão de nomes (journal_name_candidates).
     * Retorna o id do primeiro periódico (título ou variante ativa) cujas palavras-chave coincidem com as do nome.
     */
    public function suggestByKeywords(?string $name): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $this->ensureKeywordMap();

        $kw = self::cleanKeywords($name);
        if ($kw !== '' && isset($this->keywordMap[$kw])) {
            return $this->keywordMap[$kw];
        }

        $cleanKw = self::cleanKeywords(self::stripParentheses($name));
        if ($cleanKw !== '' && isset($this->keywordMap[$cleanKw])) {
            return $this->keywordMap[$cleanKw];
        }

        return null;
    }

    /**
     * Nomes (como foram recebidos) que não resolveram por pertencerem a mais de uma família, desde a última chamada.
     * Cada nome aparece uma só vez; a lista é esvaziada.
     *
     * @return list<string>
     */
    public function consumeAmbiguous(): array
    {
        $names = array_values(array_unique($this->ambiguousHits));
        $this->ambiguousHits = [];

        return $names;
    }

    /**
     * O nome (sem ISSN) casa, nas etapas 2 a 4, com periódicos de mais de uma família? Então nunca resolve sozinho.
     */
    public function isAmbiguousName(string $name): bool
    {
        return $this->resolveName($name)['ambiguous'];
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

        // 2. Linhas do cadastro, em ordem de id: o primeiro a chegar a uma chave é a linha de menor id
        $stmt = $conn->executeQuery(
            'SELECT id, title, normalized_title, canonical_journal_id, qualis, normalized_issn, normalized_issn_e, normalized_issn_l, normalized_issn_imp
               FROM qualis_journals
              ORDER BY id'
        );
        while ($r = $stmt->fetchAssociative()) {
            $jid = (int)$r['id'];
            $family = $r['canonical_journal_id'] !== null ? (int)$r['canonical_journal_id'] : $jid;
            $qualis = strtoupper(trim((string)$r['qualis']));
            $this->rowQualis[$jid] = $qualis !== '' ? $qualis : null;
            $this->rowFamily[$jid] = $family;
            if ($qualis !== '' && ($jid === $family || !isset($this->familyQualis[$family]))) {
                $this->familyQualis[$family] = ['q' => $qualis, 'j' => $jid];
            }

            foreach (['normalized_issn', 'normalized_issn_e', 'normalized_issn_l', 'normalized_issn_imp'] as $col) {
                $issnKey = strtolower(trim((string)$r[$col]));
                if ($issnKey !== '' && !isset($this->issnMap[$issnKey])) {
                    $this->issnMap[$issnKey] = $jid;
                }
            }

            [$exact, $clean] = self::titleKeys($r['title'], $r['normalized_title']);
            foreach ([$exact, $clean] as $key) {
                if ($key === '') {
                    continue;
                }
                $this->addToMap($this->titleMap, $key, $family, $jid);

                $compact = self::compactKey($key);
                if ($compact !== null) {
                    $this->addToMap($this->compactMap, $compact, $family, $jid);
                }
            }
        }

        // 3. Variantes ativas do tesauro, depois dos títulos: dentro de uma família o título tem preferência
        $varStmt = $conn->executeQuery('SELECT journal_id, normalized_name FROM journal_name_variants WHERE status = 1 ORDER BY journal_id, id');
        while ($vr = $varStmt->fetchAssociative()) {
            $jid = (int)$vr['journal_id'];
            if (!isset($this->rowFamily[$jid])) {
                continue;
            }

            $key = self::normalizeString($vr['normalized_name']);
            if ($key !== '') {
                $this->addToMap($this->titleMap, $key, $this->rowFamily[$jid], $jid);
            }
        }

        $this->isFullLoaded = true;
    }

    /**
     * Guarda a linha $journalId na chave $key. A chave guarda um id (todas as linhas encontradas são da mesma
     * família: vale a primeira, de menor id) ou, quando chegam linhas de famílias diferentes, o mapa família => id.
     *
     * @param array<string, int|array<int, int>> $map
     */
    private function addToMap(array &$map, string $key, int $family, int $journalId): void
    {
        if (!isset($map[$key])) {
            $map[$key] = $journalId;
            return;
        }

        $current = $map[$key];
        if (is_int($current)) {
            $currentFamily = $this->rowFamily[$current];
            if ($currentFamily !== $family) {
                $map[$key] = [$currentFamily => $current, $family => $journalId];
            }
            return;
        }

        if (!isset($current[$family])) {
            $map[$key][$family] = $journalId;
        }
    }

    /**
     * Chaves do resolver de um título do cadastro: [exata, sem parênteses]. A segunda vem vazia quando não difere
     * da primeira. A exata parte de `normalized_title` (ou, se a coluna ainda não foi preenchida, do próprio título).
     *
     * @return array{0: string, 1: string}
     */
    public static function titleKeys(?string $title, ?string $normalizedTitle): array
    {
        $exact = self::normalizeString($normalizedTitle !== null && $normalizedTitle !== '' ? $normalizedTitle : $title);

        $clean = '';
        if ($title !== null && str_contains($title, '(')) {
            $clean = self::normalizeString(self::stripParentheses($title));
            if ($clean === $exact) {
                $clean = '';
            }
        }

        return [$exact, $clean];
    }

    /**
     * @return array{q: ?string, j: int}
     */
    private function entryFor(int $journalId): array
    {
        return ['q' => $this->rowQualis[$journalId] ?? null, 'j' => $journalId];
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

        // Etapa 1: ISSN. O primeiro acerto vence e dispensa o nome.
        $res = $this->isFullLoaded ? $this->lookupIssnInFullMaps($issn) : $this->lookupIssnInDatabase($issn);

        // Etapas 2 a 4: nome. Nome em mais de uma família não resolve e fica registrado para a fila de revisão.
        if ($res === null) {
            $byName = $this->resolveName($journalName);
            if ($byName['ambiguous']) {
                $this->ambiguousHits[] = (string)$journalName;
            }
            $res = $byName['entry'];
        }

        $res = $this->withFamilyQualis($res);
        $this->memoLookup[$memoKey] = $res;
        return $res;
    }

    /**
     * Linha sem estrato cede o artigo à linha da mesma família que tem Qualis: é a mesma revista (ex.: linha do ISSN
     * impresso sem Qualis e linha do ISSN eletrônico A1). Vale a linha canônica; sem estrato nela, a de menor id. Assim o
     * artigo não muda de linha à toa, e as telas que agrupam por linha não dividem a revista.
     *
     * @param array{q: ?string, j: int}|null $entry
     * @return array{q: ?string, j: int}|null
     */
    private function withFamilyQualis(?array $entry): ?array
    {
        if ($entry === null || $entry['q'] !== null) {
            return $entry;
        }

        if ($this->isFullLoaded) {
            return $this->familyQualis[$this->rowFamily[$entry['j']] ?? $entry['j']] ?? $entry;
        }

        $conn = $this->em->getConnection();
        $family = $conn->fetchOne('SELECT COALESCE(canonical_journal_id, id) FROM qualis_journals WHERE id = :id', ['id' => $entry['j']]);
        if ($family === false) {
            return $entry;
        }

        $row = $conn->fetchAssociative(
            "SELECT id, qualis FROM qualis_journals
              WHERE (id = :family OR canonical_journal_id = :family) AND qualis IS NOT NULL AND qualis <> ''
              ORDER BY (id = :family) DESC, id ASC
              LIMIT 1",
            ['family' => (int)$family]
        );

        return $row !== false ? ['q' => strtoupper(trim((string)$row['qualis'])), 'j' => (int)$row['id']] : $entry;
    }

    /**
     * Etapa 1 em memória (modo lote).
     * @return array{q: ?string, j: int}|null
     */
    private function lookupIssnInFullMaps(?string $issn): ?array
    {
        foreach (Issn::extractAll($issn, false) as $n8) {
            if (isset($this->issnMap[$n8])) {
                return $this->entryFor($this->issnMap[$n8]);
            }
        }

        return null;
    }

    /**
     * Etapa 1 direto no MySQL (modo on-demand). Várias linhas com o mesmo ISSN: a de menor id.
     * @return array{q: ?string, j: int}|null
     */
    private function lookupIssnInDatabase(?string $issn): ?array
    {
        $conn = $this->em->getConnection();

        foreach (Issn::extractAll($issn, false) as $n8) {
            $rows = $conn->fetchAllAssociative(
                'SELECT id, qualis FROM qualis_journals
                  WHERE normalized_issn = ? OR normalized_issn_e = ? OR normalized_issn_l = ? OR normalized_issn_imp = ?',
                [$n8, $n8, $n8, $n8]
            );
            if ($rows === []) {
                continue;
            }

            $best = null;
            foreach ($rows as $row) {
                if ($best === null || (int)$row['id'] < (int)$best['id']) {
                    $best = $row;
                }
            }

            $q = strtoupper(trim((string)$best['qualis']));
            return ['q' => $q !== '' ? $q : null, 'j' => (int)$best['id']];
        }

        return null;
    }

    /**
     * Etapas 2 a 4 (por nome), memoizadas por nome. Os dois modos dão o mesmo resultado.
     *
     * @return array{entry: ?array{q: ?string, j: int}, ambiguous: bool}
     */
    private function resolveName(?string $name): array
    {
        if ($name === null || trim($name) === '') {
            return ['entry' => null, 'ambiguous' => false];
        }

        if (isset($this->memoByName[$name])) {
            return $this->memoByName[$name];
        }

        $res = $this->isFullLoaded ? $this->resolveNameInFullMaps($name) : $this->resolveNameInDatabase($name);
        $this->memoByName[$name] = $res;

        return $res;
    }

    /**
     * Etapas 2 a 4 em memória (modo lote).
     *
     * @return array{entry: ?array{q: ?string, j: int}, ambiguous: bool}
     */
    private function resolveNameInFullMaps(string $name): array
    {
        $none = ['entry' => null, 'ambiguous' => false];

        // Etapa 2: nome exato (a chave do título do cadastro sem parênteses e as variantes estão no mesmo mapa)
        $exact = self::normalizeString($name);
        if ($exact === '') {
            return $none;
        }
        if (isset($this->titleMap[$exact])) {
            return $this->resultFromMap($this->titleMap[$exact]);
        }

        // Etapa 3: nome sem parênteses
        $clean = self::normalizeString(self::stripParentheses($name));
        if ($clean !== '' && $clean !== $exact && isset($this->titleMap[$clean])) {
            return $this->resultFromMap($this->titleMap[$clean]);
        }

        // Etapa 4: chave compacta
        $compact = $clean !== '' ? self::compactKey($clean) : null;
        if ($compact !== null && isset($this->compactMap[$compact])) {
            return $this->resultFromMap($this->compactMap[$compact]);
        }

        return $none;
    }

    /**
     * @param int|array<int, int> $hit
     * @return array{entry: ?array{q: ?string, j: int}, ambiguous: bool}
     */
    private function resultFromMap(int|array $hit): array
    {
        if (is_array($hit)) {
            return ['entry' => null, 'ambiguous' => true];
        }

        return ['entry' => $this->entryFor($hit), 'ambiguous' => false];
    }

    /**
     * Etapas 2 a 4 direto no MySQL, pelos índices existentes (modo on-demand).
     * Depende de `qualis_journals.normalized_title` preenchido (app:journals:normalize-titles).
     *
     * @return array{entry: ?array{q: ?string, j: int}, ambiguous: bool}
     */
    private function resolveNameInDatabase(string $name): array
    {
        $none = ['entry' => null, 'ambiguous' => false];

        // Etapa 2: nome exato
        $exact = self::normalizeString($name);
        if ($exact === '') {
            return $none;
        }
        $rows = $this->findRowsByName($name, $exact);
        if ($rows !== []) {
            return $this->decide($rows);
        }

        // Etapa 3: nome sem parênteses
        $stripped = self::stripParentheses($name);
        $clean = self::normalizeString($stripped);
        if ($clean !== '' && $clean !== $exact) {
            $rows = $this->findRowsByName($stripped, $clean);
            if ($rows !== []) {
                return $this->decide($rows);
            }
        }

        // Etapa 4: chave compacta
        $compact = $clean !== '' ? self::compactKey($clean) : null;
        if ($compact !== null) {
            $rows = $this->findRowsByCompactKey($compact);
            if ($rows !== []) {
                return $this->decide($rows);
            }
        }

        return $none;
    }

    /**
     * Linhas cuja chave (título, título sem parênteses ou variante ativa) é $key.
     * O SQL só afunila; a comparação final é feita em PHP com a mesma função do modo lote, para que a collation
     * do MySQL (que iguala caracteres como «ª» e «a») não crie diferenças entre os modos.
     *
     * @param string $name nome (ou nome sem parênteses) de onde $key foi calculada
     * @return list<array{id: int, q: ?string, fam: int, kind: int}> kind 0 = título, 1 = variante
     */
    private function findRowsByName(string $name, string $key): array
    {
        $thesaurusForm = StringNormalizer::normalizeString(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if ($thesaurusForm === '') {
            return [];
        }

        $conn = $this->em->getConnection();
        $rows = [];

        // Títulos: iguais ao nome, ou com um complemento entre parênteses depois dele («Movimento (Porto Alegre)»),
        // que o modo lote também alcança pela chave sem parênteses do título.
        $titles = $conn->fetchAllAssociative(
            "SELECT id, qualis, COALESCE(canonical_journal_id, id) AS fam, title, normalized_title
               FROM qualis_journals
              WHERE normalized_title = ? OR (normalized_title LIKE ? AND title LIKE '%(%')",
            [$thesaurusForm, $thesaurusForm . ' %']
        );
        foreach ($titles as $row) {
            [$exact, $clean] = self::titleKeys($row['title'], $row['normalized_title']);
            if ($exact === $key || $clean === $key) {
                $rows[] = $this->candidateRow($row, 0);
            }
        }

        $variants = $conn->fetchAllAssociative(
            'SELECT q.id, q.qualis, COALESCE(q.canonical_journal_id, q.id) AS fam, v.normalized_name
               FROM journal_name_variants v
               JOIN qualis_journals q ON q.id = v.journal_id
              WHERE v.normalized_name = ? AND v.status = 1',
            [$thesaurusForm]
        );
        foreach ($variants as $row) {
            if (self::normalizeString($row['normalized_name']) === $key) {
                $rows[] = $this->candidateRow($row, 1);
            }
        }

        return $rows;
    }

    /**
     * Linhas cujo título (com ou sem parênteses) tem a chave compacta $compact. O SQL candidata os títulos que
     * começam pela primeira palavra da chave (ou por um conectivo seguido dela, como em «The Journal of...») e
     * contêm a última; a comparação da chave compacta é feita em PHP.
     *
     * @return list<array{id: int, q: ?string, fam: int, kind: int}>
     */
    private function findRowsByCompactKey(string $compact): array
    {
        $tokens = explode(' ', $compact);
        $first = strtoupper($tokens[0]);
        $last = strtoupper($tokens[count($tokens) - 1]);

        $prefixes = [$first . '%'];
        foreach (self::COMPACT_STOPWORDS as $stopWord) {
            $prefixes[] = strtoupper($stopWord) . ' ' . $first . '%';
        }

        $found = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, qualis, COALESCE(canonical_journal_id, id) AS fam, title, normalized_title
               FROM qualis_journals
              WHERE (' . implode(' OR ', array_fill(0, count($prefixes), 'normalized_title LIKE ?')) . ')
                AND normalized_title LIKE ?',
            [...$prefixes, '%' . $last . '%']
        );

        $rows = [];
        foreach ($found as $row) {
            [$exact, $clean] = self::titleKeys($row['title'], $row['normalized_title']);
            if (self::compactKey($exact) === $compact || ($clean !== '' && self::compactKey($clean) === $compact)) {
                $rows[] = $this->candidateRow($row, 0);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, q: ?string, fam: int, kind: int}
     */
    private function candidateRow(array $row, int $kind): array
    {
        $q = strtoupper(trim((string)$row['qualis']));

        return ['id' => (int)$row['id'], 'q' => $q !== '' ? $q : null, 'fam' => (int)$row['fam'], 'kind' => $kind];
    }

    /**
     * Linhas de uma só família resolvem para a linha de menor id entre os títulos encontrados, ou, se só houve
     * variantes, a da variante. Linhas de mais de uma família: ambíguo.
     *
     * @param non-empty-list<array{id: int, q: ?string, fam: int, kind: int}> $rows
     * @return array{entry: ?array{q: ?string, j: int}, ambiguous: bool}
     */
    private function decide(array $rows): array
    {
        $byFamily = [];
        foreach ($rows as $row) {
            $current = $byFamily[$row['fam']] ?? null;
            if ($current === null
                || $row['kind'] < $current['kind']
                || ($row['kind'] === $current['kind'] && $row['id'] < $current['id'])
            ) {
                $byFamily[$row['fam']] = $row;
            }
        }

        if (count($byFamily) > 1) {
            return ['entry' => null, 'ambiguous' => true];
        }

        $best = reset($byFamily);

        return ['entry' => ['q' => $best['q'], 'j' => $best['id']], 'ambiguous' => false];
    }

    /**
     * Monta (uma vez) o mapa de palavras-chave usado só por suggestByKeywords(). Mesmo conteúdo nos dois modos.
     */
    private function ensureKeywordMap(): void
    {
        if ($this->keywordMap !== null) {
            return;
        }

        $conn = $this->em->getConnection();
        $map = [];

        $stmt = $conn->executeQuery('SELECT id, title FROM qualis_journals ORDER BY id');
        while ($r = $stmt->fetchAssociative()) {
            $jid = (int)$r['id'];
            $title = (string)$r['title'];

            $kw = self::cleanKeywords($title);
            if ($kw !== '' && !isset($map[$kw])) {
                $map[$kw] = $jid;
            }

            if (str_contains($title, '(')) {
                $cleanKw = self::cleanKeywords(self::stripParentheses($title));
                if ($cleanKw !== '' && !isset($map[$cleanKw])) {
                    $map[$cleanKw] = $jid;
                }
            }
        }

        $varStmt = $conn->executeQuery('SELECT journal_id, normalized_name FROM journal_name_variants WHERE status = 1 ORDER BY journal_id, id');
        while ($vr = $varStmt->fetchAssociative()) {
            $varKw = self::cleanKeywords($vr['normalized_name']);
            if ($varKw !== '' && !isset($map[$varKw])) {
                $map[$varKw] = (int)$vr['journal_id'];
            }
        }

        $this->keywordMap = $map;
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
