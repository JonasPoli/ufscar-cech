<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Texto do Lattes para exibir. O banco guarda o dado como veio (regra de preservação do AGENTS.md), e o Lattes grava muitos
 * textos com entidades HTML: "Educa&ccedil;&atilde;o", "&quot;Escola&quot;". Sem decodificar, a tela mostraria esses códigos.
 */
final class LattesText
{
    public static function display(?string $text): ?string
    {
        return $text === null || !str_contains($text, '&') ? $text : html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
