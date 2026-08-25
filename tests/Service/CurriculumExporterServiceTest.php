<?php

namespace App\Tests\Service;

use App\Entity\Researcher;
use App\Service\Export\CurriculumExporterService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CurriculumExporterServiceTest extends KernelTestCase
{
    private CurriculumExporterService $exporter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->exporter = static::getContainer()->get(CurriculumExporterService::class);
    }

    public function testExportJson(): void
    {
        $r = new Researcher();
        $r->setFullName('Dr. Test Scholar');
        $r->setIdLattes('1234567890123456');
        $r->setDepartment('Departamento de Educação');

        $response = $this->exporter->exportJson([$r]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Dr. Test Scholar', (string)$response->getContent());
    }

    public function testExportCsv(): void
    {
        $r = new Researcher();
        $r->setFullName('Dr. Test Scholar');
        $r->setIdLattes('1234567890123456');
        $r->setDepartment('Departamento de Educação');

        $response = $this->exporter->exportCsv([$r]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Dr. Test Scholar', (string)$response->getContent());
    }

    public function testExportXml(): void
    {
        $r = new Researcher();
        $r->setFullName('Dr. Test Scholar');
        $r->setIdLattes('1234567890123456');
        $r->setDepartment('Departamento de Educação');

        $response = $this->exporter->exportXml([$r]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Dr. Test Scholar', (string)$response->getContent());
    }
}
