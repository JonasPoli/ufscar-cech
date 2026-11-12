<?php

declare(strict_types=1);

namespace App\Tests\Service\Research;

use App\Service\Research\CoauthorshipGraphService;
use App\Service\Research\ResearchFilter;
use App\Service\Research\ResearchMetricsService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** O núcleo é guardado uma vez por filtro; com ou sem pontes o retorno é o mesmo, e as pontes vêm do grafo. */
class ResearchMetricsServiceTest extends KernelTestCase
{
    /** @dataProvider filters */
    public function testCoreIsTheSameWithAndWithoutBridges(?string $department): void
    {
        self::bootKernel();
        $svc = static::getContainer()->get(ResearchMetricsService::class);
        $graph = static::getContainer()->get(CoauthorshipGraphService::class);

        $filter = new ResearchFilter();
        if ($department !== null) {
            $filter->department = $department;
        }

        $with = $svc->metrics($filter);
        $without = $svc->metrics($filter, false);

        $this->assertNull($without['bridges']);
        $this->assertSame($graph->bridges($filter), $with['bridges']);
        $this->assertSame(
            array_keys($with),
            ['publications', 'researchers', 'latestYear', 'sharedWorks', 'pairs', 'years', 'kinds', 'topics', 'bridges']
        );
        unset($with['bridges'], $without['bridges']);
        $this->assertSame($with, $without);
    }

    /** @return array<string, array{0: ?string}> */
    public static function filters(): array
    {
        return ['filtro vazio' => [null], 'departamento' => ['Departamento de Artes e Comunicação']];
    }
}
