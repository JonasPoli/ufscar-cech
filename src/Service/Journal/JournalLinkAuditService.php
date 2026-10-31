<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Service\Thesaurus\JournalResolverService;
use Doctrine\DBAL\Connection;

/**
 * Auditoria dos vínculos artigo→periódico (T09 de `docs/evolutions/periodicos-por-base.md`, A5.6).
 *
 * Para cada artigo (`production_items.item_type = 'ARTIGO'`), o estado guarda o periódico vinculado (`j`, a coluna
 * `qualis_journal_id`), o estrato Qualis do artigo (`q`, a coluna `qualis`) e as bases de indexação do periódico
 * (`d`, acrônimos de `qualis_journal_academic_database`). Sem Qualis, `q` é null e o estrato aparece como `-`.
 *
 * - `snapshot()` grava esse estado em JSON (antes da reindexação).
 * - `compare()` lê o JSON, recalcula o estado atual e devolve: iguais, mudaram de periódico, ganharam ou perderam
 *   vínculo, variação por estrato e por base, os maiores pares (título antes → título depois) e os três casos
 *   conhecidos de A5.6, que devem dar zero.
 *
 * Só lê o banco; a única escrita é o arquivo do snapshot. Nomes e títulos são comparados na forma do resolver
 * ({@see JournalResolverService::normalizeString()}: minúsculas, sem acento, pontuação virando espaço), porque
 * `journal_name_normalized` e `qualis_journals.normalized_title` podem estar vazios.
 *
 * Forma do estado (`snapshot()` e `compare()` usam a mesma):
 *   taken_at  data/hora da leitura (ISO 8601)
 *   articles  {id: {j, q, d}}
 *   journals  {id do periódico: título}, só os periódicos vinculados a algum artigo
 *   totals    {articles, linked, by_qualis: {estrato: n}, by_db: {acrônimo: n}}
 *   checks    {caso: {label, count, examples}} (casos conhecidos de A5.6)
 */
class JournalLinkAuditService
{
    /** Quantos pares (antes → depois) o resultado de `compare()` traz. */
    public const TOP_PAIRS = 30;

    /** Chave do estrato de artigos sem Qualis. */
    public const NO_QUALIS = '-';

    private const NO_JOURNAL = '(sem vínculo)';
    private const EXAMPLES = 5;
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * Casos conhecidos de A5.6. Há violação quando o nome do artigo (`journal_name_normalized` ou `journal_name`)
     * contém um dos trechos de `article` e o periódico vinculado (`title` ou `normalized_title`) contém um dos
     * trechos de `journal`. A meta é zero.
     */
    private const CASES = [
        'turismo_visao_acao' => [
            'label' => 'Turismo: Visão e Ação não recebe Ciência e Educação / História da Educação',
            'article' => ['ciencia e educacao', 'historia da educacao'],
            'journal' => ['turismo visao e acao'],
        ],
        'construcao_psicopedagogica' => [
            'label' => 'Construção Psicopedagógica não recebe Avaliação Psicológica',
            'article' => ['avaliacao psicologica'],
            'journal' => ['construcao psicopedagogica'],
        ],
        'caminhos_educacao_matematica' => [
            'label' => 'Caminhos da Educação Matemática não recebe Educação Matemática em Revista',
            'article' => ['educacao matematica em revista'],
            // "Caminhos da Educação Matemática em Revista" é a própria revista, não uma violação.
            'article_not' => ['caminhos da educacao matematica'],
            'journal' => ['caminhos da educacao matematica'],
        ],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Grava o estado atual em `$file` (JSON) e devolve o mesmo conteúdo.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $file): array
    {
        $state = $this->currentState();
        $this->writeJson($file, $state);

        return $state;
    }

    /**
     * Lê o snapshot em `$file`, recalcula o estado atual e devolve a comparação.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException arquivo ausente ou que não é um snapshot
     * @throws \JsonException    arquivo com JSON inválido
     */
    public function compare(string $file): array
    {
        $before = $this->readSnapshot($file);

        return $this->diff($before, $this->currentState(), $file);
    }

    /** @return array<string, mixed> */
    private function currentState(): array
    {
        return $this->buildState($this->loadRows(), (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
    }

    /**
     * Lê as três tabelas, só com SELECT. Periódicos e bases vêm apenas dos periódicos vinculados a artigos.
     *
     * @return array{articles: list<array<string, mixed>>, journals: list<array<string, mixed>>, bases: list<array<string, mixed>>}
     */
    protected function loadRows(): array
    {
        $linkedJournals = "SELECT pi.qualis_journal_id FROM production_items pi"
            . " WHERE pi.item_type = 'ARTIGO' AND pi.qualis_journal_id IS NOT NULL";
        // journal_name_normalized é opcional: sem a coluna, a comparação usa só journal_name.
        $nameColumn = $this->hasNormalizedNameColumn() ? 'pi.journal_name_normalized' : 'NULL';

        return [
            'articles' => $this->connection->fetchAllAssociative(
                "SELECT pi.id, pi.journal_name, $nameColumn AS journal_name_normalized, pi.qualis, pi.qualis_journal_id
                 FROM production_items pi
                 WHERE pi.item_type = 'ARTIGO'
                 ORDER BY pi.id"
            ),
            'journals' => $this->connection->fetchAllAssociative(
                "SELECT qj.id, qj.title, qj.normalized_title FROM qualis_journals qj WHERE qj.id IN ($linkedJournals)"
            ),
            'bases' => $this->connection->fetchAllAssociative(
                "SELECT qjad.qualis_journal_id, ad.acronym
                 FROM qualis_journal_academic_database qjad
                 INNER JOIN academic_database ad ON ad.id = qjad.academic_database_id
                 WHERE qjad.qualis_journal_id IN ($linkedJournals)"
            ),
        ];
    }

    private function hasNormalizedNameColumn(): bool
    {
        $columns = array_map('strtolower', array_keys($this->connection->createSchemaManager()->listTableColumns('production_items')));

        return in_array('journal_name_normalized', $columns, true);
    }

    /**
     * Monta o estado a partir das linhas lidas. Não acessa o banco, por isso é testável com dados fictícios.
     *
     * @param array{articles: list<array<string, mixed>>, journals: list<array<string, mixed>>, bases: list<array<string, mixed>>} $rows
     *
     * @return array<string, mixed>
     */
    private function buildState(array $rows, string $takenAt): array
    {
        $basesByJournal = [];
        foreach ($rows['bases'] as $row) {
            $basesByJournal[(int) $row['qualis_journal_id']][] = (string) $row['acronym'];
        }
        foreach ($basesByJournal as $journalId => $acronyms) {
            $acronyms = array_values(array_unique($acronyms));
            sort($acronyms, SORT_STRING);
            $basesByJournal[$journalId] = $acronyms;
        }

        $titles = [];
        $titleKeys = [];
        foreach ($rows['journals'] as $row) {
            $journalId = (int) $row['id'];
            $titles[$journalId] = (string) $row['title'];
            $titleKeys[$journalId] = [self::norm($row['title']), self::norm($row['normalized_title'])];
        }

        $checks = [];
        foreach (self::CASES as $key => $case) {
            $checks[$key] = ['label' => $case['label'], 'count' => 0, 'examples' => []];
        }

        $articles = [];
        $byQualis = [];
        $byDb = [];
        $linked = 0;
        foreach ($rows['articles'] as $row) {
            $id = (int) $row['id'];
            $journalId = $row['qualis_journal_id'] === null ? null : (int) $row['qualis_journal_id'];
            $qualis = is_string($row['qualis']) && $row['qualis'] !== '' ? $row['qualis'] : null;
            $databases = $journalId === null ? [] : ($basesByJournal[$journalId] ?? []);

            $articles[$id] = ['j' => $journalId, 'q' => $qualis, 'd' => $databases];

            $stratum = $qualis ?? self::NO_QUALIS;
            $byQualis[$stratum] = ($byQualis[$stratum] ?? 0) + 1;
            foreach ($databases as $acronym) {
                $byDb[$acronym] = ($byDb[$acronym] ?? 0) + 1;
            }

            if ($journalId === null) {
                continue;
            }
            $linked++;

            $names = [self::norm($row['journal_name_normalized'] ?? null), self::norm($row['journal_name'])];
            foreach (self::CASES as $key => $case) {
                if (
                    !self::containsAny($names, $case['article'])
                    || self::containsAny($names, $case['article_not'] ?? [])
                    || !self::containsAny($titleKeys[$journalId] ?? [], $case['journal'])
                ) {
                    continue;
                }
                $checks[$key]['count']++;
                if (count($checks[$key]['examples']) < self::EXAMPLES) {
                    $checks[$key]['examples'][] = [
                        'id' => $id,
                        'journal_name' => $row['journal_name'],
                        'journal_id' => $journalId,
                        'journal_title' => $titles[$journalId] ?? null,
                    ];
                }
            }
        }
        ksort($byDb, SORT_STRING);

        return [
            'taken_at' => $takenAt,
            'articles' => $articles,
            'journals' => $titles,
            'totals' => [
                'articles' => count($articles),
                'linked' => $linked,
                'by_qualis' => $byQualis,
                'by_db' => $byDb,
            ],
            'checks' => $checks,
        ];
    }

    /**
     * Compara o snapshot (antes) com o estado atual (depois). Os dois têm a forma de {@see buildState()}.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return array<string, mixed>
     */
    private function diff(array $before, array $after, string $file): array
    {
        $same = 0;
        $changed = 0;
        $gained = 0;
        $lost = 0;
        $otherChanges = 0;
        $removed = 0;
        $pairs = [];

        foreach ($before['articles'] as $id => $old) {
            if (!array_key_exists($id, $after['articles'])) {
                $removed++;
                continue;
            }

            $now = $after['articles'][$id];
            if ($old['j'] === $now['j']) {
                $same++;
                if ($old['q'] !== $now['q'] || $old['d'] !== $now['d']) {
                    $otherChanges++;
                }
                continue;
            }

            if ($old['j'] === null) {
                $gained++;
            } elseif ($now['j'] === null) {
                $lost++;
            } else {
                $changed++;
            }

            $key = ($old['j'] ?? 0) . '>' . ($now['j'] ?? 0);
            $pairs[$key] ??= ['from_id' => $old['j'], 'to_id' => $now['j'], 'n' => 0];
            $pairs[$key]['n']++;
        }

        $pairRows = [];
        foreach ($pairs as $pair) {
            $pairRows[] = [
                'from' => $this->journalLabel($before, $pair['from_id']),
                'from_id' => $pair['from_id'],
                'to' => $this->journalLabel($after, $pair['to_id']),
                'to_id' => $pair['to_id'],
                'n' => $pair['n'],
            ];
        }
        usort($pairRows, static fn (array $a, array $b): int => [$b['n'], $a['from'], $a['to']] <=> [$a['n'], $b['from'], $b['to']]);

        $checks = [];
        foreach (self::CASES as $key => $case) {
            $checks[$key] = [
                'label' => $case['label'],
                'before' => $before['checks'][$key]['count'] ?? null,
                'after' => $after['checks'][$key]['count'] ?? 0,
                'examples' => $after['checks'][$key]['examples'] ?? [],
            ];
        }

        return [
            'snapshot' => ['file' => $file, 'taken_at' => $before['taken_at']],
            'compared_at' => $after['taken_at'],
            'articles' => [
                'before' => count($before['articles']),
                'after' => count($after['articles']),
                'new' => count(array_diff_key($after['articles'], $before['articles'])),
                'removed' => $removed,
            ],
            'linked' => ['before' => $before['totals']['linked'], 'after' => $after['totals']['linked']],
            'same' => $same,
            'changed' => $changed,
            'gained' => $gained,
            'lost' => $lost,
            'same_journal_other_changes' => $otherChanges,
            'by_qualis' => $this->variation($before['totals']['by_qualis'], $after['totals']['by_qualis'], self::NO_QUALIS),
            'by_db' => $this->variation($before['totals']['by_db'], $after['totals']['by_db']),
            'pairs' => array_slice($pairRows, 0, self::TOP_PAIRS),
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     *
     * @return array<string, array{before: int, after: int, delta: int}>
     */
    private function variation(array $before, array $after, ?string $last = null): array
    {
        $keys = array_map('strval', array_values(array_unique([...array_keys($before), ...array_keys($after)])));
        sort($keys, SORT_STRING);
        if ($last !== null && in_array($last, $keys, true)) {
            $keys = array_values(array_diff($keys, [$last]));
            $keys[] = $last;
        }

        $variation = [];
        foreach ($keys as $key) {
            $b = (int) ($before[$key] ?? 0);
            $a = (int) ($after[$key] ?? 0);
            $variation[$key] = ['before' => $b, 'after' => $a, 'delta' => $a - $b];
        }

        return $variation;
    }

    /** @param array<string, mixed> $state */
    private function journalLabel(array $state, ?int $journalId): string
    {
        if ($journalId === null) {
            return self::NO_JOURNAL;
        }

        return $state['journals'][$journalId] ?? sprintf('(periódico %d)', $journalId);
    }

    /**
     * @param list<string> $haystacks
     * @param list<string> $needles
     */
    private static function containsAny(array $haystacks, array $needles): bool
    {
        foreach ($haystacks as $haystack) {
            foreach ($needles as $needle) {
                if ($haystack !== '' && str_contains($haystack, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function norm(mixed $value): string
    {
        return JournalResolverService::normalizeString(is_string($value) ? $value : null);
    }

    /** @return array<string, mixed> */
    private function readSnapshot(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('Snapshot não encontrado: %s (gere-o antes com --snapshot).', $file));
        }

        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['taken_at'], $data['articles'], $data['totals']) || !is_array($data['articles'])) {
            throw new \RuntimeException(sprintf('O arquivo %s não é um snapshot de vínculos artigo→periódico.', $file));
        }
        $data['journals'] ??= [];
        $data['checks'] ??= [];

        return $data;
    }

    /** @param array<string, mixed> $state */
    private function writeJson(string $file, array $state): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Não foi possível criar o diretório %s.', $dir));
        }

        // Mapas por id ou por código viram objeto JSON mesmo quando estão vazios ou têm chaves numéricas.
        $state['articles'] = (object) $state['articles'];
        $state['journals'] = (object) $state['journals'];
        $state['totals']['by_qualis'] = (object) $state['totals']['by_qualis'];
        $state['totals']['by_db'] = (object) $state['totals']['by_db'];
        $state['checks'] = (object) $state['checks'];

        if (file_put_contents($file, json_encode($state, self::JSON_FLAGS)) === false) {
            throw new \RuntimeException(sprintf('Não foi possível gravar o snapshot em %s.', $file));
        }
    }
}
