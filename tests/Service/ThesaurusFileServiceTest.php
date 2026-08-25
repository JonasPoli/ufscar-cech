<?php

namespace App\Tests\Service;

use App\Service\Thesaurus\ThesaurusFileService;
use PHPUnit\Framework\TestCase;

class ThesaurusFileServiceTest extends TestCase
{
    private ThesaurusFileService $service;

    protected function setUp(): void
    {
        $this->service = new ThesaurusFileService();
    }

    public function testParseVantagePointFormat(): void
    {
        $content = "Brazil\n; Brasil\n; Republica Federativa do Brasil\n\nArgentina\n; Republica Argentina\n";
        $records = $this->service->parseTheContent($content);

        $this->assertCount(2, $records);
        $this->assertSame('Brazil', $records[0]['preferred_name']);
        $this->assertContains('Brasil', $records[0]['variants']);
        $this->assertContains('Republica Federativa do Brasil', $records[0]['variants']);

        $this->assertSame('Argentina', $records[1]['preferred_name']);
        $this->assertContains('Republica Argentina', $records[1]['variants']);
    }

    public function testParseCsvFormat(): void
    {
        $content = "termo_preferido;variantes\nBrasil;Brazil|Republica Federativa do Brasil\nArgentina;Republica Argentina\n";
        $records = $this->service->parseCsvContent($content);

        $this->assertCount(2, $records);
        $this->assertSame('Brasil', $records[0]['preferred_name']);
        $this->assertContains('Brazil', $records[0]['variants']);
        $this->assertSame('Argentina', $records[1]['preferred_name']);
    }

    public function testParseJsonFormat(): void
    {
        $data = [
            ['preferred_name' => 'Brasil', 'variants' => ['Brazil', 'BRA']],
            ['preferred_name' => 'Chile', 'variants' => ['CHL']],
        ];
        $records = $this->service->parseJsonContent(json_encode($data));

        $this->assertCount(2, $records);
        $this->assertSame('Brasil', $records[0]['preferred_name']);
        $this->assertSame(['Brazil', 'BRA'], $records[0]['variants']);
    }
}
