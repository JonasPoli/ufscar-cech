<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Entity\JournalArea;
use App\Entity\ProductionItem;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Classifica as famílias de periódicos nas áreas CAPES e grava o resultado (A6.4 "Gravação" e A3 de
 * `docs/evolutions/periodicos-por-base.md`).
 *
 * Família = `COALESCE(canonical_journal_id, id)`. Para cada família o classificador lê as evidências de todas as linhas
 * (`journal_area_evidence`), pede a decisão ao {@see JournalAreaScorer} e grava:
 *  - em `journal_areas`, a principal e até duas secundárias, `method = 'auto'` e o `run_id`, em TODAS as linhas da
 *    família (antes apaga as linhas `auto` dessas linhas; as manuais ficam e, se coincidirem com uma área nova, vencem);
 *  - em `qualis_journals.area`, o rótulo canônico da principal ({@see CapesTaxonomy::areaLabel()}), copiando antes
 *    para `area_raw` o texto que não seja rótulo canônico. Família sem classificação: `area` volta a `area_raw`.
 * Fallback PubMed: família sem nenhuma evidência mapeada e ligada à base `pubmed` recebe `ciencias-da-saude` (nível 1),
 * confiança `low` e `score` 0. É decisão do classificador, não do scorer.
 *
 * Canônica com `areas_locked = 1` é pulada: curadoria vence, nada é alterado nela nem nas linhas da família.
 *
 * Idempotente: rodar de novo dá o mesmo resultado (as linhas `auto` são refeitas; `area` e `area_raw` só mudam se o
 * valor calculado for outro) e `area_raw` nunca recebe um rótulo canônico.
 *
 * Desempenho: as famílias são processadas em lotes (evidências lidas com `WHERE journal_id IN (...)`, uma transação por
 * lote) e a gravação em massa vai direto pelo PDO da mesma conexão, como em {@see JournalEvidenceLoader}: em dev o
 * middleware de debug do DBAL guarda cada SQL e todos os parâmetros até o fim do processo. As consultas de leitura, que
 * são poucas, passam pelo DBAL. Só `payload.works`, `payload.confidence` e `payload.role` são extraídos do JSON, no
 * próprio SQL.
 *
 * Com `$dryRun` tudo é lido e calculado, os números do resumo são os da execução real, e nada é gravado.
 */
final class JournalAreaClassifier
{
    /** Famílias por lote. */
    private const FAMILY_BATCH = 1000;

    /** Ids por `IN (...)` nas consultas e nos DELETE/UPDATE. */
    private const ID_CHUNK = 2000;

    /** Linhas por INSERT em `journal_areas`. */
    private const INSERT_CHUNK = 500;

    private const RUN_ID_MAX = 40;

    private const PUBMED_ACRONYM = 'pubmed';
    private const PUBMED_FALLBACK_SLUG = 'ciencias-da-saude';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalAreaScorer $scorer,
        private readonly CapesTaxonomy $taxonomy,
    ) {
    }

    /**
     * Classifica todas as famílias do cadastro.
     *
     * Chaves do resumo:
     *  - `families`: famílias do cadastro; `locked`: puladas por `areas_locked`;
     *  - `classified`: famílias que receberam uma principal (inclui o fallback PubMed), repartidas em `high`, `medium`
     *    e `low`; `level1`: das classificadas, as que ficaram só com a grande área (as demais são de nível 2);
     *  - `no_evidence`: famílias sem classificação (sem evidência mapeada e fora do fallback PubMed);
     *  - `visible_total`: famílias com pelo menos um artigo; `visible_classified`: dessas, as que terminam com área
     *    (as classificadas agora e as travadas que já têm `journal_areas`);
     *  - `areas_written`: linhas de `journal_areas` gravadas; `rows_updated`: linhas de `qualis_journals` em que `area`
     *    ou `area_raw` mudou; `manual_kept`: áreas automáticas não gravadas porque a linha já tem a área manual.
     *
     * @param (callable(int, int): void)|null $progress recebe (famílias processadas, total) a cada lote
     *
     * @return array{
     *     families: int, classified: int, high: int, medium: int, low: int, level1: int, no_evidence: int, locked: int,
     *     visible_total: int, visible_classified: int, areas_written: int, rows_updated: int, manual_kept: int
     * }
     *
     * @throws \InvalidArgumentException se `$runId` passar de 40 caracteres (limite de `journal_areas.run_id`)
     */
    public function classifyAll(string $runId, ?callable $progress = null, bool $dryRun = false): array
    {
        self::assertRunId($runId);
        $conn = $this->em->getConnection();

        [$familyRows, $lockedRows] = $this->loadFamilies($conn);
        $visible = $this->visibleFamilies($conn);
        $pubmed = $this->pubmedFamilies($conn);

        $summary = self::emptySummary();
        $summary['families'] = count($familyRows);
        $summary['visible_total'] = count($visible);

        $total = count($familyRows);
        $done = 0;
        foreach (array_chunk(array_keys($familyRows), self::FAMILY_BATCH) as $batch) {
            $rowsByFamily = [];
            foreach ($batch as $familyId) {
                $rowsByFamily[$familyId] = $familyRows[$familyId];
            }

            $this->processBatch($conn, $rowsByFamily, $lockedRows, $pubmed, $visible, $runId, $dryRun, $summary);

            $done += count($batch);
            if ($progress !== null) {
                $progress($done, $total);
            }
        }

        return $summary;
    }

    /**
     * Classifica uma família. `$canonicalId` é a canônica; o id de uma linha-membro também serve (a família é a dela).
     *
     * Devolve null se a família não existe ou se a canônica tem `areas_locked = 1` (nada é alterado). Senão devolve a
     * decisão do scorer (`primary`, `secondary`, `confidence`, `shares`, `total_weight`, `sources`), com `primary`
     * nulo e `confidence` `none` quando não há classificação, mais `fallback` (`'pubmed'` ou null), `family` (id da
     * canônica), `rows` (ids das linhas da família) e `dry_run`.
     *
     * @return array<string, mixed>|null
     *
     * @throws \InvalidArgumentException se `$runId` passar de 40 caracteres
     */
    public function classifyFamily(int $canonicalId, string $runId, bool $dryRun = false): ?array
    {
        self::assertRunId($runId);
        $conn = $this->em->getConnection();

        $row = $conn->fetchAssociative('SELECT id, canonical_journal_id FROM qualis_journals WHERE id = ?', [$canonicalId]);
        if ($row === false) {
            return null;
        }

        $familyId = $row['canonical_journal_id'] !== null ? (int) $row['canonical_journal_id'] : (int) $row['id'];
        $locked = $conn->fetchOne('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$familyId]);
        if ($locked === false || (int) $locked === 1) {
            return null;
        }

        $rows = array_map(
            'intval',
            $conn->fetchFirstColumn('SELECT id FROM qualis_journals WHERE id = ? OR canonical_journal_id = ? ORDER BY id', [$familyId, $familyId])
        );

        $summary = self::emptySummary();
        $decisions = $this->processBatch(
            $conn,
            [$familyId => $rows],
            [],
            $this->pubmedFamilies($conn),
            [],
            $runId,
            $dryRun,
            $summary
        );

        return $decisions[$familyId] + ['family' => $familyId, 'rows' => $rows, 'dry_run' => $dryRun];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Lote: leitura, decisão e gravação
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Processa um lote de famílias e acumula os números em `$summary`.
     *
     * @param array<int, list<int>> $rowsByFamily família => ids das suas linhas
     * @param array<int, true>      $lockedRows   ids de linhas com `areas_locked = 1`
     * @param array<int, true>      $pubmed       famílias ligadas à base PubMed
     * @param array<int, true>      $visible      famílias com pelo menos um artigo
     * @param array<string, int>    $summary
     *
     * @return array<int, array<string, mixed>> decisão de cada família que não estava travada
     */
    private function processBatch(
        Connection $conn,
        array $rowsByFamily,
        array $lockedRows,
        array $pubmed,
        array $visible,
        string $runId,
        bool $dryRun,
        array &$summary,
    ): array {
        $open = [];
        $locked = [];
        foreach ($rowsByFamily as $familyId => $rows) {
            if (isset($lockedRows[$familyId])) {
                $locked[] = $familyId;
            } else {
                $open[$familyId] = $rows;
            }
        }

        $summary['locked'] += count($locked);
        if ($locked !== []) {
            $withAreas = $this->journalsWithAreas($conn, $locked);
            foreach ($locked as $familyId) {
                if (isset($visible[$familyId], $withAreas[$familyId])) {
                    ++$summary['visible_classified'];
                }
            }
        }

        if ($open === []) {
            return [];
        }

        $evidence = $this->readEvidence($conn, $open);
        $decisions = [];
        foreach ($open as $familyId => $rows) {
            $decision = $this->decide($evidence[$familyId] ?? [], isset($pubmed[$familyId]));
            $decisions[$familyId] = $decision;

            if ($decision['primary'] === null) {
                ++$summary['no_evidence'];
                continue;
            }

            ++$summary['classified'];
            ++$summary[$decision['confidence']];
            if ($this->taxonomy->levelOf($decision['primary']['slug']) === CapesTaxonomy::LEVEL_GRANDE_AREA) {
                ++$summary['level1'];
            }
            if (isset($visible[$familyId])) {
                ++$summary['visible_classified'];
            }
        }
        unset($evidence);

        $allRows = array_merge(...array_values($open));
        $manual = $this->manualPairs($conn, $allRows);
        [$areaRows, $kept] = $this->planAreaRows($open, $decisions, $manual);
        [$grouped, $single] = $this->planColumnChanges($open, $decisions, $this->readColumns($conn, $allRows));

        $summary['areas_written'] += count($areaRows);
        $summary['manual_kept'] += $kept;
        $summary['rows_updated'] += count($single);
        foreach ($grouped as $group) {
            $summary['rows_updated'] += count($group['ids']);
        }

        if (!$dryRun) {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->write($conn, $allRows, $areaRows, $grouped, $single, $runId, $now);
        }

        return $decisions;
    }

    /**
     * Evidências das linhas das famílias, agrupadas por família, na ordem (linha, id) para a deduplicação ser estável.
     * Do JSON só saem `works` (openalex) e `confidence`/`role` (ai).
     *
     * @param array<int, list<int>> $rowsByFamily
     *
     * @return array<int, list<array{source: string, record_key: string, label: string, weight: string, payload: array<string, mixed>}>>
     */
    private function readEvidence(Connection $conn, array $rowsByFamily): array
    {
        $familyOfRow = [];
        foreach ($rowsByFamily as $familyId => $rows) {
            foreach ($rows as $rowId) {
                $familyOfRow[$rowId] = $familyId;
            }
        }

        $evidence = [];
        foreach (array_chunk(array_keys($familyOfRow), self::ID_CHUNK) as $chunk) {
            $result = $conn->executeQuery(
                "SELECT e.journal_id, e.source, e.record_key, e.label, e.weight,
                        CASE WHEN e.source = 'openalex' THEN JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.works')) END AS works,
                        CASE WHEN e.source = 'ai' THEN JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.confidence')) END AS ai_confidence,
                        CASE WHEN e.source = 'ai' THEN JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.role')) END AS ai_role
                 FROM journal_area_evidence e
                 WHERE e.journal_id IN (" . implode(',', $chunk) . ')
                 ORDER BY e.journal_id, e.id'
            );

            while (($row = $result->fetchAssociative()) !== false) {
                $payload = [];
                if ($row['works'] !== null) {
                    $payload['works'] = (int) $row['works'];
                }
                if ($row['ai_confidence'] !== null) {
                    $payload['confidence'] = $row['ai_confidence'];
                }
                if ($row['ai_role'] !== null) {
                    $payload['role'] = $row['ai_role'];
                }

                $evidence[$familyOfRow[(int) $row['journal_id']]][] = [
                    'source' => (string) $row['source'],
                    'record_key' => (string) $row['record_key'],
                    'label' => (string) $row['label'],
                    'weight' => (string) $row['weight'],
                    'payload' => $payload,
                ];
            }
        }

        return $evidence;
    }

    /**
     * Decisão do scorer mais o fallback PubMed.
     *
     * @param list<array<string, mixed>> $evidence
     *
     * @return array<string, mixed>
     */
    private function decide(array $evidence, bool $pubmed): array
    {
        $decision = $this->scorer->score($evidence);
        $decision['fallback'] = null;

        if ($decision['primary'] === null && $pubmed) {
            $decision['primary'] = ['slug' => self::PUBMED_FALLBACK_SLUG, 'share' => 0.0];
            $decision['confidence'] = JournalAreaScorer::CONFIDENCE_LOW;
            $decision['fallback'] = 'pubmed';
        }

        return $decision;
    }

    /**
     * Linhas de `journal_areas` a gravar: principal e secundárias em cada linha da família, menos as áreas que a linha
     * já tem como não automáticas (a manual vence).
     *
     * @param array<int, list<int>>        $open
     * @param array<int, array<string, mixed>> $decisions
     * @param array<string, true>          $manual "linha:área" das linhas não automáticas existentes
     *
     * @return array{0: list<array{0: int, 1: int, 2: int, 3: string, 4: string}>, 1: int} linhas (linha, área, principal,
     *         score, confiança) e quantas foram descartadas por já existirem como manuais
     */
    private function planAreaRows(array $open, array $decisions, array $manual): array
    {
        $areaRows = [];
        $kept = 0;
        foreach ($open as $familyId => $rows) {
            $decision = $decisions[$familyId];
            if ($decision['primary'] === null) {
                continue;
            }

            $areas = [[$decision['primary']['slug'], $decision['primary']['share'], 1]];
            foreach ($decision['secondary'] as $secondary) {
                $areas[] = [$secondary['slug'], $secondary['share'], 0];
            }

            foreach ($rows as $rowId) {
                foreach ($areas as [$slug, $share, $primary]) {
                    $areaId = $this->taxonomy->bySlug($slug)['id'] ?? throw new \LogicException(sprintf('Slug sem área: %s', $slug));
                    if (isset($manual[$rowId . ':' . $areaId])) {
                        ++$kept;
                        continue;
                    }
                    $areaRows[] = [$rowId, $areaId, $primary, number_format(max(0.0, min(1.0, $share)), 4, '.', ''), $decision['confidence']];
                }
            }
        }

        return [$areaRows, $kept];
    }

    /**
     * `area` e `area_raw` novos de cada linha. Cada linha vira, ou "só `area` muda" (agrupadas pelo valor novo de `area`,
     * para um UPDATE por grupo), ou "`area_raw` também muda" (UPDATE por linha). Linha sem mudança fica de fora.
     *
     * @param array<int, list<int>>                              $open
     * @param array<int, array<string, mixed>>                   $decisions
     * @param array<int, array{area: ?string, area_raw: ?string}> $columns
     *
     * @return array{0: array<string, array{area: ?string, ids: list<int>}>, 1: list<array{0: int, 1: ?string, 2: ?string}>}
     */
    private function planColumnChanges(array $open, array $decisions, array $columns): array
    {
        $grouped = [];
        $single = [];
        foreach ($open as $familyId => $rows) {
            $primary = $decisions[$familyId]['primary'];
            $label = $primary === null ? null : $this->taxonomy->areaLabel($primary['slug']);

            foreach ($rows as $rowId) {
                if (!isset($columns[$rowId])) {
                    continue;
                }

                $current = $columns[$rowId];
                [$newArea, $newRaw] = $this->columnValues($current['area'], $current['area_raw'], $label);
                $sameArea = self::sameText($current['area'], $newArea);
                $sameRaw = self::sameText($current['area_raw'], $newRaw);

                if ($sameArea && $sameRaw) {
                    continue;
                }

                if ($sameRaw) {
                    $key = $newArea === null ? "\0null" : 'v:' . $newArea;
                    $grouped[$key]['area'] = $newArea;
                    $grouped[$key]['ids'][] = $rowId;
                    continue;
                }

                $single[] = [$rowId, $newArea, $newRaw];
            }
        }

        return [$grouped, $single];
    }

    /**
     * Regra de A3 para uma linha: `area_raw` guarda o texto de `area` que não é rótulo canônico (e só ele); a classificada
     * recebe o rótulo canônico em `area`, a que ficou sem classificação volta a `area_raw`.
     *
     * @return array{0: ?string, 1: ?string} novo `area` e novo `area_raw`
     */
    private function columnValues(?string $area, ?string $areaRaw, ?string $label): array
    {
        $raw = $areaRaw;
        if ($area !== null && trim($area) !== '' && !$this->taxonomy->isCanonicalLabel($area)) {
            $raw = $area;
        }

        $newArea = $label ?? $raw;
        if ($newArea !== null && trim($newArea) === '') {
            $newArea = null;
        }

        return [$newArea, $raw];
    }

    /**
     * Grava tudo de um lote numa transação: apaga as áreas automáticas das linhas, insere as novas e atualiza `area`/`area_raw`.
     *
     * @param list<int>                                             $rowIds   todas as linhas das famílias do lote
     * @param list<array{0: int, 1: int, 2: int, 3: string, 4: string}> $areaRows
     * @param array<string, array{area: ?string, ids: list<int>}>   $grouped
     * @param list<array{0: int, 1: ?string, 2: ?string}>           $single
     */
    private function write(Connection $conn, array $rowIds, array $areaRows, array $grouped, array $single, string $runId, string $now): void
    {
        $conn->beginTransaction();
        try {
            foreach (array_chunk($rowIds, self::ID_CHUNK) as $chunk) {
                $this->execute($conn, "DELETE FROM journal_areas WHERE method = 'auto' AND journal_id IN (" . implode(',', $chunk) . ')');
            }

            foreach (array_chunk($areaRows, self::INSERT_CHUNK) as $chunk) {
                $placeholders = [];
                $params = [];
                foreach ($chunk as [$rowId, $areaId, $primary, $score, $confidence]) {
                    $placeholders[] = '(?,?,?,?,?,?,?,?)';
                    array_push($params, $rowId, $areaId, $primary, $score, $confidence, JournalArea::METHOD_AUTO, $runId, $now);
                }

                $this->execute(
                    $conn,
                    'INSERT INTO journal_areas (journal_id, area_id, is_primary, score, confidence, method, run_id, updated_at) VALUES '
                    . implode(',', $placeholders),
                    $params
                );
            }

            foreach ($grouped as $group) {
                foreach (array_chunk($group['ids'], self::ID_CHUNK) as $chunk) {
                    $this->execute(
                        $conn,
                        'UPDATE qualis_journals SET area = ? WHERE id IN (' . implode(',', $chunk) . ')',
                        [$group['area']]
                    );
                }
            }

            if ($single !== []) {
                $update = $this->prepare($conn, 'UPDATE qualis_journals SET area = ?, area_raw = ? WHERE id = ?');
                foreach ($single as [$rowId, $area, $areaRaw]) {
                    $update([$area, $areaRaw, $rowId]);
                }
            }

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Consultas
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Famílias do cadastro e as linhas travadas.
     *
     * @return array{0: array<int, list<int>>, 1: array<int, true>} família => ids das linhas (por id da família) e as
     *         linhas com `areas_locked = 1`
     */
    private function loadFamilies(Connection $conn): array
    {
        $families = [];
        $locked = [];

        $result = $conn->executeQuery('SELECT id, canonical_journal_id, areas_locked FROM qualis_journals ORDER BY id');
        while (($row = $result->fetchAssociative()) !== false) {
            $id = (int) $row['id'];
            $familyId = $row['canonical_journal_id'] !== null ? (int) $row['canonical_journal_id'] : $id;
            $families[$familyId][] = $id;
            if ((int) $row['areas_locked'] === 1) {
                $locked[$id] = true;
            }
        }
        ksort($families);

        return [$families, $locked];
    }

    /**
     * Famílias com pelo menos um artigo.
     *
     * @return array<int, true>
     */
    private function visibleFamilies(Connection $conn): array
    {
        return self::flagged($conn->fetchFirstColumn(
            'SELECT DISTINCT COALESCE(q.canonical_journal_id, q.id)
             FROM production_items pi
             JOIN qualis_journals q ON q.id = pi.qualis_journal_id
             WHERE pi.item_type = ?',
            [ProductionItem::TYPE_ARTIGO]
        ));
    }

    /**
     * Famílias com alguma linha ligada à base PubMed.
     *
     * @return array<int, true>
     */
    private function pubmedFamilies(Connection $conn): array
    {
        return self::flagged($conn->fetchFirstColumn(
            'SELECT DISTINCT COALESCE(q.canonical_journal_id, q.id)
             FROM qualis_journal_academic_database qb
             JOIN academic_database ad ON ad.id = qb.academic_database_id
             JOIN qualis_journals q ON q.id = qb.qualis_journal_id
             WHERE LOWER(ad.acronym) = ?',
            [self::PUBMED_ACRONYM]
        ));
    }

    /**
     * Entre os ids, os que têm alguma linha em `journal_areas`.
     *
     * @param list<int> $journalIds
     *
     * @return array<int, true>
     */
    private function journalsWithAreas(Connection $conn, array $journalIds): array
    {
        $found = [];
        foreach (array_chunk($journalIds, self::ID_CHUNK) as $chunk) {
            $found += self::flagged($conn->fetchFirstColumn(
                'SELECT DISTINCT journal_id FROM journal_areas WHERE journal_id IN (' . implode(',', $chunk) . ')'
            ));
        }

        return $found;
    }

    /**
     * Áreas que as linhas já têm por outro método que não o automático (hoje, só o manual).
     *
     * @param list<int> $rowIds
     *
     * @return array<string, true> "linha:área"
     */
    private function manualPairs(Connection $conn, array $rowIds): array
    {
        $pairs = [];
        foreach (array_chunk($rowIds, self::ID_CHUNK) as $chunk) {
            $rows = $conn->fetchAllNumeric(
                "SELECT journal_id, area_id FROM journal_areas WHERE method <> 'auto' AND journal_id IN (" . implode(',', $chunk) . ')'
            );
            foreach ($rows as [$journalId, $areaId]) {
                $pairs[$journalId . ':' . $areaId] = true;
            }
        }

        return $pairs;
    }

    /**
     * `area` e `area_raw` atuais das linhas.
     *
     * @param list<int> $rowIds
     *
     * @return array<int, array{area: ?string, area_raw: ?string}>
     */
    private function readColumns(Connection $conn, array $rowIds): array
    {
        $columns = [];
        foreach (array_chunk($rowIds, self::ID_CHUNK) as $chunk) {
            $result = $conn->executeQuery('SELECT id, area, area_raw FROM qualis_journals WHERE id IN (' . implode(',', $chunk) . ')');
            while (($row = $result->fetchAssociative()) !== false) {
                $columns[(int) $row['id']] = ['area' => $row['area'], 'area_raw' => $row['area_raw']];
            }
        }

        return $columns;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Infraestrutura
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Executa direto no PDO da conexão (mesma transação): o middleware de debug do DBAL guarda o SQL e os parâmetros de
     * cada instrução até o fim do processo, e uma gravação de centenas de milhares de linhas estouraria a memória.
     *
     * @param list<mixed> $params
     */
    private function execute(Connection $conn, string $sql, array $params = []): void
    {
        $native = $conn->getNativeConnection();
        if ($native instanceof \PDO) {
            $statement = $native->prepare($sql);
            if ($statement === false || !$statement->execute($params)) {
                throw new \RuntimeException('Falha ao gravar a classificação de áreas.');
            }

            return;
        }

        $conn->executeStatement($sql, $params);
    }

    /**
     * Instrução preparada uma vez e executada várias (mesmo caminho direto de {@see self::execute()}).
     *
     * @return \Closure(list<mixed>): void
     */
    private function prepare(Connection $conn, string $sql): \Closure
    {
        $native = $conn->getNativeConnection();
        if ($native instanceof \PDO) {
            $statement = $native->prepare($sql);
            if ($statement === false) {
                throw new \RuntimeException('Falha ao preparar a gravação da classificação de áreas.');
            }

            return static function (array $params) use ($statement): void {
                if (!$statement->execute($params)) {
                    throw new \RuntimeException('Falha ao gravar a classificação de áreas.');
                }
            };
        }

        return static function (array $params) use ($conn, $sql): void {
            $conn->executeStatement($sql, $params);
        };
    }

    /**
     * @param list<mixed> $ids
     *
     * @return array<int, true>
     */
    private static function flagged(array $ids): array
    {
        $flags = [];
        foreach ($ids as $id) {
            $flags[(int) $id] = true;
        }

        return $flags;
    }

    /**
     * Igualdade de texto de coluna: nulo e texto em branco são o mesmo "vazio".
     */
    private static function sameText(?string $a, ?string $b): bool
    {
        $emptyA = $a === null || trim($a) === '';
        $emptyB = $b === null || trim($b) === '';

        return ($emptyA && $emptyB) || $a === $b;
    }

    /**
     * @return array{
     *     families: int, classified: int, high: int, medium: int, low: int, level1: int, no_evidence: int, locked: int,
     *     visible_total: int, visible_classified: int, areas_written: int, rows_updated: int, manual_kept: int
     * }
     */
    private static function emptySummary(): array
    {
        return [
            'families' => 0,
            'classified' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'level1' => 0,
            'no_evidence' => 0,
            'locked' => 0,
            'visible_total' => 0,
            'visible_classified' => 0,
            'areas_written' => 0,
            'rows_updated' => 0,
            'manual_kept' => 0,
        ];
    }

    private static function assertRunId(string $runId): void
    {
        if ($runId === '' || mb_strlen($runId) > self::RUN_ID_MAX) {
            throw new \InvalidArgumentException(sprintf('O run id deve ter de 1 a %d caracteres.', self::RUN_ID_MAX));
        }
    }
}
