<?php

namespace App\Tests\Service;

use App\Entity\AcademicDatabase;
use App\Entity\QualisJournal;
use App\Service\Thesaurus\JournalDatabaseExporterService;
use App\Service\Thesaurus\JournalDatabaseImporterService;
use App\Service\Thesaurus\JournalFileDetectorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class JournalDatabaseTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private JournalFileDetectorService $detector;
    private JournalDatabaseImporterService $importer;
    private JournalDatabaseExporterService $exporter;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->detector = $container->get(JournalFileDetectorService::class);
        $this->importer = $container->get(JournalDatabaseImporterService::class);
        $this->exporter = $container->get(JournalDatabaseExporterService::class);

        // Ensure Scopus and WoS exist in test database
        $db = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => 'scopus']);
        if (!$db) {
            $db = new AcademicDatabase();
            $db->setName('Scopus');
            $db->setAcronym('scopus');
            $this->em->persist($db);
            $this->em->flush();
        }

        $wos = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => 'wos']);
        if (!$wos) {
            $wos = new AcademicDatabase();
            $wos->setName('Web of Science');
            $wos->setAcronym('wos');
            $this->em->persist($wos);
            $this->em->flush();
        }
    }

    public function testDetectionAndImportFromCsv(): void
    {
        $uniqueId = rand(1000, 9999);
        $issn1 = '88' . rand(10, 99) . '-' . $uniqueId;
        $issn2 = '77' . rand(10, 99) . '-' . $uniqueId;
        $title1 = 'UNIQUE TEST JOURNAL ' . uniqid();
        $title2 = 'ANOTHER UNIQUE REVIEW ' . uniqid();

        $tmpCsv = sys_get_temp_dir() . '/test_wos_import_' . uniqid() . '.csv';
        $content = "\"Journal title\",\"ISSN\",\"eISSN\",\"Publisher name\",\"Web of Science Categories\"\n"
                 . "\"{$title1}\",\"{$issn1}\",\"{$issn2}\",\"TEST PUBLISHER\",\"Education & Educational Research\"\n"
                 . "\"{$title2}\",\"{$issn2}\",\"\",\"SCIENCE PRESS\",\"Social Sciences\"\n";
        file_put_contents($tmpCsv, $content);

        // 1. Detect
        $detection = $this->detector->detect($tmpCsv);
        $this->assertEquals('wos', $detection['acronym']);
        $this->assertGreaterThanOrEqual(0.90, $detection['confidence']);

        // 2. Import into Scopus
        $importResult = $this->importer->import($tmpCsv, 'scopus');
        $this->assertTrue($importResult['success']);
        $this->assertEquals(2, $importResult['totalRead']);
        $this->assertGreaterThanOrEqual(1, $importResult['inserted'] + $importResult['updated']);

        // 3. Verify in DB
        $norm1 = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $issn1));
        $j1 = $this->em->getRepository(QualisJournal::class)->findByAnyIssn($norm1);
        $this->assertNotNull($j1);
        $this->assertEquals($title1, $j1->getTitle());
        $this->assertTrue($j1->hasAcademicDatabase('scopus'));

        // 4. Export
        $exportResult = $this->exporter->export(null, 'scopus', 'csv');
        $this->assertGreaterThan(0, $exportResult['totalExported']);
        $this->assertStringContainsString($title1, (string)$exportResult['content']);
        $this->assertStringContainsString($issn1, (string)$exportResult['content']);

        @unlink($tmpCsv);
    }
}
