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

    public function testParseUtf16LeTheFile(): void
    {
        $utf8Content = "**Ademir Donizeti Caldeira\n0 1 ^Ademir Dionizete Caldeira$\n**Adriana Garcia Gonçalves\n0 1 ^Adriana Garcia Gonçalves$\n";
        $utf16leContent = "\xFF\xFE" . mb_convert_encoding($utf8Content, 'UTF-16LE', 'UTF-8');

        $records = $this->service->parseTheContent($utf16leContent);

        $this->assertCount(2, $records);
        $this->assertSame('Ademir Donizeti Caldeira', $records[0]['preferred_name']);
        $this->assertContains('Ademir Dionizete Caldeira', $records[0]['variants']);
        $this->assertSame('Adriana Garcia Gonçalves', $records[1]['preferred_name']);
    }

    public function testParseRealTheFiles(): void
    {
        $authorFile = '/Users/jonaspoli/work/html/ufscar-cech/docs/banco/2026-08-29 - Tesauro - nomes padronizados docentes do CECH.the';
        if (file_exists($authorFile)) {
            $records = $this->service->parseFile($authorFile);
            $this->assertCount(86, $records);
            $this->assertSame('Ademir Donizeti Caldeira', $records[0]['preferred_name']);
            $this->assertContains('Ademir Dionizete Caldeira', $records[0]['variants']);
        }

        $instFile = '/Users/jonaspoli/work/html/ufscar-cech/docs/banco/2026-08-12 - Tesauro - nomes padronizados instituições.the';
        if (file_exists($instFile)) {
            $records = $this->service->parseFile($instFile);
            $this->assertCount(2649, $records);
            $this->assertSame('aarhus univ', $records[0]['preferred_name']);
            $this->assertContains('univ aarhus', $records[0]['variants']);
        }
    }
}
