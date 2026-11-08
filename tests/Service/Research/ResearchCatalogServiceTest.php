<?php

declare(strict_types=1);

namespace App\Tests\Service\Research;

use App\Service\Research\ResearchCatalogService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ResearchCatalogServiceTest extends KernelTestCase
{
    use ResearchFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->t = $this->newToken();
        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        parent::tearDown();
    }

    public function testAResearcherWithBothSpellingsOfAMajorAreaCountsOnce(): void
    {
        $id = $this->insertResearcher("Docente Duas Grafias {$this->t}");
        $this->insertKnowledgeArea($id, 'CIENCIAS_HUMANAS', 'Educação');
        $this->insertKnowledgeArea($id, 'Ciências Humanas', 'Psicologia');
        $this->insertItem($id, "Obra única {$this->t}", 2024);
        static::getContainer()->get('cache.research')->clear();

        $humanas = null;
        foreach (static::getContainer()->get(ResearchCatalogService::class)->majorAreas() as $row) {
            if ($row['code'] === 'CIENCIAS_HUMANAS') {
                $humanas = $row;
            }
        }

        $distinct = (int)$this->conn->fetchOne("SELECT COUNT(DISTINCT researcher_id) FROM knowledge_areas WHERE major_area IN ('CIENCIAS_HUMANAS', 'Ciências Humanas')");
        $this->assertNotNull($humanas);
        $this->assertSame($distinct, $humanas['researchers'], 'Somar grafia por grafia contaria o docente duas vezes');
    }
}
