<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Entity\JournalNameCandidate;
use App\Service\Thesaurus\JournalResolverService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fila de revisão de nomes de periódicos (`journal_name_candidates`, docs/evolutions/periodicos-por-base.md, A5.4).
 *
 * Cada nome de periódico dos artigos do Lattes que ficou sem vínculo (`qualis_journal_id IS NULL`) vira uma linha da
 * fila, agrupado por `journal_name_normalized`. Nome que casa com periódicos de mais de uma família entra com
 * `status = 'ambiguous'`; os demais, como `pending`. A sugestão por palavras-chave é só uma sugestão, nunca vínculo.
 *
 * `rebuild()` é chamado ao final da indexação (CurriculumNormalizationService); é idempotente e preserva o que a
 * revisão já decidiu (`accepted`/`rejected`) e as sugestões por ISSN gravadas pelo aprendizado de grafias.
 */
class JournalNameQueueService
{
    private const UPSERT_CHUNK = 200;
    private const DELETE_CHUNK = 500;
    private const MAX_SAMPLE_TITLES = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalResolverService $resolver,
    ) {
    }

    /**
     * Recalcula a fila a partir dos artigos sem vínculo.
     *
     * Para cada nome sem vínculo: contagens, grafia mais frequente, ISSN mais frequente, até 3 títulos de amostra
     * (os mais recentes), sugestão por palavras-chave e se o nome é ambíguo. Nomes novos entram como `pending`
     * (ou `ambiguous`); nomes que já estavam na fila têm contagens e amostras atualizadas e mantêm o `status` se
     * já foram revisados. Nomes `pending`/`ambiguous` que deixaram de aparecer (o artigo passou a ter vínculo)
     * saem da fila.
     *
     * @param bool $refreshSuggestions true (padrão): recalcula sugestão e ambiguidade de todos os nomes, o que
     *        exige resolver cada nome. false: só os nomes novos são resolvidos; os demais apenas têm contagens e
     *        amostras atualizadas (usado ao importar um currículo, em modo on-demand, para não resolver milhares de
     *        nomes a cada importação).
     *
     * @return array{pending: int, ambiguous: int, removed: int}
     */
    public function rebuild(bool $refreshSuggestions = true): array
    {
        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $groups = $this->collectUnlinkedNames($conn);
        $existing = $this->loadExisting($conn);

        $full = [];
        $light = [];
        foreach ($groups as $group) {
            $row = [
                'normalized' => $group['normalized'],
                'raw' => $group['raw'],
                'issn' => $group['issn'],
                'articles' => $group['articles'],
                'researchers' => $group['researchers'],
                'suggested' => null,
                'method' => JournalNameCandidate::SUGGESTION_NONE,
                'status' => JournalNameCandidate::STATUS_PENDING,
                'titles' => json_encode($group['titles'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ];

            if ($refreshSuggestions || !isset($existing[$group['normalized']])) {
                $suggested = $this->resolver->suggestByKeywords($group['raw']);
                $row['suggested'] = $suggested;
                $row['method'] = $suggested !== null ? JournalNameCandidate::SUGGESTION_KEYWORDS : JournalNameCandidate::SUGGESTION_NONE;
                $row['status'] = $this->resolver->isAmbiguousName($group['raw'])
                    ? JournalNameCandidate::STATUS_AMBIGUOUS
                    : JournalNameCandidate::STATUS_PENDING;
                $full[] = $row;
            } else {
                $light[] = $row;
            }
        }

        // Ordem fixa de nome: dois processos reconstruindo ao mesmo tempo travam as linhas na mesma ordem
        $byName = static fn (array $a, array $b): int => strcmp($a['normalized'], $b['normalized']);
        usort($full, $byName);
        usort($light, $byName);

        $seen = [];
        foreach ($groups as $group) {
            $seen[$group['normalized']] = true;
        }

        // Pendentes que sumiram. Sugestões por ISSN (gravadas pelo aprendizado de grafias, que se refere a artigos
        // que já têm vínculo e por isso não aparecem nesta consulta) não são tocadas.
        $staleIds = [];
        foreach ($existing as $normalized => $candidate) {
            if (isset($seen[$normalized])) {
                continue;
            }
            $open = in_array($candidate['status'], [JournalNameCandidate::STATUS_PENDING, JournalNameCandidate::STATUS_AMBIGUOUS], true);
            if ($open && $candidate['method'] !== JournalNameCandidate::SUGGESTION_ISSN) {
                $staleIds[] = $candidate['id'];
            }
        }

        $removed = 0;
        $conn->transactional(function (Connection $tx) use ($full, $light, $staleIds, $now, &$removed): void {
            $this->upsert($tx, $full, true, $now);
            $this->upsert($tx, $light, false, $now);

            foreach (array_chunk($staleIds, self::DELETE_CHUNK) as $chunk) {
                $removed += (int)$tx->executeStatement(
                    'DELETE FROM journal_name_candidates WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                    $chunk
                );
            }
        });

        $counts = $conn->fetchAllKeyValue(
            "SELECT status, COUNT(*) FROM journal_name_candidates WHERE status IN ('pending', 'ambiguous') GROUP BY status"
        );

        return [
            'pending' => (int)($counts[JournalNameCandidate::STATUS_PENDING] ?? 0),
            'ambiguous' => (int)($counts[JournalNameCandidate::STATUS_AMBIGUOUS] ?? 0),
            'removed' => $removed,
        ];
    }

    /**
     * Artigos sem vínculo agrupados por nome normalizado. Uma só consulta; o resto é feito em PHP.
     *
     * @return list<array{normalized: string, raw: string, issn: ?string, articles: int, researchers: int, titles: list<string>}>
     */
    private function collectUnlinkedNames(Connection $conn): array
    {
        $rows = $conn->fetchAllAssociative(
            "SELECT researcher_id, journal_name_normalized AS normalized, journal_name AS raw, issn, title
               FROM production_items
              WHERE item_type = 'ARTIGO'
                AND qualis_journal_id IS NULL
                AND journal_name_normalized IS NOT NULL
                AND journal_name_normalized <> ''
              ORDER BY year DESC, id DESC"
        );

        $groups = [];
        foreach ($rows as $row) {
            $key = (string)$row['normalized'];
            if (!isset($groups[$key])) {
                $groups[$key] = ['articles' => 0, 'researchers' => [], 'spellings' => [], 'issns' => [], 'titles' => []];
            }

            $groups[$key]['articles']++;
            $groups[$key]['researchers'][(int)$row['researcher_id']] = true;

            $spelling = (string)$row['raw'];
            $groups[$key]['spellings'][$spelling] = ($groups[$key]['spellings'][$spelling] ?? 0) + 1;

            $issn = trim((string)$row['issn']);
            if ($issn !== '') {
                $groups[$key]['issns'][$issn] = ($groups[$key]['issns'][$issn] ?? 0) + 1;
            }

            $title = (string)$row['title'];
            if (count($groups[$key]['titles']) < self::MAX_SAMPLE_TITLES && !in_array($title, $groups[$key]['titles'], true)) {
                $groups[$key]['titles'][] = $title;
            }
        }

        $result = [];
        foreach ($groups as $key => $group) {
            $result[] = [
                'normalized' => (string)$key,
                'raw' => self::mostFrequent($group['spellings']) ?? (string)$key,
                'issn' => self::mostFrequent($group['issns']),
                'articles' => $group['articles'],
                'researchers' => count($group['researchers']),
                'titles' => $group['titles'],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array{id: int, status: string, method: ?string}> por nome normalizado
     */
    private function loadExisting(Connection $conn): array
    {
        $existing = [];
        foreach ($conn->fetchAllAssociative('SELECT id, normalized_name, status, suggestion_method FROM journal_name_candidates') as $row) {
            $existing[(string)$row['normalized_name']] = [
                'id' => (int)$row['id'],
                'status' => (string)$row['status'],
                'method' => $row['suggestion_method'] !== null ? (string)$row['suggestion_method'] : null,
            ];
        }

        return $existing;
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE em lotes. Com $withSuggestions grava também sugestão e status, exceto:
     * `status` de linhas já revisadas (accepted/rejected) e sugestão por ISSN, que são mantidos.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function upsert(Connection $conn, array $rows, bool $withSuggestions, string $now): void
    {
        $assignments = [
            'articles_count = VALUES(articles_count)',
            'researchers_count = VALUES(researchers_count)',
            'raw_name = VALUES(raw_name)',
            'issn_raw = VALUES(issn_raw)',
            'sample_titles = VALUES(sample_titles)',
            'last_seen_at = VALUES(last_seen_at)',
        ];
        if ($withSuggestions) {
            // A ordem importa: as duas primeiras leem o suggestion_method ainda não alterado
            $assignments[] = "suggested_journal_id = IF(suggestion_method = 'issn', suggested_journal_id, VALUES(suggested_journal_id))";
            $assignments[] = "suggestion_method = IF(suggestion_method = 'issn', suggestion_method, VALUES(suggestion_method))";
            $assignments[] = "status = IF(status IN ('pending', 'ambiguous'), VALUES(status), status)";
        }

        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)';
                array_push(
                    $params,
                    $row['normalized'],
                    $row['raw'],
                    $row['issn'],
                    $row['articles'],
                    $row['researchers'],
                    $row['suggested'],
                    $row['method'],
                    $row['status'],
                    $row['titles'],
                    $now,
                    $now
                );
            }

            $conn->executeStatement(
                'INSERT INTO journal_name_candidates
                    (normalized_name, raw_name, issn_raw, articles_count, researchers_count, suggested_journal_id,
                     suggestion_method, status, resolved_journal_id, sample_titles, first_seen_at, last_seen_at)
                 VALUES ' . implode(', ', $placeholders) . '
                 ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments),
                $params
            );
        }
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
