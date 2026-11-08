<?php

declare(strict_types=1);

namespace App\Tests\Service\Research;

use App\Service\Research\CoauthorshipGraphService;
use App\Service\Research\ResearchFilter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CoauthorshipGraphServiceTest extends KernelTestCase
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

    public function testPairCountIsTheRealTotalEvenWhenTheListIsLimited(): void
    {
        $a = $this->insertResearcher("Ana Par {$this->t}");
        $b = $this->insertResearcher("Bruno Par {$this->t}");
        foreach ([2019, 2020, 2021] as $year) {
            $this->insertSharedWork([$a, $b], "Obra em comum $year {$this->t}", $year);
        }
        $filter = new ResearchFilter();
        $filter->department = $this->dept();

        $pair = static::getContainer()->get(CoauthorshipGraphService::class)->pairWorks($a, $b, $filter, 2);

        $this->assertCount(2, $pair['results'], 'A lista respeita o limite');
        $this->assertSame(3, $pair['count'], 'O total não para no limite da lista');
    }
}
