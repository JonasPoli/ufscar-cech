<?php

declare(strict_types=1);

namespace App\Tests\Controller\pub;

use App\Service\StatisticsService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Páginas institucionais (/sobre, /sobre-os-dados, /duvidas) e recursos de acessibilidade do layout público
 * (link "Pular para o conteúdo", painel "Preferências de leitura" e carregamento sob demanda do KaTeX).
 *
 * As requisições levam um cookie de sessão: o PageCacheService não lê nem grava páginas de quem tem sessão, então
 * o teste vê a página recém-renderizada e não deixa arquivo em var/cache/public_pages.
 */
class InstitutionalPagesTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->getCookieJar()->set(new Cookie('TESTE_SESSAO', '1'));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Sobre nós
    // ------------------------------------------------------------------------------------------------------------

    public function testAboutPageShowsSectionsAndLinks(): void
    {
        $crawler = $this->get('/sobre');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextSame('h1', 'Sobre nós');
        $this->assertPageTitleSame('Sobre nós | Portal CECH UFSCar');
        $this->assertMetaDescriptionIsSpecific($crawler, 'Portal de Produção do CECH');

        foreach (['o-que-e', 'o-que-voce-encontra', 'de-onde-vem-os-dados', 'quem-mantem', 'acessibilidade'] as $id) {
            $this->assertCount(1, $crawler->filter('section#' . $id), "Seção #{$id} ausente");
            $this->assertCount(1, $crawler->filter('nav[aria-labelledby="nesta-pagina"] a[href="#' . $id . '"]'), "Índice sem #{$id}");
        }

        $hrefs = $crawler->filter('section#o-que-voce-encontra a')->each(fn (Crawler $a) => $a->attr('href'));
        foreach (['/busca', '/coautorias', '/explorar', '/projetos', '/indicadores', '/periodicos', '/departamentos'] as $path) {
            $this->assertContains($path, $hrefs, "Link para {$path} ausente em \"O que você encontra aqui\"");
        }

        $maintainers = $crawler->filter('section#quem-mantem')->text();
        $this->assertStringContainsString('Centro de Educação e Ciências Humanas', $maintainers);
        $this->assertStringContainsString('NIT Materiais', $maintainers);
        $this->assertCount(1, $crawler->filter('section#quem-mantem a[href="https://www.nit.ufscar.br/"][rel~="noopener"]'));

        $this->assertCount(1, $crawler->filter('nav[aria-label="Páginas sobre o portal"] a[aria-current="page"][href="/sobre"]'));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Sobre os dados
    // ------------------------------------------------------------------------------------------------------------

    public function testDataPageShowsLiveNumbers(): void
    {
        $summary = static::getContainer()->get(StatisticsService::class)->getGlobalSummary();
        $crawler = $this->get('/sobre-os-dados');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextSame('h1', 'Sobre os dados');
        $this->assertPageTitleSame('Sobre os dados | Portal CECH UFSCar');
        $this->assertMetaDescriptionIsSpecific($crawler, 'Currículo Lattes');

        $numbers = $crawler->filter('section#numeros dl')->text();
        foreach (['totalResearchers', 'allResearchers', 'uniqueProductions', 'totalOrientations'] as $key) {
            $this->assertStringContainsString(
                number_format((int) $summary[$key], 0, ',', '.'),
                $numbers,
                "O número de {$key} deveria aparecer em \"Os números de hoje\""
            );
        }
        $this->assertStringContainsString('Docentes com vínculo vigente', $numbers);
        $this->assertStringContainsString('Assuntos no índice temático', $numbers);
        $this->assertStringContainsString('Atualização mais recente', $numbers);
    }

    public function testDataPageListsSourcesAndInterpretation(): void
    {
        $crawler = $this->get('/sobre-os-dados');
        $this->assertResponseIsSuccessful();

        foreach (['numeros', 'fontes', 'atualizacao', 'como-interpretar', 'periodicos-qualis-e-bases', 'limites', 'correcoes'] as $id) {
            $this->assertCount(1, $crawler->filter('section#' . $id), "Seção #{$id} ausente");
        }

        $sources = $crawler->filter('section#fontes')->text();
        foreach (['Currículo Lattes', 'XML', 'HTML', 'Planilha de docentes do CECH', 'TeD-UFSCar', 'Qualis CAPES', 'Scopus', 'Web of Science', 'SciELO', 'Latindex', 'PubMed', 'DOAJ'] as $term) {
            $this->assertStringContainsString($term, $sources);
        }

        $interpretation = $crawler->filter('section#como-interpretar')->text();
        $this->assertStringContainsString('DOI', $interpretation);
        $this->assertStringContainsString('tipo, o ano e o título', $interpretation);
        $this->assertStringContainsString('dois docentes do CECH', $interpretation);
        $this->assertStringContainsString('em andamento', $interpretation);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Dúvidas frequentes
    // ------------------------------------------------------------------------------------------------------------

    public function testFaqPageHasCategoriesAndAnchoredQuestions(): void
    {
        $crawler = $this->get('/duvidas');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextSame('h1', 'Dúvidas frequentes');
        $this->assertPageTitleSame('Dúvidas frequentes | Portal CECH UFSCar');
        $this->assertMetaDescriptionIsSpecific($crawler, 'acessibilidade');

        foreach (['busca-e-resultados', 'perfis-e-coautorias', 'projetos', 'dados-e-cobertura', 'acessibilidade'] as $id) {
            $section = $crawler->filter('section#' . $id);
            $this->assertCount(1, $section, "Categoria #{$id} ausente");
            $this->assertGreaterThanOrEqual(2, $section->filter('details[id] > summary')->count(), "Categoria #{$id} com poucas perguntas");
            $this->assertCount(1, $crawler->filter('a[href="#' . $id . '"]'), "Sem link para a categoria #{$id}");
        }

        foreach (['modos-de-busca', 'filtros-da-busca', 'ordem-dos-resultados', 'grafo-de-coautorias', 'exportar-rede', 'catalogo-de-projetos', 'reportar-erro', 'preferencias-de-leitura'] as $id) {
            $this->assertCount(1, $crawler->filter('details#' . $id), "Pergunta #{$id} ausente");
        }

        $search = $crawler->filter('section#busca-e-resultados')->text();
        foreach (['Especialistas por assunto', 'Publicações por assunto', 'Pesquisador por nome', 'buscar a expressão exata', 'Relevância', 'Voltar à busca'] as $term) {
            $this->assertStringContainsString($term, $search);
        }
        $this->assertStringContainsString('VOSviewer', $crawler->filter('details#exportar-rede')->text());
        $this->assertStringContainsString('BibTeX', $crawler->filter('details#exportar-publicacoes')->text());
    }

    public function testInstitutionalPagesHaveUniqueIds(): void
    {
        foreach (['/sobre', '/sobre-os-dados', '/duvidas'] as $path) {
            $crawler = $this->get($path);
            $this->assertResponseIsSuccessful();

            $ids = $crawler->filter('[id]')->each(fn (Crawler $node) => $node->attr('id'));
            $duplicates = array_keys(array_filter(array_count_values($ids), fn (int $count) => $count > 1));
            $this->assertSame([], $duplicates, "IDs repetidos em {$path}");

            // Toda âncora interna aponta para um id que existe na página
            $anchors = $crawler->filter('a[href^="#"]')->each(fn (Crawler $a) => substr((string) $a->attr('href'), 1));
            foreach (array_unique($anchors) as $anchor) {
                $this->assertContains($anchor, $ids, "Âncora #{$anchor} sem destino em {$path}");
            }
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Acessibilidade do layout
    // ------------------------------------------------------------------------------------------------------------

    public function testLayoutHasSkipLinkAndFocusableMain(): void
    {
        $crawler = $this->get('/sobre');
        $this->assertResponseIsSuccessful();

        $first = $crawler->filter('body > *')->first();
        $this->assertSame('a', $first->nodeName(), 'O primeiro elemento do <body> deve ser o link "Pular para o conteúdo"');
        $this->assertSame('#conteudo', $first->attr('href'));
        $this->assertSame('Pular para o conteúdo', trim($first->text()));
        $this->assertCount(1, $crawler->filter('main#conteudo[tabindex="-1"]'));
    }

    public function testLayoutHasReadingPreferencesPanel(): void
    {
        $crawler = $this->get('/sobre');
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $panel = $crawler->filter('#a11y-panel[role="dialog"][aria-labelledby="a11y-panel-title"]');
        $this->assertCount(1, $panel);
        $this->assertNotNull($panel->attr('hidden'), 'O painel começa fechado');
        $this->assertSame('Preferências de leitura', trim($crawler->filter('#a11y-panel-title')->text()));

        // Um botão no cabeçalho (desktop) e outro no menu do celular
        $triggers = $crawler->filter('button[data-a11y-open][aria-controls="a11y-panel"][aria-expanded="false"]');
        $this->assertCount(2, $triggers);
        $this->assertCount(1, $crawler->filter('#mobile-menu-drawer button[data-a11y-open]'));
        $this->assertStringContainsString('Acessibilidade', $crawler->filter('#a11y-toggle')->text());

        // Controles: A−/A+, tema automático/claro/escuro, três interruptores e "Restaurar"
        $this->assertCount(2, $panel->filter('button[data-a11y-font]'));
        $this->assertSame(['auto', 'light', 'dark'], $panel->filter('input[type="radio"][name="a11y-theme"]')->each(fn (Crawler $r) => $r->attr('value')));
        $this->assertSame(['contrast', 'spacing', 'reduceMotion'], $panel->filter('input[type="checkbox"][role="switch"]')->each(fn (Crawler $c) => $c->attr('data-a11y-toggle')));
        foreach ($panel->filter('input[role="switch"]')->each(fn (Crawler $c) => $c->attr('id')) as $id) {
            $this->assertCount(1, $crawler->filter('label[for="' . $id . '"]'), "Interruptor #{$id} sem rótulo");
        }
        $this->assertCount(1, $panel->filter('button[data-a11y-reset]'));

        // Preferências aplicadas no <head> antes da pintura, com compatibilidade de localStorage.theme e setDarkMode
        $head = substr($html, 0, (int) strpos($html, '</head>'));
        $this->assertStringContainsString("'cech-a11y'", $head);
        $this->assertStringContainsString("read('theme')", $head);
        $this->assertStringContainsString('w.setDarkMode = function', $head);
        $this->assertStringContainsString('id="shoelace-dark-theme"', $head);
        $this->assertStringNotContainsString('onclick="setDarkMode(', $html, 'O par de botões claro/escuro foi substituído pelo painel');

        // Scripts do painel e das fórmulas, servidos pelo AssetMapper
        $this->assertMatchesRegularExpression('#<script src="/assets/js/a11y-[^"]+\.js" defer></script>#', $head);
        $this->assertMatchesRegularExpression('#<script src="/assets/js/math-[^"]+\.js" defer></script>#', $head);
    }

    public function testMathScriptOnlyLoadsKatexOnDemand(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/js/math.js');

        $this->assertStringContainsString('katex@0.16.11', $source);
        $this->assertStringContainsString('DOMContentLoaded', $source);
        $this->assertStringContainsString("{ left: '$$', right: '$$', display: false }", $source);
        $this->assertStringContainsString("{ left: '\\\\(', right: '\\\\)', display: false }", $source);
        // Nenhum delimitador de cifrão simples (os valores em reais usam "R$")
        $this->assertDoesNotMatchRegularExpression("/left: '\\$'/", $source);

        // A página não inclui o KaTeX diretamente: ele só é baixado pelo script quando há fórmula
        $this->get('/duvidas');
        $this->assertStringNotContainsString('katex.min.js', (string) $this->client->getResponse()->getContent());
    }

    // ------------------------------------------------------------------------------------------------------------

    private function get(string $path): Crawler
    {
        return $this->client->request('GET', $path);
    }

    private function assertMetaDescriptionIsSpecific(Crawler $crawler, string $expected): void
    {
        $description = (string) $crawler->filter('meta[name="description"]')->attr('content');
        $this->assertStringContainsString($expected, $description);
    }
}
