<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Service\Thesaurus\StringNormalizer;

/**
 * Grandes áreas do CNPq como aparecem em `knowledge_areas.major_area`.
 *
 * O Lattes grava o código (`CIENCIAS_HUMANAS`); alguns registros antigos trazem o nome por extenso
 * ("Ciências Agrárias"). O dado gravado não muda: este catálogo só traduz o código para o rótulo e devolve todas as
 * grafias que valem para um código, para o filtro achar as duas formas.
 */
final class MajorAreaCatalog
{
    public const LABELS = [
        'CIENCIAS_AGRARIAS' => 'Ciências Agrárias',
        'CIENCIAS_BIOLOGICAS' => 'Ciências Biológicas',
        'CIENCIAS_DA_SAUDE' => 'Ciências da Saúde',
        'CIENCIAS_EXATAS_E_DA_TERRA' => 'Ciências Exatas e da Terra',
        'CIENCIAS_HUMANAS' => 'Ciências Humanas',
        'CIENCIAS_SOCIAIS_APLICADAS' => 'Ciências Sociais Aplicadas',
        'ENGENHARIAS' => 'Engenharias',
        'LINGUISTICA_LETRAS_E_ARTES' => 'Linguística, Letras e Artes',
        'OUTROS' => 'Outros',
    ];

    /** Rótulos curtos para cabeçalhos de tabela. */
    public const SHORT = [
        'CIENCIAS_AGRARIAS' => 'Agrárias',
        'CIENCIAS_BIOLOGICAS' => 'Biológicas',
        'CIENCIAS_DA_SAUDE' => 'Saúde',
        'CIENCIAS_EXATAS_E_DA_TERRA' => 'Exatas',
        'CIENCIAS_HUMANAS' => 'Humanas',
        'CIENCIAS_SOCIAIS_APLICADAS' => 'Soc. Aplicadas',
        'ENGENHARIAS' => 'Engenharias',
        'LINGUISTICA_LETRAS_E_ARTES' => 'Letras e Artes',
        'OUTROS' => 'Outros',
    ];

    /** Slug de URL => código. */
    public static function codeFromSlug(string $slug): ?string
    {
        foreach (self::LABELS as $code => $label) {
            if (self::slug($code) === $slug) {
                return $code;
            }
        }

        return null;
    }

    public static function slug(string $code): string
    {
        return StringNormalizer::slugify(self::LABELS[$code] ?? $code);
    }

    public static function label(?string $raw): string
    {
        $code = self::normalizeCode((string)$raw);

        return $code !== '' ? self::LABELS[$code] : trim((string)$raw);
    }

    /**
     * Código canônico de uma grafia qualquer (código ou nome por extenso); vazio se não for uma grande área conhecida.
     */
    public static function normalizeCode(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $upper = strtoupper($raw);
        if (isset(self::LABELS[$upper])) {
            return $upper;
        }

        $normalized = StringNormalizer::normalizeString($raw, false);
        foreach (self::LABELS as $code => $label) {
            if (StringNormalizer::normalizeString($label, false) === $normalized
                || str_replace('_', ' ', strtolower($code)) === $normalized) {
                return $code;
            }
        }

        return '';
    }

    /**
     * Todas as grafias gravadas que correspondem ao código: o próprio código e o nome por extenso.
     *
     * @return list<string>
     */
    public static function storedValues(string $code): array
    {
        if (!isset(self::LABELS[$code])) {
            return [$code];
        }

        return [$code, self::LABELS[$code]];
    }
}
