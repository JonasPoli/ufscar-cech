<?php

declare(strict_types=1);

namespace App\Service\Thesaurus;

use App\Service\Journal\CapesTaxonomy;
use App\Service\Journal\JournalAreaThesaurus;

/**
 * Rótulo canônico de área (`EDUCAÇÃO / CIÊNCIAS HUMANAS`) para o texto de categoria que uma planilha ou lista traz na
 * coluna `area`, pelo tesauro de categorias de revistas ({@see JournalAreaThesaurus::resolve()}).
 *
 * Usado pelos importadores de periódicos: o texto bruto vai sempre para `qualis_journals.area_raw`, e `area` recebe este
 * rótulo quando o tesauro reconhece o texto e, senão, o próprio texto. O resultado de cada texto fica em memória, porque
 * as listas repetem a mesma categoria em milhares de linhas.
 */
class JournalAreaLabeler
{
    /** @var array<string, string> texto => rótulo canônico, ou '' quando o tesauro não reconhece o texto */
    private array $cache = [];

    public function __construct(
        private readonly JournalAreaThesaurus $thesaurus,
        private readonly CapesTaxonomy $taxonomy
    ) {
    }

    /**
     * Rótulo canônico da primeira área CAPES que o tesauro reconhece no texto (a primeira é a principal).
     *
     * @return string|null null quando o texto é vazio ou o tesauro não reconhece nenhuma área nele
     */
    public function labelFor(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (!isset($this->cache[$text])) {
            $slug = $this->thesaurus->resolve($text)['slugs'][0] ?? null;
            $this->cache[$text] = $slug !== null && $this->taxonomy->isValidSlug($slug) ? $this->taxonomy->areaLabel($slug) : '';
        }

        return $this->cache[$text] !== '' ? $this->cache[$text] : null;
    }

    /**
     * Valores de `area` e `area_raw` para o texto da coluna `area`: o texto bruto vai para `area_raw`; `area` recebe o
     * rótulo canônico quando o tesauro reconhece o texto e, senão, o próprio texto.
     *
     * Um texto que já é um rótulo canônico (o que a exportação do admin traz em `area` das linhas classificadas) não é dado
     * bruto: vai para `area` e nunca para `area_raw`, que guarda só o texto original e não recebe rótulo canônico.
     *
     * @return array{0: ?string, 1: ?string} [area, area_raw]; ambos null com o texto vazio
     */
    public function columnsFor(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [null, null];
        }

        if ($this->taxonomy->isCanonicalLabel($text)) {
            return [$text, null];
        }

        return [$this->labelFor($text) ?? $text, $text];
    }
}
