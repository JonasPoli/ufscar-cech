<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Service\Thesaurus\StringNormalizer;

/**
 * Nome exibido de uma família de periódicos (docs/evolutions/periodicos-por-base.md, A4 passo 6).
 *
 * O título de uma linha do cadastro pode estar errado (REVISTA BRASILEIRA DE HISTÓRIA & CIÊNCIAS SOCIAIS com o ISSN
 * da Revista Brasileira de Ciências Sociais) ou sem acento (Scopus e WoS). Por isso o nome exibido vem, de preferência,
 * das fontes externas casadas por ISSN, em português primeiro, e só no fim das grafias do Lattes e do título da linha.
 */
final class DisplayTitle
{
    /** Valor de `display_title_source` quando o nome vem da grafia mais frequente do `journal_name` do Lattes. */
    public const SOURCE_LATTES = 'lattes';

    /** Valor de `display_title_source` quando o nome vem do título da linha canônica. */
    public const SOURCE_TITLE = 'title';

    /**
     * Fontes do artefato de evidências, na ordem de prioridade. Fontes em português primeiro: Scopus e WoS perdem
     * acentos ("Paideia", "Revista Brasileira de Ciencia Avicola").
     *
     * @var list<string>
     */
    public const SOURCE_ORDER = ['scielo', 'doaj', 'latindex', 'scopus', 'wos', 'openalex', 'pubmed'];

    /** A grafia do Lattes só vale com pelo menos esta quantidade de ocorrências. */
    public const MIN_LATTES_OCCURRENCES = 2;

    /**
     * Conectivos que ficam em minúsculas na caixa de título (exceto na primeira palavra).
     *
     * @var list<string>
     */
    private const CONNECTIVES = ['de', 'da', 'do', 'das', 'dos', 'e', 'em', 'a', 'o', 'as', 'os', 'para', 'com', 'of', 'the', 'and', 'y', 'la', 'el', 'del', 'di'];

    /**
     * Algarismos romanos que `mb_convert_case` deixaria como "Iii" (lista fechada: "Mix" e "Civil" não entram).
     *
     * @var list<string>
     */
    private const ROMAN_NUMERALS = ['ii', 'iii', 'iv', 'vi', 'vii', 'viii', 'ix', 'xi', 'xii', 'xiii', 'xiv', 'xv', 'xvi', 'xvii', 'xviii', 'xix', 'xx', 'xxi'];

    private function __construct()
    {
    }

    /**
     * Caixa de título: `mb_convert_case(mb_strtolower($s), MB_CASE_TITLE)` com os conectivos em minúsculas, exceto na
     * primeira palavra ("REVISTA BRASILEIRA DE EDUCAÇÃO ESPECIAL" => "Revista Brasileira de Educação Especial").
     * Algarismos romanos de uma lista fechada ficam em maiúsculas.
     */
    public static function titleCase(string $s): string
    {
        $s = self::collapse($s);
        if ($s === '') {
            return '';
        }

        $title = mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        if (preg_match_all('/[\p{L}\p{M}]+/u', $title, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $title;
        }

        $result = '';
        $position = 0;
        foreach ($matches[0] as $index => [$word, $offset]) {
            $lower = mb_strtolower($word, 'UTF-8');

            $replacement = null;
            if ($index > 0 && in_array($lower, self::CONNECTIVES, true)) {
                $replacement = $lower;
            } elseif (in_array($lower, self::ROMAN_NUMERALS, true)) {
                $replacement = strtoupper($lower);
            }

            if ($replacement !== null) {
                // Os dois lados são ASCII: o tamanho em bytes não muda, mas o corte usa o tamanho da palavra original
                $result .= substr($title, $position, $offset - $position) . $replacement;
                $position = $offset + strlen($word);
            }
        }

        return $result . substr($title, $position);
    }

    /**
     * Escolhe o nome exibido, na ordem de A4 passo 6: scielo, doaj, latindex, scopus, wos, openalex, pubmed, depois a
     * grafia do Lattes (mínimo 2 ocorrências) e, por fim, o título da linha canônica.
     *
     * A caixa de título só é aplicada ao nome que não tem caixa própria (tudo em maiúsculas, como o WoS, o Qualis e muitas
     * grafias do Lattes, ou tudo em minúsculas); um nome que já mistura as duas ("RAE-Revista de Administração",
     * "Journal of Education for Teaching") é mantido como está, para não estragar siglas nem preposições que a lista de
     * conectivos não cobre.
     *
     * @param array<string, string|list<string>|null> $candidatesBySource títulos achados por fonte (`scielo`, `doaj`...);
     *                                                                    numa lista vale o primeiro não vazio
     * @param array<array-key, int|string> $lattesNames grafias de `journal_name` dos artigos da família: mapa
     *                                                  grafia => ocorrências ou lista de grafias (uma por artigo)
     * @param list<string> $otherSpellings títulos das demais linhas da família, usados só para recuperar acentos
     * @return array{title: string, source: string} título vazio só quando nem o título da linha existe
     */
    public static function choose(array $candidatesBySource, array $lattesNames, string $rowTitle, array $otherSpellings = []): array
    {
        $spellings = self::allSpellings($candidatesBySource, $lattesNames, $rowTitle, $otherSpellings);

        foreach (self::SOURCE_ORDER as $source) {
            $title = self::firstUsable($candidatesBySource[$source] ?? null);
            if ($title !== null) {
                return ['title' => self::withOwnCase(self::restoreAccents($title, $spellings)), 'source' => $source];
            }
        }

        $lattes = self::mostFrequentSpelling($lattesNames);
        if ($lattes !== null) {
            return ['title' => self::withOwnCase(self::restoreAccents($lattes, $spellings)), 'source' => self::SOURCE_LATTES];
        }

        return ['title' => self::withOwnCase(self::restoreAccents(self::clean($rowTitle), $spellings)), 'source' => self::SOURCE_TITLE];
    }

    /**
     * Scopus e WoS gravam títulos sem acento ("Ciencia e Cultura"). Se outra grafia da mesma revista difere só por acentos,
     * caixa ou pontuação ("CIÊNCIA E CULTURA"), o nome exibido fica com a acentuada.
     *
     * @param list<string> $spellings
     */
    private static function restoreAccents(string $title, array $spellings): string
    {
        if ($title === '' || preg_match('/[^\x00-\x7F]/', $title)) {
            return $title;
        }

        $key = StringNormalizer::normalizeString($title, false);
        $best = $title;
        $bestCount = 0;
        foreach ($spellings as $spelling) {
            if (StringNormalizer::normalizeString($spelling, false) !== $key) {
                continue;
            }
            $count = (int) preg_match_all('/[^\x00-\x7F]/u', $spelling);
            if ($count > $bestCount) {
                $best = $spelling;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @param array<string, string|list<string>|null> $candidatesBySource
     * @param array<array-key, int|string> $lattesNames
     * @param list<string> $otherSpellings
     * @return list<string>
     */
    private static function allSpellings(array $candidatesBySource, array $lattesNames, string $rowTitle, array $otherSpellings): array
    {
        $spellings = [$rowTitle, ...$otherSpellings];
        foreach ($candidatesBySource as $candidates) {
            foreach ((array) $candidates as $candidate) {
                if (is_string($candidate)) {
                    $spellings[] = $candidate;
                }
            }
        }
        foreach ($lattesNames as $key => $value) {
            $spellings[] = is_string($key) ? $key : (string) $value;
        }

        return array_values(array_unique(array_filter(array_map([self::class, 'clean'], $spellings), static fn (string $s): bool => $s !== '')));
    }

    /**
     * @param string|list<string>|null $candidates
     */
    private static function firstUsable(string|array|null $candidates): ?string
    {
        if ($candidates === null) {
            return null;
        }

        foreach ((array) $candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = self::clean($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Grafia mais frequente com pelo menos MIN_LATTES_OCCURRENCES ocorrências. Empate: a que tem mais letras
     * acentuadas (o acento some na digitação, quase nunca aparece sobrando) e, persistindo, a menor em ordem de bytes.
     *
     * @param array<array-key, int|string> $names
     */
    private static function mostFrequentSpelling(array $names): ?string
    {
        $counts = [];
        foreach ($names as $key => $value) {
            if (is_string($value)) {
                $name = $value;
                $times = 1;
            } elseif (is_int($value)) {
                $name = (string) $key;
                $times = $value;
            } else {
                continue;
            }

            $name = self::clean($name);
            if ($name === '' || $times < 1) {
                continue;
            }
            $counts[$name] = ($counts[$name] ?? 0) + $times;
        }

        $best = null;
        $bestCount = 0;
        $bestAccents = 0;
        foreach ($counts as $name => $count) {
            $name = (string) $name;
            if ($count < self::MIN_LATTES_OCCURRENCES) {
                continue;
            }

            $accents = (int) preg_match_all('/[^\x00-\x7F]/u', $name);
            $better = $best === null
                || $count > $bestCount
                || ($count === $bestCount && $accents > $bestAccents)
                || ($count === $bestCount && $accents === $bestAccents && strcmp($name, $best) < 0);
            if ($better) {
                $best = $name;
                $bestCount = $count;
                $bestAccents = $accents;
            }
        }

        return $best;
    }

    /** Mantém o texto que mistura maiúsculas e minúsculas (já traz a caixa que o autor quis); o resto vai para a caixa de título. */
    private static function withOwnCase(string $s): string
    {
        $mixed = preg_match('/\p{Lu}/u', $s) === 1 && preg_match('/\p{Ll}/u', $s) === 1;

        return $mixed ? $s : self::titleCase($s);
    }

    /** Decodifica entidades HTML (&amp;) e colapsa espaços. */
    private static function clean(string $s): string
    {
        return self::collapse(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function collapse(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }
}
