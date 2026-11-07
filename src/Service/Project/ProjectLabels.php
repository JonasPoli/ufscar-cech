<?php

declare(strict_types=1);

namespace App\Service\Project;

/**
 * Rótulos em português dos códigos que o Lattes grava nos projetos. Os códigos são os valores reais de
 * `research_projects.status`/`nature` e de `research_project_funders.nature`; um código desconhecido aparece como veio.
 */
final class ProjectLabels
{
    public const STATUS = [
        'EM_ANDAMENTO' => 'Em andamento',
        'CONCLUIDO' => 'Concluído',
        'DESATIVADO' => 'Desativado',
    ];

    public const NATURE = [
        'PESQUISA' => 'Pesquisa',
        'EXTENSAO' => 'Extensão',
        'ENSINO' => 'Ensino',
        'DESENVOLVIMENTO' => 'Desenvolvimento',
        'OUTRA' => 'Outra',
    ];

    public const FUNDER_NATURE = [
        'AUXILIO_FINANCEIRO' => 'Auxílio financeiro',
        'BOLSA' => 'Bolsa',
        'COOPERACAO' => 'Cooperação',
        'REMUNERACAO' => 'Remuneração',
        'OUTRO' => 'Outro',
        'NAO_INFORMADO' => null,
    ];

    public static function status(?string $code): string
    {
        return self::STATUS[(string)$code] ?? self::humanize($code);
    }

    public static function nature(?string $code): string
    {
        return self::NATURE[(string)$code] ?? self::humanize($code);
    }

    /** Natureza do financiamento; null quando não informada */
    public static function funderNature(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return array_key_exists($code, self::FUNDER_NATURE) ? self::FUNDER_NATURE[$code] : self::humanize($code);
    }

    /** "2019 – atual", "2015 – 2018", "2020" ou "Ano não informado" */
    public static function period(?int $startYear, ?int $endYear, ?string $status): string
    {
        if ($startYear === null) {
            return $endYear !== null ? 'Até ' . $endYear : 'Ano não informado';
        }
        if ($endYear === null) {
            return $status === 'EM_ANDAMENTO' ? $startYear . ' – atual' : (string)$startYear;
        }

        return $endYear === $startYear ? (string)$startYear : $startYear . ' – ' . $endYear;
    }

    private static function humanize(?string $code): string
    {
        $text = trim(str_replace('_', ' ', (string)$code));

        return $text === '' ? 'Não informado' : mb_strtoupper(mb_substr($text, 0, 1)) . mb_strtolower(mb_substr($text, 1));
    }
}
