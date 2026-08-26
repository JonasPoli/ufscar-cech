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

    public function testResolveAcrossThreeIssnFields(): void
    {
        $this->resolver->clearCache();

        $uniq = rand(1000, 9999);
        $issnImp = '66' . rand(10, 99) . '-' . $uniq;
        $issnE = '55' . rand(10, 99) . '-' . $uniq;
        $issnL = '44' . rand(10, 99) . '-' . $uniq;

        $uniqueTitle = 'JOURNAL OF TRIPLE ISSN TEST ' . uniqid();
        $journal = new QualisJournal();
        $journal->setTitle($uniqueTitle);
        $journal->setIssnImp($issnImp);
        $journal->setIssnE($issnE);
        $journal->setIssnL($issnL);
        $journal->setQualis('A1');
        $this->em->persist($journal);
        $this->em->flush();

        $this->resolver->clearCache();

        // 1. Resolve Qualis via issn_imp
        $this->assertEquals('A1', $this->resolver->resolveQualis(null, $issnImp));

        // 2. Resolve Qualis via issn_e
        $this->assertEquals('A1', $this->resolver->resolveQualis(null, $issnE));

        // 3. Resolve Qualis via issn_l
        $this->assertEquals('A1', $this->resolver->resolveQualis(null, $issnL));

        // 4. Resolve canonical entity via any of the 3 ISSNs
        $resolvedByImp = $this->resolver->resolveJournal(null, $issnImp);
        $this->assertNotNull($resolvedByImp);
        $this->assertEquals($uniqueTitle, $resolvedByImp->getTitle());

        $resolvedByE = $this->resolver->resolveJournal(null, $issnE);
        $this->assertNotNull($resolvedByE);
        $this->assertEquals($uniqueTitle, $resolvedByE->getTitle());

        $resolvedByL = $this->resolver->resolveJournal(null, $issnL);
        $this->assertNotNull($resolvedByL);
        $this->assertEquals($uniqueTitle, $resolvedByL->getTitle());
    }

    public function testNormalizeStringAndCleanKeywords(): void
    {
        $this->assertEquals('revista direito em debate', JournalResolverService::normalizeString('REVISTA DIREITO EM DEBATE'));
        $this->assertEquals('direito debate', JournalResolverService::cleanKeywords('REVISTA DIREITO EM DEBATE'));
    }

    public function testResolveAcademicDatabases(): void
    {
        $this->resolver->clearCache();

        $uniq = rand(1000, 9999);
        $issn = '77' . rand(10, 99) . '-' . $uniq;
        $title = 'INDEXED JOURNAL TEST ' . uniqid();

        $dbScopus = $this->em->getRepository(\App\Entity\AcademicDatabase::class)->findOneBy(['acronym' => 'scopus']);
        if (!$dbScopus) {
            $dbScopus = new \App\Entity\AcademicDatabase();
            $dbScopus->setName('Scopus');
            $dbScopus->setAcronym('scopus');
            $this->em->persist($dbScopus);
        }

        $journal = new QualisJournal();
        $journal->setTitle($title);
        $journal->setIssnImp($issn);
        $journal->setQualis('A1');
        $journal->addAcademicDatabase($dbScopus);
        $this->em->persist($journal);
        $this->em->flush();

        $this->resolver->clearCache();

        // 1. Resolve databases via ISSN
        $dbsByIssn = $this->resolver->resolveDatabases(null, $issn);
        $this->assertNotEmpty($dbsByIssn);
        $this->assertEquals('Scopus', $dbsByIssn[0]['name']);
        $this->assertEquals('scopus', $dbsByIssn[0]['acronym']);

        // 2. Resolve databases via Title
        $dbsByTitle = $this->resolver->resolveDatabases($title);
        $this->assertNotEmpty($dbsByTitle);
        $this->assertEquals('Scopus', $dbsByTitle[0]['name']);

        // 3. Test QualisExtension Twig rendering
        $extension = static::getContainer()->get(\App\Twig\QualisExtension::class);
        $badgeHtml = $extension->renderDatabaseBadges($title, $issn);
        $this->assertStringContainsString('Scopus', $badgeHtml);

        $journalBadgesHtml = $extension->renderJournalBadges($title, $issn);
        $this->assertStringContainsString('Qualis A1', $journalBadgesHtml);
        $this->assertStringContainsString('Scopus', $journalBadgesHtml);
    }
}

