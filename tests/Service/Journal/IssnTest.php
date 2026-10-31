<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Entity\QualisJournal;
use App\Service\Journal\Issn;
use PHPUnit\Framework\TestCase;

class IssnTest extends TestCase
{
    public function testExtractAllNormalizesAndKeepsOrderWithoutRepeats(): void
    {
        $this->assertSame(['18069053', '01026909'], Issn::extractAll('1806-9053, 0102-6909'));
        $this->assertSame(['01026909', '18069053'], Issn::extractAll('0102-6909 e 0102-6909 e 1806-9053'));
    }

    public function testExtractAllKeepsUppercaseCheckCharacterAsLowercase(): void
    {
        $this->assertSame(['0103863x'], Issn::extractAll('0103-863X'));
    }

    public function testExtractAllDiscardsInvalidCheckDigitUnlessAsked(): void
    {
        // Para 0102-690 o dígito correto é 9; 0102-6900 traz 0, então é inválido.
        $this->assertSame([], Issn::extractAll('0102-6900'));
        $this->assertSame(['01026900'], Issn::extractAll('0102-6900', false));
    }

    public function testExtractAllWithoutIssnReturnsEmptyList(): void
    {
        $this->assertSame([], Issn::extractAll(null));
        $this->assertSame([], Issn::extractAll(''));
        $this->assertSame([], Issn::extractAll('sem issn aqui'));
    }

    public function testIsValidChecksTheCheckDigit(): void
    {
        $this->assertTrue(Issn::isValid('01026909'));
        $this->assertFalse(Issn::isValid('01026900'));
        $this->assertTrue(Issn::isValid('0103863x'));
        $this->assertTrue(Issn::isValid('0103863X'));
    }

    public function testIsValidRejectsAnythingButNormalizedForm(): void
    {
        $this->assertFalse(Issn::isValid('0103-863X'));
        $this->assertFalse(Issn::isValid('0103863'));
        $this->assertFalse(Issn::isValid('abcdefgh'));
    }

    public function testFormatAddsHyphenAndUppercasesX(): void
    {
        $this->assertSame('0102-6909', Issn::format('01026909'));
        $this->assertSame('0103-863X', Issn::format('0103863x'));
    }

    public function testFormatReturnsInputWhenNotNormalizedForm(): void
    {
        $this->assertSame('0102-6909', Issn::format('0102-6909'));
        $this->assertSame('abc', Issn::format('abc'));
    }

    public function testNormalizeLooseMatchesQualisJournalCriterion(): void
    {
        $samples = [null, '', '   ', '1806-9053', '0103-863X', ' 1806 9053 ', 'abc-.-'];
        foreach ($samples as $sample) {
            $this->assertSame(
                QualisJournal::normalizeIssnString($sample),
                Issn::normalizeLoose($sample),
                var_export($sample, true)
            );
        }
        $this->assertSame('0103863x', Issn::normalizeLoose('0103-863X'));
    }
}
