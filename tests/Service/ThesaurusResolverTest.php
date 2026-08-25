<?php

namespace App\Tests\Service;

use App\Entity\Researcher;
use App\Service\Thesaurus\AuthorResolverService;
use App\Service\Thesaurus\CountryResolverService;
use App\Service\Thesaurus\InstitutionResolverService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ThesaurusResolverTest extends KernelTestCase
{
    private CountryResolverService $countryResolver;
    private InstitutionResolverService $institutionResolver;
    private AuthorResolverService $authorResolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->countryResolver = $container->get(CountryResolverService::class);
        $this->institutionResolver = $container->get(InstitutionResolverService::class);
        $this->authorResolver = $container->get(AuthorResolverService::class);
    }

    public function testCountryResolverResolvesCountriesAndFlags(): void
    {
        $flagBr = $this->countryResolver->getCountryFlag('Brasil');
        $this->assertNotEmpty($flagBr);
        $this->assertSame('🇧🇷', $flagBr);

        $flagUs = $this->countryResolver->getCountryFlag('Estados Unidos');
        $this->assertSame('🇺🇸', $flagUs);

        $badge = $this->countryResolver->renderCountryBadge('Brasil');
        $this->assertStringContainsString('🇧🇷', $badge);
        $this->assertStringContainsString('Brasil', $badge);
    }

    public function testInstitutionResolverResolvesInstitutions(): void
    {
        $instName = $this->institutionResolver->getInstitutionDisplayName('UFSCar');
        $this->assertNotEmpty($instName);

        $badge = $this->institutionResolver->renderInstitutionBadge('UFSCar');
        $this->assertStringContainsString('UFSCar', $badge);
    }

    public function testAuthorThesaurusCitationVariantsForResearcher(): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $researcher = $em->getRepository(Researcher::class)->findOneBy(['slug' => 'vinicio-carrilho-martinez'])
            ?: $em->getRepository(Researcher::class)->findOneBy([]);

        if ($researcher) {
            $variants = $this->authorResolver->getCitationVariantsForResearcher($researcher);
            $this->assertIsArray($variants);
            $this->assertNotEmpty($variants);
        }
    }

    public function testAuthorThesaurusServiceSyncResearcher(): void
    {
        $thesaurusService = static::getContainer()->get(\App\Service\Thesaurus\AuthorThesaurusService::class);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Cleanup before starting if leftover exists
        $existing = $em->getRepository(Researcher::class)->findOneBy(['idLattes' => '9999999999999999']);
        if ($existing) {
            $em->remove($existing);
            $em->flush();
        }
        $existingIdent = $em->getRepository(\App\Entity\AuthorIdentity::class)->findOneBy(['preferredName' => 'Pesquisador Teste Unitario']);
        if ($existingIdent) {
            $em->remove($existingIdent);
            $em->flush();
        }

        $r = new Researcher();
        $r->setIdLattes('9999999999999999');
        $r->setFullName('Pesquisador Teste Unitario');
        $r->setCitationNames('TESTE, P. U.; Teste Unitario, P.; TESTE, PESQUISADOR U.');
        $r->setOrcid('0000-0001-2345-6789');
        $em->persist($r);
        $em->flush();

        $stats1 = $thesaurusService->syncResearcher($r);
        $this->assertTrue($stats1['identityCreated']);
        $this->assertGreaterThanOrEqual(4, $stats1['variantsAdded']);

        // Second sync should verify without adding duplicates
        $stats2 = $thesaurusService->syncResearcher($r);
        $this->assertFalse($stats2['identityCreated']);
        $this->assertSame(0, $stats2['variantsAdded']);
        $this->assertGreaterThanOrEqual(4, $stats2['variantsChecked']);

        // Clean up test researcher and created identity
        $em->remove($r);
        $ident = $em->getRepository(\App\Entity\AuthorIdentity::class)->findOneBy(['preferredName' => 'Pesquisador Teste Unitario']);
        if ($ident) {
            $em->remove($ident);
        }
        $em->flush();
    }
}

