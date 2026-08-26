<?php

namespace App\Tests\Controller\pub;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PublicRoutesTest extends WebTestCase
{
    public function testHomePageIsSuccessful(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Mapeamento da Produção Científica & Acadêmica');
        $this->assertStringContainsString('Docentes Ativos', $client->getResponse()->getContent());
        $this->assertStringContainsString('Produções Únicas', $client->getResponse()->getContent());
        $this->assertStringContainsString('Orientações', $client->getResponse()->getContent());
    }

    public function testIndicadoresPageIsSuccessful(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/indicadores');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Indicadores');
        $this->assertSelectorTextContains('#fig1LegendList', 'Departamento');
        $this->assertStringNotContainsString('D3.js Observable Template', $client->getResponse()->getContent());
        $this->assertStringNotContainsString('D3.js Sankey Observable', $client->getResponse()->getContent());
        $this->assertStringNotContainsString('D3.js Sankey & Mobilidade', $client->getResponse()->getContent());

        for ($i = 1; $i <= 19; $i++) {
            $this->assertStringContainsString("Figura {$i}", $client->getResponse()->getContent());
        }

        $this->assertStringContainsString('Bases Científicas Internacionais', $client->getResponse()->getContent());
        $this->assertStringContainsString('Trabalhos dos Docentes por Base de Indexação', $client->getResponse()->getContent());
        $this->assertStringContainsString('chartFigAcademicDatabases', $client->getResponse()->getContent());
        $this->assertStringContainsString('chartFigAcademicDatabasesTimeline', $client->getResponse()->getContent());
    }

    public function testSearchPageIsSuccessful(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $researcher = $em->getRepository(\App\Entity\Researcher::class)->findOneBy([]);
        if ($researcher) {
            $researcher->setDepartmentCode('AC');
            $researcher->setDepartment('Departamento de Artes e Comunicação');
            $em->flush();
        }

        $crawler = $client->request('GET', '/busca?q=roniberto&dept=&type=all');

        $this->assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        $this->assertStringNotContainsString('(AC) (AC)', $content);
        $this->assertStringNotContainsString('(PS) (PS)', $content);
        $this->assertStringNotContainsString('(CA) (CA)', $content);
        if ($researcher) {
            $this->assertStringContainsString('Departamento de Artes e Comunicação (AC)', $content);
        }
    }

    public function testDepartmentsPageIsSuccessful(): void
    {
        $client = static::createClient();
        $client->request('GET', '/departamentos');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Departamentos do CECH');
    }

    public function testProfessorProfilePageIsSuccessful(): void
    {
        $client = static::createClient();
        // First get a valid researcher from database
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $researcher = $em->getRepository(\App\Entity\Researcher::class)->findOneBy([]);

        if ($researcher) {
            $identifier = $researcher->getSlug() ?: $researcher->getIdLattes();
            $client->request('GET', '/professor/' . $identifier);
            $this->assertResponseIsSuccessful();
            $this->assertSelectorTextContains('h1', $researcher->getFullName());

            // Test BibTeX export
            $client->request('GET', '/professor/' . $identifier . '/export/bibtex');
            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString('application/x-bibtex', $client->getResponse()->headers->get('Content-Type'));

            // Test JSON export
            $client->request('GET', '/professor/' . $identifier . '/export/json');
            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString('application/json', $client->getResponse()->headers->get('Content-Type'));

            // Test CSV export
            $client->request('GET', '/professor/' . $identifier . '/export/csv');
            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString('text/csv', $client->getResponse()->headers->get('Content-Type'));
        }

        $roniberto = $em->getRepository(\App\Entity\Researcher::class)->findOneBy(['slug' => 'roniberto-morato-do-amaral']);
        if ($roniberto) {
            $client->request('GET', '/professor/' . $roniberto->getSlug());
            $this->assertResponseIsSuccessful();
            $content = (string)$client->getResponse()->getContent();
            $this->assertStringContainsString('Palavras-chave dos Trabalhos', $content);
            $this->assertStringContainsString('Temas e Termos Frequentes', $content);
            $this->assertStringContainsString('id="globalFilterBar"', $content);
            $this->assertStringContainsString('id="btnClearFiltersMain"', $content);
            $this->assertStringContainsString('id="tabCount-productions"', $content);
            $this->assertStringContainsString('class="pill-count"', $content);
        }
    }

    public function testLargeProfessorProfilePageMemoryUsage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $researcher = $em->getRepository(\App\Entity\Researcher::class)->findOneBy(['slug' => 'zilda-aparecida-pereira-del-prette'])
            ?: $em->getRepository(\App\Entity\Researcher::class)->findOneBy([]);

        if ($researcher) {
            $memBefore = memory_get_usage(false);
            $identifier = $researcher->getSlug() ?: $researcher->getIdLattes();
            $client->request('GET', '/professor/' . $identifier);

            $this->assertResponseIsSuccessful();
            $this->assertSelectorTextContains('h1', $researcher->getFullName());
            
            $memUsedMb = (memory_get_usage(false) - $memBefore) / 1024 / 1024;
            $this->assertLessThan(20, $memUsedMb, "O consumo de memória desta requisição não deve exceder 20MB (atual: {$memUsedMb}MB)");
        }
    }

    public function testSitemapXmlIsSuccessful(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('application/xml', $client->getResponse()->headers->get('Content-Type'));
        $this->assertStringContainsString('<urlset', (string)$client->getResponse()->getContent());
    }

    public function testRobotsTxtIsSuccessful(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/plain', $client->getResponse()->headers->get('Content-Type'));
        $this->assertStringContainsString('User-agent:', (string)$client->getResponse()->getContent());
        $this->assertStringContainsString('Sitemap:', (string)$client->getResponse()->getContent());
    }
}
