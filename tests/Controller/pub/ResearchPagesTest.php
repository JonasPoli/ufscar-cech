<?php

declare(strict_types=1);

namespace App\Tests\Controller\pub;

use App\Tests\Service\Research\ResearchFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Páginas da pesquisa pública (busca, coautorias, explorar, panorama) e a API v1, com dados próprios.
 *
 * Os dados ficam numa transação revertida no tearDown; o navegador não reinicia o kernel, para todas as requisições
 * verem as linhas ainda não confirmadas, e leva um cookie de sessão para não usar o cache de páginas.
 */
class ResearchPagesTest extends WebTestCase
{
    use ResearchFixtures;

    private KernelBrowser $client;
    private int $ana;
    private int $bruno;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->client->getCookieJar()->set(new Cookie('TESTE_SESSAO', '1'));
        $this->conn = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->t = $this->newToken();
        $this->conn->beginTransaction();

        $t = $this->t;
        $this->ana = $this->insertResearcher("Ana Rede $t");
        $this->bruno = $this->insertResearcher("Bruno Rede $t", null, 0, 1995, 2018);
        $this->insertKnowledgeArea($this->ana, 'CIENCIAS_HUMANAS', 'Educação');
        $this->insertSharedWork([$this->ana, $this->bruno], "Leitura literária na escola $t", 2016, "10.5555/$t");
        $this->insertSharedWork([$this->ana, $this->bruno], "Leitura e mediação cultural $t", 2019);
        $this->insertItem($this->ana, "Leitura em voz alta $t", 2023, 'LIVRO');
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        parent::tearDown();
    }

    public function testSearchModesShowExpertsPublicationsAndPeople(): void
    {
        $t = $this->t;

        $crawler = $this->client->request('GET', '/busca?q=' . rawurlencode("leitura $t"));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('2 docentes', $crawler->filter('#resultados')->text());
        $this->assertStringContainsString("Ana Rede $t", $crawler->filter('main')->text());
        $this->assertStringContainsString('Ver 3 publicações', $crawler->filter('main')->text());

        $crawler = $this->client->request('GET', '/busca?modo=publicacoes&q=' . rawurlencode("leitura $t"));
        $this->assertStringContainsString('3 publicações', $crawler->filter('#resultados')->text(), 'Obras em coautoria contam uma vez');

        $crawler = $this->client->request('GET', '/busca?modo=pesquisador&vinculo=encerrado&q=' . rawurlencode("Rede $t"));
        $this->assertStringContainsString("Bruno Rede $t", $crawler->filter('main')->text());
        $this->assertStringNotContainsString("Ana Rede $t", $crawler->filter('main')->text());
        $this->assertStringContainsString('1995–2018', $crawler->filter('main')->text());

        // Filtros: tipo e período
        $crawler = $this->client->request('GET', '/busca?modo=publicacoes&tipo=livro&q=' . rawurlencode("leitura $t"));
        $this->assertStringContainsString('1 publicação', $crawler->filter('#resultados')->text());
        $crawler = $this->client->request('GET', '/busca?modo=publicacoes&de=2018&ate=2020&q=' . rawurlencode("leitura $t"));
        $this->assertStringContainsString('1 publicação', $crawler->filter('#resultados')->text());

        // Teses e orientações: vínculo e período valem também nesse modo (antes só q e departamento eram aplicados)
        foreach ([[$this->ana, 2015], [$this->bruno, 2022]] as [$researcherId, $year]) {
            $this->conn->insert('orientations', [
                'researcher_id' => $researcherId, 'orientation_type' => 'MESTRADO', 'nature' => 'CONCLUIDA',
                'student_name' => "Aluno $year $t", 'title' => "Leitura mediada $year $t", 'year' => $year,
                'source' => 'lattes', 'is_coadvising' => 0,
            ]);
        }
        $crawler = $this->client->request('GET', '/busca?modo=orientacoes&q=' . rawurlencode($t));
        $this->assertStringContainsString("Aluno 2015 $t", $crawler->filter('main')->text());
        $this->assertStringContainsString("Aluno 2022 $t", $crawler->filter('main')->text());
        $crawler = $this->client->request('GET', '/busca?modo=orientacoes&de=2020&q=' . rawurlencode($t));
        $this->assertStringNotContainsString("Aluno 2015 $t", $crawler->filter('main')->text());
        $this->assertStringContainsString("Aluno 2022 $t", $crawler->filter('main')->text());
        $crawler = $this->client->request('GET', '/busca?modo=orientacoes&vinculo=vigente&q=' . rawurlencode($t));
        $this->assertStringContainsString("Aluno 2015 $t", $crawler->filter('main')->text());
        $this->assertStringNotContainsString("Aluno 2022 $t", $crawler->filter('main')->text(), 'Bruno tem vínculo encerrado');

        // Busca vazia: página inicial da busca, sem erro
        $this->client->request('GET', '/busca');
        $this->assertResponseIsSuccessful();
    }

    public function testApiGraphPairAndMetricsForADepartment(): void
    {
        $dept = $this->dept();

        $this->client->request('GET', '/api/v1/grafo?departamento=' . $dept);
        $this->assertResponseIsSuccessful();
        $graph = json_decode((string)$this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $graph['totalPairs']);
        $this->assertSame(2, $graph['edges'][0]['weight']);
        $this->assertCount(2, $graph['nodes']);

        $this->client->request('GET', '/api/v1/grafo/par?a=' . $this->ana . '&b=' . $this->bruno . '&departamento=' . $dept);
        $pair = json_decode((string)$this->client->getResponse()->getContent(), true);
        $this->assertSame(2, $pair['count']);
        $this->assertSame("Leitura e mediação cultural {$this->t}", $pair['results'][0]['title'], 'Mais recente primeiro');

        $this->client->request('GET', '/api/v1/metricas?departamento=' . $dept);
        $metrics = json_decode((string)$this->client->getResponse()->getContent(), true);
        $this->assertSame(3, $metrics['publications']);
        $this->assertSame(2, $metrics['sharedWorks']);
        $this->assertSame(1, $metrics['pairs']);
        $this->assertSame([[1]], $metrics['bridges']['departments']['values']);

        $this->client->request('GET', '/api/v1/grafo/par?a=1&b=1');
        $this->assertResponseStatusCodeSame(400);
    }

    public function testCoauthorshipAndExplorePagesRender(): void
    {
        $dept = $this->dept();

        $crawler = $this->client->request('GET', '/coautorias?departamento=' . $dept);
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-cech-graph]'));
        $this->assertStringContainsString("Ana Rede {$this->t}", $crawler->filter('#rede')->text());

        $this->client->request('GET', '/coautorias');
        $this->assertResponseIsSuccessful();

        // Valor inválido em `ligacoes` volta ao padrão (120), não ao "Todas" (0)
        foreach (['abc', '', '77'] as $invalid) {
            $crawler = $this->client->request('GET', '/coautorias?ligacoes=' . $invalid);
            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString('ligacoes=120', (string)$crawler->filter('[data-cech-graph]')->outerHtml(), "ligacoes=$invalid");
        }
        // Área ou departamento que não existe é descartado e a página não entra no cache de páginas
        $this->client->request('GET', '/coautorias?area=area-inventada-' . $this->t);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->client->getRequest()->attributes->getBoolean(\App\Service\Research\ResearchFilterFactory::NO_PAGE_CACHE));
        $this->client->request('GET', '/coautorias?departamento=' . $dept);
        $this->assertFalse($this->client->getRequest()->attributes->getBoolean(\App\Service\Research\ResearchFilterFactory::NO_PAGE_CACHE));

        $crawler = $this->client->request('GET', '/coautorias?ligacoes=0');
        $this->assertStringContainsString('ligacoes=0', (string)$crawler->filter('[data-cech-graph]')->outerHtml(), 'O número 0 continua sendo "Todas"');

        foreach (['/explorar', '/explorar/areas', '/explorar/grandes-areas'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
        }

        $crawler = $this->client->request('GET', '/explorar/area/educacao?departamento=' . $dept);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString("Ana Rede {$this->t}", $crawler->filter('section[aria-labelledby="docentes-campo"]')->text());

        $this->client->request('GET', '/explorar/grande-area/ciencias-humanas?departamento=' . $dept);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/explorar/area/area-que-nao-existe');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDepartmentPageJoinsEquivalentCodes(): void
    {
        // CS e DCSo são o mesmo departamento; o cartão de /departamentos leva a /departamento/CS
        $cs = $this->insertResearcher("Carla Sociais {$this->t}", 'CS');
        $dcso = $this->insertResearcher("Davi Sociais {$this->t}", 'DCSo');

        $crawler = $this->client->request('GET', '/departamento/CS');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString("Carla Sociais {$this->t}", $crawler->filter('main')->text());
        $this->assertStringContainsString("Davi Sociais {$this->t}", $crawler->filter('main')->text());
        $this->assertGreaterThan(0, $cs + $dcso);
    }

    public function testApiResearcherAndCatalog(): void
    {
        $slug = $this->conn->fetchOne('SELECT slug FROM researchers WHERE id = ?', [$this->ana]);

        $this->client->request('GET', '/api/v1/docentes/' . $slug);
        $this->assertResponseIsSuccessful();
        $data = json_decode((string)$this->client->getResponse()->getContent(), true);
        $this->assertSame("Ana Rede {$this->t}", $data['name']);
        $this->assertSame(3, $data['stats']['publications']);
        $this->assertSame(1, $data['stats']['coauthors']);
        $this->assertSame(2, $data['stats']['sharedWorks']);

        $this->client->request('GET', '/api/v1/catalogo');
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('public'));

        $this->client->request('GET', '/api/v1/busca');
        $this->assertResponseStatusCodeSame(400);
    }

    public function testProfileFromSearchShowsMatchingWorksBackLinkAndNetwork(): void
    {
        $t = $this->t;
        $slug = $this->conn->fetchOne('SELECT slug FROM researchers WHERE id = ?', [$this->ana]);

        // Vindo da busca: aba própria com os trabalhos que casam e o caminho de volta com os mesmos filtros
        $crawler = $this->client->request('GET', '/professor/' . $slug . '?aba=busca&modo=especialistas&tipo=artigo&q=' . rawurlencode("leitura $t"));
        $this->assertResponseIsSuccessful();
        $pane = $crawler->filter('#pane-busca')->text();
        $this->assertStringContainsString('2 trabalhos correspondem', $pane, 'O livro fica de fora pelo filtro de tipo');
        $back = $crawler->filter('#pane-busca a')->first()->attr('href');
        $this->assertStringStartsWith('/busca?', $back);
        $this->assertStringContainsString('tipo=artigo', $back);

        // Vínculo, rede no CECH e, sem busca, nenhuma aba extra
        $crawler = $this->client->request('GET', '/professor/' . $slug);
        $this->assertCount(0, $crawler->filter('#pane-busca'));
        $this->assertStringContainsString('Vínculo vigente', $crawler->filter('main')->text());
        $this->assertStringContainsString('1 coautor no CECH', $crawler->filter('main')->text());
        $this->assertStringContainsString('2 publicações compartilhadas', $crawler->filter('main')->text());

        // Aba de métricas: grafo da vizinhança com o par
        $crawler = $this->client->request('GET', '/professor/' . $slug . '/fragment/analytics');
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-cech-graph]'));
        $this->assertStringContainsString("Bruno Rede $t", $crawler->filter('[data-graph-pair]')->text());
    }
}
