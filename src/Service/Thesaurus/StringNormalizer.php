<?php

namespace App\Service\Thesaurus;

/**
 * Classe utilitária responsável pela normalização canônica de strings e geração de slugs amigáveis.
 *
 * Utiliza o transliterador Unicode nativo do PHP (Transliterator) com fallback para iconv,
 * removendo acentos, diacríticos e pontuações especiais para indexação no banco de dados.
 */
class StringNormalizer
{
    /** Instância estática reutilizável do transliterador Unicode */
    private static ?\Transliterator $transliterator = null;
    
    /** Flag para controle de inicialização do transliterador */
    private static bool $transliteratorInitialized = false;

    /**
     * Normaliza uma string removendo diacríticos/acentos e caracteres especiais.
     *
     * @param string|null $str String de entrada a ser normalizada
     * @param bool $uppercase Se true, converte o resultado para MAIÚSCULAS; se false, para minúsculas
     * @return string String limpa e normalizada
     */
    public static function normalizeString(?string $str, bool $uppercase = true): string
    {
        if ($str === null || trim($str) === '') {
            return '';
        }

        $str = trim($str);
        
        // Normaliza acentos e marcas de não-espaçamento via Transliterator
        if (!self::$transliteratorInitialized) {
            self::$transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            self::$transliteratorInitialized = true;
        }

        if (self::$transliterator) {
            $str = self::$transliterator->transliterate($str);
        } else {
            $str = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        }

        // Substitui pontuações e símbolos por espaços
        $str = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $str);
        $str = preg_replace('/\s+/', ' ', $str);
        $str = trim($str);

        return $uppercase ? mb_strtoupper($str, 'UTF-8') : mb_strtolower($str, 'UTF-8');
    }

    /**
     * Converte um texto arbitrário em um slug amigável para URLs (ex: 'João da Silva' -> 'joao-da-silva').
     *
     * @param string $text Texto original
     * @return string Slug limpo formatado em letras minúsculas separadas por hífen
     */
    public static function slugify(string $text): string
    {
        $text = self::normalizeString($text, false);
        $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
        $text = trim($text, '-');
        return $text ?: 'item-' . substr(md5(uniqid()), 0, 8);
    }
}
