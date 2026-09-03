<?php

declare(strict_types=1);

namespace App\Service\Indexing;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

class ThematicTermIndexService
{
    private AsciiSlugger $slugger;

    /**
     * @var array<string, bool>
     */
    private array $stopWordsMap;

    /**
     * @var array<string, bool>
     */
    private array $connectorsMap;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger
    ) {
        $this->slugger = new AsciiSlugger();
        $this->initializeStopWords();
    }

    /**
     * Executa a indexação completa de palavras-chave e temas para todos os docentes.
     *
     * @param callable|null $progressCallback Callback opcional function(int $step, int $totalSteps, string $message): void
     * @return array{totalTerms: int, totalLinks: int, totalResearchers: int, executionTime: float}
     */
    public function indexAll(?callable $progressCallback = null): array
    {
        ini_set('memory_limit', '-1');
        $startTime = microtime(true);
        $conn = $this->em->getConnection();

        // 1. Obter todos os pesquisadores cadastrados
        $researchers = $conn->fetchAllAssociative('SELECT id, full_name, department FROM researchers ORDER BY id ASC');
        $totalResearchers = count($researchers);
        $validResearcherIds = [];
        foreach ($researchers as $r) {
            $validResearcherIds[(int)$r['id']] = true;
        }

        if ($progressCallback) {
            $progressCallback(1, 4, 'Carregando e minerando produções bibliográficas...');
        }

        // Estrutura em memória compacta:
        // $termsMap[normalizedTerm] = [
        //    'c' => canonical,
        //    't' => sourceType ('keyword'|'mined'|'hybrid'),
        //    'r' => [
        //        researcherId => [
        //            'o' => occurrences (int),
        //            's' => sampleTitle (string)
        //        ]
        //    ]
        // ]
        $termsMap = [];

        // 2. Extração de Produções Bibliográficas via Stream DBAL
        $stmtProds = $conn->executeQuery('SELECT researcher_id, title, extra_data FROM production_items WHERE researcher_id IS NOT NULL');
        while ($row = $stmtProds->fetchAssociative()) {
            $rId = (int)$row['researcher_id'];
            if (!isset($validResearcherIds[$rId])) {
                continue;
            }

            $title = (string)($row['title'] ?? '');

            // Palavras-chave declaradas na produção
            if (!empty($row['extra_data'])) {
                $extra = json_decode((string)$row['extra_data'], true);
                if (is_array($extra) && !empty($extra['keywords']) && is_array($extra['keywords'])) {
                    foreach ($extra['keywords'] as $kw) {
                        if (is_string($kw)) {
                            $this->addTerm($kw, $title, $rId, 'keyword', 2, $termsMap);
                        }
                    }
                }
            }

            // Mineração do título da produção
            if ($title !== '') {
                $this->mineTitle($title, $rId, 1, $termsMap);
            }
        }
        unset($stmtProds);

        if ($progressCallback) {
            $progressCallback(2, 4, 'Minerando teses, dissertações e projetos de pesquisa...');
        }

        // 3. Extração de Orientações e Repositório UFSCar via Stream DBAL
        $stmtOrients = $conn->executeQuery('SELECT researcher_id, title, alternative_title, keywords FROM orientations WHERE researcher_id IS NOT NULL');
        while ($row = $stmtOrients->fetchAssociative()) {
            $rId = (int)$row['researcher_id'];
            if (!isset($validResearcherIds[$rId])) {
                continue;
            }

            $title = (string)($row['title'] ?? '');
            $altTitle = (string)($row['alternative_title'] ?? '');
            $sampleTitle = $title !== '' ? $title : $altTitle;

            // Palavras-chave declaradas (Lattes e Repositório UFSCar - "Assuntos")
            $kwStr = (string)($row['keywords'] ?? '');
            if ($kwStr !== '') {
                $parts = preg_split('/[;,]/u', $kwStr) ?: [];
                foreach ($parts as $kw) {
                    $this->addTerm($kw, $sampleTitle, $rId, 'keyword', 2, $termsMap);
                }
            }

            // Mineração do título da orientação (peso 2)
            if ($title !== '') {
                $this->mineTitle($title, $rId, 2, $termsMap);
            }
            if ($altTitle !== '' && $altTitle !== $title) {
                $this->mineTitle($altTitle, $rId, 1, $termsMap);
            }
        }
        unset($stmtOrients);

        // 4. Extração de Projetos de Pesquisa
        $stmtProjects = $conn->executeQuery('SELECT researcher_id, name FROM research_projects WHERE researcher_id IS NOT NULL');
        while ($row = $stmtProjects->fetchAssociative()) {
            $rId = (int)$row['researcher_id'];
            if (!isset($validResearcherIds[$rId])) {
                continue;
            }
            $name = (string)($row['name'] ?? '');
            if ($name !== '') {
                $this->mineTitle($name, $rId, 2, $termsMap);
            }
        }
        unset($stmtProjects);

        if ($progressCallback) {
            $progressCallback(3, 4, 'Filtrando e consolidando índice de termos...');
        }

        // 5. Filtragem in-place para economia máxima de memória
        foreach ($termsMap as $normalized => &$data) {
            $totalOccurrences = 0;
            foreach ($data['r'] as $rData) {
                $totalOccurrences += $rData['o'];
            }

            // Descarta termos de ocorrência única (ruídos)
            if ($totalOccurrences < 2) {
                unset($termsMap[$normalized]);
                continue;
            }

            $data['totalOccurrences'] = $totalOccurrences;
            $data['researcherCount'] = count($data['r']);
        }
        unset($data);

        if ($progressCallback) {
            $progressCallback(4, 4, 'Persistindo dados no banco de dados...');
        }

        // 6. Persistência em lote
        $stats = $this->persistIndexedTerms($termsMap, $conn);
        unset($termsMap);

        $executionTime = round(microtime(true) - $startTime, 2);

        $this->logger->info(sprintf(
            'Indexação temática concluída: %d termos, %d vínculos em %.2fs.',
            $stats['totalTerms'],
            $stats['totalLinks'],
            $executionTime
        ));

        return [
            'totalTerms' => $stats['totalTerms'],
            'totalLinks' => $stats['totalLinks'],
            'totalResearchers' => $totalResearchers,
            'executionTime' => $executionTime,
        ];
    }

    /**
     * Minera termos (unigramas e compostos) a partir de um título.
     *
     * @param array<string, array{
     *     c: string,
     *     t: string,
     *     r: array<int, array{o: int, s: string}>
     * }> $termsMap
     */
    private function mineTitle(string $text, int $researcherId, int $weight, array &$termsMap): void
    {
        $clean = mb_strtolower(trim($text), 'UTF-8');
        preg_match_all('/[a-záàâãéèêíïóôõöúçñ0-9]+/u', $clean, $matches);
        $words = $matches[0] ?? [];
        $totalWords = count($words);

        $compounds = [];
        $unigrams = [];

        for ($i = 0; $i < $totalWords; $i++) {
            $w1 = $words[$i];
            if (mb_strlen($w1) < 3 || isset($this->stopWordsMap[$w1]) || is_numeric($w1) || preg_match('/^[ivxlcdm]+$/i', $w1)) {
                continue;
            }

            $unigrams[$w1] = ($unigrams[$w1] ?? 0) + $weight;

            if ($i + 1 < $totalWords) {
                $w2 = $words[$i + 1];
                if (mb_strlen($w2) >= 3 && !isset($this->stopWordsMap[$w2]) && !is_numeric($w2) && !preg_match('/^[ivxlcdm]+$/i', $w2)) {
                    $comp2 = $w1 . ' ' . $w2;
                    $compounds[$comp2] = ($compounds[$comp2] ?? 0) + $weight;
                }

                if (isset($this->connectorsMap[$w2]) && $i + 2 < $totalWords) {
                    $w3 = $words[$i + 2];
                    if (mb_strlen($w3) >= 3 && !isset($this->stopWordsMap[$w3]) && !is_numeric($w3) && !preg_match('/^[ivxlcdm]+$/i', $w3)) {
                        $comp3 = $w1 . ' ' . $w2 . ' ' . $w3;
                        $compounds[$comp3] = ($compounds[$comp3] ?? 0) + $weight;
                    }
                }
            }
        }

        // Compostos
        $absorbed = [];
        foreach ($compounds as $phrase => $count) {
            $this->addTerm($phrase, $text, $researcherId, 'mined', $count, $termsMap);
            foreach (explode(' ', $phrase) as $p) {
                if (!isset($this->connectorsMap[$p])) {
                    $absorbed[$p] = ($absorbed[$p] ?? 0) + $count;
                }
            }
        }

        // Unigramas com saldo positivo e comprimento mínimo de 4 letras
        foreach ($unigrams as $word => $count) {
            $net = $count - ($absorbed[$word] ?? 0);
            if ($net > 0 && mb_strlen($word) >= 4) {
                $this->addTerm($word, $text, $researcherId, 'mined', $net, $termsMap);
            }
        }
    }

    /**
     * Adiciona ou acumula um termo para um pesquisador em $termsMap.
     */
    private function addTerm(
        string $rawTerm,
        string $sampleTitle,
        int $researcherId,
        string $sourceType,
        int $increment,
        array &$termsMap
    ): void {
        $cleaned = trim(trim($rawTerm), " \t\n\r\0\x0B.,;:-/()[]{}\"'");
        if (mb_strlen($cleaned) < 3) {
            return;
        }

        if (is_numeric($cleaned) || preg_match('/^\d+$/', $cleaned)) {
            return;
        }

        $normalized = $this->normalizeString($cleaned);
        if ($normalized === '' || mb_strlen($normalized) < 3 || is_numeric($normalized)) {
            return;
        }

        if (isset($this->stopWordsMap[$normalized])) {
            return;
        }

        if (!isset($termsMap[$normalized])) {
            $termsMap[$normalized] = [
                'c' => $this->formatCanonicalTerm($cleaned),
                't' => $sourceType,
                'r' => [],
            ];
        } else {
            if ($sourceType === 'keyword' && $termsMap[$normalized]['t'] === 'mined') {
                $termsMap[$normalized]['t'] = 'hybrid';
            }
        }

        if (!isset($termsMap[$normalized]['r'][$researcherId])) {
            $termsMap[$normalized]['r'][$researcherId] = [
                'o' => 0,
                's' => $sampleTitle,
            ];
        }

        $termsMap[$normalized]['r'][$researcherId]['o'] += $increment;
        if ($termsMap[$normalized]['r'][$researcherId]['s'] === '' && $sampleTitle !== '') {
            $termsMap[$normalized]['r'][$researcherId]['s'] = $sampleTitle;
        }
    }

    /**
     * Persiste os termos filtrados usando batch inserts diretos no MySQL.
     *
     * @param array<string, array{
     *     c: string,
     *     t: string,
     *     totalOccurrences: int,
     *     researcherCount: int,
     *     r: array<int, array{o: int, s: string}>
     * }> $termsMap
     * @return array{totalTerms: int, totalLinks: int}
     */
    private function persistIndexedTerms(array &$termsMap, Connection $conn): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $conn->executeStatement('TRUNCATE TABLE thematic_term_researchers');
        $conn->executeStatement('TRUNCATE TABLE thematic_terms');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $totalTerms = 0;
        $totalLinks = 0;

        $usedSlugs = [];
        $termBatch = [];

        $conn->beginTransaction();
        try {
            foreach ($termsMap as $normalized => $tData) {
                $rawSlug = $this->slugger->slug(mb_strtolower($tData['c'], 'UTF-8'))->lower()->toString();
                if ($rawSlug === '') {
                    $rawSlug = 'termo-' . substr(md5($normalized), 0, 10);
                }

                $slug = $rawSlug;
                if (isset($usedSlugs[$slug])) {
                    $counter = 2;
                    while (isset($usedSlugs[$slug . '-' . $counter])) {
                        $counter++;
                    }
                    $slug = $slug . '-' . $counter;
                }
                $usedSlugs[$slug] = true;

                $normalizedStr = (string)$normalized;
                $termBatch[] = [
                    'term' => mb_substr((string)$tData['c'], 0, 190),
                    'slug' => mb_substr((string)$slug, 0, 190),
                    'normalized_term' => mb_substr($normalizedStr, 0, 190),
                    'total_occurrences' => (int)$tData['totalOccurrences'],
                    'researcher_count' => (int)$tData['researcherCount'],
                    'source_type' => (string)$tData['t'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($termBatch) >= 1000) {
                    $this->insertBatchTerms($conn, $termBatch);
                    $termBatch = [];
                }
            }

            if (!empty($termBatch)) {
                $this->insertBatchTerms($conn, $termBatch);
            }

            // Mapeia IDs gerados
            $rows = $conn->fetchAllAssociative('SELECT id, normalized_term FROM thematic_terms');
            $normToId = [];
            foreach ($rows as $row) {
                $normToId[(string)$row['normalized_term']] = (int)$row['id'];
            }
            $totalTerms = count($normToId);

            // Inserir vínculos de pesquisadores
            $linkBatch = [];
            foreach ($termsMap as $normalized => $tData) {
                $normalizedStr = (string)$normalized;
                $termId = $normToId[$normalizedStr] ?? null;
                if (!$termId) {
                    continue;
                }

                foreach ($tData['r'] as $rId => $rData) {
                    $samples = $rData['s'] !== '' ? [$rData['s']] : [];
                    $linkBatch[] = [
                        'term_id' => $termId,
                        'researcher_id' => $rId,
                        'occurrences' => $rData['o'],
                        'sample_titles' => json_encode($samples, JSON_UNESCAPED_UNICODE),
                        'created_at' => $now,
                    ];
                    $totalLinks++;

                    if (count($linkBatch) >= 1500) {
                        $this->insertBatchLinks($conn, $linkBatch);
                        $linkBatch = [];
                    }
                }
            }

            if (!empty($linkBatch)) {
                $this->insertBatchLinks($conn, $linkBatch);
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return [
            'totalTerms' => $totalTerms,
            'totalLinks' => $totalLinks,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function insertBatchTerms(Connection $conn, array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        $cols = ['term', 'slug', 'normalized_term', 'total_occurrences', 'researcher_count', 'source_type', 'created_at', 'updated_at'];
        $rowPlaceholder = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $sql = 'INSERT INTO thematic_terms (' . implode(',', $cols) . ') VALUES ';
        $sql .= implode(',', array_fill(0, count($batch), $rowPlaceholder));

        $params = [];
        foreach ($batch as $row) {
            foreach ($cols as $col) {
                $params[] = $row[$col];
            }
        }

        $conn->executeStatement($sql, $params);
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function insertBatchLinks(Connection $conn, array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        $cols = ['term_id', 'researcher_id', 'occurrences', 'sample_titles', 'created_at'];
        $rowPlaceholder = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $sql = 'INSERT INTO thematic_term_researchers (' . implode(',', $cols) . ') VALUES ';
        $sql .= implode(',', array_fill(0, count($batch), $rowPlaceholder));

        $params = [];
        foreach ($batch as $row) {
            foreach ($cols as $col) {
                $params[] = $row[$col];
            }
        }

        $conn->executeStatement($sql, $params);
    }

    private function formatCanonicalTerm(string $term): string
    {
        $words = explode(' ', mb_strtolower(trim($term), 'UTF-8'));
        $cased = [];
        foreach ($words as $idx => $w) {
            if ($idx > 0 && isset($this->connectorsMap[$w])) {
                $cased[] = $w;
            } else {
                $cased[] = mb_convert_case($w, MB_CASE_TITLE, 'UTF-8');
            }
        }
        return implode(' ', $cased);
    }

    public function normalizeString(string $str): string
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

    private function initializeStopWords(): void
    {
        $connectors = ['de', 'da', 'do', 'das', 'dos', 'e', 'em', 'na', 'no', 'nas', 'nos', 'para', 'por', 'com', 'of', 'in', 'and', 'for', 'with'];
        $this->connectorsMap = array_fill_keys($connectors, true);

        $stopWords = [
            'de', 'a', 'o', 'que', 'e', 'do', 'da', 'em', 'um', 'para', 'é', 'com', 'não', 'uma', 'os', 'no', 'se', 'na', 'por', 'mais', 'as', 'dos', 'como', 'mas', 'foi', 'ao', 'ele', 'das', 'tem', 'à', 'seu', 'sua', 'ou', 'ser', 'quando', 'muito', 'há', 'nos', 'já', 'está', 'eu', 'também', 'só', 'pelo', 'pela', 'até', 'isso', 'ela', 'entre', 'era', 'depois', 'sem', 'mesmo', 'aos', 'ter', 'seus', 'quem', 'nas', 'me', 'esse', 'eles', 'estão', 'você', 'tinha', 'foram', 'essa', 'num', 'nem', 'suas', 'meu', 'às', 'minha', 'têm', 'numa', 'pelos', 'elas', 'havia', 'seja', 'qual', 'será', 'nós', 'tenho', 'lhe', 'deles', 'essas', 'esses', 'pelas', 'este', 'fosse', 'dele', 'tu', 'te', 'vocês', 'vos', 'lhes', 'meus', 'minhas', 'teu', 'tua', 'teus', 'tuas', 'nosso', 'nossa', 'nossos', 'nossas', 'dela', 'delas', 'esta', 'estes', 'estas', 'aquele', 'aquela', 'aqueles', 'aquelas', 'isto', 'aquilo', 'estou', 'está', 'estamos', 'estão', 'estive', 'esteve', 'estivemos', 'estiveram', 'estava', 'estávamos', 'estavam', 'estivera', 'estivéramos', 'esteja', 'estejamos', 'estejam', 'estivesse', 'estivéssemos', 'estivessem', 'estiver', 'estivermos', 'estiverem', 'hei', 'há', 'havemos', 'hão', 'houve', 'houvemos', 'houveram', 'houvera', 'houvéramos', 'haja', 'hajamos', 'hajam', 'houvesse', 'houvéssemos', 'houvessem', 'houver', 'houvermos', 'houverem', 'houverei', 'houverá', 'houveremos', 'houverão', 'houveria', 'houveríamos', 'houveriam', 'sou', 'somos', 'são', 'era', 'éramos', 'eram', 'fui', 'foi', 'fomos', 'foram', 'fora', 'fôramos', 'seja', 'sejamos', 'sejam', 'fosse', 'fôssemos', 'fossem', 'for', 'formos', 'forem', 'serei', 'será', 'seremos', 'serão', 'seria', 'seríamos', 'seriam', 'tenho', 'tem', 'temos', 'tém', 'tinha', 'tínhamos', 'tinham', 'tive', 'teve', 'tivemos', 'tiveram', 'tivera', 'tivéramos', 'tenha', 'tenhamos', 'tenham', 'tivesse', 'tivéssemos', 'tivessem', 'tiver', 'tivermos', 'tiverem', 'terei', 'terá', 'teremos', 'terão', 'teria', 'teríamos', 'teriam',
            'sobre', 'estudo', 'estudos', 'pesquisa', 'análise', 'analise', 'contexto', 'processo', 'processos', 'perspectiva', 'perspectivas',
            'proposta', 'aspectos', 'artigo', 'relatorio', 'relatório', 'projeto', 'trabalho', 'volume', 'anais',
            'revista', 'caderno', 'livro', 'capitulo', 'capítulo', 'resumo', 'edição', 'parte', 'partir', 'base',
            'uso', 'guia', 'apresentação', 'considerações', 'introdução', 'conclusão', 'ad', 'hoc', 'parecerista',
            'parecer', 'consultor', 'consultoria', 'cnpq', 'fapesp', 'capes', 'encaminhado', 'concedida', 'submetido', 'submetida',
            'apreciação', 'comitê', 'ética', 'hospital', 'clínicas', 'faculdade', 'universidade', 'instituto',
            'departamento', 'escola', 'ribeirão', 'preto', 'são', 'paulo', 'usp', 'ufscar', 'unesp', 'unicamp',
            'carlos', 'brasil', 'brazil', 'campus', 'docente', 'docentes', 'aluno', 'alunos',
            'sociedade', 'encontro', 'reunião', 'congresso', 'simpósio', 'seminário', 'jornada', 'progresso',
            'fundação', 'amparo', 'nacional', 'internacional', 'brasileira', 'brasileiro', 'estado', 'periódico',
            'submetidos', 'trabalhos', 'membro', 'avaliador', 'comissão', 'coordenador', 'coordenadora',
            'the', 'and', 'for', 'with', 'from', 'about', 'study', 'analysis', 'brazil', 'social', 'using',
            'between', 'into', 'through', 'during', 'before', 'after', 'above', 'below', 'year', 'data', 'journal', 'paper', 'review',
            'resumo', 'abstract', 'palavras', 'chave', 'keywords'
        ];
        $this->stopWordsMap = array_fill_keys($stopWords, true);
    }
}
