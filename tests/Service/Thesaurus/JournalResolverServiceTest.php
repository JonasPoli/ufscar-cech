<?php

namespace App\Tests\Service\Thesaurus;

use App\Entity\QualisJournal;
use App\Entity\JournalVariation;
use App\Service\Thesaurus\JournalResolverService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class JournalResolverServiceTest extends KernelTestCase
{
    private JournalResolverService $resolver;
    private $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resolver = static::getContainer()->get(JournalResolverService::class);
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
    }

    public function testThesaurusVariationAndKeywordResolution(): void
    {
        // 1. Ensure test journal exists
        $journal = $this->em->getRepository(QualisJournal::class)->findOneBy(['title' => 'BOLETIM IBCCRIM']);
        if (!$journal) {
            $journal = new QualisJournal();
            $journal->setTitle('BOLETIM IBCCRIM');
            $journal->setIssn('1676-3661');
            $journal->setQualis('C');
            $this->em->persist($journal);

            $var = new JournalVariation();
            $var->setJournal($journal);
            $var->setVariationName('Boletim do Instituto Brasileiro de Ciências Criminais');
            $var->setNormalizedName('boletim do instituto brasileiro de ciencias criminais');
            $this->em->persist($var);

            $this->em->flush();
        }

        // Test exact canonical title
        $this->assertEquals('C', $this->resolver->resolveQualis('BOLETIM IBCCRIM'));

        // Test title with stop words (Thesaurus / keyword matching)
        $this->assertEquals('C', $this->resolver->resolveQualis('Boletim do IBCCRIM'));

        // Test ISSN matching
        $this->assertEquals('C', $this->resolver->resolveQualis(null, '1676-3661'));
    }

    public function testNormalizeStringAndCleanKeywords(): void
    {
        $this->assertEquals('revista direito em debate', JournalResolverService::normalizeString('REVISTA DIREITO EM DEBATE'));
        $this->assertEquals('direito debate', JournalResolverService::cleanKeywords('REVISTA DIREITO EM DEBATE'));
    }
}
