<?php

declare(strict_types=1);

namespace App\Tests\Controller\pub;

use App\Service\Project\ProjectIndexService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Catálogo de projetos: /projetos (busca, filtros, agrupamento, paginação), /projeto/{id}, a API JSON e o link no perfil.
 *
 * Mesmo padrão de JournalNamesReadingTest: sufixo único (só letras) em nomes e no código de departamento, transação
 * revertida no tearDown, navegador sem reiniciar o kernel e cookie de sessão para não usar o cache de páginas.
 */
class ProjectCatalogTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $conn;
    private string $t;
    private string $dept;

    /** @var array<string, int> */
    private array $ids = [];

    /** @var array<string, string> IDs Lattes do cenário, sorteados a cada teste */
    private array $lattes = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->client->getCookieJar()->set(new Cookie('TESTE_SESSAO', '1'));
        $this->conn = static::getContainer()->get('doctrine.orm.entity_manager')->getConnection();
        $this->t = 'zq' . strtr(bin2hex(random_bytes(5)), '0123456789', 'ghijklmnop');
        $this->dept = strtoupper(substr($this->t, 0, 8));
        foreach (['ana', 'bia', 'carla', 'external'] as $who) {
            $this->lattes[$who] = '98' . str_pad((string)random_int(0, 99999999999999), 14, '0', STR_PAD_LEFT);
        }

        $this->conn->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }

        parent::tearDown();
    }

    public function testListUnifiesTheProjectDeclaredByTwoResearchersAndShowsTheCards(): void
    {
        $crawler = $this->client->request('GET', '/projetos?q=' . $this->t);
        $this->assertResponseIsSuccessful();

        $this->assertSame("3 projetos encontrados", trim($crawler->filter('#project-results-title')->text()));
        $cards = $crawler->filter('.project-card');
        $this->assertCount(3, $cards);

        // Do mais recente para o mais antigo
        $titles = $cards->each(static fn (Crawler $c): string => trim($c->filter('h3')->text()));
        $this->assertSame(["Oficina de leitura {$this->t}", "Formação de professores {$this->t}", "Arquivo histórico {$this->t}"], $titles);

        $shared = $cards->eq(1);
        $this->assertStringContainsString('Em andamento', $shared->text());
        $this->assertStringContainsString('Pesquisa', $shared->text());
        $this->assertStringContainsString('2019 – atual', $shared->text());
        $this->assertStringContainsString('Declarado em 2 currículos', $shared->text());
        $this->assertStringContainsString('Resumo longo', $shared->text());
        $this->assertSame('/projeto/' . $this->ids['shared_ana'], $shared->filter('h3 a')->attr('href'), 'O link usa o registro representante (o do responsável)');
        // As iniciais do avatar ficam ocultas para leitores de tela (aria-hidden) e não entram no texto comparado
        $people = $shared->filter('a[href^="/professor/"]')->each(static function (Crawler $a): string {
            $node = $a->getNode(0)->cloneNode(true);
            foreach (iterator_to_array((new \DOMXPath($node->ownerDocument))->query('.//*[@aria-hidden="true"]', $node)) as $hidden) {
                $hidden->parentNode->removeChild($hidden);
            }

            return trim(preg_replace('/\s+/', ' ', $node->textContent));
        });
        $this->assertSame(["Ana {$this->t} {$this->dept}", "Bia {$this->t} {$this->dept}"], $people);
    }

    public function testFiltersByStatusNatureDepartmentAndPeriod(): void
    {
        $count = function (string $query): int {
            $crawler = $this->client->request('GET', '/projetos?q=' . $this->t . $query);
            $this->assertResponseIsSuccessful();

            return $crawler->filter('.project-card')->count();
        };

        $this->assertSame(1, $count('&situacao=CONCLUIDO'));
        $this->assertSame(1, $count('&natureza=EXTENSAO'));
        $this->assertSame(2, $count('&situacao=EM_ANDAMENTO'));
        $this->assertSame(2, $count('&departamento=' . $this->dept), 'Ana e Bia são do departamento; o arquivo histórico é de outra docente');
        // Período: em execução em algum ano do intervalo
        $this->assertSame(1, $count('&ano_inicial=2000&ano_final=2012'), 'Só o arquivo histórico (2010–2012)');
        $this->assertSame(2, $count('&ano_inicial=2023'), 'Os dois em andamento continuam em execução');
        $this->assertSame(2, $count('&ano_final=2019'));

        // Sem resultado: estado vazio
        $crawler = $this->client->request('GET', '/projetos?q=' . $this->t . '&natureza=ENSINO');
        $this->assertSame('Nenhum projeto encontrado', trim($crawler->filter('#project-results-title')->text()));
    }

    public function testInvalidOrEmptyParametersRedirectToTheCanonicalUrl(): void
    {
        $this->client->request('GET', '/projetos?q=&situacao=QUALQUER&natureza=extensao&utm_source=x');
        $this->assertResponseRedirects('/projetos?natureza=EXTENSAO');

        $this->client->request('GET', '/projetos?page=1');
        $this->assertResponseRedirects('/projetos');

        $this->client->request('GET', '/projetos?q=' . $this->t . '&page=2');
        $this->assertResponseStatusCodeSame(404, 'Página além da última');
    }

    public function testPagination(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->insertProject($this->ids['ana'], sprintf('Projeto em série %02d %s', $i, $this->t), 2000 + $i, 'CONCLUIDO', 'PESQUISA', 2000 + $i);
        }
        $this->reindex();

        $crawler = $this->client->request('GET', '/projetos?q=' . $this->t);
        $this->assertSame('14 projetos encontrados', trim($crawler->filter('#project-results-title')->text()));
        $this->assertCount(12, $crawler->filter('.project-card'));
        $next = $crawler->filter('a[rel="next"]');
        $this->assertCount(1, $next);
        $this->assertSame('/projetos?q=' . $this->t . '&page=2', $next->attr('href'));

        $crawler = $this->client->request('GET', '/projetos?q=' . $this->t . '&page=2');
        $this->assertResponseIsSuccessful();
        $this->assertCount(2, $crawler->filter('.project-card'));
        $this->assertSame('/projetos?q=' . $this->t, $crawler->filter('a[rel="prev"]')->attr('href'));
    }

    public function testShowJoinsTheGroupAndAnyRecordOpensTheSamePage(): void
    {
        $crawler = $this->client->request('GET', '/projeto/' . $this->ids['shared_bia']);
        $this->assertResponseIsSuccessful();

        $this->assertSame("Formação de professores {$this->t}", trim($crawler->filter('h1')->text()));
        $this->assertSame('http://localhost/projeto/' . $this->ids['shared_ana'], $crawler->filter('link[rel="canonical"]')->attr('href'));
        $this->assertStringContainsString("Formação de professores {$this->t}", $crawler->filter('title')->text());
        $this->assertStringContainsString('Resumo longo', (string)$crawler->filter('meta[name="description"]')->attr('content'));

        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('Em andamento', $body);
        $this->assertStringContainsString('2019 – atual', $body);

        // Equipe do currículo representante, com papel; docentes do CECH com link para o perfil
        $members = $crawler->filter('.project-member');
        $this->assertCount(3, $members);
        $this->assertStringContainsString("Ana {$this->t}", $members->eq(0)->text());
        $this->assertStringContainsString('Responsável', $members->eq(0)->text());
        $this->assertSame('/professor/docente-ana-' . $this->t, $members->eq(0)->filter('a')->attr('href'));
        $this->assertSame('/professor/docente-bia-' . $this->t, $members->eq(1)->filter('a')->attr('href'));
        $this->assertStringContainsString('Participante', $members->eq(1)->text());
        $this->assertCount(0, $members->eq(2)->filter('a'), 'Quem não é docente do CECH não tem link');
        $this->assertStringContainsString('Estudante Externa', $members->eq(2)->text());

        // Financiadores de todos os currículos, um por instituição
        $funders = $crawler->filter('[data-project-funders] li')->each(static fn (Crawler $li): string => trim(preg_replace('/\s+/', ' ', $li->text())));
        $this->assertSame(['FAPESP · Auxílio financeiro, Bolsa', 'CNPq · Bolsa'], $funders);

        // Quem declara o projeto no Lattes
        $declared = $crawler->filter('[data-project-declared-by] li')->each(static fn (Crawler $li): string => trim(preg_replace('/\s+/', ' ', $li->text())));
        $this->assertSame(["Ana {$this->t} Departamento {$this->t} Coordenação", "Bia {$this->t} Departamento {$this->t}"], $declared);

        // O outro registro do grupo abre a mesma página
        $other = $this->client->request('GET', '/projeto/' . $this->ids['shared_ana']);
        $this->assertSame($crawler->filter('h1')->text(), $other->filter('h1')->text());
        $this->assertSame('http://localhost/projeto/' . $this->ids['shared_ana'], $other->filter('link[rel="canonical"]')->attr('href'));

        $this->client->request('GET', '/projeto/999999999');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testApiListAndDetail(): void
    {
        $this->client->request('GET', '/api/v1/projetos?q=' . $this->t . '&situacao=EM_ANDAMENTO&departamento=' . $this->dept . '&natureza=invalida');
        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $this->assertStringContainsString('max-age=3600', (string)$response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string)$response->headers->get('Cache-Control'));

        $data = json_decode((string)$response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $data['count']);
        $this->assertSame(1, $data['page']);
        $this->assertSame(1, $data['pages']);
        $this->assertSame(['q' => $this->t, 'situacao' => 'EM_ANDAMENTO', 'departamento' => $this->dept], $data['filters'], 'Valor inválido é ignorado');
        $this->assertSame(["Oficina de leitura {$this->t}", "Formação de professores {$this->t}"], array_column($data['results'], 'title'));
        $shared = $data['results'][1];
        $this->assertSame($this->ids['shared_ana'], $shared['id']);
        $this->assertSame('Em andamento', $shared['status_label']);
        $this->assertSame('http://localhost/professor/docente-ana-' . $this->t, $shared['researchers'][0]['url']);

        $this->client->request('GET', '/api/v1/projetos/' . $this->ids['shared_bia']);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('max-age=3600', (string)$this->client->getResponse()->headers->get('Cache-Control'));
        $content = (string)$this->client->getResponse()->getContent();
        $detail = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($this->ids['shared_ana'], $detail['id']);
        $this->assertSame(['Ana', 'Bia', 'Estudante'], array_map(static fn (array $m): string => strtok($m['name'], ' '), $detail['members']));
        $this->assertSame('responsavel', $detail['members'][0]['role']);
        $this->assertNull($detail['members'][2]['researcher']);
        $this->assertCount(2, $detail['declared_by']);
        // Nada além do que a página mostra: sem nome para citação nem ID Lattes de integrantes
        $this->assertStringNotContainsString('ESTUDANTE, E.', $content);
        $this->assertStringNotContainsString('citation', $content);
        $this->assertStringNotContainsString($this->lattes['external'], $content);

        $this->client->request('GET', '/api/v1/projetos/999999999');
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/projetos?q=' . $this->t . '&page=9');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testProfileLinksEachProjectAndHasTheQuickFilter(): void
    {
        $crawler = $this->client->request('GET', '/professor/docente-bia-' . $this->t);
        $this->assertResponseIsSuccessful();

        $items = $crawler->filter('#pane-activities .project-item');
        $this->assertCount(2, $items);
        $this->assertSame("Oficina de leitura {$this->t}", $items->eq(0)->attr('data-project-name'), 'Do mais recente para o mais antigo');
        $this->assertSame('EM_ANDAMENTO', $items->eq(0)->attr('data-project-status'));
        $this->assertSame('/projeto/' . $this->ids['workshop'], $items->eq(0)->filter('h4 a')->attr('href'));
        $this->assertSame('/projeto/' . $this->ids['shared_bia'], $items->eq(1)->filter('h4 a')->attr('href'));

        $this->assertCount(1, $crawler->filter('#project-quick-name'));
        $options = $crawler->filter('#project-quick-status option')->each(static fn (Crawler $o): string => $o->text());
        $this->assertSame(['Todas as situações', 'Em andamento (1)', 'Concluído (1)'], $options);
        // O filtro rápido usa a classe `hidden` e deixa o style.display para o filtro global da página
        $html = (string)$this->client->getResponse()->getContent();
        $this->assertStringContainsString("item.classList.toggle('hidden', !ok)", $html);
        $this->assertStringContainsString('function applyGlobalFilter()', $html);
    }

    // ------------------------------------------------------------------------------------------------

    /**
     * Ana (responsável) e Bia declaram "Formação de professores" (2019, em andamento); Bia também tem a oficina de
     * extensão (2024); Carla, de outro departamento, o arquivo histórico concluído (2010–2012).
     */
    private function seed(): void
    {
        $t = $this->t;
        $this->ids['ana'] = $ana = $this->insertResearcher('ana', "Ana $t", $this->lattes['ana'], $this->dept);
        $this->ids['bia'] = $bia = $this->insertResearcher('bia', "Bia $t", $this->lattes['bia'], $this->dept);
        $carla = $this->insertResearcher('carla', "Carla $t", $this->lattes['carla'], 'OUTRO' . substr($this->dept, 0, 3));

        // O registro da Bia é o mais antigo: o representante é o da Ana por ela ser a responsável, não pela ordem
        $this->ids['shared_bia'] = $sharedBia = $this->insertProject($bia, "FORMACAO DE PROFESSORES $t.", 2019, 'CONCLUIDO', 'PESQUISA', null, 'Curta.');
        $this->ids['shared_ana'] = $sharedAna = $this->insertProject($ana, "Formação de professores $t", 2019, 'EM_ANDAMENTO', 'PESQUISA', null, 'Resumo longo do projeto de formação de professores, escrito pela responsável.', true);
        $this->ids['workshop'] = $this->insertProject($bia, "Oficina de leitura $t", 2024, 'EM_ANDAMENTO', 'EXTENSAO');
        $this->insertProject($carla, "Arquivo histórico $t", 2010, 'CONCLUIDO', 'DESENVOLVIMENTO', 2012);

        $this->insertMember($sharedAna, "Ana $t", $this->lattes['ana'], true, 1);
        $this->insertMember($sharedAna, "Bia $t", $this->lattes['bia'], false, 2);
        $this->insertMember($sharedAna, 'Estudante Externa', $this->lattes['external'], false, 3, 'ESTUDANTE, E.');
        $this->insertMember($sharedBia, 'B. Silva', null, true, 1);

        $this->insertFunder($sharedAna, 'FAPESP', 'AUXILIO_FINANCEIRO', 1);
        $this->insertFunder($sharedAna, 'CNPq', 'BOLSA', 2);
        $this->insertFunder($sharedBia, 'Fapesp', 'BOLSA', 1);

        $this->reindex();
    }

    private function reindex(): void
    {
        static::getContainer()->get(ProjectIndexService::class)->index();
    }

    private function insertResearcher(string $slugPart, string $name, string $idLattes, string $dept): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->insert('researchers', [
            'id_lattes' => $idLattes,
            'full_name' => $name,
            'slug' => 'docente-' . $slugPart . '-' . $this->t,
            'status' => 1,
            'department_code' => $dept,
            'department' => 'Departamento ' . $this->t,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    private function insertProject(int $researcherId, string $name, int $startYear, string $status = 'EM_ANDAMENTO', string $nature = 'PESQUISA', ?int $endYear = null, ?string $description = null, bool $coordinator = false): int
    {
        $this->conn->insert('research_projects', [
            'researcher_id' => $researcherId,
            'name' => $name,
            'nature' => $nature,
            'status' => $status,
            'start_year' => $startYear,
            'end_year' => $endYear,
            'description' => $description,
            'is_coordinator' => $coordinator ? 1 : 0,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    private function insertMember(int $projectId, string $name, ?string $idLattes, bool $responsible, int $order, ?string $citation = null): void
    {
        $this->conn->insert('research_project_members', [
            'research_project_id' => $projectId,
            'full_name' => $name,
            'citation_name' => $citation,
            'member_order' => $order,
            'is_responsible' => $responsible ? 1 : 0,
            'id_lattes' => $idLattes,
        ]);
    }

    private function insertFunder(int $projectId, string $name, string $nature, int $order): void
    {
        $this->conn->insert('research_project_funders', [
            'research_project_id' => $projectId,
            'name' => $name,
            'nature' => $nature,
            'funder_order' => $order,
        ]);
    }
}
