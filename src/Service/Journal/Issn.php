<?php

declare(strict_types=1);

namespace App\Service\Journal;

/**
 * Utilitário de ISSN: extrai números de um texto livre, valida o dígito verificador, formata e normaliza.
 *
 * Forma normalizada (n8): 8 caracteres minúsculos, sem hífen (ex.: `0103863x`).
 * Forma exibida: `0103-863X`.
 */
final class Issn
{
    private const PATTERN = '/(\d{4})-?(\d{3}[\dXx])/';

    private function __construct()
    {
    }

    /**
     * Extrai os ISSNs de um texto, na forma normalizada, sem repetição e na ordem em que aparecem.
     *
     * @param bool $onlyValid Se true, descarta os números cujo dígito verificador não confere
     * @return list<string>
     */
    public static function extractAll(?string $raw, bool $onlyValid = true): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        preg_match_all(self::PATTERN, $raw, $matches);

        $found = [];
        foreach ($matches[0] as $match) {
            $n8 = strtolower(str_replace('-', '', $match));
            if ($onlyValid && !self::isValid($n8)) {
                continue;
            }
            $found[] = $n8;
        }

        return array_values(array_unique($found));
    }

    /**
     * Confere o dígito verificador de um ISSN na forma normalizada (8 caracteres).
     *
     * Soma dos 7 primeiros dígitos multiplicados por 8..2, resto da divisão por 11,
     * dígito = (11 - resto) mod 11, em que 10 é representado por `x`.
     */
    public static function isValid(string $n8): bool
    {
        if (!preg_match('/\A\d{7}[\dXx]\z/', $n8)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $n8[$i] * (8 - $i);
        }
        $digit = (11 - ($sum % 11)) % 11;
        $expected = $digit === 10 ? 'x' : (string) $digit;

        return $expected === strtolower($n8[7]);
    }

    /**
     * Formata a forma normalizada para exibição (`01026909` -> `0102-6909`, `0103863x` -> `0103-863X`).
     * Entrada que não tem 8 caracteres é devolvida sem alteração.
     */
    public static function format(string $n8): string
    {
        if (!preg_match('/\A(\d{4})(\d{3}[\dXx])\z/', $n8, $parts)) {
            return $n8;
        }

        return $parts[1] . '-' . strtoupper($parts[2]);
    }

    /**
     * Normalização frouxa: remove tudo que não é letra ou dígito e minusculiza, sem validar.
     * É o mesmo critério de `QualisJournal::normalizeIssnString`, mantido por compatibilidade com os dados gravados.
     */
    public static function normalizeLoose(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $norm = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $raw));

        return $norm !== '' ? $norm : null;
    }
}
