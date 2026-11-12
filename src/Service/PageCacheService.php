<?php

namespace App\Service;

use App\Service\Research\ResearchFilterFactory;
use App\Support\PageCacheFastPath;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class PageCacheService
{
    public const TTL_SECONDS = PageCacheFastPath::TTL_SECONDS; // 30 dias (2.592.000s)
    public const CACHE_SUBDIR = PageCacheFastPath::CACHE_SUBDIR;

    /** Página maior que isto (HTML sem compactar) não é gravada: uma só não pode ocupar o espaço de milhares. */
    public const MAX_PAGE_BYTES = 16 * 1024 * 1024;

    /** A cada quantas gravações, em média, o descarte automático roda. */
    public const PRUNE_EVERY = 200;

    /**
     * Parâmetros de URL que as páginas públicas leem. Com qualquer outro, a página é servida sem passar pelo cache.
     * Ao criar um parâmetro novo numa página pública, acrescente-o aqui para que ela continue sendo cacheada.
     */
    public const KNOWN_QUERY_PARAMS = [
        // Filtro da pesquisa (ResearchFilter)
        'q', 'exata', 'departamento', 'dept', 'tema', 'grande_area', 'area', 'vinculo', 'tipo', 'de', 'ate',
        // Navegação e opções das páginas
        'pagina', 'page', 'ordem', 'modo', 'ligacoes', 'pares', 'tab', 'print', 'aba', 't', 'docente',
        // Catálogo de projetos (ProjectFilter)
        'situacao', 'natureza', 'ano_inicial', 'ano_final',
    ];

    private Filesystem $filesystem;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(bool:PAGE_CACHE_ENABLED)%')]
        private readonly bool $pageCacheEnabled = true,
        // Cálculos da pesquisa pública por filtro (panorama, rede): limpos junto com as páginas, pelo mesmo motivo
        #[Autowire(service: 'cache.research')]
        private readonly ?CacheItemPoolInterface $researchCache = null,
        // Teto do espaço em disco do cache de páginas, em MB. Passando dele, as páginas mais antigas são descartadas
        #[Autowire('%env(int:default:page_cache_default_max_mb:PAGE_CACHE_MAX_MB)%')]
        private readonly int $maxTotalMegabytes = 2048
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

        // Visitante autenticado: a página renderizada contém elementos exclusivos do
        // administrador (ex.: botão "Painel Admin"), que não podem ser servidos ao público.
        if ($this->hasSessionCookie($request)) {
            return false;
        }

        $path = $request->getPathInfo();

        // Ignorar painel administrativo, autenticação, depuração, APIs e a busca
        // (consulta livre geraria um arquivo de cache por termo pesquisado).
        $excludedPrefixes = [
            '/admin',
            '/_wdt',
            '/_profiler',
            '/_error',
            '/login',
            '/logout',
            '/photo',
            '/api',
            '/busca',
        ];

        foreach ($excludedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        // Texto livre em qualquer página (filtros do panorama, da rede, dos projetos…) geraria um arquivo por termo
        if (trim((string)$request->query->get('q', '')) !== '') {
            return false;
        }

        // Parâmetro que nenhuma página pública lê (`?a=1`, `?a=2`, marcadores de campanha…) geraria um arquivo por variação
        foreach (array_keys($request->query->all()) as $name) {
            if (!in_array((string)$name, self::KNOWN_QUERY_PARAMS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retorna se a resposta pode ser gravada em disco e reaproveitada por outros visitantes.
     */
    public function isCacheableResponse(Response $response): bool
    {
        if ($response->getStatusCode() !== Response::HTTP_OK) {
            return false;
        }

        // Uma resposta que cria cookies (sessão, flash, CSRF) é específica daquele visitante
        if (count($response->headers->getCookies()) > 0) {
            return false;
        }

        $contentType = $response->headers->get('Content-Type', '');

        return $contentType === '' || str_contains($contentType, 'text/html');
    }

    /**
     * Detecta a presença de um cookie de sessão, indicando visitante com sessão ativa.
     *
     * A verificação é feita pelos cookies porque o cache é consultado antes do firewall,
     * quando a sessão ainda não foi carregada.
     */
    private function hasSessionCookie(Request $request): bool
    {
        if ($request->hasPreviousSession()) {
            return true;
        }

        return PageCacheFastPath::hasSessionCookie($request->cookies->all());
    }

    /**
     * Obtém o caminho completo para o arquivo de cache físico correspondente à requisição.
     */
    public function getCacheFilePath(Request $request): string
    {
        return PageCacheFastPath::filePath(
            $this->projectDir,
            PageCacheFastPath::canonicalUri($request->getPathInfo(), $request->query->all())
        );
    }

    /**
     * Retorna se existe um arquivo de cache válido (existente e com idade <= 30 dias).
     */
    public function hasValidCache(Request $request): bool
    {
        $mtime = @filemtime($this->getCacheFilePath($request));

        return $mtime !== false && time() - $mtime <= self::TTL_SECONDS;
    }

    /**
     * Obtém a resposta HTTP a partir do arquivo físico de cache.
     *
     * Em produção quem responde é o `public/index.php`, antes do Symfony ({@see PageCacheFastPath::serve()}); este
     * caminho vale quando a requisição chega ao kernel assim mesmo, com os mesmos cabeçalhos.
     */
    public function getCachedResponse(Request $request): ?Response
    {
        $filePath = $this->getCacheFilePath($request);
        $mtime = @filemtime($filePath);
        if ($mtime === false || time() - $mtime > self::TTL_SECONDS) {
            return null;
        }

        $raw = @file_get_contents($filePath);
        $content = $raw === false ? false : @gzdecode($raw);
        if ($content === false) {
            return null;
        }

        $response = new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Page-Cache' => 'HIT',
        ]);
        $response->headers->set('ETag', PageCacheFastPath::etag($filePath, $mtime));
        $response->setLastModified((new \DateTimeImmutable())->setTimestamp($mtime));
        $response->headers->set('Cache-Control', 'no-cache');
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Salva a resposta HTML gerada no arquivo físico de cache, compactada (gzip).
     */
    public function saveCache(Request $request, Response $response): bool
    {
        if (!$this->isCacheableResponse($response)) {
            return false;
        }

        // A página avisou que a URL não é a canônica (filtro com valor descartado ou reescrito): não grava
        if ($request->attributes->getBoolean(ResearchFilterFactory::NO_PAGE_CACHE)) {
            return false;
        }

        $content = $response->getContent();
        if (empty($content) || strlen($content) > self::MAX_PAGE_BYTES) {
            return false;
        }

        $compressed = gzencode($content, 6);
        if ($compressed === false) {
            return false;
        }

        $filePath = $this->getCacheFilePath($request);
        $cacheDir = dirname($filePath);

        try {
            if (!$this->filesystem->exists($cacheDir)) {
                $this->filesystem->mkdir($cacheDir, 0755);
            }

            $this->filesystem->dumpFile($filePath, $compressed);
            $this->syncEnabledMarker();
        } catch (\Throwable) {
            return false;
        }

        if (random_int(1, self::PRUNE_EVERY) === 1) {
            $this->prune();
        }

        return true;
    }

    /**
     * Mantém o arquivo que autoriza o `public/index.php` a servir o cache: existe só com o cache ligado.
     */
    public function syncEnabledMarker(): void
    {
        $marker = PageCacheFastPath::cacheDir($this->projectDir) . '/' . PageCacheFastPath::ENABLED_MARKER;

        try {
            if ($this->pageCacheEnabled) {
                if (!is_file($marker) && is_dir(dirname($marker))) {
                    $this->filesystem->dumpFile($marker, '1');
                }
            } elseif (is_file($marker)) {
                $this->filesystem->remove($marker);
            }
        } catch (\Throwable) {
            // Sem o marcador o cache só deixa de ser servido antes do kernel
        }
    }

    /**
     * Descarte automático: apaga as páginas vencidas e, se o total passar do teto, as mais antigas até sobrar 80% dele.
     *
     * @return array{removed: int, remainingFiles: int, remainingBytes: int}
     */
    public function prune(): array
    {
        $files = [];
        $total = 0;
        $removed = 0;
        $now = time();

        foreach (glob(PageCacheFastPath::cacheDir($this->projectDir) . '/*' . PageCacheFastPath::FILE_SUFFIX) ?: [] as $file) {
            $mtime = @filemtime($file);
            $size = @filesize($file);
            if ($mtime === false || $size === false) {
                continue;
            }
            if ($now - $mtime > self::TTL_SECONDS) {
                $removed += @unlink($file) ? 1 : 0;
                continue;
            }
            $files[$file] = [$mtime, $size];
            $total += $size;
        }

        $limit = max(1, $this->maxTotalMegabytes) * 1024 * 1024;
        if ($total > $limit) {
            uasort($files, static fn(array $a, array $b) => $a[0] <=> $b[0]);
            $target = (int)($limit * 0.8);
            foreach ($files as $file => [, $size]) {
                if ($total <= $target) {
                    break;
                }
                if (@unlink($file)) {
                    $total -= $size;
                    $removed++;
                    unset($files[$file]);
                }
            }
        }

        return ['removed' => $removed, 'remainingFiles' => count($files), 'remainingBytes' => $total];
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
            $this->researchCache?->clear();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Retorna estatísticas sobre o estado atual do cache.
     *
     * @return array{fileCount: int, totalSizeBytes: int, cacheDir: string, exists: bool, maxTotalBytes: int}
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
                'maxTotalBytes' => $this->maxTotalMegabytes * 1024 * 1024,
            ];
        }

        $fileCount = 0;
        $totalSizeBytes = 0;

        $files = glob($cacheDir . '/*' . PageCacheFastPath::FILE_SUFFIX);
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
            'maxTotalBytes' => $this->maxTotalMegabytes * 1024 * 1024,
        ];
    }
}
