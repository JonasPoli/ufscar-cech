<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parte do cache de páginas que roda antes do Symfony, em `public/index.php`.
 *
 * Não depende de nenhuma outra classe: é carregada com `require` antes do autoload, para que uma página em cache seja
 * devolvida sem dar partida no kernel. O {@see \App\Service\PageCacheService} usa as mesmas regras (endereço canônico,
 * nome do arquivo, cookie de sessão), de modo que só existe arquivo para requisições que ele considerou elegíveis.
 */
final class PageCacheFastPath
{
    public const CACHE_SUBDIR = 'var/cache/public_pages';
    public const FILE_SUFFIX = '.html.gz';
    public const TTL_SECONDS = 30 * 86400;

    /** Arquivo que marca o cache como ligado. Sem ele, nada é servido por aqui (PAGE_CACHE_ENABLED desligado). */
    public const ENABLED_MARKER = '.enabled';

    public const THROTTLE_SUBDIR = 'var/cache/bot_throttle';

    /** Páginas fora do cache que um mesmo robô pode pedir por minuto; acima disso recebe 429. */
    public const BOT_REQUESTS_PER_MINUTE = 30;

    /**
     * Endereço que identifica a página no cache.
     *
     * Os parâmetros são ordenados, e no perfil do docente são descartados: fora da aba da busca (`aba=busca`), o
     * servidor não lê nenhum deles (`?tema=` só preenche o filtro pelo JavaScript). Sem isso, cada tema de cada
     * docente gravava outra cópia do mesmo HTML.
     *
     * @param array<array-key, mixed> $query
     */
    public static function canonicalUri(string $path, array $query): string
    {
        if (preg_match('#^/professor/[^/]+/?$#', $path) === 1 && ($query['aba'] ?? null) !== 'busca') {
            $query = [];
        }

        if ($query === []) {
            return $path;
        }

        ksort($query);

        return $path . '?' . http_build_query($query);
    }

    public static function cacheDir(string $projectDir): string
    {
        return rtrim($projectDir, '/') . '/' . self::CACHE_SUBDIR;
    }

    public static function filePath(string $projectDir, string $canonicalUri): string
    {
        return self::cacheDir($projectDir) . '/' . md5($canonicalUri) . self::FILE_SUFFIX;
    }

    /**
     * Visitante com sessão (administrador logado) nunca recebe nem grava página em cache.
     *
     * @param array<array-key, mixed> $cookies
     */
    public static function hasSessionCookie(array $cookies): bool
    {
        foreach (array_keys($cookies) as $name) {
            if (str_contains(strtoupper((string)$name), 'SESS')) {
                return true;
            }
        }

        return false;
    }

    public static function etag(string $filePath, int $mtime): string
    {
        return '"' . md5(basename($filePath) . ':' . $mtime) . '"';
    }

    /**
     * Devolve a página em cache, se houver, e informa se respondeu.
     *
     * @param array<string, mixed>    $server  $_SERVER
     * @param array<array-key, mixed> $query   $_GET
     * @param array<array-key, mixed> $cookies $_COOKIE
     */
    public static function serve(string $projectDir, array $server, array $query, array $cookies): bool
    {
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        if (self::hasSessionCookie($cookies)) {
            return false;
        }

        if (!is_file(self::cacheDir($projectDir) . '/' . self::ENABLED_MARKER)) {
            return false;
        }

        $path = self::requestPath($server);
        if ($path === null) {
            return false;
        }

        $file = self::filePath($projectDir, self::canonicalUri($path, $query));
        $mtime = @filemtime($file);
        if ($mtime === false || time() - $mtime > self::TTL_SECONDS) {
            return false;
        }

        $etag = self::etag($file, $mtime);
        header('X-Page-Cache: HIT');
        header('Cache-Control: no-cache');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('Vary: Accept-Encoding');

        if (trim((string)($server['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);

            return true;
        }

        header('Content-Type: text/html; charset=UTF-8');

        if (str_contains(strtolower((string)($server['HTTP_ACCEPT_ENCODING'] ?? '')), 'gzip')) {
            $size = @filesize($file);
            if ($size === false) {
                return false;
            }
            header('Content-Encoding: gzip');
            header('Content-Length: ' . $size);
            if ($method !== 'HEAD') {
                readfile($file);
            }

            return true;
        }

        $raw = @file_get_contents($file);
        $content = $raw === false ? false : @gzdecode($raw);
        if ($content === false) {
            return false;
        }
        header('Content-Length: ' . strlen($content));
        if ($method !== 'HEAD') {
            echo $content;
        }

        return true;
    }

    /**
     * Freio para robôs nas páginas que não estão em cache: cada robô pode gerar até BOT_REQUESTS_PER_MINUTE páginas
     * por minuto. Acima disso recebe 429 com `Retry-After`, sem dar partida no Symfony. Visitantes comuns, páginas em
     * cache, `robots.txt` e `sitemap.xml` não passam por aqui.
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function throttleBot(string $projectDir, array $server, ?int $now = null): bool
    {
        $family = self::botFamily((string)($server['HTTP_USER_AGENT'] ?? ''));
        if ($family === null) {
            return false;
        }

        $path = self::requestPath($server) ?? '';
        if ($path === '/robots.txt' || $path === '/sitemap.xml') {
            return false;
        }

        $dir = rtrim($projectDir, '/') . '/' . self::THROTTLE_SUBDIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $minute = intdiv($now ?? time(), 60);
        $file = $dir . '/' . $family . '-' . $minute;
        if (@file_put_contents($file, '1', FILE_APPEND | LOCK_EX) === false) {
            return false;
        }
        clearstatcache(true, $file);
        $count = (int)@filesize($file);

        if ($count === 1) {
            // Primeiro pedido do minuto: descarta os contadores antigos deste robô
            foreach (glob($dir . '/' . $family . '-*') ?: [] as $old) {
                if ($old !== $file) {
                    @unlink($old);
                }
            }
        }

        return $count > self::BOT_REQUESTS_PER_MINUTE;
    }

    public static function sendTooManyRequests(): void
    {
        http_response_code(429);
        header('Retry-After: 60');
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo "Muitas requisições. Tente novamente em um minuto.\n";
    }

    /** Nome do robô no User-Agent ("perplexitybot", "amazonbot"…), ou null para um navegador comum. */
    public static function botFamily(string $userAgent): ?string
    {
        if (preg_match('/[a-z0-9]*(?:bot|crawler|spider|slurp|scrapy)[a-z0-9]*/i', $userAgent, $m) !== 1) {
            return null;
        }

        $family = preg_replace('/[^a-z0-9]/', '', strtolower($m[0]));

        return $family === '' ? null : substr($family, 0, 40);
    }

    /** @param array<string, mixed> $server */
    private static function requestPath(array $server): ?string
    {
        $uri = (string)($server['REQUEST_URI'] ?? '');
        $path = strtok($uri, '?');

        return ($path === false || $path === '' || $path[0] !== '/') ? null : $path;
    }
}
