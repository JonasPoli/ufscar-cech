<?php

declare(strict_types=1);

namespace App\Service\Journal;

use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Constrói o artefato de evidências de área dos periódicos (A6.1 de `docs/evolutions/periodicos-por-base.md`).
 *
 * Lê as listas de cada base em `$sourcesDir` (Scopus, WoS, DOAJ, Latindex, OpenAlex, PubMed) e o derivado do
 * SciELO em `$derivedDir`, e grava um gzip com uma linha JSON por registro de fonte:
 *
 *   {"_meta":{"built_at":"...","sources":{"scopus":{"file":"ext_list_Jul_2026.xlsx","records":48888},...}}}
 *   {"src":"scopus","rec":"21101170611","issns":["20349130","22959149"],"title":"@GRH","labels":["1407"],"extra":{...}}
 *
 * Regras comuns: ISSNs na forma normalizada (8 caracteres minúsculos, só com dígito verificador válido, via
 * {@see Issn}); registro sem ISSN válido é mantido apenas se tiver título (casamento por título); as linhas saem
 * ordenadas por `src`, `rec` e `title` (bytes), então a mesma entrada gera sempre o mesmo conteúdo, salvo `built_at`.
 *
 * Fonte ausente (sem arquivo) ou pulada com `$skip` não entra em `_meta.sources`: quem carrega o artefato só
 * recarrega as fontes presentes nele.
 *
 * Além do artefato, grava os vocabulários (um rótulo por linha, ou `código<TAB>descrição` para ASJC e subcampos do
 * OpenAlex) em `$vocabDir`. Arquivo existente com o mesmo conteúdo não é regravado; se o conteúdo mudou, é gravado e
 * a diferença volta nos avisos.
 *
 * @phpstan-type Row array{0: string, 1: string, 2: string}
 * @phpstan-type SourceResult array{file: string, files: int, rows: list<Row>, vocab: array<string, array<string, string>>, notes: list<string>, warnings: list<string>}
 */
class JournalEvidenceArtifactBuilder
{
    /** Fontes do artefato, em ordem alfabética (é a ordem em que saem no arquivo). */
    public const SOURCES = ['doaj', 'latindex', 'openalex', 'pubmed', 'scielo', 'scopus', 'wos'];

    /** Vocabulários gravados em `$vocabDir`, um arquivo `<nome>.txt` cada. */
    public const VOCABULARIES = ['wos', 'doaj', 'latindex', 'scielo_desc', 'asjc', 'openalex_subfields'];

    /** Vocabulários cujo arquivo traz `código<TAB>descrição` em vez de um rótulo por linha. */
    private const PAIR_VOCABULARIES = ['asjc', 'openalex_subfields'];

    /** `PRESERVE_ZERO_FRACTION` mantém `share` como número decimal também quando vale 1 (`1.0`, não `1`). */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /** Índices do WoS que vêm primeiro quando o mesmo periódico aparece em vários CSVs (define o título mantido). */
    private const WOS_INDEX_PRIORITY = ['SCIE', 'SSCI', 'AHCI', 'ESCI'];

    /**
     * @param (callable(string): void)|null $progress recebe mensagens de andamento (início e fim de cada fonte)
     * @param list<string>                  $skip     fontes a não ler (valores de {@see self::SOURCES})
     *
     * @return array{
     *     built_at: string,
     *     out: string,
     *     bytes: int,
     *     records: int,
     *     sources: array<string, array<string, mixed>>,
     *     seconds: array<string, float>,
     *     skipped: list<string>,
     *     missing: list<string>,
     *     notes: list<string>,
     *     warnings: list<string>,
     *     vocab: array<string, array{path: string, lines: int, status: string, added: list<string>, removed: list<string>}>,
     *     total_seconds: float
     * }
     *
     * @throws \InvalidArgumentException fonte desconhecida em `$skip`
     * @throws \RuntimeException         pasta de fontes inexistente, arquivo em formato inesperado, nenhuma fonte lida
     */
    public function build(
        string $sourcesDir,
        string $derivedDir,
        string $outFile,
        ?string $vocabDir,
        ?callable $progress = null,
        array $skip = [],
    ): array {
        $startedAt = microtime(true);
        $skip = $this->normalizeSkip($skip);

        if (!is_dir($sourcesDir)) {
            throw new \RuntimeException(sprintf('Pasta das listas de periódicos não encontrada: %s', $sourcesDir));
        }

        $notify = static function (string $message) use ($progress): void {
            if ($progress !== null) {
                $progress($message);
            }
        };

        $builtAt = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $rowsBySource = [];
        $metaSources = [];
        $vocabulary = [];
        $seconds = [];
        $skipped = [];
        $missing = [];
        $notes = [];
        $warnings = [];

        foreach (self::SOURCES as $source) {
            if (in_array($source, $skip, true)) {
                $skipped[] = $source;
                continue;
            }

            $sourceStart = microtime(true);
            $notify(sprintf('%s: lendo...', $source));
            $result = $this->collect($source, $sourcesDir, $derivedDir, $notify);

            if ($result === null) {
                $missing[] = $source;
                $warnings[] = $this->missingMessage($source, $sourcesDir, $derivedDir);
                $notify(end($warnings));
                continue;
            }

            $count = count($result['rows']);
            if ($count === 0) {
                // Uma fonte vazia no artefato faria o carregamento apagar as evidências dela sem repor nada.
                throw new \RuntimeException(sprintf(
                    '%s: o arquivo foi encontrado mas não gerou nenhum registro; confira o formato ou use --skip=%s.%s',
                    $source,
                    $source,
                    $result['warnings'] === [] ? '' : ' Avisos: ' . implode(' ', $result['warnings'])
                ));
            }
            $rowsBySource[$source] = $result['rows'];
            $metaSources[$source] = ['file' => $result['file']]
                + ($result['files'] > 1 ? ['files' => $result['files']] : [])
                + ['records' => $count];
            foreach ($result['vocab'] as $name => $entries) {
                $vocabulary[$name] = $entries;
            }
            foreach ($result['notes'] as $note) {
                $notes[] = $note;
            }
            foreach ($result['warnings'] as $warning) {
                $warnings[] = $warning;
            }

            $seconds[$source] = round(microtime(true) - $sourceStart, 2);
            $notify(sprintf('%s: %d registros em %.1f s', $source, $count, $seconds[$source]));
        }

        if ($rowsBySource === []) {
            throw new \RuntimeException('Nenhuma fonte foi lida (todas puladas ou sem arquivo); o artefato não foi gravado.');
        }

        $meta = json_encode(['_meta' => ['built_at' => $builtAt, 'sources' => $metaSources]], self::JSON_FLAGS);
        $total = $this->writeArtifact($outFile, $meta, $rowsBySource);

        $vocabResult = [];
        if ($vocabDir !== null) {
            $vocabResult = $this->writeVocabularies($vocabDir, $vocabulary, $warnings);
        }

        return [
            'built_at' => $builtAt,
            'out' => $outFile,
            'bytes' => (int) filesize($outFile),
            'records' => $total,
            'sources' => $metaSources,
            'seconds' => $seconds,
            'skipped' => $skipped,
            'missing' => $missing,
            'notes' => $notes,
            'warnings' => $warnings,
            'vocab' => $vocabResult,
            'total_seconds' => round(microtime(true) - $startedAt, 2),
        ];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Fontes
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @return SourceResult|null null quando a fonte não tem arquivo
     */
    private function collect(string $source, string $sourcesDir, string $derivedDir, callable $notify): ?array
    {
        return match ($source) {
            'doaj' => $this->collectDoaj($sourcesDir),
            'latindex' => $this->collectLatindex($sourcesDir),
            'openalex' => $this->collectOpenAlex($sourcesDir, $notify),
            'pubmed' => $this->collectPubmed($sourcesDir),
            'scielo' => $this->collectScielo($derivedDir),
            'scopus' => $this->collectScopus($sourcesDir, $notify),
            'wos' => $this->collectWos($sourcesDir, $notify),
        };
    }

    /**
     * DOAJ: `Subjects` separado por `|`; `rec` = primeiro ISSN válido (ou o id do DOAJ, ou um md5 do título).
     *
     * @return SourceResult|null
     */
    private function collectDoaj(string $sourcesDir): ?array
    {
        $dir = $this->findDir($sourcesDir, ['doaj']);
        $files = $dir === null ? [] : $this->listFiles($dir, '*.csv');
        if ($files === []) {
            return null;
        }

        $file = end($files);
        $warnings = $this->ignoredFilesWarning('doaj', $files, $file);

        $rows = [];
        $subjects = [];
        $required = ['journal title', 'journal issn (print version)', 'journal eissn (online version)', 'subjects'];
        $optional = ['keywords', 'publisher', 'country of publisher', 'url in doaj'];

        foreach ($this->csvRows($file, ',', $required, $optional) as $r) {
            $title = $this->cleanText($r['journal title']);
            $issns = Issn::extractAll($r['journal issn (print version)'] . ' ' . $r['journal eissn (online version)']);
            if ($issns === [] && $title === '') {
                continue;
            }

            $labels = $this->splitList($r['subjects'], '|');
            foreach ($labels as $label) {
                $subjects[$label] = $label;
            }

            $rec = $issns[0] ?? $this->lastSegment($r['url in doaj']) ?? $this->fallbackRec($issns, $title);
            $rows[] = $this->row('doaj', $rec, $issns, $title, $labels, [
                'keywords' => $this->splitList($r['keywords'], ','),
                'country' => $this->nullIfEmpty($r['country of publisher']),
                'publisher' => $this->nullIfEmpty($r['publisher']),
            ]);
        }

        return $this->result(basename($file), 1, $rows, ['doaj' => $subjects], [], $warnings);
    }

    /**
     * Latindex: delimitador `;` (arquivo com BOM), `subtemas` separados por vírgula, `nombre_largo` = país,
     * `nombre_edi` = editora, `rec` = `folio_u`.
     *
     * @return SourceResult|null
     */
    private function collectLatindex(string $sourcesDir): ?array
    {
        $dir = $this->findDir($sourcesDir, ['latindex']);
        $files = $dir === null ? [] : $this->listFiles($dir, '*.csv');
        if ($files === []) {
            return null;
        }

        $file = end($files);
        $warnings = $this->ignoredFilesWarning('latindex', $files, $file);

        $rows = [];
        $subtopics = [];
        $required = ['folio_u', 'tit_propio', 'subtemas'];
        $optional = ['nombre_edi', 'nombre_largo', 'issn_imp', 'issn_e', 'issn_l'];

        foreach ($this->csvRows($file, ';', $required, $optional) as $r) {
            $title = $this->cleanText($r['tit_propio']);
            $issns = Issn::extractAll($r['issn_imp'] . ' ' . $r['issn_e'] . ' ' . $r['issn_l']);
            if ($issns === [] && $title === '') {
                continue;
            }

            $labels = $this->splitList($r['subtemas'], ',');
            foreach ($labels as $label) {
                $subtopics[$label] = $label;
            }

            $rec = $r['folio_u'] !== '' ? $r['folio_u'] : $this->fallbackRec($issns, $title);
            $rows[] = $this->row('latindex', $rec, $issns, $title, $labels, [
                'country' => $this->nullIfEmpty($r['nombre_largo']),
                'publisher' => $this->nullIfEmpty($r['nombre_edi']),
            ]);
        }

        return $this->result(basename($file), 1, $rows, ['latindex' => $subtopics], [], $warnings);
    }

    /**
     * PubMed (`J_Medline.txt`): blocos `Chave: valor` separados por uma linha de hifens; `rec` = `NlmId`, sem rótulos.
     *
     * @return SourceResult|null
     */
    private function collectPubmed(string $sourcesDir): ?array
    {
        $dir = $this->findDir($sourcesDir, ['pubmed']);
        $file = null;
        if ($dir !== null) {
            foreach ($this->listFiles($dir, '*.txt') as $candidate) {
                if (strcasecmp(basename($candidate), 'J_Medline.txt') === 0) {
                    $file = $candidate;
                    break;
                }
                $file ??= $candidate;
            }
        }
        if ($file === null) {
            return null;
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Não foi possível abrir %s.', $file));
        }

        $rows = [];
        $block = [];
        $flush = function () use (&$block, &$rows): void {
            if ($block === []) {
                return;
            }
            $title = $this->cleanText($block['journaltitle'] ?? '');
            $issns = Issn::extractAll(($block['issn (print)'] ?? '') . ' ' . ($block['issn (online)'] ?? ''));
            if ($issns !== [] || $title !== '') {
                $nlm = trim($block['nlmid'] ?? '');
                $rec = $nlm !== '' ? $nlm : $this->fallbackRec($issns, $title);
                $rows[] = $this->row('pubmed', $rec, $issns, $title, [], [
                    'medabbr' => $this->nullIfEmpty($block['medabbr'] ?? ''),
                ]);
            }
            $block = [];
        };

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (str_starts_with($line, '---')) {
                    $flush();
                    continue;
                }
                $pos = strpos($line, ':');
                if ($pos === false) {
                    continue;
                }
                $block[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
            $flush();
        } finally {
            fclose($handle);
        }

        return $this->result(basename($file), 1, $rows, [], [], []);
    }

    /**
     * SciELO: lê `scielo_articlemeta.json` (gerado por `app:journals:evidence:fetch-scielo`) em `$derivedDir`;
     * `rec` = código do periódico no SciELO, `labels` = `area` (v441); `wos` (v854) e `desc` (v440) ficam no `extra`
     * e o carregamento os desdobra em `scielo_wos` e `scielo_desc`.
     *
     * @return SourceResult|null
     */
    private function collectScielo(string $derivedDir): ?array
    {
        $file = rtrim($derivedDir, '/') . '/scielo_articlemeta.json';
        if (!is_file($file)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('%s não é um JSON válido: %s', $file, $e->getMessage()), 0, $e);
        }
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('%s deveria ser um objeto JSON indexado pelo código do periódico.', $file));
        }

        $rows = [];
        $descriptors = [];
        foreach ($data as $code => $journal) {
            if (!is_array($journal)) {
                continue;
            }

            $title = $this->cleanText((string) ($journal['title'] ?? ''));
            if ($title === '') {
                $title = $this->cleanText((string) ($journal['short'] ?? ''));
            }

            $issns = [];
            $types = [];
            foreach ((array) ($journal['issns'] ?? []) as $item) {
                $raw = is_array($item) ? (string) ($item['issn'] ?? '') : (string) $item;
                $type = is_array($item) ? trim((string) ($item['type'] ?? '')) : '';
                foreach (Issn::extractAll($raw) as $issn) {
                    if (!in_array($issn, $issns, true)) {
                        $issns[] = $issn;
                    }
                    if ($type !== '') {
                        $types[$issn] ??= $type;
                    }
                }
            }
            if ($issns === [] && $title === '') {
                continue;
            }

            $desc = $this->stringList($journal['desc'] ?? []);
            foreach ($desc as $label) {
                $descriptors[$label] = $label;
            }

            $rec = (string) $code !== '' ? (string) $code : $this->fallbackRec($issns, $title);
            $rows[] = $this->row('scielo', $rec, $issns, $title, $this->stringList($journal['area'] ?? []), [
                'wos' => $this->stringList($journal['wos'] ?? []),
                'desc' => $desc,
                'status' => $this->nullIfEmpty((string) ($journal['status'] ?? '')),
                'issn_types' => $types === [] ? new \stdClass() : $types,
            ]);
        }

        return $this->result(basename($file), 1, $rows, ['scielo_desc' => $descriptors], [], []);
    }

    /**
     * Scopus: aba `Scopus Sources ...` do xlsx; `All Science Journal Classification Codes (ASJC)` separado por `;`;
     * `Source Type` (Journal, Book Series, Trade Journal...) fica em `extra.type`. A aba `ASJC Classification Codes`
     * do mesmo arquivo alimenta o vocabulário `asjc`.
     *
     * @return SourceResult|null
     */
    private function collectScopus(string $sourcesDir, callable $notify): ?array
    {
        $dir = $this->findDir($sourcesDir, ['scopus']);
        $warnings = [];
        $chosen = [];
        foreach ($dir === null ? [] : $this->listFiles($dir, '*.xlsx') as $candidate) {
            try {
                $reader = IOFactory::createReaderForFile($candidate);
                $names = method_exists($reader, 'listWorksheetNames') ? $reader->listWorksheetNames($candidate) : [];
            } catch (\Throwable $e) {
                $warnings[] = sprintf('scopus: não foi possível abrir %s (%s); arquivo ignorado.', basename($candidate), $e->getMessage());
                continue;
            }
            foreach ($names as $name) {
                if (preg_match('/^\s*scopus sources\b/i', (string) $name) === 1) {
                    $chosen[] = [$candidate, (string) $name];
                    break;
                }
            }
        }
        if ($chosen === []) {
            return null;
        }

        [$file, $sheetName] = end($chosen);
        if (count($chosen) > 1) {
            $warnings[] = sprintf(
                'scopus: %d arquivos têm a aba "Scopus Sources"; usado o último por nome (%s).',
                count($chosen),
                basename($file)
            );
        }

        $notify(sprintf('scopus: lendo a aba "%s" de %s (leva alguns segundos)', $sheetName, basename($file)));

        $rows = [];
        foreach ($this->scopusRows($file, $sheetName) as $r) {
            $title = $this->cleanText($r['title']);
            $issns = Issn::extractAll($this->padIssn($r['issn']) . ' ' . $this->padIssn($r['eissn']));
            if ($issns === [] && $title === '') {
                continue;
            }

            $rec = $r['rec'] !== '' ? $r['rec'] : $this->fallbackRec($issns, $title);
            $rows[] = $this->row('scopus', $rec, $issns, $title, $this->splitList($r['asjc'], ';'), [
                'publisher' => $this->nullIfEmpty($this->cleanText($r['publisher'])),
                'active' => $r['active'] === '' ? null : strcasecmp($r['active'], 'active') === 0,
                'type' => $this->nullIfEmpty($r['type']),
            ]);
        }

        $vocab = [];
        $asjc = $this->readAsjcVocabulary($file);
        if ($asjc === []) {
            $warnings[] = sprintf('scopus: aba "ASJC Classification Codes" ausente ou vazia em %s; vocabulário asjc não gerado.', basename($file));
        } else {
            $vocab['asjc'] = $asjc;
        }

        return $this->result(basename($file), 1, $rows, $vocab, [], $warnings);
    }

    /**
     * WoS: todos os CSVs da pasta, exceto `JCR*.csv` (não tem ISSN). `Web of Science Categories` separado por `|`.
     * Um periódico em vários arquivos vira um único registro (deduplicação pelo conjunto de ISSNs, ou pelo título
     * quando não há ISSN válido), com a união das categorias e a lista dos índices em `extra.indexes`.
     *
     * @return SourceResult|null
     */
    private function collectWos(string $sourcesDir, callable $notify): ?array
    {
        $dir = $this->findDir($sourcesDir, ['webofscience', 'wos']);
        $files = $dir === null ? [] : $this->listFiles($dir, '*.csv');
        $files = array_values(array_filter(
            $files,
            static fn (string $file): bool => preg_match('/^jcr\b/i', basename($file)) !== 1
        ));
        if ($files === []) {
            return null;
        }

        usort($files, function (string $a, string $b): int {
            $rankA = array_search($this->wosIndexName($a), self::WOS_INDEX_PRIORITY, true);
            $rankB = array_search($this->wosIndexName($b), self::WOS_INDEX_PRIORITY, true);

            return [$rankA === false ? PHP_INT_MAX : $rankA, basename($a)] <=> [$rankB === false ? PHP_INT_MAX : $rankB, basename($b)];
        });

        $warnings = [];
        $categories = [];
        $merged = [];
        $readFiles = 0;
        $required = ['journal title', 'issn', 'eissn', 'web of science categories'];

        foreach ($files as $file) {
            $index = $this->wosIndexName($file);
            $notify(sprintf('wos: %s', basename($file)));

            try {
                foreach ($this->csvRows($file, ',', $required) as $r) {
                    $title = $this->cleanText($r['journal title']);
                    $issns = Issn::extractAll($r['issn'] . ' ' . $r['eissn']);
                    if ($issns === [] && $title === '') {
                        continue;
                    }

                    $key = $issns !== []
                        ? 'i:' . implode(',', $this->sorted($issns))
                        : 't:' . mb_strtolower($title);

                    $labels = $this->splitList($r['web of science categories'], '|');
                    foreach ($labels as $label) {
                        $categories[$label] = $label;
                    }

                    if (!isset($merged[$key])) {
                        $merged[$key] = ['issns' => $issns, 'title' => $title, 'labels' => [], 'indexes' => []];
                    }
                    foreach ($labels as $label) {
                        if (!in_array($label, $merged[$key]['labels'], true)) {
                            $merged[$key]['labels'][] = $label;
                        }
                    }
                    if (!in_array($index, $merged[$key]['indexes'], true)) {
                        $merged[$key]['indexes'][] = $index;
                    }
                }
                ++$readFiles;
            } catch (\RuntimeException $e) {
                $warnings[] = sprintf('wos: %s ignorado (%s)', basename($file), $e->getMessage());
            }
        }

        $rows = [];
        foreach ($merged as $record) {
            $rec = $record['issns'][0] ?? $this->fallbackRec([], $record['title']);
            $rows[] = $this->row('wos', $rec, $record['issns'], $record['title'], $record['labels'], [
                'indexes' => $record['indexes'],
            ]);
        }

        return $this->result(basename((string) $dir) . '/*.csv', $readFiles, $rows, ['wos' => $categories], [], $warnings);
    }

    /**
     * OpenAlex: o snapshot (`snapshot/**\/*.gz`, uma fonte por linha) quando existe; senão as páginas da API em
     * `openalex_journals/pagina_*.json` (as sem `results` são ignoradas). Só `type = journal` com ISSN válido.
     * `labels` = subcampos (id numérico = código ASJC) com `share` = contagem do subcampo ÷ soma das contagens.
     *
     * @return SourceResult|null
     */
    private function collectOpenAlex(string $sourcesDir, callable $notify): ?array
    {
        $dir = $this->findDir($sourcesDir, ['openalex']);
        if ($dir === null) {
            return null;
        }

        $snapshotDir = $this->findDir($dir, ['snapshot']);
        $files = $snapshotDir === null ? [] : $this->listFilesRecursive($snapshotDir, '*.gz');
        $fromSnapshot = $files !== [];
        $description = $fromSnapshot ? basename((string) $snapshotDir) . '/**/*.gz' : '';

        if (!$fromSnapshot) {
            $pagesDir = $this->findDir($dir, ['openalex_journals']);
            $files = $pagesDir === null ? [] : $this->listFiles($pagesDir, 'pagina_*.json');
            $description = basename((string) $pagesDir) . '/pagina_*.json';
        }
        if ($files === []) {
            return null;
        }

        $warnings = [];
        $stats = ['read' => 0, 'not_journal' => 0, 'no_issn' => 0, 'repeated' => 0];
        $seen = [];
        $rows = [];
        $subfields = [];
        $readFiles = 0;

        $consume = function (array $source) use (&$stats, &$seen, &$rows, &$subfields): void {
            ++$stats['read'];
            $row = $this->openAlexRow($source, $stats, $seen, $subfields);
            if ($row !== null) {
                $rows[] = $row;
            }
        };

        $total = count($files);
        foreach ($files as $position => $file) {
            if ($fromSnapshot) {
                $notify(sprintf('openalex: %s (%d/%d)', basename(dirname($file)) . '/' . basename($file), $position + 1, $total));
                $handle = @fopen('compress.zlib://' . $file, 'rb');
                if ($handle === false) {
                    $warnings[] = sprintf('openalex: %s não pôde ser aberto (download incompleto?); ignorado.', basename($file));
                    continue;
                }
                $badLines = 0;
                try {
                    while (($line = fgets($handle)) !== false) {
                        $line = trim($line);
                        if ($line === '') {
                            continue;
                        }
                        $source = json_decode($line, true);
                        if (!is_array($source)) {
                            ++$badLines;
                            continue;
                        }
                        $consume($source);
                    }
                } finally {
                    fclose($handle);
                }
                if ($badLines > 0) {
                    // Um gzip truncado entrega a última linha pela metade; é o sinal de download incompleto.
                    $warnings[] = sprintf('openalex: %s tem %d linha(s) que não são JSON (download truncado?); ignoradas.', basename($file), $badLines);
                }
                ++$readFiles;
            } else {
                if ($position % 50 === 0) {
                    $notify(sprintf('openalex: página %d/%d', $position + 1, $total));
                }
                $page = json_decode((string) file_get_contents($file), true);
                if (!is_array($page) || !isset($page['results']) || !is_array($page['results'])) {
                    $warnings[] = sprintf('openalex: %s sem "results" (página de erro?); ignorada.', basename($file));
                    continue;
                }
                foreach ($page['results'] as $source) {
                    if (is_array($source)) {
                        $consume($source);
                    }
                }
                ++$readFiles;
            }
        }

        if ($rows !== [] && $subfields === []) {
            $warnings[] = 'openalex: nenhuma fonte trouxe "topics" com subcampo; a fonte entra no artefato sem rótulos (confira o formato do snapshot).';
        }

        $notes = [sprintf(
            'openalex (%s): %d fontes lidas; mantidas %d; ignoradas: %d com type diferente de journal, %d sem ISSN válido, %d repetidas.',
            $fromSnapshot ? 'snapshot' : 'páginas locais',
            $stats['read'],
            count($rows),
            $stats['not_journal'],
            $stats['no_issn'],
            $stats['repeated']
        )];

        return $this->result($description, $readFiles, $rows, ['openalex_subfields' => $subfields], $notes, $warnings);
    }

    /**
     * Converte uma fonte do OpenAlex (formato da API/snapshot) em linha do artefato; null quando é ignorada.
     *
     * @param array<string, mixed>  $source
     * @param array<string, int>    $stats
     * @param array<string, true>   $seen
     * @param array<string, string> $subfields
     *
     * @return Row|null
     */
    private function openAlexRow(array $source, array &$stats, array &$seen, array &$subfields): ?array
    {
        if (($source['type'] ?? null) !== 'journal') {
            ++$stats['not_journal'];

            return null;
        }

        $raw = [];
        foreach ((array) ($source['issn'] ?? []) as $value) {
            if (is_string($value)) {
                $raw[] = $value;
            }
        }
        if (is_string($source['issn_l'] ?? null)) {
            $raw[] = $source['issn_l'];
        }
        $issns = Issn::extractAll(implode(' ', $raw));
        if ($issns === []) {
            ++$stats['no_issn'];

            return null;
        }

        $title = $this->cleanText((string) ($source['display_name'] ?? ''));
        $rec = preg_replace('~^.*/~', '', trim((string) ($source['id'] ?? ''))) ?? '';
        if ($rec === '') {
            $rec = $this->fallbackRec($issns, $title);
        }
        if (isset($seen[$rec])) {
            ++$stats['repeated'];

            return null;
        }
        $seen[$rec] = true;

        $sums = [];
        $names = [];
        foreach ((array) ($source['topics'] ?? []) as $topic) {
            $subfield = is_array($topic) ? ($topic['subfield'] ?? null) : null;
            if (!is_array($subfield)) {
                continue;
            }
            $code = preg_replace('~^.*/~', '', trim((string) ($subfield['id'] ?? ''))) ?? '';
            if ($code === '' || !ctype_digit($code)) {
                continue;
            }
            $sums[$code] = ($sums[$code] ?? 0) + max(0, (int) ($topic['count'] ?? 0));
            $name = $this->cleanText((string) ($subfield['display_name'] ?? ''));
            if ($name !== '') {
                $names[$code] ??= $name;
            }
        }

        $labels = [];
        $total = array_sum($sums);
        if ($total > 0) {
            // Maior contagem primeiro; empate pelo código, para a saída ser determinística.
            uksort($sums, static function (int|string $a, int|string $b) use ($sums): int {
                return [$sums[$b], (string) $a] <=> [$sums[$a], (string) $b];
            });
            foreach ($sums as $code => $sum) {
                $labels[] = ['code' => (string) $code, 'share' => round($sum / $total, 4)];
            }
        }
        foreach ($names as $code => $name) {
            $subfields[(string) $code] ??= $name;
        }

        return $this->row('openalex', $rec, $issns, $title, $labels, [
            'works' => (int) ($source['works_count'] ?? 0),
            'country' => $this->nullIfEmpty((string) ($source['country_code'] ?? '')),
            'publisher' => $this->nullIfEmpty($this->cleanText((string) ($source['host_organization_name'] ?? ''))),
            'in_doaj' => (bool) ($source['is_in_doaj'] ?? false),
            'in_scielo' => (bool) ($source['is_in_scielo'] ?? false),
            'type' => 'journal',
            'alt_titles' => $this->stringList($source['alternate_titles'] ?? []),
        ]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Excel (Scopus)
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Linhas da aba de fontes do Scopus. O xlsx é pesado (31 MB de XML e 390 mil textos compartilhados), então a
     * leitura é em duas passadas e só traz as colunas necessárias: a primeira lê o cabeçalho, a segunda lê as
     * colunas escolhidas por nome (a posição das colunas não é fixa).
     *
     * @return \Generator<int, array{rec: string, title: string, issn: string, eissn: string, active: string, type: string, publisher: string, asjc: string}>
     */
    private function scopusRows(string $file, string $sheetName): \Generator
    {
        [$spreadsheet, $sheet] = $this->loadSheet($file, $sheetName, static fn (string $column, int $row): bool => $row === 1);
        $header = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator('A', null, true) as $cell) {
                $header[$cell->getColumn()] = $this->normalizeHeader($this->cellToString($cell->getValue()));
            }
        }
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);

        $columns = ['rec' => null, 'title' => null, 'issn' => null, 'eissn' => null, 'active' => null, 'type' => null, 'publisher' => null, 'asjc' => null];
        foreach ($header as $letter => $name) {
            $field = match (true) {
                $name === 'sourcerecord id', $name === 'source record id' => 'rec',
                $name === 'source title' => 'title',
                $name === 'issn' => 'issn',
                $name === 'eissn' => 'eissn',
                $name === 'active or inactive' => 'active',
                $name === 'source type' => 'type',
                $name === 'publisher' => 'publisher',
                str_starts_with($name, 'all science journal classification codes') => 'asjc',
                default => null,
            };
            if ($field !== null && $columns[$field] === null) {
                $columns[$field] = $letter;
            }
        }
        foreach (['title', 'issn', 'asjc'] as $required) {
            if ($columns[$required] === null) {
                throw new \RuntimeException(sprintf(
                    'Aba "%s" de %s sem a coluna esperada "%s". Cabeçalhos encontrados: %s',
                    $sheetName,
                    basename($file),
                    $required,
                    implode(' | ', $header)
                ));
            }
        }

        $wanted = array_flip(array_values(array_filter($columns)));
        [$spreadsheet, $sheet] = $this->loadSheet(
            $file,
            $sheetName,
            static fn (string $column, int $row): bool => $row > 1 && isset($wanted[$column])
        );

        try {
            foreach ($sheet->getRowIterator(2) as $row) {
                $values = [];
                foreach ($row->getCellIterator('A', null, true) as $cell) {
                    $values[$cell->getColumn()] = $cell->getValue();
                }
                if ($values === []) {
                    continue;
                }

                $out = [];
                foreach ($columns as $field => $letter) {
                    $out[$field] = $letter === null ? '' : $this->cellToString($values[$letter] ?? null);
                }

                yield $out;
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * Vocabulário ASJC (`código => descrição`) da aba "ASJC Classification Codes": só as linhas com código numérico
     * (as demais são textos explicativos e títulos de grupo).
     *
     * @return array<string, string>
     */
    private function readAsjcVocabulary(string $file): array
    {
        $reader = IOFactory::createReaderForFile($file);
        $names = method_exists($reader, 'listWorksheetNames') ? $reader->listWorksheetNames($file) : [];
        $sheetName = null;
        foreach ($names as $name) {
            if (preg_match('/^\s*asjc classification codes\s*$/i', (string) $name) === 1) {
                $sheetName = (string) $name;
                break;
            }
        }
        if ($sheetName === null) {
            return [];
        }

        [$spreadsheet, $sheet] = $this->loadSheet($file, $sheetName, null);
        $vocabulary = [];
        try {
            foreach ($sheet->getRowIterator() as $row) {
                $code = null;
                $description = '';
                foreach ($row->getCellIterator('A', 'B', true) as $cell) {
                    if ($cell->getColumn() === 'A') {
                        $code = $this->cellToString($cell->getValue());
                    } else {
                        $description = $this->cleanText($this->cellToString($cell->getValue()));
                    }
                }
                if ($code !== null && preg_match('/^\d{4}$/', $code) === 1 && $description !== '') {
                    $vocabulary[$code] = $description;
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $vocabulary;
    }

    /**
     * Carrega uma aba só com os dados (sem estilos), aplicando um filtro de células `fn(coluna, linha): bool`.
     *
     * @param (callable(string, int): bool)|null $accept
     *
     * @return array{0: Spreadsheet, 1: Worksheet}
     */
    private function loadSheet(string $file, string $sheetName, ?callable $accept): array
    {
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setLoadSheetsOnly([$sheetName]);
        if ($accept !== null) {
            $reader->setReadFilter(new class(\Closure::fromCallable($accept)) implements IReadFilter {
                public function __construct(private readonly \Closure $accept)
                {
                }

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return ($this->accept)($columnAddress, $row);
                }
            });
        }

        $spreadsheet = $reader->load($file);
        $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();

        return [$spreadsheet, $sheet];
    }

    private function cellToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof RichText) {
            return trim($value->getPlainText());
        }
        if (is_float($value)) {
            return $value == floor($value) && abs($value) < 1e15 ? (string) (int) $value : trim((string) $value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    /**
     * Número de ISSN gravado como número no Excel perde os zeros à esquerda; devolve os 8 dígitos.
     */
    private function padIssn(string $value): string
    {
        return preg_match('/^\d{1,7}$/', $value) === 1 ? str_pad($value, 8, '0', STR_PAD_LEFT) : $value;
    }

    // ------------------------------------------------------------------------------------------------------------
    // CSV
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Linhas de um CSV, indexadas pelo nome normalizado da coluna (minúsculas, espaços colapsados, sem BOM).
     * Só as colunas pedidas são devolvidas (as opcionais ausentes vêm vazias). O cabeçalho é lido por posição,
     * então colunas com nome repetido não geram erro (vale a primeira).
     *
     * @param list<string> $required
     * @param list<string> $optional
     *
     * @return \Generator<int, array<string, string>>
     *
     * @throws \RuntimeException coluna obrigatória ausente
     */
    private function csvRows(string $file, string $delimiter, array $required, array $optional = []): \Generator
    {
        $csv = Reader::from($file, 'r');
        $csv->setDelimiter($delimiter);
        $csv->setEnclosure('"');
        $csv->setEscape('');

        $positions = null;
        $wanted = array_merge($required, $optional);

        foreach ($csv->getRecords() as $record) {
            if ($positions === null) {
                $positions = [];
                foreach (array_values($record) as $index => $name) {
                    $positions[$this->normalizeHeader((string) $name)] ??= $index;
                }
                $missing = array_values(array_filter($required, static fn (string $name): bool => !isset($positions[$name])));
                if ($missing !== []) {
                    throw new \RuntimeException(sprintf(
                        'Colunas ausentes em %s: %s. Cabeçalhos encontrados: %s',
                        basename($file),
                        implode(', ', $missing),
                        implode(' | ', array_keys($positions))
                    ));
                }
                continue;
            }

            $row = [];
            foreach ($wanted as $name) {
                $index = $positions[$name] ?? null;
                $row[$name] = $index === null ? '' : trim((string) ($record[$index] ?? ''));
            }

            yield $row;
        }
    }

    private function normalizeHeader(string $name): string
    {
        $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return mb_strtolower(trim($name));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Saída
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Grava o gzip (primeiro `_meta`, depois cada fonte em ordem alfabética, cada uma ordenada por rec, title) num
     * arquivo temporário e só então o move para o destino, para uma falha no meio não deixar artefato pela metade.
     *
     * @param array<string, list<Row>> $rowsBySource
     *
     * @return int total de registros gravados
     */
    private function writeArtifact(string $outFile, string $metaLine, array $rowsBySource): int
    {
        $dir = dirname($outFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Não foi possível criar a pasta %s.', $dir));
        }

        $temporary = $outFile . '.tmp';
        $gz = @gzopen($temporary, 'wb9');
        if ($gz === false) {
            throw new \RuntimeException(sprintf('Não foi possível gravar %s.', $temporary));
        }

        $total = 0;
        try {
            $this->gzLine($gz, $metaLine);
            foreach (self::SOURCES as $source) {
                if (!isset($rowsBySource[$source])) {
                    continue;
                }
                $rows = $rowsBySource[$source];
                unset($rowsBySource[$source]);

                usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]) ?: strcmp($a[2], $b[2]));
                foreach ($rows as $row) {
                    $this->gzLine($gz, $row[2]);
                    ++$total;
                }
            }
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($temporary);

            throw $e;
        }

        gzclose($gz);
        if (!rename($temporary, $outFile)) {
            @unlink($temporary);
            throw new \RuntimeException(sprintf('Não foi possível mover %s para %s.', $temporary, $outFile));
        }

        return $total;
    }

    /**
     * @param resource $gz
     */
    private function gzLine($gz, string $line): void
    {
        if (gzwrite($gz, $line . "\n") === false) {
            throw new \RuntimeException('Falha ao gravar no artefato (disco cheio?).');
        }
    }

    /**
     * Grava `<nome>.txt` de cada vocabulário lido. Arquivo igual ao existente não é tocado; se for diferente,
     * é substituído e a diferença vai para `$warnings`.
     *
     * @param array<string, array<string, string>> $vocabulary
     * @param list<string>                         $warnings
     *
     * @return array<string, array{path: string, lines: int, status: string, added: list<string>, removed: list<string>}>
     */
    private function writeVocabularies(string $vocabDir, array $vocabulary, array &$warnings): array
    {
        $vocabDir = rtrim($vocabDir, '/');
        $result = [];

        foreach (self::VOCABULARIES as $name) {
            if (!isset($vocabulary[$name])) {
                continue;
            }

            $entries = $vocabulary[$name];
            if ($entries === []) {
                // Vocabulário versionado nunca é esvaziado por uma leitura que não encontrou rótulos.
                $warnings[] = sprintf('vocabulário %s.txt ficou vazio nesta leitura; o arquivo existente foi mantido.', $name);
                continue;
            }

            if (in_array($name, self::PAIR_VOCABULARIES, true)) {
                ksort($entries, SORT_NUMERIC);
                $lines = [];
                foreach ($entries as $code => $description) {
                    $lines[] = $code . "\t" . $this->cleanText($description);
                }
            } else {
                $lines = array_map('strval', array_keys($entries));
                sort($lines, SORT_STRING);
            }
            $content = implode("\n", $lines) . "\n";

            if (!is_dir($vocabDir) && !@mkdir($vocabDir, 0775, true) && !is_dir($vocabDir)) {
                throw new \RuntimeException(sprintf('Não foi possível criar a pasta %s.', $vocabDir));
            }

            $path = $vocabDir . '/' . $name . '.txt';
            $previous = is_file($path) ? (string) file_get_contents($path) : null;
            $added = [];
            $removed = [];

            if ($previous === $content) {
                $status = 'unchanged';
            } else {
                if ($previous !== null) {
                    $oldLines = array_values(array_filter(explode("\n", $previous), static fn (string $l): bool => $l !== ''));
                    $added = array_values(array_diff($lines, $oldLines));
                    $removed = array_values(array_diff($oldLines, $lines));
                    $warnings[] = sprintf(
                        'vocabulário %s.txt difere do existente e foi regravado (+%d novas, -%d ausentes; antes %d linhas, agora %d)%s%s',
                        $name,
                        count($added),
                        count($removed),
                        count($oldLines),
                        count($lines),
                        $added === [] ? '' : '; novas: ' . implode(' || ', array_slice($added, 0, 5)),
                        $removed === [] ? '' : '; ausentes: ' . implode(' || ', array_slice($removed, 0, 5))
                    );
                }
                if (file_put_contents($path, $content) === false) {
                    throw new \RuntimeException(sprintf('Não foi possível gravar %s.', $path));
                }
                $status = $previous === null ? 'created' : 'changed';
            }

            $result[$name] = ['path' => $path, 'lines' => count($lines), 'status' => $status, 'added' => $added, 'removed' => $removed];
        }

        return $result;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Utilitários
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Monta a linha do artefato e devolve `[rec, title, json]` (rec e title servem à ordenação).
     *
     * @param list<string>                              $issns
     * @param list<string|array{code: string, share: float}> $labels
     * @param array<string, mixed>                      $extra
     *
     * @return Row
     */
    private function row(string $source, string $rec, array $issns, string $title, array $labels, array $extra): array
    {
        $json = json_encode([
            'src' => $source,
            'rec' => $rec,
            'issns' => array_values($issns),
            'title' => $title,
            'labels' => array_values($labels),
            'extra' => $extra === [] ? new \stdClass() : $extra,
        ], self::JSON_FLAGS);

        return [$rec, $title, $json];
    }

    /**
     * @param list<Row>                            $rows
     * @param array<string, array<string, string>> $vocab
     * @param list<string>                         $notes
     * @param list<string>                         $warnings
     *
     * @return SourceResult
     */
    private function result(string $file, int $files, array $rows, array $vocab, array $notes, array $warnings): array
    {
        return ['file' => $file, 'files' => $files, 'rows' => $rows, 'vocab' => $vocab, 'notes' => $notes, 'warnings' => $warnings];
    }

    /**
     * Chave de registro quando a fonte não traz identificador: md5 de ISSNs e título.
     *
     * @param list<string> $issns
     */
    private function fallbackRec(array $issns, string $title): string
    {
        return md5(implode(',', $issns) . $title);
    }

    private function lastSegment(string $url): ?string
    {
        $segment = trim((string) preg_replace('~^.*/~', '', rtrim(trim($url), '/')));

        return $segment !== '' ? $segment : null;
    }

    private function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Texto de uma linha só: espaços e quebras colapsados e aparado.
     */
    private function cleanText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * Separa uma lista (`a|b`, `a, b`, `1; 2`), apara cada item e descarta vazios e repetidos, mantendo a ordem.
     *
     * @return list<string>
     */
    private function splitList(string $raw, string $separator): array
    {
        $items = [];
        foreach (explode($separator, $raw) as $item) {
            $item = trim($item);
            if ($item !== '' && !in_array($item, $items, true)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return list<string> textos não vazios e sem repetição de uma lista que veio do JSON
     */
    private function stringList(mixed $value): array
    {
        $items = [];
        foreach ((array) $value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = $this->cleanText($item);
            if ($item !== '' && !in_array($item, $items, true)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * Nome do índice do WoS a partir do arquivo: a sigla entre parênteses (`... Index (SSCI).csv` => `SSCI`) ou,
     * sem parênteses, o nome do arquivo sem extensão.
     */
    private function wosIndexName(string $file): string
    {
        $stem = pathinfo($file, PATHINFO_FILENAME);

        return preg_match('/\(([^()]+)\)\s*$/', $stem, $m) === 1 ? trim($m[1]) : $stem;
    }

    /**
     * @param list<string> $skip
     *
     * @return list<string>
     */
    private function normalizeSkip(array $skip): array
    {
        $result = [];
        foreach ($skip as $item) {
            foreach (explode(',', $item) as $name) {
                $name = strtolower(trim($name));
                if ($name === '') {
                    continue;
                }
                if (!in_array($name, self::SOURCES, true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Fonte desconhecida em --skip: "%s". Fontes válidas: %s.',
                        $name,
                        implode(', ', self::SOURCES)
                    ));
                }
                $result[] = $name;
            }
        }

        return array_values(array_unique($result));
    }

    private function missingMessage(string $source, string $sourcesDir, string $derivedDir): string
    {
        if ($source === 'scielo') {
            return sprintf(
                'scielo: %s/scielo_articlemeta.json não existe; seguindo sem a fonte SciELO (gere-o com app:journals:evidence:fetch-scielo).',
                rtrim($derivedDir, '/')
            );
        }

        return sprintf('%s: nenhum arquivo encontrado em %s; fonte ignorada.', $source, rtrim($sourcesDir, '/'));
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function ignoredFilesWarning(string $source, array $files, string $used): array
    {
        if (count($files) < 2) {
            return [];
        }

        return [sprintf(
            '%s: %d arquivos CSV na pasta; usado o último por nome (%s), ignorados: %s.',
            $source,
            count($files),
            basename($used),
            implode(', ', array_map('basename', array_slice($files, 0, -1)))
        )];
    }

    /**
     * Subpasta de `$base` cujo nome, sem distinguir maiúsculas, é um dos `$names` (em minúsculas).
     *
     * @param list<string> $names
     */
    private function findDir(string $base, array $names): ?string
    {
        foreach (@scandir($base) ?: [] as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }
            if (in_array(strtolower($entry), $names, true) && is_dir($base . '/' . $entry)) {
                return $base . '/' . $entry;
            }
        }

        return null;
    }

    /**
     * Arquivos de uma pasta que casam com o padrão (sem distinguir maiúsculas), por nome. Ignora arquivos
     * ocultos (`._*` do macOS inclusive) e os de bloqueio do Excel (`~$*`).
     *
     * @return list<string>
     */
    private function listFiles(string $dir, string $pattern): array
    {
        $files = [];
        foreach (@scandir($dir) ?: [] as $entry) {
            if ($this->isIgnoredName($entry) || !fnmatch(strtolower($pattern), strtolower($entry)) || !is_file($dir . '/' . $entry)) {
                continue;
            }
            $files[] = $dir . '/' . $entry;
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function listFilesRecursive(string $dir, string $pattern): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $info) {
            /** @var \SplFileInfo $info */
            if ($info->isFile() && !$this->isIgnoredName($info->getFilename()) && fnmatch(strtolower($pattern), strtolower($info->getFilename()))) {
                $files[] = $info->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private function isIgnoredName(string $name): bool
    {
        return str_starts_with($name, '.') || str_starts_with($name, '~$');
    }
}
