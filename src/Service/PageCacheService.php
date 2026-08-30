<?php

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class PageCacheService
{
    public const TTL_SECONDS = 30 * 86400; // 30 dias (2.592.000s)
    public const CACHE_SUBDIR = 'var/cache/public_pages';

    private Filesystem $filesystem;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(bool:PAGE_CACHE_ENABLED)%')]
        private readonly bool $pageCacheEnabled = true
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Retorna se a verificação e servimento do cache está ativada.
     * Controlado via variável de ambiente PAGE_CACHE_ENABLED.
     */
    public function isEnabled(): bool
    {
        return $this->pageCacheEnabled;
    }

    /**
     * Retorna se a requisição é elegível para ser salva ou lida do cache de páginas públicas.
     */
    public function isCacheableRequest(Request $request): bool
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return false;
        }

        $path = $request->getPathInfo();

        // Ignorar rotas do painel administrativo, autenticação, depuração e APIs de foto/assets
        $excludedPrefixes = [
            '/admin',
            '/_wdt',
            '/_profiler',
            '/_error',
            '/login',
            '/logout',
            '/photo',
        ];

        foreach ($excludedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Obtém o caminho completo para o arquivo de cache físico correspondente à requisição.
     */
    public function getCacheFilePath(Request $request): string
    {
        $uri = $request->getRequestUri();
        $hash = md5($uri);
        return sprintf('%s/%s/%s.html', rtrim($this->projectDir, '/'), self::CACHE_SUBDIR, $hash);
    }

    /**
     * Retorna se existe um arquivo de cache válido (existente e com idade <= 30 dias).
     */
    public function hasValidCache(Request $request): bool
    {
        $filePath = $this->getCacheFilePath($request);

        if (!$this->filesystem->exists($filePath)) {
            return false;
        }

        $mtime = @filemtime($filePath);
        if ($mtime === false) {
            return false;
        }

        $age = time() - $mtime;

        return $age <= self::TTL_SECONDS;
    }

    /**
     * Obtém a resposta HTTP a partir do arquivo físico de cache.
     */
    public function getCachedResponse(Request $request): ?Response
    {
        if (!$this->hasValidCache($request)) {
            return null;
        }

        $filePath = $this->getCacheFilePath($request);
        $content = @file_get_contents($filePath);

        if ($content === false) {
            return null;
        }

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Page-Cache' => 'HIT',
        ]);
    }

    /**
     * Salva a resposta HTML gerada no arquivo físico de cache.
     */
    public function saveCache(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() !== Response::HTTP_OK) {
            return false;
        }

        $contentType = $response->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return false;
        }

        $content = $response->getContent();
        if (empty($content)) {
            return false;
        }

        $filePath = $this->getCacheFilePath($request);
        $cacheDir = dirname($filePath);

        try {
            if (!$this->filesystem->exists($cacheDir)) {
                $this->filesystem->mkdir($cacheDir, 0755);
            }

            $this->filesystem->dumpFile($filePath, $content);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Limpa completamente o diretório de cache de páginas públicas.
     */
    public function clearCache(): bool
    {
        $cacheDir = sprintf('%s/%s', rtrim($this->projectDir, '/'), self::CACHE_SUBDIR);

        try {
            if ($this->filesystem->exists($cacheDir)) {
                $this->filesystem->remove($cacheDir);
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Retorna estatísticas sobre o estado atual do cache.
     *
     * @return array{fileCount: int, totalSizeBytes: int, cacheDir: string, exists: bool}
     */
    public function getStats(): array
    {
        $cacheDir = sprintf('%s/%s', rtrim($this->projectDir, '/'), self::CACHE_SUBDIR);
        $exists = $this->filesystem->exists($cacheDir);

        if (!$exists) {
            return [
                'fileCount' => 0,
                'totalSizeBytes' => 0,
                'cacheDir' => $cacheDir,
                'exists' => false,
            ];
        }

        $fileCount = 0;
        $totalSizeBytes = 0;

        $files = glob($cacheDir . '/*.html');
        if (is_array($files)) {
            $fileCount = count($files);
            foreach ($files as $file) {
                $totalSizeBytes += (int)@filesize($file);
            }
        }

        return [
            'fileCount' => $fileCount,
            'totalSizeBytes' => $totalSizeBytes,
            'cacheDir' => $cacheDir,
            'exists' => true,
        ];
    }
}
