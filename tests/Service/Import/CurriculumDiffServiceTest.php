<?php

namespace App\Tests\Service\Import;

use App\Entity\Orientation;
use App\Entity\ProductionItem;
use App\Entity\Researcher;
use App\Service\Import\CurriculumDiffService;
use PHPUnit\Framework\TestCase;

class CurriculumDiffServiceTest extends TestCase
{
    public function testSnapshotAndDiffCalculation(): void
    {
        $diffService = new CurriculumDiffService();

        // 1. New researcher
        $newResearcher = new Researcher();
        $newResearcher->setFullName('Maria Silva');
        $newResearcher->setIdLattes('1234567890123456');

        $snapshotNew = $diffService->takeSnapshot($newResearcher);
        $this->assertFalse($snapshotNew['exists']);

        $p1 = new ProductionItem();
        $p1->setItemType(ProductionItem::TYPE_ARTIGO);
        $p1->setTitle('Estudo sobre Educação e Sociedade');
        $p1->setYear(2024);
        $p1->setJournalName('Revista CECH');
        $newResearcher->addProduction($p1);

        $o1 = new Orientation();
        $o1->setOrientationType(Orientation::TYPE_MESTRADO);
        $o1->setStudentName('João Souza');
        $o1->setYear(2024);
        $newResearcher->addOrientation($o1);

        $reportNew = $diffService->computeReport($newResearcher, $snapshotNew);

        $this->assertTrue($reportNew['isNewResearcher']);
        $this->assertEquals(1, $reportNew['summary']['articles']);
        $this->assertEquals(1, $reportNew['summary']['orientations']);
        $this->assertEquals(2, $reportNew['summary']['totalAdded']);
        $this->assertCount(1, $reportNew['addedItems']['articles']);
        $this->assertEquals('Estudo sobre Educação e Sociedade', $reportNew['addedItems']['articles'][0]['title']);

        // 2. Existing researcher update with new article
        // Simulate existing snapshot
        $refProp = new \ReflectionProperty(Researcher::class, 'id');
        $refProp->setAccessible(true);
        $refProp->setValue($newResearcher, 42);

        $snapshotExisting = $diffService->takeSnapshot($newResearcher);
        $this->assertTrue($snapshotExisting['exists']);

        // Add a second article
        $p2 = new ProductionItem();
        $p2->setItemType(ProductionItem::TYPE_ARTIGO);
        $p2->setTitle('Novo Artigo de Filosofia');
        $p2->setYear(2025);
        $p2->setJournalName('Revista de Filosofia');
        $newResearcher->addProduction($p2);

        $reportUpdate = $diffService->computeReport($newResearcher, $snapshotExisting);

        $this->assertFalse($reportUpdate['isNewResearcher']);
        $this->assertEquals(1, $reportUpdate['summary']['articles']);
        $this->assertEquals(0, $reportUpdate['summary']['orientations']);
        $this->assertEquals(1, $reportUpdate['summary']['totalAdded']);
        $this->assertCount(1, $reportUpdate['addedItems']['articles']);
        $this->assertEquals('Novo Artigo de Filosofia', $reportUpdate['addedItems']['articles'][0]['title']);
    }
}
