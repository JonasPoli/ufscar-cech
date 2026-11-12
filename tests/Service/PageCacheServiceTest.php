<?php

namespace App\Tests\Service;

use App\Service\PageCacheService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Cookie;
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

        // Endpoints de API e busca livre -> não elegíveis
        $this->assertFalse($service->isCacheableRequest(Request::create('/api/temas/autocomplete?q=educacao', 'GET')));
        $this->assertFalse($service->isCacheableRequest(Request::create('/busca?q=educacao', 'GET')));

        // Texto livre em qualquer página filtrável também não gera arquivo; os demais filtros, sim
        $this->assertFalse($service->isCacheableRequest(Request::create('/coautorias?q=leitura', 'GET')));
        $this->assertFalse($service->isCacheableRequest(Request::create('/indicadores?q=leitura&departamento=PS', 'GET')));
        $this->assertTrue($service->isCacheableRequest(Request::create('/coautorias?departamento=PS', 'GET')));
        $this->assertTrue($service->isCacheableRequest(Request::create('/coautorias?q=', 'GET')));
    }

    public function testRequestWithSessionCookieIsNotCacheable(): void
    {
        $service = new PageCacheService($this->tempDir, true);

        // Visitante logado no painel: a página traz elementos exclusivos do administrador
        $request = Request::create('/', 'GET', [], ['PHPSESSID' => 'abc123']);

        $this->assertFalse($service->isCacheableRequest($request));
    }

    public function testResponseWithCookieIsNotSaved(): void
    {
        $service = new PageCacheService($this->tempDir, true);
        $request = Request::create('/departamentos', 'GET');

        $response = new Response('<html>Departamentos</html>', 200, ['Content-Type' => 'text/html']);
        $response->headers->setCookie(Cookie::create('PHPSESSID', 'abc123'));

        $this->assertFalse($service->isCacheableResponse($response));
        $this->assertFalse($service->saveCache($request, $response));
        $this->assertFalse($service->hasValidCache($request));
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

    public function testUnknownQueryParameterIsNotCacheable(): void
    {
        $service = new PageCacheService($this->tempDir, true);

        $this->assertTrue($service->isCacheableRequest(Request::create('/coautorias?departamento=ED&ligacoes=250', 'GET')));
        // Parâmetro que nenhuma página lê geraria um arquivo por valor
        $this->assertFalse($service->isCacheableRequest(Request::create('/professor/fulano?a=1', 'GET')));
        $this->assertFalse($service->isCacheableRequest(Request::create('/?utm_source=boletim', 'GET')));
    }

    public function testPageMarkedAsNonCanonicalIsNotSaved(): void
    {
        $service = new PageCacheService($this->tempDir, true);
        $request = Request::create('/coautorias?area=inventada', 'GET');
        $request->attributes->set(\App\Service\Research\ResearchFilterFactory::NO_PAGE_CACHE, true);

        $this->assertFalse($service->saveCache($request, new Response('<html></html>', 200, ['Content-Type' => 'text/html'])));
        $this->assertFalse($service->hasValidCache($request));
    }

    public function testPagesAreStoredCompressed(): void
    {
        $service = new PageCacheService($this->tempDir, true);
        $request = Request::create('/indicadores', 'GET');
        $html = '<html>' . str_repeat('<p>Indicadores do CECH</p>', 500) . '</html>';

        $this->assertTrue($service->saveCache($request, new Response($html, 200, ['Content-Type' => 'text/html'])));

        $file = $service->getCacheFilePath($request);
        $this->assertStringEndsWith('.html.gz', $file);
        $this->assertLessThan(strlen($html) / 5, filesize($file));
        $this->assertSame($html, gzdecode((string)file_get_contents($file)));
    }

    public function testProfileSharesOneFileForAnyTopic(): void
    {
        $service = new PageCacheService($this->tempDir, true);

        $plain = $service->getCacheFilePath(Request::create('/professor/fulano', 'GET'));
        $this->assertSame($plain, $service->getCacheFilePath(Request::create('/professor/fulano?tema=astronomia', 'GET')));
        $this->assertSame($plain, $service->getCacheFilePath(Request::create('/professor/fulano?tema=ensino', 'GET')));

        // Vindo da busca, a página traz os resultados daquele filtro: arquivo próprio
        $this->assertNotSame($plain, $service->getCacheFilePath(Request::create('/professor/fulano?aba=busca&tema=ensino', 'GET')));
        // Fora do perfil, o parâmetro muda a página
        $this->assertNotSame(
            $service->getCacheFilePath(Request::create('/coautorias?tema=ensino', 'GET')),
            $service->getCacheFilePath(Request::create('/coautorias?tema=astronomia', 'GET'))
        );
    }

    public function testParameterOrderDoesNotCreateAnotherFile(): void
    {
        $service = new PageCacheService($this->tempDir, true);

        $this->assertSame(
            $service->getCacheFilePath(Request::create('/projetos?situacao=CONCLUIDO&pagina=2', 'GET')),
            $service->getCacheFilePath(Request::create('/projetos?pagina=2&situacao=CONCLUIDO', 'GET'))
        );
    }

    public function testHugePageIsNotSaved(): void
    {
        $service = new PageCacheService($this->tempDir, true);
        $request = Request::create('/indicadores', 'GET');
        $response = new Response(str_repeat('a', PageCacheService::MAX_PAGE_BYTES + 1), 200, ['Content-Type' => 'text/html']);

        $this->assertFalse($service->saveCache($request, $response));
        $this->assertFalse($service->hasValidCache($request));
    }

    public function testPruneRemovesExpiredAndOldestAboveLimit(): void
    {
        // Teto de 1 MB; cada página aleatória de ~300 KB quase não compacta
        $service = new PageCacheService($this->tempDir, true, null, 1);
        $paths = [];
        foreach (range(1, 5) as $i) {
            $request = Request::create('/explorar/assunto/tema-' . $i, 'GET');
            $service->saveCache($request, new Response(base64_encode(random_bytes(300 * 1024)), 200, ['Content-Type' => 'text/html']));
            $paths[$i] = $service->getCacheFilePath($request);
            touch($paths[$i], time() - (10 - $i) * 3600); // a página 1 é a mais antiga
        }
        touch($paths[5], time() - 31 * 86400); // vencida

        $result = $service->prune();

        $this->assertFileDoesNotExist($paths[5]);
        $this->assertFileDoesNotExist($paths[1]);
        $this->assertFileExists($paths[4]);
        $this->assertLessThanOrEqual(1024 * 1024, $result['remainingBytes']);
        $this->assertGreaterThanOrEqual(2, $result['removed']);
    }

    public function testCachedResponseCarriesValidators(): void
    {
        $service = new PageCacheService($this->tempDir, true);
        $request = Request::create('/sobre', 'GET');
        $service->saveCache($request, new Response('<html>Sobre</html>', 200, ['Content-Type' => 'text/html']));

        $first = $service->getCachedResponse($request);
        $this->assertNotNull($first);
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertNotNull($first->getLastModified());

        $revalidation = Request::create('/sobre', 'GET', [], [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        $second = $service->getCachedResponse($revalidation);
        $this->assertNotNull($second);
        $this->assertSame(304, $second->getStatusCode());
    }

    public function testEnabledMarkerFollowsTheSwitch(): void
    {
        $marker = $this->tempDir . '/' . PageCacheService::CACHE_SUBDIR . '/' . \App\Support\PageCacheFastPath::ENABLED_MARKER;

        $enabled = new PageCacheService($this->tempDir, true);
        $enabled->saveCache(Request::create('/', 'GET'), new Response('<html>Home</html>', 200, ['Content-Type' => 'text/html']));
        $this->assertFileExists($marker);

        (new PageCacheService($this->tempDir, false))->syncEnabledMarker();
        $this->assertFileDoesNotExist($marker);
    }
}
