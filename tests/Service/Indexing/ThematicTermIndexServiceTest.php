<?php

declare(strict_types=1);

namespace App\Tests\Service\Indexing;

use App\Service\Indexing\ThematicTermIndexService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ThematicTermIndexServiceTest extends KernelTestCase
{
    private ThematicTermIndexService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->service = $container->get(ThematicTermIndexService::class);
    }

    public function testNormalizeStringRemovesAccentsAndPunctuation(): void
    {
        $this->assertEquals('educacao especial', $this->service->normalizeString('Educação Especial!'));
        $this->assertEquals('inteligencia artificial redes neurais', $this->service->normalizeString('Inteligência Artificial & Redes Neurais'));
        $this->assertEquals('politicas publicas', $this->service->normalizeString('  POLÍTICAS PÚBLICAS  '));
        $this->assertEquals('ciencias humanas sociais', $this->service->normalizeString('Ciências Humanas / Sociais'));
    }

    public function testIndexAllProducesValidOutput(): void
    {
        // Executa indexAll e verifica a consistência do array de retorno
        $stats = $this->service->indexAll();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('totalTerms', $stats);
        $this->assertArrayHasKey('totalLinks', $stats);
        $this->assertArrayHasKey('totalResearchers', $stats);
        $this->assertArrayHasKey('executionTime', $stats);

        $this->assertGreaterThan(0, $stats['totalTerms']);
        $this->assertGreaterThan(0, $stats['totalLinks']);
        $this->assertGreaterThan(0, $stats['totalResearchers']);
    }
}
