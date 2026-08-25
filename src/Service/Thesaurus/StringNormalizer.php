<?php

namespace App\Service\Thesaurus;

class StringNormalizer
{
    private static ?\Transliterator $transliterator = null;
    private static bool $transliteratorInitialized = false;

    public static function normalizeString(?string $str, bool $uppercase = true): string
    {
        if ($str === null || trim($str) === '') {
            return '';
        }

        $str = trim($str);
        
        // Normalize accents / diacritics using reusable Transliterator
        if (!self::$transliteratorInitialized) {
            self::$transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            self::$transliteratorInitialized = true;
        }

        if (self::$transliterator) {
            $str = self::$transliterator->transliterate($str);
        } else {
            $str = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        }

        // Replace multiple whitespace/punctuation
        $str = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $str);
        $str = preg_replace('/\s+/', ' ', $str);
        $str = trim($str);

        return $uppercase ? mb_strtoupper($str, 'UTF-8') : mb_strtolower($str, 'UTF-8');
    }

    public static function slugify(string $text): string
    {
        $text = self::normalizeString($text, false);
        $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
        $text = trim($text, '-');
        return $text ?: 'item-' . substr(md5(uniqid()), 0, 8);
    }
}
