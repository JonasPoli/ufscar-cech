<?php

namespace App\Tests\Service\Thesaurus;

use App\Entity\QualisJournal;
use App\Entity\JournalVariation;
use App\Service\Thesaurus\JournalResolverService;
use App\Service\Thesaurus\StringNormalizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class JournalResolverServiceTest extends KernelTestCase
{
    private JournalResolverService $resolver;
    private $em;

    /** @var int[] periódicos criados pelos testes de vínculo; removidos no tearDown (variantes caem em cascata) */
    private array $createdJournalIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resolver = static::getContainer()->get(JournalResolverService::class);
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
    }

    protected function tearDown(): void
    {
        if ($this->createdJournalIds !== []) {
            $this->em->getConnection()->executeStatement(
                'DELETE FROM qualis_journals WHERE id IN (' . implode(',', array_map('intval', $this->createdJournalIds)) . ')'
            );
            $this->createdJournalIds = [];
            $this->em->clear();
        }

        parent::tearDown();
    }

    public function testThesaurusVariationAndKeywordResolution(): void
    {
        // 1. Ensure test journal exists
        $journal = $this->em->getRepository(QualisJournal::class)->findOneBy(['title' => 'BOLETIM IBCCRIM']);
        if ($journal) {
            // Linha criada antes da coluna normalized_title existir: preenche pelo setter
            $journal->setTitle($journal->getTitle());
            $this->em->flush();
        } else {
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

        // Conectivo a mais ("do"): resolve pela chave compacta (A5.2, etapa 4), não por palavras-chave
        $this->assertEquals('C', $this->resolver->resolveQualis('Boletim do IBCCRIM'));

        // Palavras-chave nunca viram vínculo: o nome abaixo só coincide em "ibccrim", então não resolve,
        // mas a fila de revisão recebe o periódico como sugestão
        $this->assertNull($this->resolver->resolveQualis('Revista do IBCCRIM'));
        $this->assertSame($journal->getId(), $this->resolver->suggestByKeywords('Revista do IBCCRIM'));
        $this->assertSame($journal->getId(), $this->resolver->suggestByKeywords('Boletim do IBCCRIM'));

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

    public function testNormalizeStringAccents(): void
    {
        $this->assertEquals('historia da educacao', JournalResolverService::normalizeString('História da Educação'));
        $this->assertEquals('ciencia e educacao unesp', JournalResolverService::normalizeString('Ciência e Educação (UNESP)'));
        $this->assertEquals('educacao sociedade', JournalResolverService::normalizeString('EDUCAÇÃO & SOCIEDADE'));
        $this->assertEquals('psicologia teoria e pesquisa unb impresso', JournalResolverService::normalizeString('Psicologia: Teoria e Pesquisa (UnB. Impresso)'));
        $this->assertEquals('revista direito em debate', JournalResolverService::normalizeString('REVISTA DIREITO EM DEBATE'));
    }

    // ------------------------------------------------------------------------------------------------
    // Vínculo por etapas (A5.2). Cada caso roda nos dois modos (on-demand e lote) e exige o mesmo resultado.
    // ------------------------------------------------------------------------------------------------

    public function testAccentedNameResolvesByExactTitle(): void
    {
        $t = $this->token();
        $journal = $this->createJournal("HISTÓRIA DA EDUCAÇÃO $t", null, 'a2');

        $this->assertSame($journal->getId(), $this->resolvedId("História da Educação $t"));
        $this->assertSame($journal->getId(), $this->resolvedId("historia da educacao $t"));
        $this->assertSame($journal->getId(), $this->resolvedId("HISTÓRIA  DA EDUCAÇÃO — $t"));
        $this->assertSame('A2', $this->inBothModes(fn (JournalResolverService $r) => $r->resolveQualis("História da Educação $t")));
        $this->assertNull($this->resolvedId("História da Educação $t Extra"));
    }

    public function testSameTitleInTwoFamiliesIsAmbiguousAndNeverResolvesAlone(): void
    {
        $t = $this->token();
        $issnA = $this->issn();
        $issnB = $this->issn();
        $a = $this->createJournal("CADERNOS DE PESQUISA $t", $issnA);
        $b = $this->createJournal("Cadernos de Pesquisa $t", $issnB);
        $name = "Cadernos de Pesquisa $t";

        $result = $this->inBothModes(fn (JournalResolverService $r) => [
            'journal' => $r->resolveJournalIdAndQualis($name),
            'ambiguous' => $r->consumeAmbiguous(),
            'isAmbiguousName' => $r->isAmbiguousName($name),
        ]);
        $this->assertNull($result['journal']);
        $this->assertContains($name, $result['ambiguous']);
        $this->assertTrue($result['isAmbiguousName']);

        // Com parênteses (etapa 3) o nome continua pertencendo às duas famílias
        $withParens = "$name (Fundação Carlos Chagas)";
        $result = $this->inBothModes(fn (JournalResolverService $r) => [
            'journal' => $r->resolveJournalIdAndQualis($withParens),
            'ambiguous' => $r->consumeAmbiguous(),
        ]);
        $this->assertNull($result['journal']);
        $this->assertContains($withParens, $result['ambiguous']);

        // O ISSN decide (etapa 1) e dispensa o nome: nada fica registrado como ambíguo
        $result = $this->inBothModes(fn (JournalResolverService $r) => [
            'a' => $r->resolveJournalIdAndQualis($name, $issnA)['id'] ?? null,
            'b' => $r->resolveJournalIdAndQualis($name, $issnB)['id'] ?? null,
            'ambiguous' => $r->consumeAmbiguous(),
        ]);
        $this->assertSame($a->getId(), $result['a']);
        $this->assertSame($b->getId(), $result['b']);
        $this->assertSame([], $result['ambiguous']);
    }

    public function testPrintAndOnlineRowsOfTheSameFamilyResolve(): void
    {
        $t = $this->token();
        $print = $this->createJournal("PSICOLOGIA ESCOLAR $t", $this->issn(), 'b1');
        $online = $this->createJournal("Psicologia Escolar $t", $this->issn(), null, $print);
        $name = "Psicologia Escolar $t";

        // Mesma família: resolve para a linha de menor id entre as de mesmo título
        $this->assertSame($print->getId(), $this->resolvedId($name));
        $this->assertSame('B1', $this->inBothModes(fn (JournalResolverService $r) => $r->resolveQualis($name)));
        $this->assertSame([], $this->inBothModes(fn (JournalResolverService $r) => $r->consumeAmbiguous()));

        // A linha de menor id vence mesmo quando a canônica da família é a de maior id
        $u = $this->token();
        $low = $this->createJournal("ESTUDOS EM AVALIAÇÃO $u");
        $high = $this->createJournal("Estudos em Avaliação $u");
        $low->setCanonical($high);
        $this->em->flush();
        $this->assertSame($low->getId(), $this->resolvedId("Estudos em Avaliação $u"));
    }

    public function testRowWithoutQualisInheritsTheQualisOfItsFamily(): void
    {
        $token = $this->token();
        $canonical = $this->createJournal("Revista Canonica {$token}", $this->issn(), 'A1');
        $printIssn = $this->issn();
        $this->createJournal("Revista Membro {$token}", $printIssn, null, $canonical);
        $alone = $this->createJournal("Revista Sozinha {$token}", $otherIssn = $this->issn());

        $this->assertSame('A1', $this->inBothModes(fn () => $this->resolver->resolveQualis(null, $printIssn)));
        $this->assertSame('A1', $this->inBothModes(fn () => $this->resolver->resolveJournalIdAndQualis(null, $printIssn)['qualis']));
        $this->assertSame($canonical->getId(), $this->inBothModes(fn () => $this->resolver->resolveJournalIdAndQualis(null, $printIssn)['id']), 'O artigo fica na linha que tem Qualis.');
        $this->assertNull($this->inBothModes(fn () => $this->resolver->resolveQualis(null, $otherIssn)), 'Linha sem família e sem Qualis continua sem estrato.');
    }

    public function testOwnQualisOfARowIsNotReplacedByTheFamily(): void
    {
        $token = $this->token();
        $canonical = $this->createJournal("Revista Canonica {$token}", $this->issn(), 'A1');
        $memberIssn = $this->issn();
        $this->createJournal("Revista Membro {$token}", $memberIssn, 'B2', $canonical);

        $this->assertSame('B2', $this->inBothModes(fn () => $this->resolver->resolveQualis(null, $memberIssn)));
    }

    public function testCompactKeyDropsConnectivesOnBothSides(): void
    {
        $t = $this->token();
        $journal = $this->createJournal("EDUCAÇÃO & SOCIEDADE $t");

        // "e" x "&": resolve pela chave compacta
        $this->assertSame($journal->getId(), $this->resolvedId("Educação e Sociedade $t"));
        // parênteses (etapa 3) e conectivos (etapa 4) juntos
        $this->assertSame($journal->getId(), $this->resolvedId("Educação e Sociedade $t (Campinas)"));
        $this->assertSame($journal->getId(), $this->resolvedId("Educacao Sociedade $t"));

        // Conectivo na frente do título do cadastro («The Journal of ...»)
        $u = $this->token();
        $english = $this->createJournal("THE JOURNAL OF $u STUDIES");
        $this->assertSame($english->getId(), $this->resolvedId("Journal of $u Studies"));

        // Restando uma só palavra depois dos conectivos, a chave compacta não vale
        $v = $this->token();
        $this->createJournal("DE $v");
        $this->assertNull($this->resolvedId($v));
    }

    public function testCompactKeyIsAlsoAmbiguousAcrossFamilies(): void
    {
        $t = $this->token();
        $this->createJournal("EDUCAÇÃO & PESQUISA $t", $this->issn());
        $this->createJournal("Educação e Pesquisa $t (Online)", $this->issn());
        $name = "Educacao em Pesquisa $t";

        // exato e sem parênteses não casam; a chave compacta casa com duas famílias (o título de uma delas só
        // tem a chave compacta depois de tirar os parênteses)
        $this->assertNull($this->resolvedId($name));
        $this->assertTrue($this->inBothModes(fn (JournalResolverService $r) => $r->isAmbiguousName($name)));
    }

    public function testCatalogTitleWithParenthesesAnswersToTheCleanName(): void
    {
        $t = $this->token();
        $journal = $this->createJournal("MOVIMENTO $t (PORTO ALEGRE)");

        $this->assertSame($journal->getId(), $this->resolvedId("Movimento $t"));
        $this->assertSame($journal->getId(), $this->resolvedId("Movimento $t (Porto Alegre)"));
        $this->assertSame($journal->getId(), $this->resolvedId("Movimento $t (UFRGS)"));
    }

    public function testKeywordsNeverCreateALinkButSuggest(): void
    {
        $t = $this->token();
        $journal = $this->createJournal("CADERNOS DE $t");
        $name = "Revista $t";

        $this->assertNull($this->resolvedId($name));
        $this->assertFalse($this->inBothModes(fn (JournalResolverService $r) => $r->isAmbiguousName($name)));
        $this->assertSame($journal->getId(), $this->inBothModes(fn (JournalResolverService $r) => $r->suggestByKeywords($name)));
        $this->assertSame($journal->getId(), $this->inBothModes(fn (JournalResolverService $r) => $r->suggestByKeywords("$name (UFSCar)")));
        $this->assertNull($this->inBothModes(fn (JournalResolverService $r) => $r->suggestByKeywords('Zzqq nome sem correspondência ' . $this->token())));
        $this->assertNull($this->inBothModes(fn (JournalResolverService $r) => $r->suggestByKeywords(null)));
    }

    public function testIssnStep(): void
    {
        $t = $this->token();
        $issn = $this->issn();
        $first = $this->createJournal("PRIMEIRA LINHA $t", $issn, 'b2');
        $this->createJournal("SEGUNDA LINHA $t", $issn, 'a1');

        // Duas linhas com o mesmo ISSN: a de menor id; formatos diferentes do mesmo ISSN
        $hyphen = substr($issn, 0, 4) . '-' . substr($issn, 4);
        $this->assertSame($first->getId(), $this->resolvedId(null, $issn));
        $this->assertSame($first->getId(), $this->resolvedId(null, $hyphen));
        $this->assertSame($first->getId(), $this->resolvedId(null, "ISSN $hyphen (impresso)"));
        // Dois ISSNs no mesmo campo: o primeiro que existir no cadastro vale
        $unknownIssn = $this->issn();
        $this->assertSame($first->getId(), $this->resolvedId(null, substr($unknownIssn, 0, 4) . '-' . substr($unknownIssn, 4) . " / $hyphen"));
        $this->assertSame('B2', $this->inBothModes(fn (JournalResolverService $r) => $r->resolveQualis('Nome qualquer', $hyphen)));
        // ISSN inexistente: cai no nome, que também não existe
        $this->assertNull($this->resolvedId("Nome que não existe $t", $unknownIssn));
    }

    public function testVariantsResolveOnlyWhenActive(): void
    {
        $t = $this->token();
        $journal = $this->createJournal("REVISTA OFICIAL $t");
        $this->addVariant($journal, "Grafia Alternativa $t");
        $this->addVariant($journal, "Grafia Antiga $t", false);

        $this->assertSame($journal->getId(), $this->resolvedId("Grafia Alternativa $t"));
        $this->assertSame($journal->getId(), $this->resolvedId("grafia alternativa $t (outra edicao)"));
        $this->assertNull($this->resolvedId("Grafia Antiga $t"));
    }

    public function testVariantAndTitleOfDifferentFamiliesAreAmbiguous(): void
    {
        $t = $this->token();
        $owner = $this->createJournal("PERSPECTIVA $t", $this->issn());
        $other = $this->createJournal("REVISTA PERSPECTIVAS $t", $this->issn());
        $this->addVariant($other, "Perspectiva $t");
        $name = "Perspectiva $t";

        $result = $this->inBothModes(fn (JournalResolverService $r) => [
            'journal' => $r->resolveJournalIdAndQualis($name),
            'ambiguous' => $r->consumeAmbiguous(),
        ]);
        $this->assertNull($result['journal']);
        $this->assertContains($name, $result['ambiguous']);

        // Uma variante na mesma família do título não é conflito
        $other->setCanonical($owner);
        $this->em->flush();
        $this->assertSame($owner->getId(), $this->resolvedId($name));
    }

    public function testStripParenthesesAndCompactKeyHelpers(): void
    {
        $this->assertSame('Educação & Sociedade ', JournalResolverService::stripParentheses('Educação & Sociedade (Campinas)'));
        $this->assertSame('', JournalResolverService::stripParentheses(null));
        $this->assertSame('educacao sociedade', JournalResolverService::compactKey('educacao e sociedade'));
        $this->assertSame('journal educational psychology', JournalResolverService::compactKey('the journal of educational psychology'));
        $this->assertNull(JournalResolverService::compactKey('revista de'));
        $this->assertNull(JournalResolverService::compactKey('movimento'));
        $this->assertNull(JournalResolverService::compactKey(''));
    }

    public function testNameResolutionNeverTouchesTheLattesText(): void
    {
        // O resolver só lê: a grafia recebida volta intacta em consumeAmbiguous()
        $t = $this->token();
        $this->createJournal("MEDICINA $t", $this->issn());
        $this->createJournal("Medicina $t", $this->issn());
        $name = "  MEDICINA   $t  ";

        $this->resolver->clearCache();
        $this->assertNull($this->resolver->resolveJournalIdAndQualis($name));
        $this->assertSame([$name], $this->resolver->consumeAmbiguous());
        $this->assertSame([], $this->resolver->consumeAmbiguous());
    }

    // ------------------------------------------------------------------------------------------------

    /** Sufixo único (letras e dígitos) para os títulos de teste não colidirem com dados existentes. */
    private function token(): string
    {
        return 'Zq' . bin2hex(random_bytes(5));
    }

    /** ISSN de 8 dígitos improvável de existir no banco de testes. */
    private function issn(): string
    {
        return '3' . str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    }

    private function createJournal(string $title, ?string $issn = null, ?string $qualis = null, ?QualisJournal $canonical = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        if ($issn !== null) {
            $journal->setIssn($issn);
        }
        if ($qualis !== null) {
            $journal->setQualis($qualis);
        }
        if ($canonical !== null) {
            $journal->setCanonical($canonical);
        }
        $this->em->persist($journal);
        $this->em->flush();
        $this->createdJournalIds[] = $journal->getId();

        return $journal;
    }

    private function addVariant(QualisJournal $journal, string $name, bool $active = true): JournalVariation
    {
        $variation = new JournalVariation();
        $variation->setJournal($journal);
        $variation->setVariationName($name);
        $variation->setNormalizedName(StringNormalizer::normalizeString($name, true));
        $variation->setStatus($active);
        $this->em->persist($variation);
        $this->em->flush();

        return $variation;
    }

    /**
     * Executa a consulta no modo on-demand (sem loadFullMaps) e no modo lote (com loadFullMaps) e exige o mesmo
     * resultado nos dois.
     */
    private function inBothModes(callable $query): mixed
    {
        $this->resolver->clearCache();
        $onDemand = $query($this->resolver);

        $this->resolver->clearCache();
        $this->resolver->loadFullMaps();
        $batch = $query($this->resolver);
        $this->resolver->clearCache();

        $this->assertSame($onDemand, $batch, 'O modo lote e o modo on-demand devem dar o mesmo resultado');

        return $onDemand;
    }

    private function resolvedId(?string $name, ?string $issn = null): ?int
    {
        return $this->inBothModes(fn (JournalResolverService $r) => $r->resolveJournalIdAndQualis($name, $issn)['id'] ?? null);
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

