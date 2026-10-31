<?php

declare(strict_types=1);

namespace App\Service\Thesaurus;

use App\Entity\QualisJournal;
use App\Repository\JournalAreaRepository;
use App\Service\Journal\Issn;
use App\Service\Journal\JournalAreaThesaurus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;

/**
 * Importação de periódicos por CSV na tela de periódicos do admin (colunas `title`, `issn`, `qualis`, `area` e, opcional,
 * `areas_capes`; o arquivo exportado por `/admin/journals/export` também serve, e as colunas que não conhece são ignoradas).
 *
 * É um upsert, então importar o mesmo arquivo duas vezes não duplica nada:
 *  - procura o periódico pelo ISSN normalizado nas quatro colunas de ISSN (a linha de menor id vence, como no resolver
 *    de periódicos) e, só quando a linha não traz ISSN, por `normalized_title`; um título que pertence a mais de uma
 *    família é ambíguo e a linha é ignorada, porque não há como saber qual periódico completar;
 *  - se achou, só preenche o que está vazio (`qualis`, ISSN, `area`, `area_raw`, `normalized_title`, e as áreas CAPES de
 *    `areas_capes` se a família ainda não tem nenhuma): nada é sobrescrito, e uma linha de família com `areas_locked`
 *    nunca é tocada (a curadoria vence);
 *  - se não achou, insere.
 *
 * O texto da coluna `area` é o dado bruto e vai sempre para `area_raw`. Em `area` entra o rótulo canônico da primeira área
 * CAPES que o tesauro de categorias reconhece nesse texto ({@see JournalAreaThesaurus::resolve()}) ou, se não reconhece,
 * o próprio texto. Isso não trava a família: a classificação automática continua valendo. A coluna `areas_capes`, essa sim,
 * grava curadoria manual travada ({@see JournalAreaRepository::replaceManual()}).
 *
 * Tudo corre numa transação: um erro inesperado desfaz o arquivo inteiro. Linha inválida (sem título, título com mais de
 * 500 caracteres, Qualis com mais de 10, ISSN com mais de 50) não é erro: vira aviso e as outras linhas seguem.
 *
 * O catálogo todo (84 mil linhas em produção) é lido uma vez para a memória; achar um periódico não faz consulta. Os ISSNs
 * são indexados pelas colunas normalizadas e também pelo texto das colunas de ISSN, porque há linhas antigas sem a
 * normalizada e o arquivo exportado traz o texto.
 */
class JournalCsvImporterService
{
    /** Tamanho das colunas `title` e `normalized_title`. */
    private const TITLE_MAX = 500;

    /** Tamanho da coluna `qualis`. */
    private const QUALIS_MAX = 10;

    /** Tamanho das colunas `issn` e `normalized_issn`. */
    private const ISSN_MAX = 50;

    /** Limite em bytes de uma coluna TEXT (`area`, `area_raw`), com folga. */
    private const AREA_MAX_BYTES = 60000;

    /** Quantos avisos por linha o relatório guarda (o contador `invalid` conta todas). */
    private const WARNINGS_LIMIT = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalAreaRepository $areaRepo,
        private readonly JournalAreaLabeler $areaLabeler
    ) {
    }

    /**
     * @return array{
     *     read: int, processed: int, created: int, completed: int, unchanged: int, locked: int, ambiguous: int, invalid: int,
     *     ignored: int, withAreas: int, unknownAreas: list<string>, warnings: list<string>
     * } `read`: linhas com algum conteúdo; `processed`: as que não eram inválidas; `created`, `completed` (achou e
     *   preencheu algo), `unchanged` (achou e não havia o que preencher), `locked` (achou em família travada),
     *   `ambiguous` e `invalid`: ignoradas; `ignored` = unchanged + locked + ambiguous + invalid; `withAreas`: periódicos
     *   que receberam `areas_capes`; `unknownAreas`: valores de `areas_capes` que não são áreas CAPES; `warnings`: um
     *   texto por linha inválida ou ambígua (até 50)
     *
     * @throws \League\Csv\Exception arquivo ilegível ou cabeçalho inválido (nada é gravado)
     * @throws \Throwable erro de banco durante a gravação (a transação é desfeita)
     */
    public function import(string $path): array
    {
        $csv = Reader::createFromPath($path, 'r');
        $csv->setDelimiter($this->detectDelimiter($path));
        $csv->setHeaderOffset(0);

        $report = [
            'read' => 0, 'processed' => 0, 'created' => 0, 'completed' => 0, 'unchanged' => 0, 'locked' => 0,
            'ambiguous' => 0, 'invalid' => 0, 'ignored' => 0, 'withAreas' => 0, 'unknownAreas' => [], 'warnings' => [],
        ];
        $unknownAreas = [];

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $catalog = $this->loadCatalog($conn);

            foreach ($csv->getRecords() as $offset => $record) {
                $line = (int) $offset + 1;
                $fields = $this->readFields($record);
                if ($fields === null) {
                    continue; // linha em branco (só separadores)
                }
                ++$report['read'];

                $problem = $this->validate($fields);
                if ($problem !== null) {
                    $this->reject($report, sprintf('Linha %d: %s; a linha foi ignorada.', $line, $problem));
                    continue;
                }
                ++$report['processed'];

                $resolved = ['slugs' => [], 'unknown' => []];
                if ($fields['areas_capes'] !== '') {
                    $resolved = $this->areaRepo->resolveSlugs($fields['areas_capes']);
                    foreach ($resolved['unknown'] as $unknown) {
                        $unknownAreas[$unknown] = true;
                    }
                }

                $match = $this->find($catalog, $fields);
                if ($match === 'ambiguous') {
                    ++$report['ambiguous'];
                    $this->reject($report, sprintf('Linha %d: o título «%s» pertence a mais de uma família de periódicos e a linha não tem ISSN; ignorada.', $line, $fields['title']), false);
                    continue;
                }

                if ($match === null) {
                    $id = $this->insert($conn, $catalog, $fields);
                    ++$report['created'];
                    if ($resolved['slugs'] !== [] && $this->saveManualAreas($catalog, $id, $resolved['slugs'])) {
                        ++$report['withAreas'];
                    }
                    continue;
                }

                if (isset($catalog['locked'][$catalog['rows'][$match]['family']])) {
                    ++$report['locked'];
                    continue;
                }

                $changed = $this->complete($conn, $catalog, $match, $fields);
                if ($resolved['slugs'] !== [] && $this->completeAreas($conn, $catalog, $match, $resolved['slugs'])) {
                    ++$report['withAreas'];
                    $changed = true;
                }
                ++$report[$changed ? 'completed' : 'unchanged'];
            }

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->em->clear();

            throw $e;
        }

        $report['unknownAreas'] = array_map('strval', array_keys($unknownAreas));
        $report['ignored'] = $report['unchanged'] + $report['locked'] + $report['ambiguous'] + $report['invalid'];

        return $report;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Leitura do arquivo
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Delimitador do CSV pelo cabeçalho: o mais frequente entre `;`, `,` e tabulação (vírgula se nenhum aparece).
     * A exportação do admin usa `;`, e um arquivo assim precisa voltar pela importação.
     */
    private function detectDelimiter(string $path): string
    {
        $handle = @fopen($path, 'r');
        $line = $handle !== false ? (string) fgets($handle, 65536) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;

        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($counts);
        $best = (string) array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    /**
     * Campos da linha, com os nomes de coluna que o admin sempre aceitou.
     *
     * @param array<string, mixed> $record
     *
     * @return array{title: string, issn: string, qualis: string, area: string, areas_capes: string}|null null = linha em branco
     */
    private function readFields(array $record): ?array
    {
        $pick = static function (array $names) use ($record): string {
            foreach ($names as $name) {
                if (isset($record[$name]) && is_scalar($record[$name])) {
                    return trim((string) $record[$name]);
                }
            }

            return '';
        };

        $fields = [
            'title' => $pick(['title', 'TITLE', 'titulo', 'Título']),
            'issn' => $pick(['issn', 'ISSN']),
            'qualis' => mb_strtoupper($pick(['qualis', 'QUALIS']), 'UTF-8'),
            'area' => $pick(['area', 'AREA', 'Área']),
            'areas_capes' => $pick(['areas_capes', 'AREAS_CAPES']),
        ];

        return implode('', $fields) === '' ? null : $fields;
    }

    /**
     * @param array{title: string, issn: string, qualis: string, area: string, areas_capes: string} $fields
     *
     * @return string|null o que está errado na linha (sem ponto final), ou null se pode ser importada
     */
    private function validate(array $fields): ?string
    {
        if ($fields['title'] === '') {
            return 'sem título';
        }
        if (mb_strlen($fields['title'], 'UTF-8') > self::TITLE_MAX) {
            return sprintf('título com %d caracteres (máximo %d)', mb_strlen($fields['title'], 'UTF-8'), self::TITLE_MAX);
        }
        if (mb_strlen($fields['qualis'], 'UTF-8') > self::QUALIS_MAX) {
            return sprintf('Qualis «%s» com mais de %d caracteres', mb_substr($fields['qualis'], 0, 20, 'UTF-8') . '…', self::QUALIS_MAX);
        }
        if (mb_strlen($fields['issn'], 'UTF-8') > self::ISSN_MAX) {
            return sprintf('ISSN com %d caracteres (máximo %d)', mb_strlen($fields['issn'], 'UTF-8'), self::ISSN_MAX);
        }
        if (strlen($fields['area']) > self::AREA_MAX_BYTES) {
            return 'texto da coluna area grande demais';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function reject(array &$report, string $warning, bool $countInvalid = true): void
    {
        if ($countInvalid) {
            ++$report['invalid'];
        }
        if (count($report['warnings']) < self::WARNINGS_LIMIT) {
            $report['warnings'][] = $warning;
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Catálogo em memória
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Lê o catálogo uma vez: o que é preciso para achar o periódico de cada linha do arquivo e para decidir o que falta
     * preencher nele.
     *
     * @return array{
     *     issn: array<string, int>,
     *     title: array<string, int|array<int, int>>,
     *     locked: array<int, true>,
     *     rows: array<int, array{family: int, qualis: bool, issn: bool, area: bool, areaRaw: bool, titleToFill: ?string}>
     * } `issn`: ISSN normalizado => id da linha de menor id; `title`: `normalized_title` => id, ou família => id quando o
     *   título é de mais de uma família; `locked`: famílias com `areas_locked` na canônica (as linhas dela, membros
     *   incluídos, não são tocadas); `rows`: o que cada linha já tem; `titleToFill`: o título normalizado de quem ainda
     *   não tem `normalized_title`
     */
    private function loadCatalog(Connection $conn): array
    {
        $catalog = ['issn' => [], 'title' => [], 'locked' => [], 'rows' => []];

        $stmt = $conn->executeQuery(
            'SELECT id, canonical_journal_id, title, normalized_title, normalized_issn, normalized_issn_e, normalized_issn_l, normalized_issn_imp,
                    qualis, issn, issn_e, issn_l, issn_imp, area, area_raw, areas_locked
               FROM qualis_journals
              ORDER BY id'
        );
        while (($r = $stmt->fetchAssociative()) !== false) {
            $id = (int) $r['id'];
            $family = $r['canonical_journal_id'] !== null ? (int) $r['canonical_journal_id'] : $id;

            if ((int) $r['areas_locked'] === 1) {
                $catalog['locked'][$family] = true;
            }

            foreach (['normalized_issn', 'normalized_issn_e', 'normalized_issn_l', 'normalized_issn_imp'] as $column) {
                $key = strtolower(trim((string) $r[$column]));
                if ($key !== '' && !isset($catalog['issn'][$key])) {
                    $catalog['issn'][$key] = $id;
                }
            }
            // Linhas antigas guardam o ISSN só no texto (`issn`, `issn_e`...), sem a coluna normalizada: o arquivo exportado
            // traz esse texto, e a linha precisa ser achada por ele em vez de ser duplicada
            foreach (['issn', 'issn_e', 'issn_l', 'issn_imp'] as $column) {
                foreach (self::issnKeys(trim((string) $r[$column])) as $key) {
                    $catalog['issn'][$key] ??= $id;
                }
            }

            $storedKey = trim((string) $r['normalized_title']);
            $titleKey = $storedKey !== '' ? $storedKey : self::titleKey((string) $r['title']);
            if ($titleKey !== '') {
                $this->addTitle($catalog, $titleKey, $family, $id);
            }

            $catalog['rows'][$id] = [
                'family' => $family,
                'qualis' => trim((string) $r['qualis']) !== '',
                'issn' => trim((string) $r['issn']) !== '',
                'area' => trim((string) $r['area']) !== '',
                'areaRaw' => trim((string) $r['area_raw']) !== '',
                'titleToFill' => $storedKey === '' && $titleKey !== '' ? $titleKey : null,
            ];
        }

        return $catalog;
    }

    /**
     * Guarda a linha na chave de título: um id enquanto todas as linhas da chave são da mesma família (vale a primeira,
     * de menor id) ou, vindo uma linha de outra família, o mapa família => id (ambíguo).
     *
     * @param array{title: array<string, int|array<int, int>>} $catalog
     */
    private function addTitle(array &$catalog, string $key, int $family, int $id): void
    {
        $known = $catalog['title'][$key] ?? null;
        if ($known === null) {
            $catalog['title'][$key] = $id;

            return;
        }

        if (is_int($known)) {
            $knownFamily = $catalog['rows'][$known]['family'] ?? $known;
            if ($knownFamily !== $family) {
                $catalog['title'][$key] = [$knownFamily => $known, $family => $id];
            }

            return;
        }

        $catalog['title'][$key][$family] ??= $id;
    }

    /**
     * Procura o periódico da linha: ISSN primeiro (qualquer um dos ISSNs do campo, nas quatro colunas) e, só sem ISSN,
     * o título normalizado.
     *
     * @param array{issn: array<string, int>, title: array<string, int|array<int, int>>} $catalog
     * @param array{title: string, issn: string, qualis: string, area: string, areas_capes: string} $fields
     *
     * @return int|'ambiguous'|null id da linha, `ambiguous` (título de mais de uma família) ou null (não existe)
     */
    private function find(array $catalog, array $fields): int|string|null
    {
        foreach (self::issnKeys($fields['issn']) as $key) {
            if (isset($catalog['issn'][$key])) {
                return $catalog['issn'][$key];
            }
        }

        if ($fields['issn'] !== '') {
            return null; // com ISSN que o catálogo não conhece, é periódico novo (o título não decide)
        }

        $hit = $catalog['title'][self::titleKey($fields['title'])] ?? null;

        return is_array($hit) ? 'ambiguous' : $hit;
    }

    /**
     * Chaves de busca de um campo de ISSN: cada ISSN do texto (pode haver dois, «1984-6444, 0102-5473») e o texto todo
     * normalizado, que é como as linhas antigas guardaram o campo.
     *
     * @return list<string>
     */
    private static function issnKeys(string $issn): array
    {
        if ($issn === '') {
            return [];
        }

        $keys = Issn::extractAll($issn, false);
        $whole = QualisJournal::normalizeIssnString($issn);
        if ($whole !== null && !in_array($whole, $keys, true)) {
            $keys[] = $whole;
        }

        return $keys;
    }

    private static function titleKey(string $title): string
    {
        return mb_substr(StringNormalizer::normalizeString($title, true), 0, self::TITLE_MAX, 'UTF-8');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Gravação
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Insere o periódico novo e o registra no catálogo em memória (as linhas seguintes do mesmo arquivo o encontram).
     *
     * @param array<string, mixed> $catalog
     * @param array{title: string, issn: string, qualis: string, area: string, areas_capes: string} $fields
     */
    private function insert(Connection $conn, array &$catalog, array $fields): int
    {
        $titleKey = self::titleKey($fields['title']);
        $normalizedIssn = QualisJournal::normalizeIssnString($fields['issn']);
        [$area, $areaRaw] = $this->areaLabeler->columnsFor($fields['area']);

        $conn->executeStatement(
            'INSERT INTO qualis_journals (title, normalized_title, issn, normalized_issn, qualis, area, area_raw) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['title'],
                $titleKey !== '' ? $titleKey : null,
                $fields['issn'] !== '' ? $fields['issn'] : null,
                $normalizedIssn,
                $fields['qualis'] !== '' ? $fields['qualis'] : null,
                $area,
                $areaRaw,
            ]
        );
        $id = (int) $conn->lastInsertId();

        foreach (self::issnKeys($fields['issn']) as $key) {
            $catalog['issn'][$key] ??= $id;
        }
        $catalog['rows'][$id] = [
            'family' => $id,
            'qualis' => $fields['qualis'] !== '',
            'issn' => $fields['issn'] !== '',
            'area' => $area !== null,
            'areaRaw' => $areaRaw !== null,
            'titleToFill' => null,
        ];
        if ($titleKey !== '') {
            $this->addTitle($catalog, $titleKey, $id, $id);
        }

        return $id;
    }

    /**
     * Preenche, no periódico que já existe, só o que está vazio. Nada é sobrescrito.
     *
     * @param array<string, mixed> $catalog
     * @param array{title: string, issn: string, qualis: string, area: string, areas_capes: string} $fields
     *
     * @return bool true se alguma coluna foi preenchida
     */
    private function complete(Connection $conn, array &$catalog, int $id, array $fields): bool
    {
        $row = $catalog['rows'][$id];
        [$area, $areaRaw] = $this->areaLabeler->columnsFor($fields['area']);

        $values = [
            'qualis' => !$row['qualis'] && $fields['qualis'] !== '' ? $fields['qualis'] : null,
            'issn' => !$row['issn'] && $fields['issn'] !== '' ? $fields['issn'] : null,
            'normalized_issn' => !$row['issn'] && $fields['issn'] !== '' ? QualisJournal::normalizeIssnString($fields['issn']) : null,
            'area' => !$row['area'] ? $area : null,
            'area_raw' => !$row['areaRaw'] ? $areaRaw : null,
            'normalized_title' => $row['titleToFill'],
        ];
        $values = array_filter($values, static fn (mixed $value): bool => $value !== null);
        if ($values === []) {
            return false;
        }

        $assignments = [];
        foreach (array_keys($values) as $column) {
            $assignments[] = sprintf("%s = COALESCE(NULLIF(%s, ''), ?)", $column, $column);
        }
        $conn->executeStatement(
            'UPDATE qualis_journals SET ' . implode(', ', $assignments) . ' WHERE id = ?',
            [...array_values($values), $id]
        );

        $catalog['rows'][$id]['qualis'] = $row['qualis'] || isset($values['qualis']);
        $catalog['rows'][$id]['issn'] = $row['issn'] || isset($values['issn']);
        $catalog['rows'][$id]['area'] = $row['area'] || isset($values['area']);
        $catalog['rows'][$id]['areaRaw'] = $row['areaRaw'] || isset($values['area_raw']);
        $catalog['rows'][$id]['titleToFill'] = null;

        return true;
    }

    /**
     * `areas_capes` de uma linha que já existia: grava a curadoria manual só se a família não tem nenhuma área ainda
     * (o que já está lá não é sobrescrito).
     *
     * @param array<string, mixed> $catalog
     * @param list<string> $slugs
     */
    private function completeAreas(Connection $conn, array &$catalog, int $id, array $slugs): bool
    {
        $family = $catalog['rows'][$id]['family'];
        $rowIds = $this->areaRepo->familyRowIds($family);
        $hasAreas = $conn->fetchOne('SELECT 1 FROM journal_areas WHERE journal_id IN (' . implode(',', array_map('intval', $rowIds)) . ') LIMIT 1');
        if ($hasAreas !== false) {
            return false;
        }

        return $this->saveManualAreas($catalog, $family, $slugs);
    }

    /**
     * Curadoria manual travada (a primeira área é a principal), pelo repositório, que replica na família. A família passa
     * a contar como travada para as linhas seguintes do arquivo.
     *
     * @param array<string, mixed> $catalog
     * @param list<string> $slugs
     */
    private function saveManualAreas(array &$catalog, int $journalId, array $slugs): bool
    {
        $journal = $this->em->find(QualisJournal::class, $journalId);
        if ($journal === null) {
            return false;
        }

        $this->areaRepo->replaceManual($journal, $slugs, $slugs[0]);
        $this->em->clear();
        $catalog['locked'][$journalId] = true;

        return true;
    }
}
