<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Service\Thesaurus\StringNormalizer;
use App\Support\LattesText;

/**
 * Chave que une o mesmo projeto declarado em vários currículos (`research_projects.project_group_key`): sha1 do nome
 * normalizado (sem acentos, pontuação, caixa e espaços repetidos; entidades HTML do Lattes decodificadas antes) e do
 * ano de início. Dois docentes que escrevem "Educação e Sociedade" e "EDUCACAO E SOCIEDADE." com início em 2019 caem
 * no mesmo grupo; com anos de início diferentes, não.
 *
 * O nome bruto fica como veio em `name`; a chave é coluna calculada (regra de preservação do AGENTS.md).
 */
final class ProjectGroupKey
{
    public static function compute(?string $rawName, ?int $startYear): ?string
    {
        $normalized = StringNormalizer::normalizeString(LattesText::display($rawName), true);
        if ($normalized === '') {
            return null;
        }

        return sha1($normalized . '|' . ($startYear !== null && $startYear > 0 ? (string)$startYear : ''));
    }
}
