<?php

namespace App\Tests\Service;

use App\Entity\Researcher;
use App\Service\Import\LattesXmlParserService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LattesXmlParserServiceTest extends KernelTestCase
{
    private LattesXmlParserService $parser;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->parser = static::getContainer()->get(LattesXmlParserService::class);
    }

    public function testParseSampleLattesXml(): void
    {
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $files = glob($projectDir . '/docs/banco/CECH/*.xml');
        if (empty($files)) {
            $this->markTestSkipped('No sample Lattes XML found.');
        }

        $sampleXml = $files[0];
        $researcher = $this->parser->parseAndSave($sampleXml);

        $this->assertInstanceOf(Researcher::class, $researcher);
        $this->assertNotEmpty($researcher->getIdLattes());
        $this->assertNotEmpty($researcher->getFullName());
        $this->assertNotEmpty($researcher->getSlug());
        $this->assertGreaterThanOrEqual(0, count($researcher->getProductions()));
    }
}
