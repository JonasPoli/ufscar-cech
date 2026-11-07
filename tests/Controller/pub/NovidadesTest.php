<?php

declare(strict_types=1);

namespace App\Tests\Controller\pub;

use App\Service\Novidades\NovidadesService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Routing\RouterInterface;

/**
 * Histórico de novidades: a página /novidades, a dica no topo das páginas que mudaram e o arquivo
 * config/novidades.yaml (rotas existentes, entradas completas).
 */
class NovidadesTest extends WebTestCase
{
    public function testNewsPageListsEveryEntryWithItsGitCommits(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie('TESTE_SESSAO', '1'));
        $novidades = static::getContainer()->get(NovidadesService::class);

        $crawler = $client->request('GET', '/novidades');
        $this->assertResponseIsSuccessful();

        $entries = $novidades->entries();
        $this->assertCount(count($entries), $crawler->filter('ol > li[id^="d-"]'));
        $first = $entries[0];
        $this->assertStringContainsString($first['titulo'], $crawler->filter('#d-' . $first['data'])->text());
        $this->assertStringContainsString($first['commits'][0]['hash'], $crawler->filter('#d-' . $first['data'])->text());
    }

    public function testChangedPageShowsTheTipAndUnchangedPageDoesNot(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie('TESTE_SESSAO', '1'));

        $crawler = $client->request('GET', '/busca');
        $this->assertResponseIsSuccessful();
        $tip = $crawler->filter('aside[id^="dica-app_pub_search-"]');
        $this->assertCount(1, $tip);
        $this->assertStringContainsString('Antes:', $tip->text());
        $this->assertStringContainsString('Agora:', $tip->text());

        // Periódicos não mudou nesta atualização: sem dica
        $crawler = $client->request('GET', '/periodicos');
        $this->assertCount(0, $crawler->filter('aside[id^="dica-"]'));
    }

    public function testDataFileIsConsistent(): void
    {
        self::bootKernel();
        $novidades = static::getContainer()->get(NovidadesService::class);
        $router = static::getContainer()->get(RouterInterface::class);

        foreach ($novidades->routes() as $route) {
            $this->assertNotNull($router->getRouteCollection()->get($route), "A rota $route citada em config/novidades.yaml não existe");
        }

        $hashes = [];
        foreach ($novidades->entries() as $entry) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $entry['data']);
            $this->assertNotSame('', $entry['titulo']);
            $this->assertNotEmpty($entry['mudancas'], "A entrada de {$entry['data']} não explica o que mudou");
            foreach ($entry['commits'] as $commit) {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{7,40}$/', $commit['hash']);
                $this->assertNotSame('', $commit['texto']);
                $this->assertNotContains($commit['hash'], $hashes, "O commit {$commit['hash']} aparece duas vezes");
                $hashes[] = $commit['hash'];
            }
        }

        $this->assertTrue($novidades->isNewInMenu('app_pub_coauthorship', new \DateTimeImmutable('2026-11-08')));
        $this->assertFalse($novidades->isNewInMenu('app_pub_coauthorship', new \DateTimeImmutable('2030-01-01')));
        $this->assertFalse($novidades->isNewInMenu('app_pub_journals_index', new \DateTimeImmutable('2026-11-08')));
    }
}
