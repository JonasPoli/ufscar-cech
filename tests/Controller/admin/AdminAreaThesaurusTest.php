<?php

declare(strict_types=1);

namespace App\Tests\Controller\admin;

use App\Entity\User;
use App\Service\Journal\CapesTaxonomy;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tela admin do tesauro de categorias de revistas (/admin/journal-categories).
 *
 * Os dados usam sufixo único (que também vai na forma do tesauro do rótulo) e saem no tearDown pelo sufixo: `cech_test` é
 * compartilhado com outros testes e agentes, então nenhuma linha alheia é apagada. Os pedidos do cliente passam por
 * outra instância do kernel, por isso as linhas são gravadas de verdade, e não numa transação revertida.
 */
class AdminAreaThesaurusTest extends WebTestCase
{
    private const BASE = '/admin/journal-categories';

    private ?EntityManagerInterface $em = null;
    private ?Connection $conn = null;
    private ?CapesTaxonomy $taxonomy = null;
    private string $uniq = '';
    /** @var list<string> */
    private array $usernames = [];

    protected function tearDown(): void
    {
        if ($this->conn !== null) {
            if ($this->uniq !== '') {
                $this->conn->executeStatement('DELETE FROM capes_area_variants WHERE normalized_name LIKE ?', ['%' . strtoupper($this->uniq) . '%']);
            }
            foreach ($this->usernames as $username) {
                $this->conn->executeStatement('DELETE FROM users WHERE username = ?', [$username]);
            }
        }

        parent::tearDown();
    }

    public function testAnonymousIsRedirectedToLoginAndChangesNothing(): void
    {
        $client = $this->anonymousClient();
        $id = $this->createRow('wos', "Categoria Anonima {$this->uniq}", 'educacao');

        $client->request('GET', self::BASE . '/');
        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));

        $client->request('POST', self::BASE . "/{$id}/area", ['_token' => 'qualquer', 'area' => 'direito']);
        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));

        $client->request('GET', self::BASE . '/export');
        $this->assertResponseRedirects();

        $this->assertSame([['educacao', 'seed', 'active']], $this->rowsOf('wos', "Categoria Anonima {$this->uniq}"));
    }

    public function testUserWithoutTheAdminRoleIsDenied(): void
    {
        $client = $this->anonymousClient();
        $username = 'usuario-comum-' . $this->uniq;
        $user = new User();
        $user->setUsername($username);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword(static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'senha-qualquer'));
        $this->em->persist($user);
        $this->em->flush();
        $this->usernames[] = $username;
        $id = $this->createRow('wos', "Categoria Negada {$this->uniq}", 'educacao');

        $client->loginUser($user);
        $client->request('GET', self::BASE . '/');
        $this->assertResponseStatusCodeSame(403);
        $client->request('POST', self::BASE . "/{$id}/ignore", ['_token' => 'qualquer']);
        $this->assertResponseStatusCodeSame(403);

        $this->assertSame([['educacao', 'seed', 'active']], $this->rowsOf('wos', "Categoria Negada {$this->uniq}"));
    }

    public function testIndexShowsTheRowEscapedWithItsAreaAndHighlightsTheMenu(): void
    {
        $client = $this->adminClient();
        $label = "Categoria <i>Destaque</i> {$this->uniq}";
        $this->createRow('wos', $label, 'educacao', 'seed', 'active', 7);

        $crawler = $client->request('GET', self::BASE . "/?q={$this->uniq}");

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Tesauro de categorias de revistas');
        $this->assertSelectorTextContains('body', $label);
        $this->assertStringNotContainsString('<i>Destaque</i>', (string) $client->getResponse()->getContent(), 'O texto das bases precisa sair escapado');
        $row = $crawler->filter('tbody tr')->first();
        $this->assertStringContainsString('Educação', $row->text());
        $this->assertStringContainsString('Ciências Humanas', $row->text());
        $this->assertStringContainsString('Web of Science', $row->text());
        $this->assertStringContainsString('Semente', $row->text());
        $this->assertSelectorTextContains('body', 'ativos');
        $this->assertSelectorTextContains('body', 'app:journals:classify');

        // O menu: a tela acende e "Periódicos & Qualis" não
        $this->assertStringContainsString('font-semibold', (string) $crawler->filter('nav a[href="' . self::BASE . '/"]')->attr('class'));
        $this->assertStringNotContainsString('font-semibold', (string) $crawler->filter('nav a[href="/admin/journals/"]')->attr('class'));
        $this->assertSelectorTextContains('nav', 'Tesauro de categorias');
    }

    public function testTabsSearchAndFiltersNarrowTheList(): void
    {
        $client = $this->adminClient();
        $u = $this->uniq;
        $this->createRow('wos', "Categoria Ativa Wos {$u}", 'educacao', 'seed', 'active', 3);
        $this->createRow('doaj', "Categoria Ativa Doaj {$u}", 'direito', 'seed', 'active', 9);
        $this->createRow('livre', "Categoria Pendente {$u}", null, 'seed', 'pending', 2);
        $this->createRow('cnpq', "Categoria Ignorada {$u}", null, 'seed', 'ignored', 1);

        $client->request('GET', self::BASE . "/?q={$u}");
        $text = $client->getCrawler()->filter('tbody')->text();
        $this->assertStringContainsString("Categoria Ativa Wos {$u}", $text);
        $this->assertStringContainsString("Categoria Ativa Doaj {$u}", $text);
        $this->assertStringNotContainsString("Categoria Pendente {$u}", $text);
        $this->assertLessThan(strpos($text, "Categoria Ativa Wos {$u}"), strpos($text, "Categoria Ativa Doaj {$u}"), 'Mais revistas primeiro');

        $client->request('GET', self::BASE . "/?status=pending&q={$u}");
        $text = $client->getCrawler()->filter('tbody')->text();
        $this->assertStringContainsString("Categoria Pendente {$u}", $text);
        $this->assertStringContainsString('Sem decisão', $text);
        $this->assertStringNotContainsString('Categoria Ativa', $text);

        $client->request('GET', self::BASE . "/?status=ignored&q={$u}");
        $this->assertStringContainsString("Categoria Ignorada {$u}", $client->getCrawler()->filter('tbody')->text());
        $this->assertStringContainsString('Sem área (ignorado)', $client->getCrawler()->filter('tbody')->text());

        // Filtro por vocabulário e por área; a busca olha também a forma do tesauro (sem acento)
        $client->request('GET', self::BASE . "/?q={$u}&vocabulary=doaj");
        $text = $client->getCrawler()->filter('tbody')->text();
        $this->assertStringContainsString("Categoria Ativa Doaj {$u}", $text);
        $this->assertStringNotContainsString("Categoria Ativa Wos {$u}", $text);

        $client->request('GET', self::BASE . "/?q={$u}&area=educacao");
        $text = $client->getCrawler()->filter('tbody')->text();
        $this->assertStringContainsString("Categoria Ativa Wos {$u}", $text);
        $this->assertStringNotContainsString("Categoria Ativa Doaj {$u}", $text);

        $client->request('GET', self::BASE . '/?q=' . urlencode("CATEGÓRIA ATIVA DOAJ {$u}"));
        $this->assertStringContainsString("Categoria Ativa Doaj {$u}", $client->getCrawler()->filter('tbody')->text());

        // Valores inválidos de aba, vocabulário e área caem no padrão em vez de dar erro
        $client->request('GET', self::BASE . '/?status=x&vocabulary=x&area=x&page=-4');
        $this->assertResponseIsSuccessful();
    }

    public function testDefineAreaMakesTheLabelManualAndReplacesItsOtherRows(): void
    {
        $client = $this->adminClient();
        $u = $this->uniq;
        $label = "Categoria Definida {$u}";
        $first = $this->createRow('wos', $label, 'educacao', 'seed', 'active', 5);
        $this->createRow('wos', $label, 'direito', 'seed', 'active', 5);
        $this->createRow('wos', "Categoria Vizinha {$u}", 'artes', 'seed', 'active');

        $crawler = $client->request('GET', self::BASE . "/?q=Definida%20{$u}");
        $action = self::BASE . "/{$first}/area";
        $client->request('POST', $action, [
            '_token' => $this->tokenFor($crawler, $action),
            'area' => 'sociologia',
            'status' => 'active',
            'q' => "Definida {$u}",
            'vocabulary' => 'wos',
            'filter_area' => 'educacao',
        ]);
        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString("q=Definida%20{$u}", $location, 'Volta à busca de onde veio');
        $this->assertStringContainsString('vocabulary=wos', $location);
        $this->assertStringContainsString('area=educacao', $location);
        $client->followRedirect();

        $this->assertSelectorTextContains('body', 'agora vale «Sociologia»');
        $this->assertSelectorTextContains('body', 'app:journals:classify');
        $this->assertSame([['sociologia', 'manual', 'active']], $this->rowsOf('wos', $label));
        $this->assertSame(5, (int) $this->conn->fetchOne('SELECT occurrences FROM capes_area_variants WHERE normalized_name = ?', [self::norm($label)]), 'O número de revistas passa para a linha nova');
        $this->assertSame([['artes', 'seed', 'active']], $this->rowsOf('wos', "Categoria Vizinha {$u}"), 'Outro rótulo não é tocado');

        // Rótulo pendente: definir a área tira o rótulo da lista de pendentes
        $pending = $this->createRow('livre', "Categoria Pendente {$u}", null, 'seed', 'pending', 3);
        $crawler = $client->request('GET', self::BASE . "/?status=pending&q={$u}");
        $action = self::BASE . "/{$pending}/area";
        $client->request('POST', $action, ['_token' => $this->tokenFor($crawler, $action), 'area' => 'ciencias-humanas', 'status' => 'pending']);
        $client->followRedirect();
        $this->assertSame([['ciencias-humanas', 'manual', 'active']], $this->rowsOf('livre', "Categoria Pendente {$u}"));

        // Área fora da lista: nada muda
        $crawler = $client->request('GET', self::BASE . "/?q=Definida%20{$u}");
        $action = self::BASE . '/' . $this->idOf('wos', $label) . '/area';
        $client->request('POST', $action, ['_token' => $this->tokenFor($crawler, $action), 'area' => 'nao-existe']);
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Escolha uma das áreas da lista');
        $this->assertSame([['sociologia', 'manual', 'active']], $this->rowsOf('wos', $label));
    }

    public function testIgnoreMakesTheLabelManualAndIgnoredWithoutArea(): void
    {
        $client = $this->adminClient();
        $label = "Categoria Ignorar {$this->uniq}";
        $id = $this->createRow('wos', $label, 'educacao', 'seed', 'active', 4);

        $crawler = $client->request('GET', self::BASE . "/?q={$this->uniq}");
        $action = self::BASE . "/{$id}/ignore";
        $client->request('POST', $action, ['_token' => $this->tokenFor($crawler, $action), 'status' => 'active', 'q' => $this->uniq]);
        $this->assertResponseRedirects();
        $client->followRedirect();

        $this->assertSelectorTextContains('body', 'foi ignorado');
        $this->assertSelectorTextContains('body', 'app:journals:classify');
        $this->assertSame([[null, 'manual', 'ignored']], $this->rowsOf('wos', $label));
        $this->assertSame(4, (int) $this->conn->fetchOne('SELECT occurrences FROM capes_area_variants WHERE normalized_name = ?', [self::norm($label)]));

        // Aba Ignorados: o rótulo aparece, sem o botão Ignorar e com o de excluir (é manual)
        $crawler = $client->request('GET', self::BASE . "/?status=ignored&q={$this->uniq}");
        $this->assertSelectorTextContains('tbody', $label);
        $newId = $this->idOf('wos', $label);
        $this->assertCount(0, $crawler->filter(sprintf('form[action="%s/%d/ignore"]', self::BASE, $newId)));
        $this->assertCount(1, $crawler->filter(sprintf('form[action="%s/%d/delete"]', self::BASE, $newId)));
    }

    public function testNewSynonymCreatesAFreeManualRowAndRepeatingItWarns(): void
    {
        $client = $this->adminClient();
        $u = $this->uniq;
        $label = "Sinonimo Novo {$u}";
        // O rótulo estava pendente: o sinônimo o resolve
        $this->createRow('livre', $label, null, 'seed', 'pending', 6);

        $crawler = $client->request('GET', self::BASE . "/?q={$u}");
        $token = (string) $crawler->filter('form[action="' . self::BASE . '/synonym"] input[name="_token"]')->attr('value');
        $client->request('POST', self::BASE . '/synonym', ['_token' => $token, 'label' => "  {$label}  ", 'area' => 'direito', 'status' => 'active']);
        $this->assertResponseRedirects();
        $client->followRedirect();

        $this->assertSelectorTextContains('body', "Sinônimo «{$label}» cadastrado para «Direito»");
        $this->assertSelectorTextContains('body', 'app:journals:classify');
        $this->assertSame([['direito', 'manual', 'active']], $this->rowsOf('livre', $label));
        $this->assertSame(6, (int) $this->conn->fetchOne('SELECT occurrences FROM capes_area_variants WHERE normalized_name = ?', [self::norm($label)]));

        // O mesmo sinônimo de novo: aviso, nada duplica; em outra área: uma segunda linha
        $crawler = $client->request('GET', self::BASE . "/?q={$u}");
        $token = (string) $crawler->filter('form[action="' . self::BASE . '/synonym"] input[name="_token"]')->attr('value');
        $client->request('POST', self::BASE . '/synonym', ['_token' => $token, 'label' => strtoupper($label), 'area' => 'direito']);
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'já existe');
        $this->assertCount(1, $this->rowsOf('livre', $label));

        $client->request('POST', self::BASE . '/synonym', ['_token' => $token, 'label' => $label, 'area' => 'economia']);
        $client->followRedirect();
        $this->assertSame([['direito', 'manual', 'active'], ['economia', 'manual', 'active']], $this->rowsOf('livre', $label));

        // Rótulo sem letras, vazio ou área inválida: nada é gravado
        foreach ([['label' => '12345', 'area' => 'direito'], ['label' => '   ', 'area' => 'direito'], ['label' => "Sem Area {$u}", 'area' => 'nao-existe'], ['label' => "Sem Area {$u}", 'area' => '']] as $post) {
            $client->request('POST', self::BASE . '/synonym', ['_token' => $token] + $post);
            $client->followRedirect();
            $this->assertSelectorExists('.bg-rose-100');
        }
        $this->assertSame([], $this->rowsOf('livre', "Sem Area {$u}"));
        $this->assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM capes_area_variants WHERE vocabulary = 'livre' AND normalized_name = '12345'"));
    }

    public function testDeleteOnlyRemovesManualRows(): void
    {
        $client = $this->adminClient();
        $u = $this->uniq;
        $seed = $this->createRow('wos', "Categoria Semente {$u}", 'educacao', 'seed', 'active');
        $stale = $this->createRow('wos', "Categoria Desatualizada {$u}", 'artes', 'manual', 'active');
        $manual = $this->createRow('wos', "Categoria Manual {$u}", 'direito', 'manual', 'active');

        $crawler = $client->request('GET', self::BASE . "/?q={$u}");
        $this->assertCount(0, $crawler->filter(sprintf('form[action="%s/%d/delete"]', self::BASE, $seed)), 'A semente não tem botão de excluir');
        $this->assertCount(1, $crawler->filter(sprintf('form[action="%s/%d/delete"]', self::BASE, $stale)));
        $this->assertCount(1, $crawler->filter(sprintf('form[action="%s/%d/delete"]', self::BASE, $manual)));

        // Pedido de uma tela desatualizada, forjado com o token certo: a linha deixou de ser manual e não é excluída
        $staleAction = self::BASE . "/{$stale}/delete";
        $staleToken = $this->tokenFor($crawler, $staleAction);
        $this->conn->executeStatement("UPDATE capes_area_variants SET origin = 'seed' WHERE id = ?", [$stale]);
        $client->request('POST', $staleAction, ['_token' => $staleToken]);
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Só correções manuais podem ser excluídas');
        $this->assertSame([['artes', 'seed', 'active']], $this->rowsOf('wos', "Categoria Desatualizada {$u}"));
        $this->assertSame([['educacao', 'seed', 'active']], $this->rowsOf('wos', "Categoria Semente {$u}"));

        $action = self::BASE . "/{$manual}/delete";
        $client->request('POST', $action, ['_token' => $this->tokenFor($crawler, $action), 'status' => 'active', 'q' => $u]);
        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Correção do rótulo');
        $this->assertSelectorTextContains('body', 'volta na próxima execução');
        $this->assertSame([], $this->rowsOf('wos', "Categoria Manual {$u}"));
    }

    public function testInvalidTokenChangesNothing(): void
    {
        $client = $this->adminClient();
        $u = $this->uniq;
        $seed = $this->createRow('wos', "Categoria Protegida {$u}", 'educacao', 'seed', 'active', 2);
        $manual = $this->createRow('wos', "Categoria Manual Protegida {$u}", 'direito', 'manual', 'active');

        // O token da ação "Ignorar" não serve para "Definir": cada ação tem o seu
        $crawler = $client->request('GET', self::BASE . "/?q={$u}");
        $ignoreToken = $this->tokenFor($crawler, self::BASE . "/{$seed}/ignore");

        $posts = [
            [self::BASE . "/{$seed}/area", ['_token' => 'token-invalido', 'area' => 'direito']],
            [self::BASE . "/{$seed}/area", ['_token' => $ignoreToken, 'area' => 'direito']],
            [self::BASE . "/{$seed}/ignore", ['_token' => 'token-invalido']],
            [self::BASE . "/{$manual}/delete", ['_token' => 'token-invalido']],
            [self::BASE . '/synonym', ['_token' => 'token-invalido', 'label' => "Sinonimo Invalido {$u}", 'area' => 'direito']],
            [self::BASE . "/{$seed}/area", ['area' => 'direito']],
        ];
        foreach ($posts as [$url, $data]) {
            $client->request('POST', $url, $data);
            $this->assertResponseRedirects();
            $client->followRedirect();
            $this->assertSelectorTextContains('body', 'Token de segurança inválido');
        }

        $this->assertSame([['educacao', 'seed', 'active']], $this->rowsOf('wos', "Categoria Protegida {$u}"));
        $this->assertSame([['direito', 'manual', 'active']], $this->rowsOf('wos', "Categoria Manual Protegida {$u}"));
        $this->assertSame([], $this->rowsOf('livre', "Sinonimo Invalido {$u}"));
        $this->assertSame(2, (int) $this->conn->fetchOne('SELECT occurrences FROM capes_area_variants WHERE id = ?', [$seed]));
    }

    public function testExportCsvHasBomHeaderAndOneLinePerRow(): void
    {
        $client = $this->adminClient();
        $u = $this->uniq;
        $this->createRow('wos', "Categoria Exportada {$u}", 'educacao', 'seed', 'active', 12);
        $this->createRow('livre', "Categoria Exportada Pendente {$u}", null, 'seed', 'pending', 1);
        $this->createRow('doaj', "=HIPERLINK(\"x\") {$u}", 'ciencias-humanas', 'manual', 'active', 0);

        $client->request('GET', self::BASE . '/export');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('tesauro_categorias_revistas.csv', (string) $response->headers->get('Content-Disposition'));
        $content = (string) $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'UTF-8 com BOM');

        $lines = preg_split('/\r?\n/', substr($content, 3), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertSame('rótulo;vocabulário;slug;área;"grande área";status;origem;revistas', $lines[0]);
        $byLabel = [];
        foreach ($lines as $line) {
            $fields = str_getcsv($line, ';', '"', '\\');
            $byLabel[$fields[0]] = $fields;
        }
        $this->assertSame(["Categoria Exportada {$u}", 'wos', 'educacao', 'Educação', 'Ciências Humanas', 'active', 'seed', '12'], $byLabel["Categoria Exportada {$u}"]);
        $this->assertSame(["Categoria Exportada Pendente {$u}", 'livre', '', '', '', 'pending', 'seed', '1'], $byLabel["Categoria Exportada Pendente {$u}"]);
        $this->assertSame(["'=HIPERLINK(\"x\") {$u}", 'doaj', 'ciencias-humanas', 'Ciências Humanas', 'Ciências Humanas', 'active', 'manual', '0'], $byLabel["'=HIPERLINK(\"x\") {$u}"], 'Texto que o Excel leria como fórmula leva um apóstrofo');
    }

    public function testPaginationKeepsTheFiltersAndClampsThePage(): void
    {
        $client = $this->adminClient();
        $u = $this->uniq;

        // 51 rótulos: duas páginas, de 50 e de 1
        for ($i = 1; $i <= 51; ++$i) {
            $this->createRow('wos', sprintf('Categoria Paginada %s %02d', $u, $i), 'educacao');
        }

        $crawler = $client->request('GET', self::BASE . "/?q={$u}&vocabulary=wos&area=educacao");
        $this->assertSelectorTextContains('body', 'Página 1 de 2 (51 rótulos)');
        $this->assertCount(50, $crawler->filter('tbody tr'));
        $next = $crawler->filter('a:contains("Próxima")');
        $this->assertCount(1, $next);
        $this->assertStringContainsString("q={$u}", (string) $next->attr('href'), 'A paginação mantém a busca');
        $this->assertStringContainsString('vocabulary=wos', (string) $next->attr('href'));
        $this->assertStringContainsString('area=educacao', (string) $next->attr('href'));
        $this->assertStringContainsString('page=2', (string) $next->attr('href'));

        // Página além do fim cai na última, com a busca mantida
        $crawler = $client->request('GET', self::BASE . "/?q={$u}&page=99");
        $this->assertSelectorTextContains('body', 'Página 2 de 2 (51 rótulos)');
        $this->assertCount(1, $crawler->filter('tbody tr'));
        $this->assertCount(1, $crawler->filter('input[type="text"][name="q"][value="' . $u . '"]'));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Montagem
    // ------------------------------------------------------------------------------------------------------------

    private function anonymousClient(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->taxonomy = static::getContainer()->get(CapesTaxonomy::class);
        $this->uniq = 'qz' . bin2hex(random_bytes(4));

        return $client;
    }

    /**
     * Cliente logado como o admin de teste (criado se não existir), como em AdminJournalNameTest.
     */
    private function adminClient(): KernelBrowser
    {
        $client = $this->anonymousClient();
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);

        if (!$user) {
            $user = new User();
            $user->setUsername('admin');
            $user->setRoles(['ROLE_ADMIN']);
            $hasher = static::getContainer()->get('security.user_password_hasher');
            $user->setPassword($hasher->hashPassword($user, 'admin123'));
            $this->em->persist($user);
            $this->em->flush();
        }

        $client->loginUser($user);

        return $client;
    }

    /**
     * Token do formulário de uma ação, lido da própria tela (o token fica ligado à sessão do cliente).
     */
    private function tokenFor(Crawler $crawler, string $action): string
    {
        $field = $crawler->filter(sprintf('form[action="%s"] input[name="_token"]', $action));
        $this->assertCount(1, $field, "Formulário de {$action} não aparece na tela");

        return (string) $field->attr('value');
    }

    private function createRow(string $vocabulary, string $label, ?string $slug, string $origin = 'seed', string $status = 'active', int $occurrences = 0): int
    {
        $this->conn->executeStatement(
            'INSERT INTO capes_area_variants (area_id, vocabulary, variation_name, normalized_name, origin, status, occurrences, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$slug === null ? null : $this->taxonomy->bySlug($slug)['id'], $vocabulary, $label, self::norm($label), $origin, $status, $occurrences]
        );

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Linhas de um rótulo: [slug, origem, status], por id.
     *
     * @return list<array{0: string|null, 1: string, 2: string}>
     */
    private function rowsOf(string $vocabulary, string $label): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT a.slug, v.origin, v.status FROM capes_area_variants v LEFT JOIN capes_areas a ON a.id = v.area_id WHERE v.vocabulary = ? AND v.normalized_name = ? ORDER BY v.id',
            [$vocabulary, self::norm($label)]
        );

        return array_map(static fn (array $row): array => [$row['slug'], $row['origin'], $row['status']], $rows);
    }

    private function idOf(string $vocabulary, string $label): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT MIN(id) FROM capes_area_variants WHERE vocabulary = ? AND normalized_name = ?',
            [$vocabulary, self::norm($label)]
        );
    }

    private static function norm(string $label): string
    {
        return StringNormalizer::normalizeString($label, true);
    }
}
