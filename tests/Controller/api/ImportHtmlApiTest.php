<?php

declare(strict_types=1);

namespace App\Tests\Controller\api;

use App\Service\Crawler\LattesPhotoCrawlerService;
use App\Service\Import\CurriculumPostImportService;
use App\Service\Import\RepositoryImportService;
use App\Service\PageCacheService;
use App\Service\Security\SyncTokenProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * POST /api/curriculum/import-html, chamado pelo marcador do navegador sobre a página do Lattes: depois de gravar o
 * currículo ele limpa o cache de páginas, devolve o aviso do que a tela não atualiza e não deixa uma falha secundária
 * (foto, cache, Repositório) derrubar uma importação já gravada.
 *
 * A foto do CNPq, o CSV do Repositório e a pasta do cache de páginas são trocados no container; os docentes de teste
 * usam ID único e saem no tearDown (cech_test é compartilhado).
 */
class ImportHtmlApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $conn;
    private string $uniq;
    private string $cacheRoot;

    /** @var string[] */
    private array $lattesIds = [];

    /** O que o rastreador de fotos simulado faz quando é chamado (um serviço do container só pode ser trocado uma vez) */
    private \Closure $photoBehavior;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->conn = $container->get(Connection::class);
        $this->uniq = 'qz' . bin2hex(random_bytes(4));

        $this->cacheRoot = sys_get_temp_dir() . '/cache' . $this->uniq;
        mkdir($this->cacheRoot . '/var/cache/public_pages', 0777, true);
        file_put_contents($this->sentinel(), 'página antiga em cache');
        $container->set(PageCacheService::class, new PageCacheService($this->cacheRoot, true));
        $container->set(RepositoryImportService::class, $this->createMock(RepositoryImportService::class));
        // Sem rede: o padrão é um rastreador que não acha foto
        $this->photoBehavior = static fn (): ?string => null;
        $photo = $this->createMock(LattesPhotoCrawlerService::class);
        $photo->method('crawlPhoto')->willReturnCallback(fn ($researcher): ?string => ($this->photoBehavior)($researcher));
        $container->set(LattesPhotoCrawlerService::class, $photo);
    }

    protected function tearDown(): void
    {
        foreach ($this->lattesIds as $idLattes) {
            $this->conn->executeStatement('DELETE FROM researchers WHERE id_lattes = ?', [$idLattes]);
        }
        $this->conn->executeStatement('DELETE FROM author_identities WHERE normalized_name LIKE ?', ['%' . strtoupper($this->uniq) . '%']);
        (new Filesystem())->remove($this->cacheRoot);

        parent::tearDown();
    }

    public function testSuccessClearsThePageCacheAndTellsWhatTheScreenDoesNotUpdate(): void
    {
        $id = $this->newId();

        $data = $this->post(['idLattes' => $id, 'html' => $this->html($id, "Docente Api {$this->uniq}")]);

        $this->assertResponseIsSuccessful();
        $this->assertTrue($data['success']);
        $this->assertSame($id, $data['researcher']['idLattes']);
        $this->assertSame(CurriculumPostImportService::HEAVY_STEPS_NOTICE, $data['notice']);
        $this->assertStringContainsString('app:index:topics', $data['message']);
        $this->assertSame([], $data['warnings']);
        $this->assertFileDoesNotExist($this->sentinel(), 'O cache de páginas públicas foi limpo depois da importação.');
        $this->assertNotSame('', $this->conn->fetchOne('SELECT slug FROM researchers WHERE id_lattes = ?', [$id]), 'O docente novo pelo HTML tem slug.');
    }

    public function testAnIdThatIsNotSixteenDigitsIsIgnoredAndTheParserFindsTheIdInTheHtml(): void
    {
        $id = $this->newId();
        // A página da busca textual tem o campo `id` com o K-ID (10 caracteres), que o marcador também pode mandar como idLattes
        $html = '<form><input type="hidden" name="id" value="K4123456A7"></form>' . $this->html($id, "Docente Kid {$this->uniq}");

        $data = $this->post(['idLattes' => 'K4123456A7', 'html' => $html]);

        $this->assertResponseIsSuccessful();
        $this->assertSame($id, $data['researcher']['idLattes'], 'O K-ID não vira o ID Lattes de um docente novo.');
        $this->assertFalse($this->conn->fetchOne('SELECT id FROM researchers WHERE id_lattes = ?', ['K4123456A7']));
    }

    public function testAFailureInThePhotoBecomesAWarningInsteadOfAnError(): void
    {
        $id = $this->newId();
        $this->photoBehavior = static function (): never {
            throw new \RuntimeException('CNPq fora do ar');
        };

        $data = $this->post(['idLattes' => $id, 'html' => $this->html($id, "Docente Foto {$this->uniq}")]);

        $this->assertResponseIsSuccessful();
        $this->assertTrue($data['success'], 'O currículo já estava gravado.');
        $this->assertCount(1, $data['warnings']);
        $this->assertStringContainsString('CNPq fora do ar', $data['warnings'][0]);
        $this->assertNotFalse($this->conn->fetchOne('SELECT id FROM researchers WHERE id_lattes = ?', [$id]));
    }

    public function testHtmlWithoutAnyIdIsStillAnError(): void
    {
        $data = $this->post(['idLattes' => '', 'html' => '<div class="infpessoa"><h2 class="nome">Sem ID</h2></div>']);

        $this->assertResponseStatusCodeSame(500);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('ID Lattes', $data['message']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        $token = static::getContainer()->get(SyncTokenProvider::class)->getToken();
        $this->client->request('POST', '/api/curriculum/import-html', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CECH_SYNC_TOKEN' => $token,
        ], json_encode($payload));

        return json_decode((string)$this->client->getResponse()->getContent(), true);
    }

    private function sentinel(): string
    {
        return $this->cacheRoot . '/var/cache/public_pages/pagina.html';
    }

    private function newId(): string
    {
        $id = '9922' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        $this->lattesIds[] = $id;

        return $id;
    }

    private function html(string $idLattes, string $name): string
    {
        return '<div class="infpessoa"><h2 class="nome">' . $name . '</h2></div>'
            . '<ul class="informacoes-autor"><li>Endereço para acessar este CV: http://lattes.cnpq.br/' . $idLattes . '</li></ul>';
    }
}
