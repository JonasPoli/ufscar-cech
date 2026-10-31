<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Entity\JournalNameCandidate;
use App\Entity\QualisJournal;
use App\Service\Thesaurus\JournalResolverService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Aprendizado de grafias do Lattes com confirmação por ISSN (docs/evolutions/periodicos-por-base.md, A5.3).
 *
 * Um artigo com vínculo cujo ISSN pertence à família do periódico vinculado "confirma" o vínculo. Se o nome de periódico
 * desse artigo (`journal_name_normalized`) ainda não é título nem variante de nenhuma linha, ele é uma grafia nova do
 * periódico. "É título ou variante" segue o resolver (A5.2, etapas 2 e 3): a chave do nome, com e sem parênteses, existe
 * entre os títulos (também sem parênteses) e as variantes de qualquer família. Nome que só casa pela chave compacta
 * (etapa 4, sem conectivos) ainda conta como desconhecido: a variante exata o torna explícito e vale também no modo
 * on-demand, que não alcança todos esses casos. Então:
 *  - confirmado por pelo menos `$minArticles` artigos, todos da mesma família: vira variante `lattes` na linha canônica
 *    da família, com a grafia bruta mais frequente;
 *  - confirmado por menos artigos (um, com o padrão): entra na fila de revisão (`journal_name_candidates`) com
 *    `suggestion_method = 'issn'`, `suggested_journal_id` = a canônica e `status = 'pending'`, para aceitar com um clique;
 *  - confirmado por famílias diferentes: conflito. Nada é aprendido; entra na fila como `ambiguous`, sem sugestão.
 *
 * Os dados brutos do Lattes (`journal_name`, `issn`) e os vínculos dos artigos não são tocados: o que se grava são
 * variantes (por JournalVariantGuard) e linhas da fila. Nomes que a revisão já decidiu (`accepted`/`rejected`) são
 * respeitados. Linhas da fila por ISSN que deixaram de valer (o nome virou título ou variante, ou nenhum artigo o
 * confirma mais) saem da fila, porque JournalNameQueueService::rebuild() não as toca.
 *
 * A forma do tesauro do nome é `journal_name_normalized` e, onde a coluna ainda está vazia, é calculada na hora
 * (StringNormalizer::normalizeString($journalName, true)); o mesmo vale para `qualis_journals.normalized_title`. Assim
 * o relatório (`$dryRun`) é fiel antes de `app:journals:normalize-titles` ser executado.
 */
class JournalNameLearner
{
    public const VARIATION_TYPE = 'lattes';

    /** Gravado em `journal_name_candidates.reviewed_by` quando o aprendizado resolve uma linha que estava na fila. */
    public const REVIEWER = 'auto:issn';

    public const ACTION_LEARNED = 'learned';
    public const ACTION_QUEUED = 'queued';
    public const ACTION_CONFLICT = 'conflict';
    public const ACTION_SKIPPED = 'skipped';

    private const IN_CHUNK = 500;
    private const MAX_SAMPLE_TITLES = 3;
    private const MAX_EXAMPLES_PER_ACTION = 5;
    private const OPEN_STATUSES = [JournalNameCandidate::STATUS_PENDING, JournalNameCandidate::STATUS_AMBIGUOUS];
    private const REVIEWED_STATUSES = [JournalNameCandidate::STATUS_ACCEPTED, JournalNameCandidate::STATUS_REJECTED];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalVariantGuard $guard,
    ) {
    }

    /**
     * Aprende as grafias confirmadas por ISSN.
     *
     * @param int $minArticles mínimo de artigos confirmados para criar a variante direto (menos que isso vai para a fila)
     * @param bool $dryRun calcula tudo e devolve os números, sem gravar nada
     * @param list<string>|null $onlyNames restringe a execução a estes nomes (qualquer grafia; é normalizada), para
     *        testes e reprocessamentos pontuais; null = todos. Com a restrição, a limpeza da fila só olha estes nomes.
     *
     * @return array{
     *     learned: int, queued: int, conflicts: int, examples: list<array<string, mixed>>,
     *     candidates: int, skipped: int, removed: int, unnormalized_titles: int, unnormalized_articles: int
     * } `learned` variantes criadas (ou a criar, em `$dryRun`); `queued` nomes enfileirados com sugestão por ISSN;
     *   `conflicts` nomes em mais de uma família (enfileirados como `ambiguous`); `candidates` = learned + queued +
     *   conflicts + skipped; `skipped` nomes já decididos na fila ou já conhecidos pela família; `removed` linhas
     *   obsoletas da fila por ISSN; `unnormalized_*` linhas cuja forma do tesauro foi calculada em memória;
     *   `examples` até 5 casos de cada ação (`action`, `name`, `journal_id`, `journal`, `articles`)
     */
    public function learn(int $minArticles = 2, bool $dryRun = false, ?array $onlyNames = null): array
    {
        $minArticles = max(1, $minArticles);
        $conn = $this->em->getConnection();
        $scope = self::buildScope($onlyNames);

        $result = [
            'learned' => 0,
            'queued' => 0,
            'conflicts' => 0,
            'examples' => [],
            'candidates' => 0,
            'skipped' => 0,
            'removed' => 0,
            'unnormalized_titles' => 0,
            'unnormalized_articles' => 0,
        ];
        if ($scope === []) {
            return $result;
        }

        $articles = $this->loadLinkedArticles($conn, $scope, $result['unnormalized_articles']);
        $known = $this->findKnownKeys($conn, $articles, $result['unnormalized_titles']);

        $familyTitles = [];
        $groups = $this->groupConfirmed($conn, $articles, $known, $familyTitles);
        unset($articles, $known);

        $queue = $this->loadQueue($conn);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        /** @var list<array<string, mixed>> $upserts */
        $upserts = [];
        /** @var array<int, int> $accepted id da linha da fila => id do periódico */
        $accepted = [];
        $touched = [];
        $exampleCounts = [];

        foreach ($groups as $group) {
            $norm = $group['norm'];
            $touched[$norm] = true;
            $result['candidates']++;
            $existing = $queue[$norm] ?? null;
            $families = array_keys($group['families']);
            $example = [
                'name' => $group['raw'],
                'journal_id' => null,
                'journal' => '',
                'articles' => $group['articles'],
            ];

            // Decisão da revisão (aceitar/rejeitar) vale mais que o aprendizado automático
            if ($existing !== null && in_array($existing['status'], self::REVIEWED_STATUSES, true)) {
                $result['skipped']++;
                $this->addExample($result['examples'], $exampleCounts, self::ACTION_SKIPPED, $example + ['detail' => 'fila: ' . $existing['status']]);
                continue;
            }

            // O nome aparece com ISSNs de famílias diferentes
            if (count($families) > 1) {
                $result['conflicts']++;
                $upserts[] = $this->queueRow($group, null, JournalNameCandidate::STATUS_AMBIGUOUS, $now);
                $example['journal'] = implode(' | ', array_map(fn (int $f): string => $familyTitles[$f] ?? ('#' . $f), $families));
                $this->addExample($result['examples'], $exampleCounts, self::ACTION_CONFLICT, $example);
                continue;
            }

            $familyId = $families[0];
            $example['journal_id'] = $familyId;
            $example['journal'] = $familyTitles[$familyId] ?? ('#' . $familyId);

            // Pouca confirmação: um clique na fila de revisão
            if ($group['articles'] < $minArticles) {
                $result['queued']++;
                $upserts[] = $this->queueRow($group, $familyId, JournalNameCandidate::STATUS_PENDING, $now);
                $this->addExample($result['examples'], $exampleCounts, self::ACTION_QUEUED, $example);
                continue;
            }

            // Confirmação suficiente: variante `lattes` na canônica da família
            $conflict = null;
            if ($dryRun) {
                $conflict = $this->guard->findConflict($norm, $familyId);
            } else {
                $canonical = $this->em->find(QualisJournal::class, $familyId);
                if ($canonical === null) {
                    $result['skipped']++;
                    continue;
                }
                try {
                    $variation = $this->guard->addVariant($canonical, $group['raw'], self::VARIATION_TYPE);
                    if ($variation === null) {
                        // A família já conhece o nome (corrida com outro processo ou diferença de collation): nada a aprender
                        $result['skipped']++;
                        $this->addExample($result['examples'], $exampleCounts, self::ACTION_SKIPPED, $example + ['detail' => 'já conhecido pela família']);
                        continue;
                    }
                    $this->em->flush();
                } catch (JournalVariantConflictException $e) {
                    $conflict = $e->getConflict();
                }
            }

            if ($conflict !== null) {
                $result['conflicts']++;
                $upserts[] = $this->queueRow($group, null, JournalNameCandidate::STATUS_AMBIGUOUS, $now);
                $this->addExample($result['examples'], $exampleCounts, self::ACTION_CONFLICT, $example + [
                    'detail' => sprintf('já é %s do periódico #%d «%s»', $conflict['kind'] === JournalVariantGuard::KIND_TITLE ? 'o título' : 'variante', $conflict['journalId'], $conflict['title']),
                ]);
                continue;
            }

            $result['learned']++;
            $this->addExample($result['examples'], $exampleCounts, self::ACTION_LEARNED, $example);

            // A linha que estava na fila para este nome está resolvida
            if ($existing !== null) {
                $accepted[$existing['id']] = $familyId;
            }
        }

        // Linhas da fila por ISSN que não valem mais: o nome virou título/variante ou nenhum artigo o confirma
        $staleIds = [];
        foreach ($queue as $name => $row) {
            $name = (string)$name;
            if (isset($touched[$name]) || ($scope !== null && !isset($scope[$name]))) {
                continue;
            }
            if (in_array($row['status'], self::OPEN_STATUSES, true) && $row['method'] === JournalNameCandidate::SUGGESTION_ISSN) {
                $staleIds[] = $row['id'];
            }
        }
        $result['removed'] = count($staleIds);

        if (!$dryRun && ($upserts !== [] || $accepted !== [] || $staleIds !== [])) {
            $conn->transactional(function (Connection $tx) use ($upserts, $accepted, $staleIds, $now): void {
                // Ordem fixa de nome: processos em paralelo travam as linhas na mesma ordem
                usort($upserts, static fn (array $a, array $b): int => strcmp((string)$a['normalized'], (string)$b['normalized']));
                foreach ($upserts as $row) {
                    $this->upsertCandidate($tx, $row);
                }
                foreach ($accepted as $candidateId => $journalId) {
                    $tx->executeStatement(
                        "UPDATE journal_name_candidates
                            SET status = 'accepted', resolved_journal_id = ?, suggested_journal_id = ?, suggestion_method = 'issn',
                                reviewed_at = ?, reviewed_by = ?
                          WHERE id = ? AND status IN ('pending', 'ambiguous')",
                        [$journalId, $journalId, $now, self::REVIEWER, $candidateId]
                    );
                }
                foreach (array_chunk($staleIds, self::IN_CHUNK) as $chunk) {
                    $tx->executeStatement(
                        "DELETE FROM journal_name_candidates
                          WHERE status IN ('pending', 'ambiguous') AND suggestion_method = 'issn'
                            AND id IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                        $chunk
                    );
                }
            });
        }

        return $result;
    }

    /**
     * @param list<string>|null $onlyNames
     *
     * @return array<string, true>|null null = sem restrição
     */
    private static function buildScope(?array $onlyNames): ?array
    {
        if ($onlyNames === null) {
            return null;
        }

        $scope = [];
        foreach ($onlyNames as $name) {
            $norm = StringNormalizer::normalizeString((string)$name, true);
            if ($norm !== '') {
                $scope[$norm] = true;
            }
        }

        return $scope;
    }

    /**
     * Artigos com vínculo e ISSN, do mais recente ao mais antigo. A forma do tesauro do nome vem de
     * `journal_name_normalized` ou, onde a coluna está vazia, é calculada (e contada em $computed).
     *
     * @param array<string, true>|null $scope
     *
     * @return list<array{norm: string, keys: list<string>, fam: int, issns: list<string>, raw: string, issn_raw: string, researcher: int, title: string}>
     */
    private function loadLinkedArticles(Connection $conn, ?array $scope, int &$computed): array
    {
        $stmt = $conn->executeQuery(
            "SELECT p.researcher_id, p.title, p.journal_name, p.journal_name_normalized, p.issn,
                    COALESCE(q.canonical_journal_id, q.id) AS fam
               FROM production_items p
               JOIN qualis_journals q ON q.id = p.qualis_journal_id
              WHERE p.item_type = 'ARTIGO'
                AND p.journal_name IS NOT NULL AND p.journal_name <> ''
                AND p.issn IS NOT NULL AND p.issn <> ''
              ORDER BY p.year DESC, p.id DESC"
        );

        $articles = [];
        while (($row = $stmt->fetchAssociative()) !== false) {
            $raw = trim((string)$row['journal_name']);
            $norm = (string)($row['journal_name_normalized'] ?? '');
            if ($norm === '') {
                $norm = StringNormalizer::normalizeString($raw, true);
                if ($norm !== '') {
                    $computed++;
                }
            }
            if ($norm === '' || ($scope !== null && !isset($scope[$norm]))) {
                continue;
            }

            // Sem exigir dígito verificador: só vale se estiver entre os ISSNs válidos da família
            $issns = Issn::extractAll((string)$row['issn'], false);
            if ($issns === []) {
                continue;
            }

            $articles[] = [
                'norm' => $norm,
                'keys' => self::resolverKeys($raw),
                'fam' => (int)$row['fam'],
                'issns' => $issns,
                'raw' => $raw,
                'issn_raw' => trim((string)$row['issn']),
                'researcher' => (int)$row['researcher_id'],
                'title' => (string)$row['title'],
            ];
        }

        return $articles;
    }

    /**
     * Chaves do resolver (as das etapas 2 e 3 de A5.2: nome exato e nome sem parênteses) que já pertencem ao
     * cadastro: título de alguma linha (com e sem parênteses; `normalized_title` ou, onde está vazio, o próprio título)
     * ou variante (de qualquer status, porque o índice único vale para todas), de qualquer família. Só as chaves
     * que algum artigo usa são guardadas.
     *
     * @param list<array{keys: list<string>}> $articles
     *
     * @return array<string, true>
     */
    private function findKnownKeys(Connection $conn, array $articles, int &$unnormalizedTitles): array
    {
        $wanted = [];
        foreach ($articles as $article) {
            foreach ($article['keys'] as $key) {
                $wanted[$key] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $known = [];

        $stmt = $conn->executeQuery('SELECT title, normalized_title FROM qualis_journals');
        while (($row = $stmt->fetchAssociative()) !== false) {
            $title = (string)$row['title'];
            $normalizedTitle = (string)($row['normalized_title'] ?? '');
            $keys = self::resolverKeys($title, $normalizedTitle);
            if ($normalizedTitle === '' && $keys !== []) {
                $unnormalizedTitles++;
            }
            foreach ($keys as $key) {
                if (isset($wanted[$key])) {
                    $known[$key] = true;
                }
            }
        }

        $stmt = $conn->executeQuery('SELECT normalized_name FROM journal_name_variants');
        while (($row = $stmt->fetchAssociative()) !== false) {
            $key = JournalResolverService::normalizeString((string)$row['normalized_name']);
            if ($key !== '' && isset($wanted[$key])) {
                $known[$key] = true;
            }
        }

        return $known;
    }

    /**
     * Chaves do resolver de um nome ou título: a exata e, se tem parênteses, a sem parênteses (A5.2, etapas 2 e 3).
     * `$normalized` é o `normalized_title` gravado, quando existe: a chave exata parte dele, como em loadFullMaps().
     *
     * @return list<string> sem vazias nem repetidas
     */
    private static function resolverKeys(string $text, ?string $normalized = null): array
    {
        $exact = JournalResolverService::normalizeString($normalized !== null && $normalized !== '' ? $normalized : $text);
        $keys = $exact !== '' ? [$exact] : [];

        if (str_contains($text, '(')) {
            $clean = JournalResolverService::normalizeString(JournalResolverService::stripParentheses($text));
            if ($clean !== '' && $clean !== $exact) {
                $keys[] = $clean;
            }
        }

        return $keys;
    }

    /**
     * Mantém os artigos de nome desconhecido cujo ISSN confirma o vínculo e os agrupa por nome.
     *
     * @param list<array{norm: string, keys: list<string>, fam: int, issns: list<string>, raw: string, issn_raw: string, researcher: int, title: string}> $articles
     * @param array<string, true> $known chaves do resolver que o cadastro já tem (findKnownKeys)
     * @param array<int, string> $familyTitles preenchido com o título da canônica de cada família envolvida
     *
     * @return list<array{
     *     norm: string, articles: int, raw: string, issn_raw: ?string, researchers: int,
     *     titles: list<string>, families: array<int, int>
     * }> do nome com mais artigos para o com menos
     */
    private function groupConfirmed(Connection $conn, array $articles, array $known, array &$familyTitles): array
    {
        $unknown = [];
        $families = [];
        foreach ($articles as $article) {
            $isKnown = false;
            foreach ($article['keys'] as $key) {
                if (isset($known[$key])) {
                    $isKnown = true;
                    break;
                }
            }
            if (!$isKnown) {
                $unknown[] = $article;
                $families[$article['fam']] = true;
            }
        }
        if ($unknown === []) {
            return [];
        }

        $familyIssns = $this->loadFamilyIssns($conn, array_keys($families), $familyTitles);

        $raw = [];
        foreach ($unknown as $article) {
            $valid = $familyIssns[$article['fam']] ?? [];
            $confirmed = false;
            foreach ($article['issns'] as $issn) {
                if (isset($valid[$issn])) {
                    $confirmed = true;
                    break;
                }
            }
            if (!$confirmed) {
                continue;
            }

            $norm = $article['norm'];
            $group = &$raw[$norm];
            $group ??= ['articles' => 0, 'researchers' => [], 'spellings' => [], 'issns' => [], 'titles' => [], 'families' => []];

            $group['articles']++;
            $group['researchers'][$article['researcher']] = true;
            $group['spellings'][$article['raw']] = ($group['spellings'][$article['raw']] ?? 0) + 1;
            $group['issns'][$article['issn_raw']] = ($group['issns'][$article['issn_raw']] ?? 0) + 1;
            $group['families'][$article['fam']] = ($group['families'][$article['fam']] ?? 0) + 1;
            if (count($group['titles']) < self::MAX_SAMPLE_TITLES && !in_array($article['title'], $group['titles'], true)) {
                $group['titles'][] = $article['title'];
            }
            unset($group);
        }

        $groups = [];
        foreach ($raw as $norm => $group) {
            $groups[] = [
                'norm' => (string)$norm,
                'articles' => $group['articles'],
                'raw' => self::mostFrequent($group['spellings']) ?? (string)$norm,
                'issn_raw' => self::mostFrequent($group['issns']),
                'researchers' => count($group['researchers']),
                'titles' => $group['titles'],
                'families' => $group['families'],
            ];
        }
        usort($groups, static fn (array $a, array $b): int => ($b['articles'] <=> $a['articles']) ?: strcmp($a['norm'], $b['norm']));

        return $groups;
    }

    /**
     * ISSNs válidos (dígito verificador conferido) de todas as linhas de cada família.
     *
     * @param list<int|string> $families
     * @param array<int, string> $titles preenchido com o título da linha canônica de cada família
     *
     * @return array<int, array<string, true>> família => ISSNs (8 caracteres)
     */
    private function loadFamilyIssns(Connection $conn, array $families, array &$titles): array
    {
        $issns = [];
        foreach (array_chunk(array_map('intval', $families), self::IN_CHUNK) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            $rows = $conn->fetchAllAssociative(
                "SELECT id, canonical_journal_id, title, issn, issn_e, issn_l, issn_imp
                   FROM qualis_journals
                  WHERE id IN ($in) OR canonical_journal_id IN ($in)",
                [...$chunk, ...$chunk]
            );
            foreach ($rows as $row) {
                $family = $row['canonical_journal_id'] !== null ? (int)$row['canonical_journal_id'] : (int)$row['id'];
                if ((int)$row['id'] === $family) {
                    $titles[$family] = (string)$row['title'];
                }
                foreach (['issn', 'issn_e', 'issn_l', 'issn_imp'] as $column) {
                    foreach (Issn::extractAll($row[$column] !== null ? (string)$row[$column] : null, true) as $n8) {
                        $issns[$family][$n8] = true;
                    }
                }
            }
        }

        return $issns;
    }

    /**
     * Linhas atuais da fila por nome normalizado.
     *
     * @return array<string, array{id: int, status: string, method: ?string}>
     */
    private function loadQueue(Connection $conn): array
    {
        $queue = [];
        foreach ($conn->fetchAllAssociative('SELECT id, normalized_name, status, suggestion_method FROM journal_name_candidates') as $row) {
            $queue[(string)$row['normalized_name']] = [
                'id' => (int)$row['id'],
                'status' => (string)$row['status'],
                'method' => $row['suggestion_method'] !== null ? (string)$row['suggestion_method'] : null,
            ];
        }

        return $queue;
    }

    /**
     * @param array{norm: string, articles: int, raw: string, issn_raw: ?string, researchers: int, titles: list<string>} $group
     *
     * @return array<string, mixed>
     */
    private function queueRow(array $group, ?int $suggestedJournalId, string $status, string $now): array
    {
        return [
            'normalized' => mb_substr($group['norm'], 0, 500, 'UTF-8'),
            'raw' => mb_substr($group['raw'], 0, 500, 'UTF-8'),
            'issn' => $group['issn_raw'] !== null ? mb_substr($group['issn_raw'], 0, 50, 'UTF-8') : null,
            'articles' => $group['articles'],
            'researchers' => $group['researchers'],
            'suggested' => $suggestedJournalId,
            'status' => $status,
            'titles' => json_encode($group['titles'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'now' => $now,
        ];
    }

    /**
     * Grava o nome na fila. Linha que já existe só é atualizada se ainda está aberta (pending/ambiguous): o que a revisão
     * decidiu não muda. `status` é a última atribuição porque as anteriores leem o valor antigo.
     *
     * @param array<string, mixed> $row
     */
    private function upsertCandidate(Connection $conn, array $row): void
    {
        $open = "status IN ('pending', 'ambiguous')";
        $assignments = [];
        foreach (['raw_name', 'issn_raw', 'articles_count', 'researchers_count', 'suggested_journal_id', 'suggestion_method', 'sample_titles', 'last_seen_at'] as $column) {
            $assignments[] = "$column = IF($open, VALUES($column), $column)";
        }
        $assignments[] = "status = IF($open, VALUES(status), status)";

        $conn->executeStatement(
            "INSERT INTO journal_name_candidates
                (normalized_name, raw_name, issn_raw, articles_count, researchers_count, suggested_journal_id,
                 suggestion_method, status, resolved_journal_id, sample_titles, first_seen_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, 'issn', ?, NULL, ?, ?, ?)
             ON DUPLICATE KEY UPDATE " . implode(', ', $assignments),
            [
                $row['normalized'],
                $row['raw'],
                $row['issn'],
                $row['articles'],
                $row['researchers'],
                $row['suggested'],
                $row['status'],
                $row['titles'],
                $row['now'],
                $row['now'],
            ]
        );
    }

    /**
     * @param list<array<string, mixed>> $examples
     * @param array<string, int> $counts
     * @param array<string, mixed> $example
     */
    private function addExample(array &$examples, array &$counts, string $action, array $example): void
    {
        if (($counts[$action] ?? 0) >= self::MAX_EXAMPLES_PER_ACTION) {
            return;
        }
        $counts[$action] = ($counts[$action] ?? 0) + 1;
        $examples[] = ['action' => $action] + $example;
    }

    /**
     * Valor mais frequente de um mapa valor => contagem; em empate, o menor em ordem alfabética (resultado estável).
     *
     * @param array<int|string, int> $counts
     */
    private static function mostFrequent(array $counts): ?string
    {
        $best = null;
        $bestCount = 0;
        foreach ($counts as $value => $count) {
            $value = (string)$value;
            if ($count > $bestCount || ($count === $bestCount && $best !== null && strcmp($value, $best) < 0)) {
                $best = $value;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
