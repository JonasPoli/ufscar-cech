<?php

namespace App\Tests\Service;

use App\Service\PageCacheService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PageCacheServiceTest extends TestCase
{
    private string $tempDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/page_cache_test_' . uniqid('', true);
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->tempDir)) {
            $this->filesystem->remove($this->tempDir);
        }
    }

    public function testIsEnabledBasedOnPageCacheEnabledParam(): void
    {
        $enabledService = new PageCacheService($this->tempDir, true);
        $this->assertTrue($enabledService->isEnabled());

        $disabledService = new PageCacheService($this->tempDir, false);
        $this->assertFalse($disabledService->isEnabled());
    }

    public function testIsCacheableRequestFilter(): void
    {
        $service = new PageCacheService($this->tempDir, true);

        // Requisição pública GET -> elegível
        $req1 = Request::create('/', 'GET');
        $this->assertTrue($service->isCacheableRequest($req1));

        // Requisição pública HEAD -> elegível
        $req2 = Request::create('/professores', 'HEAD');
        $this->assertTrue($service->isCacheableRequest($req2));

        // Requisição POST -> não elegível
        $req3 = Request::create('/', 'POST');
        $this->assertFalse($service->isCacheableRequest($req3));

        // Rotas administrativas/login -> não elegível
        $req4 = Request::create('/admin', 'GET');
        $this->assertFalse($service->isCacheableRequest($req4));

        $req5 = Request::create('/login', 'GET');
        $this->assertFalse($service->isCacheableRequest($req5));

        $req6 = Request::create('/admin/cache', 'GET');
        $this->assertFalse($service->isCacheableRequest($req6));
    }

    public function testSaveAndRetrieveCacheInProd(): void
    {
        $service = new PageCacheService($this->tempDir, true);
        $request = Request::create('/professores?departamento=dep1', 'GET');
        $htmlContent = '<html><body><h1>Lista de Professores</h1></body></html>';
        $response = new Response($htmlContent, 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        // Antes de salvar, não tem cache válido
        $this->assertFalse($service->hasValidCache($request));
        $this->assertNull($service->getCachedResponse($request));

        // Salva no cache
        $saved = $service->saveCache($request, $response);
        $this->assertTrue($saved);

        // Após salvar, tem cache válido
        $this->assertTrue($service->hasValidCache($request));

        $cachedResponse = $service->getCachedResponse($request);
        $this->assertNotNull($cachedResponse);
        $this->assertEquals(200, $cachedResponse->getStatusCode());
        $this->assertEquals($htmlContent, $cachedResponse->getContent());
        $this->assertEquals('HIT', $cachedResponse->headers->get('X-Page-Cache'));
    }

    public function testCacheExpirationAfter30Days(): void
    {
        $service = new PageCacheService($this->tempDir, true);
        $request = Request::create('/indicadores', 'GET');
        $response = new Response('<html>Indicadores</html>', 200, ['Content-Type' => 'text/html']);

        $service->saveCache($request, $response);
        $filePath = $service->getCacheFilePath($request);

        $this->assertTrue($service->hasValidCache($request));

        // Alterar mtime para 31 dias atrás (31 * 86400 segundos no passado)
        $pastTime = time() - (31 * 86400);
        touch($filePath, $pastTime);

        // Deve expirar e retornar falso
        $this->assertFalse($service->hasValidCache($request));
        $this->assertNull($service->getCachedResponse($request));
    }

    public function testClearCacheRemovesFolderCompletely(): void
    {
        $service = new PageCacheService($this->tempDir, true);
        $request = Request::create('/', 'GET');
        $response = new Response('<html>Home Page</html>', 200, ['Content-Type' => 'text/html']);

        $service->saveCache($request, $response);

        $cacheDir = $this->tempDir . '/' . PageCacheService::CACHE_SUBDIR;
        $this->assertTrue($this->filesystem->exists($cacheDir));

        // Limpa o cache
        $cleared = $service->clearCache();
        $this->assertTrue($cleared);

        // A pasta var/cache/public_pages foi removida por completo
        $this->assertFalse($this->filesystem->exists($cacheDir));
    }
}
