<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Entity\JournalNameCandidate;
use App\Entity\QualisJournal;
use App\Service\Thesaurus\JournalResolverService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Alimenta o tesauro de periódicos com os títulos das listas externas (Scopus, Web of Science, DOAJ, Latindex, SciELO e
 * OpenAlex, inclusive `alternate_titles`), lidos do artefato de evidências.
 *
 * Um título externo só vale pelo ISSN: o registro precisa apontar para uma única família do cadastro. A partir daí, o
 * nome vira variante `external` na linha canônica da família, por JournalVariantGuard, se:
 *  - ainda não é título nem variante de nenhuma linha, nem foi rejeitado na fila de nomes;
 *  - só aparece em uma família (nome em mais de uma é ambíguo e fica de fora);
 *  - é coerente com um título da família: nota >= 0,6, ou está contido nele (ou o contém) com uma palavra rara, ou duas
 *    listas diferentes dão o mesmo nome à mesma família.
 *
 * O que não passa não é gravado nem vai para a fila, porque um nome desses só faria diferença se um dia aparecesse num
 * currículo; aí o aprendizado por ISSN (JournalNameLearner) ou a fila de nomes cuidam dele. O relatório traz as contagens.
 * Rodar de novo não duplica nada, e nenhuma variante existente é alterada.
 */
class JournalExternalNameLearner
{
    public const VARIATION_TYPE = 'external';

    /** Nota mínima contra um título da família. */
    private const MIN_SCORE = 0.6;

    /** Uma palavra que aparece em mais títulos do que isso não identifica uma revista sozinha. */
    private const RARE_WORD_MAX_TITLES = 5;

    private const MIN_KEY_LENGTH = 4;
    private const MAX_EXAMPLES = 10;
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalVariantGuard $guard,
        private readonly JournalEvidenceArtifactReader $reader,
    ) {
    }

    /**
     * @return array{records: int, candidates: int, learned: int, ambiguous: int, incoherent: int, conflicts: int, by_source: array<string, int>, examples: list<array{name: string, journal: string}>, rejected_examples: list<array{name: string, journal: string, score: float}>}
     */
    public function learn(string $artifact, bool $dryRun = false): array
    {
        $conn = $this->em->getConnection();

        // Família e títulos de cada linha; ISSN em mais de uma família não serve para decidir nada.
        $issnFamily = [];
        $titlesByFamily = [];
        $known = [];
        $wordTitles = [];
        foreach ($conn->iterateAssociative(
            'SELECT id, COALESCE(canonical_journal_id, id) AS family, title, normalized_title,
                    normalized_issn, normalized_issn_e, normalized_issn_l, normalized_issn_imp
               FROM qualis_journals'
        ) as $row) {
            $family = (int)$row['family'];
            foreach (['normalized_issn', 'normalized_issn_e', 'normalized_issn_l', 'normalized_issn_imp'] as $column) {
                $issn = strtolower(trim((string)$row[$column]));
                if ($issn !== '') {
                    $issnFamily[$issn] = isset($issnFamily[$issn]) && $issnFamily[$issn] !== $family ? 0 : $family;
                }
            }

            // As mesmas chaves do resolver: um nome igual ao título sem parênteses de outra revista ("PSYCHE", de
            // "Psyche (Pasadena)") deixaria de resolver se virasse variante.
            [$key, $clean] = JournalResolverService::titleKeys($row['title'], $row['normalized_title']);
            if ($key === '') {
                continue;
            }
            $titlesByFamily[$family][$key] = true;
            $known[$key] = true;
            if ($clean !== '') {
                $known[$clean] = true;
            }
            foreach (array_unique(explode(' ', $key)) as $word) {
                $wordTitles[$word] = ($wordTitles[$word] ?? 0) + 1;
            }
        }
        foreach ($conn->iterateNumeric('SELECT normalized_name FROM journal_name_variants') as [$name]) {
            $known[JournalResolverService::normalizeString((string)$name)] = true;
        }
        foreach ($conn->iterateNumeric('SELECT normalized_name FROM journal_name_candidates WHERE status = ?', [JournalNameCandidate::STATUS_REJECTED]) as [$name]) {
            $known[JournalResolverService::normalizeString((string)$name)] = true;
        }

        // Nome => famílias, listas que o trazem e a primeira grafia.
        $records = 0;
        $names = [];
        foreach ($this->reader->read($artifact) as $record) {
            $family = $this->familyOf((array)($record['issns'] ?? []), $issnFamily);
            if ($family === null) {
                continue;
            }
            ++$records;

            $source = (string)($record['src'] ?? '');
            $titles = [(string)($record['title'] ?? '')];
            foreach ((array)($record['extra']['alt_titles'] ?? []) as $alternative) {
                $titles[] = (string)$alternative;
            }

            foreach ($titles as $raw) {
                // Na forma do resolver, que só guarda letras latinas: um título só em cirílico ou em árabe nunca seria achado.
                $raw = trim($raw);
                $key = JournalResolverService::normalizeString($raw);
                if (strlen($key) < self::MIN_KEY_LENGTH || isset($known[$key])) {
                    continue;
                }
                $names[$key]['raw'] ??= $raw;
                $names[$key]['families'][$family][$source] = true;
            }
        }

        $result = [
            'records' => $records,
            'candidates' => count($names),
            'learned' => 0,
            'ambiguous' => 0,
            'incoherent' => 0,
            'conflicts' => 0,
            'by_source' => [],
            'examples' => [],
            'rejected_examples' => [],
        ];

        $pending = 0;
        if (!$dryRun) {
            $conn->beginTransaction();
        }
        foreach ($names as $key => $name) {
            if (count($name['families']) > 1) {
                ++$result['ambiguous'];
                continue;
            }

            $family = (int)array_key_first($name['families']);
            $sources = $name['families'][$family];
            $titles = array_map('strval', array_keys($titlesByFamily[$family] ?? []));
            $score = $this->bestScore((string)$key, $titles);
            if ($score < self::MIN_SCORE && count($sources) < 2 && !$this->containsWithRareWord((string)$key, $titles, $wordTitles)) {
                ++$result['incoherent'];
                if (count($result['rejected_examples']) < self::MAX_EXAMPLES) {
                    $result['rejected_examples'][] = ['name' => (string)$name['raw'], 'journal' => $titles[0] ?? '', 'score' => round($score, 2)];
                }
                continue;
            }

            if (!$dryRun) {
                $journal = $this->em->getRepository(QualisJournal::class)->find($family);
                if ($journal === null) {
                    continue;
                }
                try {
                    if ($this->guard->addVariant($journal, (string)$name['raw'], self::VARIATION_TYPE) === null) {
                        continue;
                    }
                } catch (JournalVariantConflictException) {
                    ++$result['conflicts'];
                    continue;
                }
                // Grava já: o índice único segue a colação do banco ("HOMŒOPATHIC" = "HOMOEOPATHIC"), e a trava só
                // enxerga isso em linhas gravadas. A transação evita um fsync por nome.
                $this->em->flush();
                if (++$pending >= self::BATCH_SIZE) {
                    $conn->commit();
                    $this->em->clear();
                    $conn->beginTransaction();
                    $pending = 0;
                }
            }

            ++$result['learned'];
            foreach (array_keys($sources) as $source) {
                $result['by_source'][(string)$source] = ($result['by_source'][(string)$source] ?? 0) + 1;
            }
            if (count($result['examples']) < self::MAX_EXAMPLES) {
                $result['examples'][] = ['name' => (string)$name['raw'], 'journal' => $titles[0] ?? ''];
            }
        }

        if (!$dryRun) {
            $conn->commit();
            $this->em->clear();
        }

        return $result;
    }

    /**
     * Família do registro: a única apontada pelos seus ISSNs. Nenhuma, ou mais de uma, responde null.
     *
     * @param array<mixed> $issns
     * @param array<string, int> $issnFamily
     */
    private function familyOf(array $issns, array $issnFamily): ?int
    {
        $family = null;
        foreach ($issns as $issn) {
            $current = $issnFamily[strtolower(trim((string)$issn))] ?? null;
            if ($current === null) {
                continue;
            }
            if ($current === 0 || ($family !== null && $family !== $current)) {
                return null;
            }
            $family = $current;
        }

        return $family;
    }

    /**
     * @param list<string> $titles
     */
    private function bestScore(string $key, array $titles): float
    {
        $best = 0.0;
        foreach ($titles as $title) {
            $best = max($best, self::score($key, $title));
        }

        return $best;
    }

    /**
     * Nota de semelhança: média das palavras em comum (Jaccard) e dos caracteres em comum.
     */
    private static function score(string $a, string $b): float
    {
        $wordsA = array_unique(explode(' ', $a));
        $wordsB = array_unique(explode(' ', $b));
        $union = count(array_unique(array_merge($wordsA, $wordsB)));
        similar_text($a, $b, $percent);

        return 0.5 * ($union > 0 ? count(array_intersect($wordsA, $wordsB)) / $union : 0.0) + 0.5 * $percent / 100;
    }

    /**
     * Todas as palavras do nome menor estão no maior, e uma delas é rara no cadastro: "Changing English" dentro de
     * "Changing English: Studies in Culture and Education" passa; "Social Sciences" dentro de "Journal of Social
     * Sciences" não, porque essas palavras aparecem em centenas de títulos.
     *
     * @param list<string> $titles
     * @param array<string, int> $wordTitles
     */
    private function containsWithRareWord(string $key, array $titles, array $wordTitles): bool
    {
        $nameWords = array_unique(explode(' ', $key));
        foreach ($titles as $title) {
            $titleWords = array_unique(explode(' ', $title));
            [$small, $large] = count($nameWords) <= count($titleWords) ? [$nameWords, $titleWords] : [$titleWords, $nameWords];
            if (array_diff($small, $large) !== []) {
                continue;
            }
            foreach ($small as $word) {
                if (mb_strlen($word, 'UTF-8') >= 5 && ($wordTitles[$word] ?? 0) <= self::RARE_WORD_MAX_TITLES) {
                    return true;
                }
            }
        }

        return false;
    }
}
