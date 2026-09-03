<?php

declare(strict_types=1);

namespace App\Tests\Controller\pub;

use App\Entity\Researcher;
use App\Entity\ThematicTerm;
use App\Entity\ThematicTermResearcher;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ThematicSearchControllerTest extends WebTestCase
{
    private function createSampleData($client): ThematicTerm
    {
        $container = $client->getContainer();
        $em = $container->get('doctrine.orm.entity_manager');

        $term = $em->getRepository(ThematicTerm::class)->findOneBy(['slug' => 'teste-inteligencia-artificial']);
        if (!$term) {
            $researcher = $em->getRepository(Researcher::class)->findOneBy([]) ?: new Researcher();
            if (!$researcher->getId()) {
                $researcher->setFullName('Prof Teste Temas');
                $researcher->setSlug('prof-teste-temas');
                $researcher->setIdLattes('1234567890123456');
                $em->persist($researcher);
            }

            $term = new ThematicTerm();
            $term->setTerm('Inteligência Artificial');
            $term->setSlug('teste-inteligencia-artificial');
            $term->setNormalizedTerm('inteligencia artificial');
            $term->setTotalOccurrences(150);
            $term->setResearcherCount(5);
            $em->persist($term);

            $link = new ThematicTermResearcher();
            $link->setTerm($term);
            $link->setResearcher($researcher);
            $link->setOccurrences(42);
            $link->setSampleTitles(['Artigo pioneiro sobre IA', 'Redes neurais na educação']);
            $em->persist($link);

            $em->flush();
        }

        return $term;
    }

    public function testThematicSearchPageIsSuccessful(): void
    {
        $client = static::createClient();
        $client->request('GET', '/temas');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Pesquisa Temática');
        $this->assertStringContainsString('topic-search-input', $client->getResponse()->getContent());
        $this->assertStringContainsString('terms-cloud', $client->getResponse()->getContent());
    }

    public function testThematicSearchPageWithSelectedTerm(): void
    {
        $client = static::createClient();
        $term = $this->createSampleData($client);

        $client->request('GET', '/temas?t=' . $term->getSlug());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($term->getTerm(), $client->getResponse()->getContent());
        $this->assertStringContainsString('selected-term-name', $client->getResponse()->getContent());
    }

    public function testAutocompleteApiWithValidQuery(): void
    {
        $client = static::createClient();
        $this->createSampleData($client);

        $client->request('GET', '/api/temas/autocomplete?q=intelig');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('terms', $data);
        $this->assertGreaterThan(0, $data['count']);
        $this->assertArrayHasKey('weight', $data['terms'][0]);
    }

    public function testAutocompleteApiWithShortQuery(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/temas/autocomplete?q=in');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(0, $data['count']);
        $this->assertEmpty($data['terms']);
    }

    public function testResearchersApiForTerm(): void
    {
        $client = static::createClient();
        $term = $this->createSampleData($client);

        $client->request('GET', '/api/temas/docentes?term_id=' . $term->getId() . '&offset=0&limit=10');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('researchers', $data);
        $this->assertArrayHasKey('term', $data);
        $this->assertArrayHasKey('hasMore', $data);
        $this->assertArrayHasKey('timeline', $data);
        $this->assertArrayHasKey('relatedConcepts', $data);
        $this->assertArrayHasKey('editorialAnalytics', $data);
        $this->assertGreaterThan(0, count($data['researchers']));
    }

    public function testThematicSearchTimelineApi(): void
    {
        $client = static::createClient();
        $term = $this->createSampleData($client);

        $client->request('GET', '/api/temas/evolucao?slug=' . $term->getSlug());

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('term', $data);
        $this->assertArrayHasKey('timeline', $data);
        $this->assertArrayHasKey('endYear', $data['timeline']);
        $this->assertArrayHasKey('years', $data['timeline']);
        $this->assertArrayHasKey('totalWorks', $data['timeline']);
    }

    public function testThematicSearchPageRendersTimelineChartCard(): void
    {
        $client = static::createClient();
        $term = $this->createSampleData($client);

        $client->request('GET', '/temas?t=' . $term->getSlug());

        $this->assertResponseIsSuccessful();
        $content = (string)$client->getResponse()->getContent();
        $this->assertStringContainsString('theme-timeline-card', $content);
        $this->assertStringContainsString('thematicTimelineChart', $content);
        $this->assertStringContainsString('Evolução da Produção no Tema', $content);
        $this->assertStringContainsString('theme-editorial-card', $content);
        $this->assertStringContainsString('thematicQualisChart', $content);
        $this->assertStringContainsString('related-concepts-container', $content);
    }

    public function testThematicSearchLinksPassTopicToProfessorProfile(): void
    {
        $client = static::createClient();
        $term = $this->createSampleData($client);

        $crawler = $client->request('GET', '/temas?t=' . $term->getSlug());
        $this->assertResponseIsSuccessful();

        $content = (string)$client->getResponse()->getContent();
        // Asserts that researcher cards contain ?tema= with urlencoded topic name
        $this->assertStringContainsString('?tema=', $content);
        $this->assertStringContainsString('/professor/', $content);

        // Also test that visiting professor profile with ?tema= pre-fills the filter search
        $researcher = $client->getContainer()->get('doctrine.orm.entity_manager')->getRepository(Researcher::class)->findOneBy([]);
        if ($researcher) {
            $identifier = $researcher->getSlug() ?: $researcher->getIdLattes();
            $client->request('GET', '/professor/' . $identifier . '?tema=inteligencia');
            $this->assertResponseIsSuccessful();
            $profContent = (string)$client->getResponse()->getContent();
            $this->assertStringContainsString('value="inteligencia"', $profContent);
            $this->assertStringContainsString('filterTopic', $profContent);
        }
    }
}
