<?php

namespace App\Tests\Service;

use App\Service\Thesaurus\StringNormalizer;
use PHPUnit\Framework\TestCase;

class StringNormalizerTest extends TestCase
{
    public function testNormalizeString(): void
    {
        $this->assertSame('UNIVERSIDADE FEDERAL DE SAO CARLOS', StringNormalizer::normalizeString('Universidade Federal de São Carlos'));
        $this->assertSame('BRASIL', StringNormalizer::normalizeString('Brasil'));
        $this->assertSame('EDUCACAO', StringNormalizer::normalizeString('Educação'));
        $this->assertSame('CIENCIA', StringNormalizer::normalizeString('Ciência'));
    }

    public function testSlugify(): void
    {
        $this->assertSame('universidade-federal-de-sao-carlos', StringNormalizer::slugify('Universidade Federal de São Carlos'));
        $this->assertSame('joao-da-silva', StringNormalizer::slugify('João da Silva'));
    }
}
