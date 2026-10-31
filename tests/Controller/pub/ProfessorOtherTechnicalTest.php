<?php

declare(strict_types=1);

namespace App\Tests\Controller\pub;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * "Demais tipos de produção técnica" do Lattes têm seção própria na página do professor. Cada teste roda numa transação revertida.
 */
class ProfessorOtherTechnicalTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->client->getCookieJar()->set(new Cookie('TESTE_SESSAO', '1'));
        $this->conn = static::getContainer()->get('doctrine.orm.entity_manager')->getConnection();
        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        parent::tearDown();
    }

    public function testSectionShowsTheItemWithAReadableType(): void
    {
        $slug = 'docente-tecnica-' . bin2hex(random_bytes(4));
        $this->conn->insert('researchers', [
            'id_lattes' => substr('98' . random_int(10 ** 13, 10 ** 14 - 1) . '00', 0, 16),
            'full_name' => 'Docente Técnica',
            'slug' => $slug,
            'status' => 1,
            'created_at' => '2026-10-31 00:00:00',
            'updated_at' => '2026-10-31 00:00:00',
        ]);
        $this->conn->insert('production_items', [
            'researcher_id' => (int)$this->conn->lastInsertId(),
            'item_type' => 'OUTRA_TECNICA',
            'nature' => 'APRESENTACAO-DE-TRABALHO',
            'title' => 'Apresentação sobre avaliação escolar',
            'year' => 2024,
            'order_sequence' => 1,
            'created_at' => '2026-10-31 00:00:00',
            'updated_at' => '2026-10-31 00:00:00',
        ]);

        $crawler = $this->client->request('GET', '/professor/' . $slug);

        self::assertResponseIsSuccessful();
        $section = $crawler->filter('.prod-section[data-section-category="tecnico-demais"]');
        $this->assertCount(1, $section);
        $this->assertStringContainsString('Demais Produções Técnicas (1)', $section->text());
        $this->assertStringContainsString('Apresentação sobre avaliação escolar', $section->text());
        $this->assertStringContainsString('Apresentação de trabalho', $section->text());
    }
}
