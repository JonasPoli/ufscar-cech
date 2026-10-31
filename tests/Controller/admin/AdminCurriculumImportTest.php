<?php

declare(strict_types=1);

namespace App\Tests\Controller\admin;

use App\Entity\User;
use App\Service\Crawler\LattesPhotoCrawlerService;
use App\Service\Import\CurriculumPostImportService;
use App\Service\Import\RepositoryImportService;
use App\Service\PageCacheService;
use App\Service\Thesaurus\AuthorResolverService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Telas de importação de currículo do painel (/new e /import) e exclusão de docente.
 *
 * Os serviços de fora (foto do CNPq, CSV do Repositório e a pasta do cache de páginas) são trocados no container: o teste
 * não faz rede, não lê o CSV de produção e não apaga o cache de páginas do ambiente. Docentes e arquivos de teste usam
 * ID único e saem no tearDown (cech_test é compartilhado com outros testes e agentes).
 */
class AdminCurriculumImportTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $conn;
    private string $uniq;
    private string $dir;
    private string $cacheRoot;

    /** @var string[] IDs Lattes de teste (saem no tearDown, com tudo o que depende deles) */
    private array $lattesIds = [];
    /** @var string[] IDs Lattes para os quais o rastreador de fotos foi chamado */
    private array $photoCalls = [];
    /** @var int[] Chamadas do importador do Repositório (uma por docente ou uma passada geral) */
    private array $repositoryCalls = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Sem reiniciar o kernel a cada requisição, os serviços trocados abaixo valem para todas elas
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->conn = $container->get(Connection::class);
        $this->uniq = 'qz' . bin2hex(random_bytes(4));

        $this->dir = sys_get_temp_dir() . '/import' . $this->uniq;
        mkdir($this->dir);

        // Pasta de cache de páginas só deste teste, com uma página que a importação precisa derrubar
        $this->cacheRoot = sys_get_temp_dir() . '/cache' . $this->uniq;
        mkdir($this->cacheRoot . '/var/cache/public_pages', 0777, true);
        $this->plantSentinel();
        $container->set(PageCacheService::class, new PageCacheService($this->cacheRoot, true));

        $photo = $this->createMock(LattesPhotoCrawlerService::class);
        $photo->method('crawlPhoto')->willReturnCallback(function ($researcher): ?string {
            $this->photoCalls[] = $researcher->getIdLattes();

            return null;
        });
        $container->set(LattesPhotoCrawlerService::class, $photo);

        $repository = $this->createMock(RepositoryImportService::class);
        $repository->method('import')->willReturnCallback(function () {
            $this->repositoryCalls[] = func_num_args();

            return [];
        });
        $container->set(RepositoryImportService::class, $repository);

        $em = $container->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        if (!$user) {
            $user = new User();
            $user->setUsername('admin');
            $user->setRoles(['ROLE_ADMIN']);
            $user->setPassword($container->get('security.user_password_hasher')->hashPassword($user, 'wab12345678'));
            $em->persist($user);
            $em->flush();
        }
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        foreach ($this->lattesIds as $idLattes) {
            // Produções, autores e demais filhos saem junto com o docente (ON DELETE CASCADE)
            $this->conn->executeStatement('DELETE FROM researchers WHERE id_lattes = ?', [$idLattes]);
        }
        $this->conn->executeStatement('DELETE FROM author_identities WHERE normalized_name LIKE ?', ['%' . strtoupper($this->uniq) . '%']);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->cacheRoot);

        parent::tearDown();
    }

    public function testNewImportsTheUploadedXmlWithTheIdTypedOnTheScreen(): void
    {
        $id = $this->newId();
        // O XML não traz NUMERO-IDENTIFICADOR (10 dos 414 não trazem) e o arquivo enviado se chama /tmp/phpXXXXXX
        $xml = $this->writeXml('curriculo-sem-id.xml', null, "Docente Tela {$this->uniq}");

        $this->client->request('POST', '/admin/curriculum/new', [
            'lattes_id' => "http://lattes.cnpq.br/{$id}",
            'department' => 'DCI|Departamento de Ciência da Informação',
        ], ['xml_file' => new UploadedFile($xml, 'curriculo-sem-id.xml', 'application/xml', null, true)]);

        $row = $this->row($id);
        $this->assertNotFalse($row, 'O ID digitado na tela foi usado para o XML sem identificador.');
        $this->assertSame("Docente Tela {$this->uniq}", $row['full_name']);
        $this->assertSame('DCI', $row['department_code']);
        $this->assertSame('Departamento de Ciência da Informação', $row['department']);
        $this->assertResponseRedirects('/admin/curriculum/' . $row['id']);

        $this->client->followRedirect();
        $this->assertSelectorTextContains('main', 'app:journals:pipeline');
        $this->assertSelectorTextContains('main', 'app:index:topics');
        $this->assertSelectorTextContains('main', 'atualizar temas e rankings');
        $this->assertFileDoesNotExist($this->sentinel(), 'O cache de páginas públicas foi limpo depois da importação.');
    }

    public function testNewFetchesThePhotoOnlyWhenTheBoxIsChecked(): void
    {
        $id = $this->newId();
        $xml = $this->writeXml('curriculo.xml', $id, "Docente Foto {$this->uniq}");
        $upload = fn () => ['xml_file' => new UploadedFile($xml, 'curriculo.xml', 'application/xml', null, true)];

        // Caixa desmarcada: o navegador não envia o campo, e a foto enviada à mão não pode ser trocada
        $this->client->request('POST', '/admin/curriculum/new', ['lattes_id' => $id], $upload());
        $this->assertSame([], $this->photoCalls);

        $this->client->request('POST', '/admin/curriculum/new', ['lattes_id' => $id, 'fetch_photo' => '1'], $upload());
        $this->assertSame([$id], $this->photoCalls);
    }

    public function testNewWithoutAnyFileRegistersTheResearcherWithASlug(): void
    {
        $id = $this->newId();

        $this->client->request('POST', '/admin/curriculum/new', ['lattes_id' => $id, 'department' => 'DL|Departamento de Letras']);

        $row = $this->row($id);
        $this->assertNotFalse($row);
        $this->assertSame("Pesquisador Lattes {$id}", $row['full_name']);
        $this->assertSame("pesquisador-lattes-{$id}", $row['slug'], 'Sem slug, a página pública do docente novo não tem endereço.');
        $this->assertFileDoesNotExist($this->sentinel());
    }

    public function testNewDoesNotReplaceANewerCurriculumWithAnOlderXmlUnlessForced(): void
    {
        $id = $this->newId();
        $newer = $this->writeXml('novo.xml', $id, "Nome Novo {$this->uniq}", '15012026');
        $older = $this->writeXml('antigo.xml', $id, "Nome Antigo {$this->uniq}", '10012020');
        $upload = fn (string $path, string $name) => ['xml_file' => new UploadedFile($path, $name, 'application/xml', null, true)];

        $this->client->request('POST', '/admin/curriculum/new', ['lattes_id' => $id], $upload($newer, 'novo.xml'));
        $this->assertSame("Nome Novo {$this->uniq}", $this->row($id)['full_name']);

        $this->client->request('POST', '/admin/curriculum/new', ['lattes_id' => $id], $upload($older, 'antigo.xml'));
        $this->assertSame("Nome Novo {$this->uniq}", $this->row($id)['full_name'], 'O XML mais antigo não substitui o currículo.');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('main', 'Importar mesmo com data antiga');
        $this->assertSelectorTextNotContains('main', 'cadastrado/atualizado com sucesso');

        $this->client->request('POST', '/admin/curriculum/new', ['lattes_id' => $id, 'force_older' => '1'], $upload($older, 'antigo.xml'));
        $this->assertSame("Nome Antigo {$this->uniq}", $this->row($id)['full_name'], 'Com a caixa marcada, o XML antigo é importado.');
    }

    public function testNewShowsTheErrorOfAnXmlThatCannotBeImportedInsteadOfClaimingSuccess(): void
    {
        $id = $this->newId();
        $broken = "{$this->dir}/quebrado.xml";
        file_put_contents($broken, '<?xml version="1.0"?><NAO-E-CURRICULO/>');

        $this->client->request('POST', '/admin/curriculum/new', ['lattes_id' => $id], ['xml_file' => new UploadedFile($broken, 'quebrado.xml', 'application/xml', null, true)]);

        $this->client->followRedirect();
        $this->assertSelectorTextContains('main', 'Erro ao processar arquivo XML');
        $this->assertSelectorTextNotContains('main', 'cadastrado/atualizado com sucesso');
    }

    public function testNewSurvivesAnXmlThatClosesTheEntityManager(): void
    {
        $id = $this->newId();
        // O ano fora da faixa da coluna faz o banco recusar, o que fecha o EntityManager no meio da importação
        $bad = "{$this->dir}/ruim.xml";
        file_put_contents($bad, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE NUMERO-IDENTIFICADOR="%s"><DADOS-GERAIS NOME-COMPLETO="Ano Errado %s"/><PRODUCAO-TECNICA><SOFTWARE><DADOS-BASICOS-DO-SOFTWARE TITULO-DO-SOFTWARE="X" ANO="99999999999"/></SOFTWARE></PRODUCAO-TECNICA></CURRICULO-VITAE>',
            $id,
            $this->uniq
        ));

        $this->client->request('POST', '/admin/curriculum/new', ['lattes_id' => $id], ['xml_file' => new UploadedFile($bad, 'ruim.xml', 'application/xml', null, true)]);

        // Antes, o EntityManager fechado derrubava o resto da tela com erro 500
        $this->assertResponseStatusCodeSame(302);
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'Erro ao processar arquivo XML');
        $this->assertSelectorTextNotContains('main', 'cadastrado/atualizado com sucesso');
    }

    public function testImportUsesTheIdFromTheOriginalFileNameAndAppliesTheDepartment(): void
    {
        $id = $this->newId();
        $xml = $this->writeXml('tmp-upload', null, "Docente Lote {$this->uniq}");

        $this->client->request('POST', '/admin/curriculum/import', [
            'department' => 'DEd|Departamento de Educação',
        ], ['xml_files' => [new UploadedFile($xml, "lattes{$id}.xml", 'application/xml', null, true)]]);

        $row = $this->row($id);
        $this->assertNotFalse($row, 'Os 16 dígitos do nome original do arquivo serviram de ID.');
        $this->assertSame('DEd', $row['department_code']);
        $this->assertSame('Departamento de Educação', $row['department']);
        $this->assertFileDoesNotExist($this->sentinel());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('main', '1 currículo(s) importado(s) com sucesso');
        $this->assertSelectorTextContains('main', 'app:index:topics');
    }

    public function testImportWithoutDepartmentKeepsTheOneTheResearcherAlreadyHas(): void
    {
        $id = $this->newId();
        $xml = $this->writeXml('curriculo.xml', $id, "Docente Mantém {$this->uniq}");
        $upload = fn () => ['xml_files' => [new UploadedFile($xml, "lattes{$id}.xml", 'application/xml', null, true)]];

        $this->client->request('POST', '/admin/curriculum/import', ['department' => 'DFil|Departamento de Filosofia'], $upload());
        $this->client->request('POST', '/admin/curriculum/import', ['force_older' => '1'], $upload());

        $this->assertSame('DFil', $this->row($id)['department_code']);
    }

    public function testImportKeepsGoingAfterAFileThatClosesTheEntityManager(): void
    {
        $badId = $this->newId();
        $goodId = $this->newId();
        // O ano fora da faixa da coluna faz o banco recusar, o que fecha o EntityManager
        $bad = "{$this->dir}/ruim.xml";
        file_put_contents($bad, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE NUMERO-IDENTIFICADOR="%s"><DADOS-GERAIS NOME-COMPLETO="Ano Errado %s"/><PRODUCAO-TECNICA><SOFTWARE><DADOS-BASICOS-DO-SOFTWARE TITULO-DO-SOFTWARE="X" ANO="99999999999"/></SOFTWARE></PRODUCAO-TECNICA></CURRICULO-VITAE>',
            $badId,
            $this->uniq
        ));
        $good = $this->writeXml('bom.xml', $goodId, "Docente Seguinte {$this->uniq}");

        $this->client->request('POST', '/admin/curriculum/import', [], ['xml_files' => [
            new UploadedFile($bad, "lattes{$badId}.xml", 'application/xml', null, true),
            new UploadedFile($good, "lattes{$goodId}.xml", 'application/xml', null, true),
        ]]);

        $this->assertNotFalse($this->row($goodId), 'O arquivo bom, que vem depois do ruim, foi importado.');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('main', "lattes{$badId}.xml");
        $this->assertSelectorTextContains('main', '1 currículo(s) importado(s)');
    }

    public function testImportSkipsAnOlderXmlUnlessForced(): void
    {
        $id = $this->newId();
        $newer = $this->writeXml('novo.xml', $id, "Nome Novo {$this->uniq}", '15012026');
        $older = $this->writeXml('antigo.xml', $id, "Nome Antigo {$this->uniq}", '10012020');
        $upload = fn (string $path) => ['xml_files' => [new UploadedFile($path, "lattes{$id}.xml", 'application/xml', null, true)]];

        $this->client->request('POST', '/admin/curriculum/import', [], $upload($newer));
        $this->client->request('POST', '/admin/curriculum/import', [], $upload($older));
        $this->assertSame("Nome Novo {$this->uniq}", $this->row($id)['full_name']);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('main', 'pulado');
        $this->assertSelectorTextContains('main', 'Importar mesmo com data antiga');

        $this->client->request('POST', '/admin/curriculum/import', ['force_older' => '1'], $upload($older));
        $this->assertSame("Nome Antigo {$this->uniq}", $this->row($id)['full_name']);
    }

    public function testImportReapplyingTheRepositoryIsAttemptedOncePerImportedBatch(): void
    {
        // O CSV do Repositório não existe no ambiente de teste: o importador simulado não deve ser chamado
        $id = $this->newId();
        $xml = $this->writeXml('curriculo.xml', $id, "Docente Repositório {$this->uniq}");

        $this->client->request('POST', '/admin/curriculum/import', [], ['xml_files' => [new UploadedFile($xml, "lattes{$id}.xml", 'application/xml', null, true)]]);

        if (is_readable(static::getContainer()->getParameter('kernel.project_dir') . '/' . CurriculumPostImportService::REPOSITORY_CSV_PATH)) {
            $this->assertCount(1, $this->repositoryCalls);
        } else {
            $this->assertSame([], $this->repositoryCalls);
        }
    }

    public function testNewAndImportPagesCarryThePortalEndpointFirstAndTheDepartmentAndForceFields(): void
    {
        $crawler = $this->client->request('GET', '/admin/curriculum/new');
        $this->assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();

        // O script roda na página do Lattes: o endereço do portal tem de estar na lista, e em primeiro lugar
        $portal = 'http://localhost/api/curriculum/import-html';
        $this->assertSame(3, substr_count($html, $portal), 'Marcador, script copiável e o código exibido.');
        $this->assertStringContainsString("new Set(['{$portal}','https://127.0.0.1:8002", $html, 'O marcador tenta o portal antes dos endereços locais.');
        $this->assertSame(3, preg_match_all('~new Set\(\[\s*\'' . preg_quote($portal, '~') . '\',\s*\'https://127\.0\.0\.1:8002~', $html), 'Marcador e scripts: o portal vem antes dos locais.');
        $this->assertStringNotContainsString('window.location.origin + ', $html, 'O origin da página do Lattes (CNPq) não é mais tentado.');
        $this->assertStringContainsString('localhost:8000/api/curriculum/import-html', $html, 'Os endereços locais continuam depois.');

        $this->assertSelectorExists('input[type=checkbox][name=force_older]');
        $this->assertSelectorExists('select[name=department] option[value="DCI|Departamento de Ciência da Informação"]');
        $this->assertSelectorExists('input[type=checkbox][name=fetch_photo][checked]');

        // O href do marcador não pode ser cortado por aspas duplas soltas: o atributo vai até o fim do código
        $href = $crawler->filter('a[href^="javascript:"]')->attr('href');
        $this->assertStringContainsString($portal, $href);
        $this->assertStringEndsWith('})();', $href, 'O marcador chega inteiro ao navegador.');

        $this->client->request('GET', '/admin/curriculum/import');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select[name=department] option[value="DEn|Departamento de Educação"]');
        $this->assertSelectorExists('select[name=department] option[value=""]', 'O departamento é opcional nesta tela.');
        $this->assertSelectorExists('input[type=checkbox][name=force_older]');
    }

    public function testDeleteClearsTheAuthorIndexCacheAndThePageCache(): void
    {
        $id = $this->newId();
        $name = "Docente Excluído {$this->uniq}";
        $this->client->request('POST', '/admin/curriculum/new', ['lattes_id' => $id]);
        $researcherId = (int)$this->row($id)['id'];
        $this->conn->executeStatement('UPDATE researchers SET full_name = ? WHERE id = ?', [$name, $researcherId]);

        // Índice de docentes em cache, citando o docente que vai ser excluído, e uma página pública em cache
        $cache = static::getContainer()->get('cache.app');
        $cache->get(AuthorResolverService::CACHE_KEY, static fn (): array => ['indice' => 'antigo']);
        $this->assertTrue($cache->hasItem(AuthorResolverService::CACHE_KEY));
        $this->plantSentinel();

        // O botão de excluir da listagem leva a URL e o token CSRF para o formulário
        $this->client->request('GET', '/admin/curriculum/?q=' . rawurlencode($name));
        $this->assertSelectorExists('button[onclick*="confirmDelete"]');
        $this->assertSame(1, preg_match("~confirmDelete\\('([^']+)', '([^']+)'~", $this->client->getResponse()->getContent(), $m));
        $this->client->request('POST', html_entity_decode($m[1]), ['_token' => html_entity_decode($m[2])]);

        $this->assertResponseRedirects('/admin/curriculum/');
        $this->assertFalse($this->row($id), 'O docente foi excluído.');
        $this->assertFalse($cache->hasItem(AuthorResolverService::CACHE_KEY), 'O índice de docentes em cache foi invalidado: um id excluído nele quebra as importações seguintes.');
        $this->assertFileDoesNotExist($this->sentinel(), 'As páginas públicas em cache também.');
    }

    private function sentinel(): string
    {
        return $this->cacheRoot . '/var/cache/public_pages/pagina.html';
    }

    /** Uma página pública em cache (a limpeza apaga a pasta inteira, então ela é recriada a cada uso). */
    private function plantSentinel(): void
    {
        if (!is_dir(dirname($this->sentinel()))) {
            mkdir(dirname($this->sentinel()), 0777, true);
        }
        file_put_contents($this->sentinel(), 'página antiga em cache');
    }

    private function newId(): string
    {
        $id = '9933' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        $this->lattesIds[] = $id;

        return $id;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function row(string $idLattes): array|false
    {
        return $this->conn->fetchAssociative('SELECT id, full_name, slug, department_code, department FROM researchers WHERE id_lattes = ?', [$idLattes]);
    }

    private function writeXml(string $file, ?string $id, string $name, string $date = '15012026'): string
    {
        $path = "{$this->dir}/{$file}";
        file_put_contents($path, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE%s DATA-ATUALIZACAO="%s"><DADOS-GERAIS NOME-COMPLETO="%s"/></CURRICULO-VITAE>',
            $id !== null ? sprintf(' NUMERO-IDENTIFICADOR="%s"', $id) : '',
            $date,
            $name
        ));

        return $path;
    }
}
