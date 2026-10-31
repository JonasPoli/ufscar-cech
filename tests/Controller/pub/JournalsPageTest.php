<?php

declare(strict_types=1);

namespace App\Tests\Controller\pub;

use App\Service\JournalsByDatabaseService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Página pública /periodicos (docs/evolutions/periodicos-por-base.md, A1).
 *
 * Os testes de rota e de texto não dependem de dados: as cinco seções aparecem sempre (com o estado vazio quando a base
 * não tem periódicos). Os que precisam de periódicos, bases, docentes e artigos os criam dentro de uma transação que é
 * revertida no tearDown, de modo que nada fica gravado em `cech_test`; as asserções olham só para as linhas criadas
 * aqui (nomes com sufixo único), porque o banco de testes é compartilhado.
 *
 * Todas as requisições levam um cookie de sessão: o `PageCacheService` não lê nem grava páginas de quem tem sessão, e
 * assim o teste vê a página renderizada com os dados dele sem deixar uma página de teste em var/cache/public_pages
 * (a pasta é a mesma do servidor local).
 */
class JournalsPageTest extends WebTestCase
{
    private const BASES = ['scopus', 'pubmed', 'scielo', 'wos', 'latindex'];

    private KernelBrowser $client;
    private ?Connection $conn = null;
    private string $uniq;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->getCookieJar()->set(new Cookie('TESTE_SESSAO', '1'));
        $this->uniq = 'jp' . random_int(100000, 999999);
    }

    protected function tearDown(): void
    {
        if ($this->conn !== null && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------------------
    // Rotas, textos e menu (sem dados)
    // ------------------------------------------------------------------------------------------------------------

    public function testIndexShowsHeadingAndTheFiveBasesInFixedOrder(): void
    {
        $crawler = $this->get('/periodicos');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextSame('h1', 'Periódicos em que o CECH publica');
        $this->assertPageTitleSame('Periódicos por base de indexação | Portal CECH UFSCar');
        $this->assertSame(
            'Periódicos científicos em que os docentes do Centro de Educação e Ciências Humanas da UFSCar publicam artigos, agrupados pela base em que estão indexados (Scopus, PubMed, SciELO, Web of Science e Latindex) e pela área de conhecimento.',
            $crawler->filter('meta[name="description"]')->attr('content')
        );

        $html = (string) $this->client->getResponse()->getContent();
        $positions = array_map(static fn (string $base) => strpos($html, sprintf('data-base="%s"', $base)), self::BASES);
        foreach ($positions as $i => $position) {
            $this->assertNotFalse($position, 'Falta a seção da base ' . self::BASES[$i]);
            if ($i > 0) {
                $this->assertGreaterThan($positions[$i - 1], $position, 'As bases devem aparecer na ordem fixa.');
            }
        }
        $this->assertCount(5, $crawler->filter('section[data-base]'));

        $this->assertCount(1, $crawler->filter('section[data-general]'));
        $this->assertLessThan($positions[0], strpos($html, 'data-general'), 'As bases somadas vêm antes das cinco bases.');
    }

    public function testPublicTextsAreTheOnesFromTheDocument(): void
    {
        $crawler = $this->get('/periodicos');
        $text = $crawler->filter('main')->text();

        $this->assertStringContainsString('Produção científica', $text);
        $this->assertStringContainsString(
            'Periódicos com artigos de docentes do CECH registrados no Currículo Lattes, agrupados pela base em que estão indexados e pela área de avaliação da CAPES.',
            $text
        );
        $this->assertStringContainsString('Como esta página é feita', $text);
        $this->assertStringContainsString('(coautorias entre docentes do Centro contam uma vez)', $text);
        $this->assertStringContainsString('A ordem é apenas decrescente de quantidade de artigos, com empates em ordem alfabética, e não representa avaliação de qualidade, prestígio ou impacto dos periódicos.', $text);
        $this->assertStringContainsString('pode conter omissões; a área de conhecimento é atribuída automaticamente a partir das categorias informadas pelas bases, segundo a taxonomia da CAPES, e pode conter imprecisões', $text);
        $this->assertStringContainsString('Metodologia e fontes', $text);
        $this->assertStringContainsString('Fonte dos artigos: Currículos Lattes dos docentes do CECH importados neste portal; são considerados os itens do tipo "artigo em periódico".', $text);
        $this->assertStringContainsString('taxonomia de áreas de avaliação da CAPES (9 grandes áreas, 50 áreas)', $text);
        $this->assertMatchesRegularExpression('#Correções: envie para o contato do portal\. Dados atualizados em \d{2}/\d{2}/\d{4}\.#', $text);
        $this->assertStringContainsString('Todas as bases somadas: reúne os periódicos presentes em pelo menos uma das cinco bases. Cada periódico aparece uma vez, e os artigos não são contados de novo por ele estar em mais de uma base.', $text);
        $this->assertSelectorExists('#metodologia');
    }

    /**
     * @dataProvider publicUrls
     */
    public function testPublicMarkupHasNoRankingVocabulary(string $url): void
    {
        $crawler = $this->get($url);
        $this->assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsStringIgnoringCase('ranking', $html);
        $this->assertDoesNotMatchRegularExpression('/\brank\b/i', $html);

        // Dentro do <main> (sem os nomes de classe utilitária, como top-0 do layout) valem também as demais palavras
        // proibidas de A1.1: classes próprias, data-*, ids e textos.
        $main = (string) preg_replace('/\sclass="[^"]*"/', '', $crawler->filter('main')->outerHtml());
        foreach (['/\btop\b/i', '/melhores/i', '/posi(ç|c)ão/iu', '/classificação de periódicos/iu', '/#1\b/', '/loop\.index/'] as $forbidden) {
            $this->assertDoesNotMatchRegularExpression($forbidden, $main);
        }

        // Nenhuma lista numerada: <ol> com list-none e sem o atributo value nos itens
        foreach ($crawler->filter('main ol') as $ol) {
            $this->assertStringContainsString('list-none', (string) $ol->getAttribute('class'));
        }
        $this->assertDoesNotMatchRegularExpression('/<li[^>]*\svalue=/', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicUrls(): iterable
    {
        yield 'visão geral' => ['/periodicos'];
        yield 'visão geral com área' => ['/periodicos?area=educacao'];
        yield 'base' => ['/periodicos/scopus'];
        yield 'base com grande área' => ['/periodicos/wos?area=ciencias-humanas'];
        yield 'bases somadas' => ['/periodicos/geral'];
        yield 'bases somadas com área' => ['/periodicos/geral?area=educacao'];
    }

    public function testAreaFilterIsAcceptedForEvaluationAreaAndMajorArea(): void
    {
        $this->get('/periodicos?area=educacao');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select[name="area"] option[value="educacao"][selected]');
        $this->assertSelectorNotExists('select[name="area"] option[value=""][selected]');

        $this->get('/periodicos?area=ciencias-humanas');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select[name="area"] option[value="ciencias-humanas"][selected]');
    }

    public function testFilterFormIsAPlainGetFormWithGroupedOptionsAndAButton(): void
    {
        $crawler = $this->get('/periodicos');

        $form = $crawler->filter('main form[method="GET"]');
        $this->assertCount(1, $form);
        $this->assertSame('/periodicos', $form->attr('action'));
        $this->assertCount(1, $form->filter('select[name="area"]'));
        $this->assertSame('Filtrar', trim($form->filter('button[type="submit"]')->text()));
        $this->assertSame('Todas as áreas', trim($form->filter('select option[value=""]')->text()));
        // Cada grande área é um optgroup cuja primeira opção é a própria grande área
        $group = $form->filter('select optgroup[label="Ciências Humanas"]');
        $this->assertCount(1, $group);
        $this->assertSame('ciencias-humanas', $group->filter('option')->first()->attr('value'));
        $this->assertSame('Ciências Humanas — todas', trim($group->filter('option')->first()->text()));
        $this->assertSame('educacao', $group->filter('option')->eq(4)->attr('value'));
        $this->assertCount(9, $form->filter('select optgroup'));

        // O carregamento fica pronto e escondido; o select envia pelo mesmo caminho do botão, que dispara o bloqueio
        $this->assertStringContainsString('hidden', $form->filter('button[type="submit"] [data-filter-spinner]')->attr('class'));
        $this->assertStringContainsString('requestSubmit', $form->filter('select[name="area"]')->attr('onchange'));
    }

    /**
     * @dataProvider invalidAreas
     */
    public function testInvalidAreaIsNotFound(string $query): void
    {
        $this->get('/periodicos?' . $query);
        $this->assertResponseStatusCodeSame(404);

        $this->get('/periodicos/scopus?' . $query);
        $this->assertResponseStatusCodeSame(404);

        $this->get('/periodicos/geral?' . $query);
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAreas(): iterable
    {
        yield 'slug que não existe' => ['area=nao-existe'];
        yield 'maiúsculas' => ['area=Educacao'];
        yield 'caractere inválido' => ['area=educacao%20'];
        yield 'barra' => ['area=a%2Fb'];
        yield 'longo demais' => ['area=' . str_repeat('a', 81)];
        yield 'lista' => ['area[]=educacao'];
    }

    /**
     * O cache de páginas grava um arquivo por URL: parâmetros que não mudam o conteúdo redirecionam para a URL canônica.
     *
     * @dataProvider nonCanonicalUrlProvider
     */
    public function testNonCanonicalUrlsRedirectToTheCanonicalOne(string $url, string $expected): void
    {
        $this->get($url);
        $this->assertResponseRedirects($expected, 301);
    }

    public static function nonCanonicalUrlProvider(): array
    {
        return [
            'área vazia' => ['/periodicos?area=', '/periodicos'],
            'parâmetros desconhecidos' => ['/periodicos?foo=bar&page=9', '/periodicos'],
            'área e parâmetro desconhecido' => ['/periodicos?area=educacao&utm_source=x', '/periodicos?area=educacao'],
            'página de base' => ['/periodicos/scielo?foo=bar', '/periodicos/scielo'],
            'bases somadas' => ['/periodicos/geral?foo=bar', '/periodicos/geral'],
            'bases somadas com área' => ['/periodicos/geral?area=educacao&utm_source=x', '/periodicos/geral?area=educacao'],
        ];
    }

    public function testCanonicalUrlHasNoSelectedAreaWithoutFilter(): void
    {
        $this->get('/periodicos');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('select[name="area"] option[selected]');
    }

    public function testDatabasePageShowsOnlyThatBaseWithTheFullListLinkBack(): void
    {
        $crawler = $this->get('/periodicos/scopus');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Periódicos indexados em ');
        $this->assertSelectorTextContains('h1', ' com artigos do CECH');
        $this->assertCount(1, $crawler->filter('section[data-base]'));
        $this->assertSelectorExists('section[data-base="scopus"]');
        $this->assertSelectorNotExists('section[data-base="pubmed"]');
        $this->assertSame('/periodicos', $crawler->selectLink('Todas as bases')->attr('href'));
        $this->assertSame('/periodicos/scopus', $crawler->filter('main form')->attr('action'));
        $this->assertSelectorNotExists('main a[href^="/periodicos/scopus"]');

        $crawler = $this->get('/periodicos/scopus?area=educacao');
        $this->assertSame('/periodicos?area=educacao', $crawler->selectLink('Todas as bases')->attr('href'));
    }

    public function testUnknownDatabaseIsNotFound(): void
    {
        foreach (['/periodicos/foo', '/periodicos/doaj', '/periodicos/Scopus', '/periodicos/scopus/extra'] as $url) {
            $this->get($url);
            $this->assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testRoutesAreRegisteredAndTheBaseRequirementFollowsTheServiceConstant(): void
    {
        $routes = static::getContainer()->get('router')->getRouteCollection();

        $index = $routes->get('app_pub_journals_index');
        $database = $routes->get('app_pub_journals_database');
        $this->assertNotNull($index);
        $this->assertNotNull($database);
        $this->assertSame('/periodicos', $index->getPath());
        $this->assertSame('/periodicos/{base}', $database->getPath());
        $this->assertSame(implode('|', JournalsByDatabaseService::BASES), $database->getRequirement('base'));
        $this->assertSame(self::BASES, JournalsByDatabaseService::BASES);
        $this->assertNotContains(JournalsByDatabaseService::GENERAL, JournalsByDatabaseService::BASES);
        $this->assertSame('/periodicos/geral', $routes->get('app_pub_journals_general')->getPath());
        $this->assertSame(25, JournalsByDatabaseService::PREVIEW_LIMIT);
    }

    public function testSitemapListsTheJournalPages(): void
    {
        $this->get('/sitemap.xml');

        $this->assertResponseIsSuccessful();
        $xml = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('/periodicos</loc>', $xml);
        $this->assertStringContainsString('/periodicos/geral</loc>', $xml);
        foreach (self::BASES as $base) {
            $this->assertStringContainsString('/periodicos/' . $base . '</loc>', $xml);
        }
    }

    public function testMenuHasJournalsLinkBetweenIndicatorsAndDepartmentsInTheThreePlaces(): void
    {
        $crawler = $this->get('/');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Periódicos', (string) $this->client->getResponse()->getContent());

        // Barra de navegação, menu móvel e rodapé: o link entre "Indicadores" e "Departamentos"
        foreach (['header nav' => 'a', '#mobile-menu-drawer' => 'a[href]', 'footer' => 'a'] as $container => $anchor) {
            $labels = $crawler->filter("$container $anchor")->each(static fn (Crawler $a): string => trim(preg_replace('/\s+/', ' ', $a->text())));
            $journals = array_search('Periódicos', array_map(static fn (string $l): string => preg_replace('/ Onde os docentes publicam$/', '', $l), $labels), true);
            $this->assertNotFalse($journals, "Falta o link Periódicos em $container");
            $this->assertStringStartsWith('Indicadores', $labels[$journals - 1], "Antes de Periódicos deve vir Indicadores em $container");
            $this->assertStringStartsWith('Departamentos', $labels[$journals + 1], "Depois de Periódicos deve vir Departamentos em $container");
        }
        $this->assertSelectorTextContains('#mobile-menu-drawer a[href="/periodicos"]', 'Onde os docentes publicam');
        $this->assertSelectorExists('#mobile-menu-drawer a[href="/periodicos"] sl-icon[name="journal-text"]');
        // Fora da página de periódicos, o item do menu móvel não fica em destaque
        $this->assertSelectorNotExists('#mobile-menu-drawer a[href="/periodicos"].bg-amber-50');

        $this->get('/periodicos');
        $this->assertSelectorExists('#mobile-menu-drawer a[href="/periodicos"].bg-amber-50');
        $this->get('/periodicos/wos');
        $this->assertSelectorExists('#mobile-menu-drawer a[href="/periodicos"].bg-amber-50');
    }

    public function testEmptySectionsShowTheEmptyStateForAnAreaWithoutJournals(): void
    {
        $this->beginSeed();
        $areaId = (int) $this->conn->fetchOne("SELECT id FROM capes_areas WHERE slug = 'servico-social'");
        if ((int) $this->conn->fetchOne('SELECT COUNT(*) FROM journal_areas WHERE area_id = ?', [$areaId]) > 0) {
            $this->markTestSkipped('O banco de testes já tem periódicos em Serviço Social.');
        }

        $crawler = $this->get('/periodicos?area=servico-social');

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            5,
            substr_count((string) $this->client->getResponse()->getContent(), 'Nenhum periódico desta base com artigos na área selecionada.')
        );
        $this->assertCount(0, $crawler->filter('li.journal-item'));
        $this->assertCount(0, $crawler->filter('main ol'));
        $this->assertCount(5, $crawler->filter('section[data-base]'));
        $this->assertStringContainsString('Nenhum periódico das bases com artigos na área selecionada.', $crawler->filter('section[data-general]')->text());
    }

    // ------------------------------------------------------------------------------------------------------------
    // Contagens (com dados criados no teste)
    // ------------------------------------------------------------------------------------------------------------

    public function testInternalCoauthorshipCountsOnceAndResearchersCountTwo(): void
    {
        $this->beginSeed();
        $journal = $this->insertJournal("Revista Coautoria {$this->uniq}");
        $this->linkToBase($journal, 'scopus');
        $ana = $this->insertResearcher("Ana {$this->uniq}");
        $bruno = $this->insertResearcher("Bruno {$this->uniq}");

        // O mesmo artigo registrado nos dois currículos, com o DOI em caixa e espaços diferentes
        $doi = "10.5555/coautoria-{$this->uniq}";
        $this->insertArticle($ana, $journal, 'Artigo em coautoria', 2024, $doi);
        $this->insertArticle($bruno, $journal, 'Artigo em coautoria (versão do outro currículo)', 2024, '  ' . strtoupper($doi) . ' ');

        $row = $this->rowOf($this->service()->getDatabaseList('scopus')['rows'], $journal);
        $this->assertSame(1, $row['n_articles'], 'A coautoria entre docentes do Centro conta um artigo só.');
        $this->assertSame(2, $row['n_researchers'], 'Os dois docentes contam.');

        $crawler = $this->get('/periodicos/scopus');
        $item = $this->itemOf($crawler, "Revista Coautoria {$this->uniq}");
        $this->assertStringContainsString('1 artigo', $item->text());
        $this->assertStringNotContainsString('1 artigos', $item->text());
        $this->assertStringContainsString('2 docentes', $item->text());
        $this->assertSame('1 artigo', $item->filter('[role="img"]')->attr('aria-label'));
    }

    public function testArticleKeyIsTheDoiOtherwiseYearAndTitleAndOnlyArticlesCount(): void
    {
        $this->beginSeed();
        $journal = $this->insertJournal("Revista Chave {$this->uniq}");
        $this->linkToBase($journal, 'scopus');
        $r1 = $this->insertResearcher("R1 {$this->uniq}");
        $r2 = $this->insertResearcher("R2 {$this->uniq}");
        $r3 = $this->insertResearcher("R3 {$this->uniq}");

        $this->insertArticle($r1, $journal, 'Com DOI', 2024, "10.5555/a-{$this->uniq}");
        $this->insertArticle($r2, $journal, 'Com DOI, título diferente', 2023, "10.5555/A-{$this->uniq}");       // mesmo DOI: o mesmo artigo
        $this->insertArticle($r1, $journal, 'Artigo Sem DOI', 2023, null);
        $this->insertArticle($r2, $journal, '  artigo sem doi  ', 2023, '');                                       // mesmo ano e título (sem caixa nem espaços): o mesmo
        $this->insertArticle($r1, $journal, 'Artigo Sem DOI', 2022, null);                                          // outro ano: outro artigo
        $this->insertArticle($r2, $journal, 'Outro artigo', 2021, '   ');                                           // DOI em branco vale como sem DOI
        $this->insertArticle($r3, $journal, 'Capítulo que não é artigo', 2024, null, 'CAPITULO');                  // outro tipo: não conta

        $row = $this->rowOf($this->service()->getDatabaseList('scopus')['rows'], $journal);
        $this->assertSame(4, $row['n_articles']);
        $this->assertSame(2, $row['n_researchers'], 'Só os docentes dos artigos contam, e cada um uma vez.');
        $this->assertSame(2021, $row['year_min']);
        $this->assertSame(2024, $row['year_max']);
    }

    public function testFamilyMembersAreListedOnceUnderTheCanonicalRowAndCountTogether(): void
    {
        $this->beginSeed();
        $canonical = $this->insertJournal("Revista Canônica {$this->uniq}");
        $member = $this->insertJournal("Revista Membro {$this->uniq}", $canonical);
        // Só o membro está na lista da base: a família inteira entra
        $this->linkToBase($member, 'scopus');
        $r1 = $this->insertResearcher("R1 {$this->uniq}");
        $r2 = $this->insertResearcher("R2 {$this->uniq}");
        $doi = "10.5555/familia-{$this->uniq}";
        $this->insertArticle($r1, $member, 'Artigo da família', 2024, $doi);
        $this->insertArticle($r2, $canonical, 'Artigo da família (outro currículo)', 2024, $doi);
        $this->insertArticle($r1, $canonical, 'Outro artigo da família', 2022, null);

        $rows = $this->service()->getDatabaseList('scopus')['rows'];
        $row = $this->rowOf($rows, $canonical);
        $this->assertSame(2, $row['n_articles'], 'O mesmo DOI em duas linhas da família conta uma vez.');
        $this->assertSame(2, $row['n_researchers']);
        $this->assertSame("Revista Canônica {$this->uniq}", $row['name']);
        $this->assertSame([], array_filter($rows, static fn (array $r): bool => $r['id'] === $member), 'O membro não aparece sozinho.');

        $html = (string) $this->get('/periodicos/scopus')->filter('main')->html();
        $this->assertStringContainsString("Revista Canônica {$this->uniq}", $html);
        $this->assertStringNotContainsString("Revista Membro {$this->uniq}", $html);
    }

    public function testListIsOrderedByArticlesThenByDisplayNameWithoutNumbering(): void
    {
        $this->beginSeed();
        $u = $this->uniq;
        $journals = [
            'beta' => [$this->insertJournal("Revista Beta $u"), 5],
            'alfa' => [$this->insertJournal("Revista Alfa $u"), 3],
            'zeta' => [$this->insertJournal("Revista Zeta $u"), 3],
            // O título bruto ordenaria por último; o nome exibido o coloca entre Alfa e Zeta
            'exibido' => [$this->insertJournal("ZZZ título bruto $u", null, null, null, "Revista Beta-Exibido $u"), 3],
        ];
        $researcher = $this->insertResearcher("Docente {$this->uniq}");
        foreach ($journals as [$id, $articles]) {
            $this->linkToBase($id, 'scopus');
            for ($i = 1; $i <= $articles; $i++) {
                $this->insertArticle($researcher, $id, "Artigo $i de $id", 2000 + $i, null);
            }
        }

        $ids = array_column(array_filter($this->service()->getDatabaseList('scopus')['rows'], fn (array $r): bool => in_array($r['id'], array_column($journals, 0), true)), 'id');
        $this->assertSame(
            [$journals['beta'][0], $journals['alfa'][0], $journals['exibido'][0], $journals['zeta'][0]],
            array_values($ids),
            'Mais artigos primeiro; empate pela ordem alfabética do nome exibido.'
        );

        $crawler = $this->get('/periodicos/scopus');
        $html = (string) $this->client->getResponse()->getContent();
        $positions = [];
        foreach (["Revista Beta $u", "Revista Alfa $u", "Revista Beta-Exibido $u", "Revista Zeta $u"] as $name) {
            $this->assertNotFalse($position = strpos($html, '>' . $name . '<'), "A página deve mostrar $name");
            $positions[] = $position;
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertStringNotContainsString("ZZZ título bruto $u", $html);
        $this->assertCount(1, $crawler->filter('section[data-base="scopus"] ol.journal-list.list-none'));
        $this->assertDoesNotMatchRegularExpression('/<li[^>]*\svalue=/', $html);
    }

    public function testBarWidthIsProportionalToTheLargestValueOfTheSection(): void
    {
        $this->beginSeed();
        $researcher = $this->insertResearcher("Docente {$this->uniq}");
        $large = $this->insertJournal("Revista Grande {$this->uniq}");
        $small = $this->insertJournal("Revista Pequena {$this->uniq}");
        foreach ([$large, $small] as $id) {
            $this->linkToBase($id, 'scopus');
        }
        for ($i = 1; $i <= 400; $i++) {
            $this->insertArticle($researcher, $large, "Artigo grande $i", 1900 + ($i % 120), null);
        }
        $this->insertArticle($researcher, $small, 'Artigo pequeno', 2024, null);

        $rows = $this->service()->getDatabaseList('scopus')['rows'];
        $largest = (int) max(array_column($rows, 'n_articles'));
        $this->assertSame($largest, $rows[0]['n_articles']);
        $this->assertSame(100.0, $rows[0]['pct']);
        $smallRow = $this->rowOf($rows, $small);
        $this->assertEqualsWithDelta(max(1.0, round(1 / $largest * 100, 1)), $smallRow['pct'], 0.001);

        $crawler = $this->get('/periodicos/scopus');
        $this->assertStringContainsString('style="width: 100%"', $crawler->filter('li.journal-item')->first()->html());
        $this->assertSame(
            'width: ' . (string) $smallRow['pct'] . '%',
            $this->itemOf($crawler, "Revista Pequena {$this->uniq}")->filter('[role="img"]')->attr('style')
        );
    }

    public function testIssnsAreValidatedFormattedAndDeduplicated(): void
    {
        $this->beginSeed();
        // 0103-863X e 1678-4626 são ISSNs válidos; 1234-5678 tem dígito verificador errado e não é mostrado
        $journal = $this->insertJournal("Revista Issn {$this->uniq}", null, '1678-4626', '1234-5678', null, '0103-863x');
        $this->linkToBase($journal, 'scopus');
        $this->insertArticle($this->insertResearcher("Docente {$this->uniq}"), $journal, 'Artigo', 2024, null);
        $semIssn = $this->insertJournal("Revista Sem Issn {$this->uniq}");
        $this->linkToBase($semIssn, 'scopus');
        $this->insertArticle($this->insertResearcher("Docente 2 {$this->uniq}"), $semIssn, 'Artigo', 2024, null);

        $this->assertSame(['0103-863X', '1678-4626'], $this->rowOf($this->service()->getDatabaseList('scopus')['rows'], $journal)['issns']);
        $this->assertSame([], $this->rowOf($this->service()->getDatabaseList('scopus')['rows'], $semIssn)['issns']);

        $crawler = $this->get('/periodicos/scopus');
        $this->assertStringContainsString('ISSN 0103-863X, 1678-4626', $this->itemOf($crawler, "Revista Issn {$this->uniq}")->text());
        $this->assertStringNotContainsString('ISSN', $this->itemOf($crawler, "Revista Sem Issn {$this->uniq}")->text());
        $this->assertStringNotContainsString('1234-5678', (string) $this->client->getResponse()->getContent());
    }

    public function testQualisSealShowsTheStratumOfTheArticlesWithTheSystemIconAndColor(): void
    {
        $this->beginSeed();
        $u = $this->uniq;
        $researcher = $this->insertResearcher("Docente $u");

        $canonical = $this->insertJournal("Revista Estrato $u");
        $online = $this->insertJournal("Revista Estrato Online $u", $canonical);
        $this->linkToBase($canonical, 'scopus');
        // Dois artigos A3 na linha impressa e um A1 na online: vale o estrato de mais artigos, em toda a família
        $this->insertArticle($researcher, $canonical, 'Artigo 1', 2024, null, 'ARTIGO', 'A3');
        $this->insertArticle($researcher, $canonical, 'Artigo 2', 2023, null, 'ARTIGO', 'A3');
        $this->insertArticle($researcher, $online, 'Artigo 3', 2022, null, 'ARTIGO', 'A1');

        $empate = $this->insertJournal("Revista Empate $u");
        $this->linkToBase($empate, 'scopus');
        $this->insertArticle($researcher, $empate, 'Artigo B1', 2024, null, 'ARTIGO', 'B1');
        $this->insertArticle($researcher, $empate, 'Artigo A4', 2023, null, 'ARTIGO', 'A4');

        // O mesmo artigo em dois currículos conta uma vez: um A2 repetido não vence dois artigos B2
        $coautoria = $this->insertJournal("Revista Coautoria Estrato $u");
        $this->linkToBase($coautoria, 'scopus');
        $outro = $this->insertResearcher("Outro Docente $u");
        $this->insertArticle($researcher, $coautoria, 'Artigo repetido', 2024, "10.5555/estrato-$u", 'ARTIGO', 'A2');
        $this->insertArticle($outro, $coautoria, 'Artigo repetido', 2024, "10.5555/estrato-$u", 'ARTIGO', 'A2');
        $this->insertArticle($outro, $coautoria, 'Artigo repetido', 2024, "10.5555/estrato-$u", 'ARTIGO', 'A2');
        $this->insertArticle($researcher, $coautoria, 'Artigo B2 um', 2023, null, 'ARTIGO', 'B2');
        $this->insertArticle($researcher, $coautoria, 'Artigo B2 dois', 2022, null, 'ARTIGO', 'B2');

        $semEstrato = $this->insertJournal("Revista Sem Estrato $u");
        $this->linkToBase($semEstrato, 'scopus');
        $this->insertArticle($researcher, $semEstrato, 'Artigo sem estrato', 2024, null);

        // Capítulo com estrato não conta: a página é só de artigos
        $capitulo = $this->insertJournal("Revista Capítulo $u");
        $this->linkToBase($capitulo, 'scopus');
        $this->insertArticle($researcher, $capitulo, 'Artigo sem estrato da revista', 2024, null);
        $this->insertArticle($researcher, $capitulo, 'Capítulo', 2023, null, 'CAPITULO', 'A1');

        $rows = $this->service()->getDatabaseList('scopus')['rows'];
        $this->assertSame('A3', $this->rowOf($rows, $canonical)['qualis']);
        $this->assertSame('A4', $this->rowOf($rows, $empate)['qualis'], 'No empate, o estrato mais alto.');
        $this->assertSame('B2', $this->rowOf($rows, $coautoria)['qualis']);
        $this->assertNull($this->rowOf($rows, $semEstrato)['qualis']);
        $this->assertNull($this->rowOf($rows, $capitulo)['qualis']);

        $crawler = $this->get('/periodicos/scopus');
        $badge = $this->itemOf($crawler, "Revista Estrato $u")->filter('span[title="Estrato Qualis CAPES: A3"]');
        $this->assertCount(1, $badge);
        $this->assertSame('Qualis A3', trim($badge->text()));
        $this->assertCount(1, $badge->filter('sl-icon[name="award"]'));
        // A mesma cor dos selos dos artigos (`QualisExtension::renderQualisBadge`)
        $this->assertStringContainsString('bg-teal-100 text-teal-800', $badge->attr('class'));
        $this->assertCount(0, $this->itemOf($crawler, "Revista Sem Estrato $u")->filter('sl-icon[name="award"]'));
        $this->assertStringNotContainsString('Qualis', $this->itemOf($crawler, "Revista Sem Estrato $u")->text());
    }

    public function testQualisSealAlsoAppearsInTheOverviewAndMethodologyExplainsIt(): void
    {
        $this->beginSeed();
        $u = $this->uniq;
        $journal = $this->insertJournal("Revista Visão Geral $u");
        $this->linkToBase($journal, 'scopus');
        $researcher = $this->insertResearcher("Docente $u");
        // Artigos suficientes para entrar nos primeiros 25 da Scopus
        for ($i = 1; $i <= 500; $i++) {
            $this->insertArticle($researcher, $journal, "Artigo $i", 1900 + ($i % 120), null, 'ARTIGO', 'A1');
        }

        $crawler = $this->get('/periodicos');

        $badge = $this->itemOf($crawler->filter('section[data-base="scopus"]'), "Revista Visão Geral $u")->filter('span[title="Estrato Qualis CAPES: A1"]');
        $this->assertCount(1, $badge);
        $this->assertStringContainsString('bg-emerald-100 text-emerald-800', $badge->attr('class'));
        $this->assertStringContainsString(
            'Qualis: estrato da CAPES registrado nos artigos do periódico neste portal; se houver mais de um, aparece o de mais artigos. O selo é informativo e não define a ordem da lista.',
            $crawler->filter('#metodologia')->text()
        );
    }

    // ------------------------------------------------------------------------------------------------------------
    // Bases somadas
    // ------------------------------------------------------------------------------------------------------------

    public function testGeneralListShowsEachJournalOnceWithItsBasesAndDoesNotCountArticlesTwice(): void
    {
        $this->beginSeed();
        $u = $this->uniq;
        $researcher = $this->insertResearcher("Docente $u");

        // Gravada na ordem inversa: as bases saem na ordem fixa da página
        $duasBases = $this->insertJournal("Revista Duas Bases $u");
        $this->linkToBase($duasBases, 'wos');
        $this->linkToBase($duasBases, 'scopus');
        $this->insertArticle($researcher, $duasBases, 'Artigo 1', 2024, null, 'ARTIGO', 'A1');
        $this->insertArticle($researcher, $duasBases, 'Artigo 2', 2023, null, 'ARTIGO', 'A1');

        // Cada linha da família está em uma base: a família inteira aparece uma vez, com as duas
        $canonical = $this->insertJournal("Revista Família Bases $u");
        $member = $this->insertJournal("Revista Membro Bases $u", $canonical);
        $this->linkToBase($canonical, 'latindex');
        $this->linkToBase($member, 'pubmed');
        $this->insertArticle($researcher, $member, 'Artigo 3', 2022, null);

        $outside = $this->insertJournal("Revista Fora das Bases $u");
        $this->insertArticle($researcher, $outside, 'Artigo 4', 2021, null);

        $onlyDoaj = $this->insertJournal("Revista Só Doaj $u");
        $this->linkToBase($onlyDoaj, 'doaj');
        $this->insertArticle($researcher, $onlyDoaj, 'Artigo 5', 2020, null);

        $general = $this->service()->getGeneralList();
        $rows = $general['rows'];

        $row = $this->rowOf($rows, $duasBases);
        $this->assertSame(2, $row['n_articles'], 'Estar em duas bases não dobra os artigos.');
        $this->assertSame(['scopus', 'wos'], array_column($row['bases'], 'acronym'));
        $this->assertSame(['pubmed', 'latindex'], array_column($this->rowOf($rows, $canonical)['bases'], 'acronym'));

        $ids = array_column($rows, 'id');
        $this->assertSame($ids, array_values(array_unique($ids)), 'Cada periódico aparece uma vez.');
        foreach ([$member, $outside, $onlyDoaj] as $absent) {
            $this->assertNotContains($absent, $ids);
        }
        $this->assertSame(array_sum(array_column($rows, 'n_articles')), $general['total_articles']);
        $this->assertSame(count($rows), $general['total_journals']);
        $this->assertGreaterThanOrEqual(1, $general['total_researchers']);

        // As listas de uma base não repetem as bases nas linhas
        $this->assertSame([], $this->rowOf($this->service()->getDatabaseList('scopus')['rows'], $duasBases)['bases']);

        $crawler = $this->get('/periodicos/geral');
        $item = $this->itemOf($crawler, "Revista Duas Bases $u");
        $this->assertCount(2, $item->filter('span[title^="Indexado na base científica"]'));
        $this->assertCount(1, $item->filter('span[title="Estrato Qualis CAPES: A1"]'));
        $this->assertStringContainsString('2 artigos', $item->text());

        $crawler = $this->get('/periodicos/scopus');
        $this->assertCount(0, $this->itemOf($crawler, "Revista Duas Bases $u")->filter('span[title^="Indexado na base científica"]'));
    }

    public function testGeneralListFollowsTheOrderOfArticlesAndTheAreaFilter(): void
    {
        $this->beginSeed();
        $u = $this->uniq;
        $researcher = $this->insertResearcher("Docente $u");
        $small = $this->insertJournal("Revista Geral Menor $u");
        $large = $this->insertJournal("Revista Geral Maior $u");
        $this->linkToBase($small, 'scielo');
        $this->linkToBase($large, 'pubmed');
        $this->insertArticle($researcher, $small, 'Artigo menor', 2024, null);
        foreach ([2021, 2022, 2023] as $year) {
            $this->insertArticle($researcher, $large, "Artigo maior $year", $year, null);
        }
        $this->assignArea($small, 'educacao', true);
        $this->assignArea($large, 'historia', true);

        $ids = array_column($this->service()->getGeneralList()['rows'], 'id');
        $this->assertLessThan(array_search($small, $ids, true), array_search($large, $ids, true));

        $filtered = array_column($this->service()->getGeneralList($this->areaId('educacao'))['rows'], 'id');
        $this->assertContains($small, $filtered);
        $this->assertNotContains($large, $filtered);
    }

    public function testOverviewShowsTheFirstJournalsOfTheGeneralListAndLinksToTheFullOneKeepingTheArea(): void
    {
        $this->beginSeed();
        $researcher = $this->insertResearcher("Docente {$this->uniq}");
        for ($i = 1; $i <= 27; $i++) {
            $id = $this->insertJournal(sprintf('Revista Geral Prévia %s %02d', $this->uniq, $i));
            $this->linkToBase($id, 'latindex');
            $this->insertArticle($researcher, $id, "Artigo $i", 2024, null);
            $this->assignArea($id, 'educacao', true);
        }

        $overview = $this->service()->getOverview();
        $this->assertCount(JournalsByDatabaseService::PREVIEW_LIMIT, $overview['general']['rows']);
        $this->assertGreaterThanOrEqual(27, $overview['general']['total_journals']);
        $this->assertSame(['latindex'], array_column($this->lastRowWithBases($overview['general']['rows']), 'acronym'));

        $crawler = $this->get('/periodicos');
        $section = $crawler->filter('section[data-general]');
        $this->assertSame('Todas as bases somadas', trim($section->filter('h2')->text()));
        $this->assertCount(25, $section->filter('li.journal-item'));
        $this->assertSame('/periodicos/geral', $section->filter('a[href^="/periodicos/geral"]')->attr('href'));

        $crawler = $this->get('/periodicos?area=educacao');
        $this->assertSame('/periodicos/geral?area=educacao', $crawler->filter('section[data-general] a[href^="/periodicos/geral"]')->attr('href'));

        $crawler = $this->get('/periodicos/geral?area=educacao');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextSame('h1', 'Periódicos das cinco bases com artigos do CECH');
        $this->assertPageTitleSame('Periódicos das cinco bases de indexação | Portal CECH UFSCar');
        $this->assertCount(1, $crawler->filter('section[data-general]'));
        $this->assertCount(0, $crawler->filter('section[data-base]'));
        $this->assertGreaterThanOrEqual(27, $crawler->filter('li.journal-item')->count());
        $this->assertSame('/periodicos/geral', $crawler->filter('main form')->attr('action'));
        $this->assertSame('/periodicos?area=educacao', $crawler->selectLink('Voltar aos periódicos')->attr('href'));
        $this->assertSelectorExists('select[name="area"] option[value="educacao"][selected]');
        $this->assertCount(0, $crawler->filter('main a')->reduce(static fn (Crawler $a): bool => str_starts_with(trim($a->text()), 'Ver todos')));
    }

    public function testDisplayNameIsTheDisplayTitleOrTheTitleInTitleCase(): void
    {
        $this->beginSeed();
        $upper = strtoupper($this->uniq);
        $caps = $this->insertJournal("REVISTA DE ESTUDOS DA EDUCAÇÃO $upper");
        $named = $this->insertJournal("TÍTULO BRUTO DA LINHA $upper", null, null, null, "Nome Exibido da Revista $upper");
        $mixed = $this->insertJournal("RAE-Revista de Administração de Empresas $upper");
        $researcher = $this->insertResearcher("Docente {$this->uniq}");
        foreach ([$caps, $named, $mixed] as $id) {
            $this->linkToBase($id, 'scopus');
            $this->insertArticle($researcher, $id, "Artigo de $id", 2024, null);
        }

        $rows = $this->service()->getDatabaseList('scopus')['rows'];
        $this->assertSame('Revista de Estudos da Educação ' . ucfirst(strtolower($upper)), $this->rowOf($rows, $caps)['name']);
        $this->assertSame("Nome Exibido da Revista $upper", $this->rowOf($rows, $named)['name']);
        $this->assertSame("RAE-Revista de Administração de Empresas $upper", $this->rowOf($rows, $mixed)['name']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Filtro de área e chips
    // ------------------------------------------------------------------------------------------------------------

    public function testAreaFilterMatchesAnyAreaUnderTheSelectionWithoutDuplicatingRows(): void
    {
        $this->beginSeed();
        $u = $this->uniq;
        $educacaoPsicologia = $this->insertJournal("Revista Educação e Psicologia $u");
        $historia = $this->insertJournal("Revista de História $u");
        $semArea = $this->insertJournal("Revista Sem Área $u");
        $researcher = $this->insertResearcher("Docente $u");
        foreach ([$educacaoPsicologia, $historia, $semArea] as $id) {
            $this->linkToBase($id, 'scopus');
            $this->insertArticle($researcher, $id, "Artigo de $id", 2024, null);
        }
        $this->assignArea($educacaoPsicologia, 'educacao', true, '0.7000');
        $this->assignArea($educacaoPsicologia, 'psicologia', false, '0.3000');
        $this->assignArea($historia, 'historia', true);

        $service = $this->service();
        $idsFor = fn (?string $slug): array => array_values(array_intersect(
            array_column($service->getDatabaseList('scopus', $slug === null ? null : $this->areaId($slug))['rows'], 'id'),
            [$educacaoPsicologia, $historia, $semArea]
        ));

        $this->assertEqualsCanonicalizing([$educacaoPsicologia, $historia, $semArea], $idsFor(null));
        $this->assertSame([$educacaoPsicologia], $idsFor('educacao'), 'Área de avaliação: só quem a tem.');
        $this->assertSame([$educacaoPsicologia], $idsFor('psicologia'), 'A área secundária também vale.');
        $this->assertSame([$historia], $idsFor('historia'));
        $this->assertEqualsCanonicalizing([$educacaoPsicologia, $historia], $idsFor('ciencias-humanas'), 'A grande área reúne as suas áreas, e a família com duas áreas sob ela aparece uma vez.');
        $this->assertSame([], $idsFor('engenharias-i'));

        // Os totais do cabeçalho acompanham o filtro
        $totals = $service->getOverview($this->areaId('historia'))['totals'];
        $this->assertGreaterThanOrEqual(1, $totals['journals']);
        $this->assertGreaterThanOrEqual(1, $totals['articles']);
        $this->assertLessThanOrEqual($service->getOverview()['totals']['journals'], $totals['journals']);

        // Na página: o filtro esconde as outras linhas
        $this->get('/periodicos/scopus?area=educacao');
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString("Revista Educação e Psicologia $u", $html);
        $this->assertStringNotContainsString("Revista de História $u", $html);
        $this->assertStringNotContainsString("Revista Sem Área $u", $html);

        $this->get('/periodicos/scopus?area=ciencias-humanas');
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertSame(1, substr_count($html, "Revista Educação e Psicologia $u"));
        $this->assertStringContainsString("Revista de História $u", $html);
    }

    public function testAreaChipsShowThePrimaryAreaFirstAndOnlyForJournalsThatHaveAreas(): void
    {
        $this->beginSeed();
        $u = $this->uniq;
        $withAreas = $this->insertJournal("Revista Com Áreas $u");
        $without = $this->insertJournal("Revista Sem Chips $u");
        $researcher = $this->insertResearcher("Docente $u");
        foreach ([$withAreas, $without] as $id) {
            $this->linkToBase($id, 'scopus');
            $this->insertArticle($researcher, $id, "Artigo de $id", 2024, null);
        }
        // A secundária entra primeiro no banco e com a maior pontuação: a principal continua na frente
        $this->assignArea($withAreas, 'psicologia', false, '0.9000');
        $this->assignArea($withAreas, 'educacao', true, '0.5000');
        $this->assignArea($withAreas, 'sociologia', false, '0.2000');

        $rows = $this->service()->getDatabaseList('scopus')['rows'];
        $this->assertSame(
            [['Educação', true], ['Psicologia', false], ['Sociologia', false]],
            array_map(static fn (array $chip): array => [$chip['name'], $chip['is_primary']], $this->rowOf($rows, $withAreas)['areas'])
        );
        $this->assertSame([], $this->rowOf($rows, $without)['areas']);

        $crawler = $this->get('/periodicos/scopus');
        $chips = $this->itemOf($crawler, "Revista Com Áreas $u")->filter('.journal-areas > span');
        $this->assertCount(3, $chips);
        $this->assertSame('Educação', trim($chips->eq(0)->text()));
        $this->assertStringContainsString('bg-sky-100', $chips->eq(0)->attr('class'));
        $this->assertStringContainsString('bg-slate-100', $chips->eq(1)->attr('class'));
        $this->assertCount(0, $this->itemOf($crawler, "Revista Sem Chips $u")->filter('.journal-areas'));
    }

    public function testOverviewShowsTheFirstTwentyFiveAndLinksToTheFullListKeepingTheArea(): void
    {
        $this->beginSeed();
        $researcher = $this->insertResearcher("Docente {$this->uniq}");
        // Latindex: as 27 linhas desta base têm 1 artigo cada, todas em Educação
        $ids = [];
        for ($i = 1; $i <= 27; $i++) {
            $id = $this->insertJournal(sprintf('Revista Prévia %s %02d', $this->uniq, $i));
            $this->linkToBase($id, 'latindex');
            $this->insertArticle($researcher, $id, "Artigo prévio $i", 2024, null);
            $this->assignArea($id, 'educacao', true);
            $ids[] = $id;
        }

        $overview = $this->service()->getOverview();
        $latindex = $overview['bases']['latindex'];
        $this->assertCount(JournalsByDatabaseService::PREVIEW_LIMIT, $latindex['rows']);
        $this->assertGreaterThanOrEqual(27, $latindex['total_journals']);
        $this->assertGreaterThanOrEqual(27, $latindex['total_articles']);
        $this->assertSame(array_keys($overview['bases']), self::BASES);

        $crawler = $this->get('/periodicos');
        $section = $crawler->filter('section[data-base="latindex"]');
        $this->assertCount(25, $section->filter('li.journal-item'));
        $link = $section->filter('a[href^="/periodicos/latindex"]');
        $this->assertCount(1, $link);
        $this->assertSame('/periodicos/latindex', $link->attr('href'));
        $this->assertSame(
            sprintf('Ver todos os %s periódicos', number_format($latindex['total_journals'], 0, ',', '.')),
            trim(preg_replace('/\s+/', ' ', $link->text()))
        );

        // Com o filtro, o link leva a área junto
        $crawler = $this->get('/periodicos?area=educacao');
        $link = $crawler->filter('section[data-base="latindex"] a[href^="/periodicos/latindex"]');
        $this->assertCount(1, $link);
        $this->assertSame('/periodicos/latindex?area=educacao', $link->attr('href'));

        // A página da base mostra todas e não repete o link
        $crawler = $this->get('/periodicos/latindex');
        $this->assertGreaterThanOrEqual(27, $crawler->filter('li.journal-item')->count());
        $this->assertCount(0, $crawler->filter('main a')->reduce(static fn (Crawler $a): bool => str_starts_with(trim($a->text()), 'Ver todos')));
    }

    public function testDataUpdatedAtIsTheLatestUpdateOfTheAreas(): void
    {
        $this->beginSeed();
        $journal = $this->insertJournal("Revista Data {$this->uniq}");
        $this->assignArea($journal, 'educacao', true, '0.9000', '2099-05-17 10:00:00');

        $this->assertSame('2099-05-17', $this->service()->getUpdatedAt()->format('Y-m-d'));

        $crawler = $this->get('/periodicos');
        $this->assertStringContainsString('Dados atualizados em 17/05/2099.', $crawler->filter('#metodologia')->text());
    }

    public function testAreaHelpersReadTheTaxonomyTable(): void
    {
        $service = $this->service();

        $educacao = $service->resolveAreaSlug('educacao');
        $this->assertNotNull($educacao);
        $this->assertSame('Educação', $educacao['name']);
        $this->assertSame(2, $educacao['level']);
        $this->assertSame(404, $educacao['id']);
        $this->assertSame(400, $educacao['parent_id']);

        $humanas = $service->resolveAreaSlug('ciencias-humanas');
        $this->assertSame(1, $humanas['level']);
        $this->assertNull($humanas['parent_id']);
        $this->assertNull($service->resolveAreaSlug('nao-existe'));

        $options = $service->getAreaOptions();
        $this->assertCount(9, $options);
        $this->assertSame('ciencias-agrarias', $options[0]['area']['slug']);
        $this->assertSame(59, array_sum(array_map(static fn (array $g): int => 1 + count($g['children']), $options)));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Auxiliares
    // ------------------------------------------------------------------------------------------------------------

    private function get(string $uri): Crawler
    {
        return $this->client->request('GET', $uri);
    }

    /**
     * Abre a transação revertida no tearDown. O navegador não reinicia o kernel entre requisições, para que todas usem
     * a mesma conexão e vejam as linhas ainda não confirmadas.
     */
    private function beginSeed(): void
    {
        $this->client->disableReboot();
        $this->conn = static::getContainer()->get('doctrine.orm.entity_manager')->getConnection();
        $this->conn->beginTransaction();
    }

    private function service(): JournalsByDatabaseService
    {
        return new JournalsByDatabaseService($this->conn ?? static::getContainer()->get('doctrine.orm.entity_manager')->getConnection());
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function rowOf(array $rows, int $journalId): array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $journalId) {
                return $row;
            }
        }

        $this->fail(sprintf('O periódico %d não está na lista.', $journalId));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function lastRowWithBases(array $rows): array
    {
        $this->assertNotSame([], end($rows)['bases'], 'Toda linha das bases somadas tem ao menos uma base.');

        return end($rows)['bases'];
    }

    private function itemOf(Crawler $crawler, string $name): Crawler
    {
        $items = $crawler->filter('li.journal-item')->reduce(static fn (Crawler $node): bool => str_contains($node->text(), $name));
        $this->assertCount(1, $items, sprintf('A lista deve ter exatamente uma linha de "%s".', $name));

        return $items;
    }

    private function insertJournal(string $title, ?int $canonicalId = null, ?string $issn = null, ?string $issnE = null, ?string $displayTitle = null, ?string $issnImp = null): int
    {
        $this->conn->insert('qualis_journals', [
            'title' => $title,
            'issn' => $issn,
            'issn_e' => $issnE,
            'issn_imp' => $issnImp,
            'display_title' => $displayTitle,
            'canonical_journal_id' => $canonicalId,
            'areas_locked' => 0,
        ]);

        return (int) $this->conn->lastInsertId();
    }

    private function insertResearcher(string $name): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->insert('researchers', [
            'id_lattes' => '97' . str_pad((string) random_int(0, 99999999999999), 14, '0', STR_PAD_LEFT),
            'full_name' => $name,
            'slug' => 'docente-' . strtolower($this->uniq) . '-' . random_int(1000, 9999),
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->conn->lastInsertId();
    }

    private function insertArticle(int $researcherId, int $journalId, string $title, ?int $year, ?string $doi, string $type = 'ARTIGO', ?string $qualis = null): void
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->insert('production_items', [
            'researcher_id' => $researcherId,
            'item_type' => $type,
            'title' => $title,
            'year' => $year,
            'doi' => $doi,
            'qualis' => $qualis,
            'qualis_journal_id' => $journalId,
            'is_scientific_dissemination' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Liga o periódico à base; cria a linha de `academic_database` (dentro da transação) se o banco de testes não a tem. */
    private function linkToBase(int $journalId, string $acronym): void
    {
        $baseId = $this->conn->fetchOne('SELECT id FROM academic_database WHERE acronym = ?', [$acronym]);
        if ($baseId === false) {
            $this->conn->insert('academic_database', ['name' => ucfirst($acronym), 'acronym' => $acronym]);
            $baseId = $this->conn->lastInsertId();
        }

        $this->conn->insert('qualis_journal_academic_database', ['qualis_journal_id' => $journalId, 'academic_database_id' => (int) $baseId]);
    }

    private function areaId(string $slug): int
    {
        $id = $this->conn->fetchOne('SELECT id FROM capes_areas WHERE slug = ?', [$slug]);
        $this->assertNotFalse($id, "A taxonomia deve ter a área $slug");

        return (int) $id;
    }

    private function assignArea(int $journalId, string $slug, bool $primary, string $score = '0.9000', ?string $updatedAt = null): void
    {
        $this->conn->insert('journal_areas', [
            'journal_id' => $journalId,
            'area_id' => $this->areaId($slug),
            'is_primary' => (int) $primary,
            'score' => $score,
            'confidence' => 'high',
            'method' => 'auto',
            'updated_at' => $updatedAt ?? date('Y-m-d H:i:s'),
        ]);
    }
}
