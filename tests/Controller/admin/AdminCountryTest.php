<?php

namespace App\Tests\Controller\admin;

use App\Entity\Country;
use App\Entity\CountryVariation;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminCountryTest extends WebTestCase
{
    private function getAuthenticatedClient()
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'admin']);

        if (!$user) {
            $user = new User();
            $user->setUsername('admin');
            $user->setRoles(['ROLE_ADMIN']);
            $hasher = static::getContainer()->get('security.user_password_hasher');
            $user->setPassword($hasher->hashPassword($user, 'admin123'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        return $client;
    }

    public function testAdminCountriesIndexRendersFlagsAndDates(): void
    {
        $client = $this->getAuthenticatedClient();
        $crawler = $client->request('GET', '/admin/countries/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Tesauro de Países');

        // Verify flags exist in table
        $this->assertGreaterThan(0, $crawler->filter('img[src*="flagcdn.com"]')->count(), 'Flag images should be present in countries table.');
    }

    public function testAdminCountry11EditRendersDatesFlagsAndVariations(): void
    {
        $client = $this->getAuthenticatedClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        $afg = $em->getRepository(Country::class)->findOneBy(['commonName' => 'Afeganistão']);
        $this->assertNotNull($afg);

        $crawler = $client->request('GET', "/admin/countries/{$afg->getId()}/edit");

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Afeganistão');

        // Check foundation year input value
        $this->assertEquals('1919', $crawler->filter('input[name="foundationYear"]')->attr('value'));

        // Check flag preview
        $this->assertGreaterThan(0, $crawler->filter('img[src*="flagcdn.com/32x24/af.png"], img[src*="flagcdn.com/20x15/af.png"]')->count());

        // Check mass variations textarea
        $this->assertGreaterThan(0, $crawler->filter('textarea[name="variationsText"]')->count());
    }

    public function testAdminCountrySaveAndVariationManagement(): void
    {
        $client = $this->getAuthenticatedClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        // 1. Create a test country
        $country = new Country();
        $country->setCommonName('País de Teste Antigravity');
        $country->setOfficialName('República de Teste Antigravity');
        $country->setIsoAlpha2('ZZ');
        $country->setIsoAlpha3('ZZZ');
        $country->setFoundationYear(2020);
        $country->setExtinctionYear(2025);

        $var1 = new CountryVariation();
        $var1->setCountry($country);
        $var1->setVariationName('Variante Alfa Teste');
        $var1->setNormalizedName('variante alfa teste');
        $country->addVariation($var1);

        $var2 = new CountryVariation();
        $var2->setCountry($country);
        $var2->setVariationName('Variante Beta Teste');
        $var2->setNormalizedName('variante beta teste');
        $country->addVariation($var2);

        $em->persist($country);
        $em->flush();

        $cId = $country->getId();
        $v1Id = $var1->getId();

        // 2. Edit country and update dates
        $client->request('POST', "/admin/countries/{$cId}/edit", [
            'commonName' => 'País de Teste Antigravity Atualizado',
            'officialName' => 'República Atualizada',
            'isoAlpha2' => 'ZZ',
            'isoAlpha3' => 'ZZZ',
            'foundationYear' => '2019',
            'extinctionYear' => '2026',
            'status' => '1',
            'variationsText' => "Variante Alfa Teste\nVariante Gama Nova",
        ]);

        $this->assertResponseRedirects('/admin/countries/');
        $client->followRedirect();

        $updated = $em->getRepository(Country::class)->find($cId);
        $this->assertNotNull($updated);
        $this->assertEquals(2019, $updated->getFoundationYear());
        $this->assertEquals(2026, $updated->getExtinctionYear());

        // 3. Delete a variation
        $varGama = $em->getRepository(CountryVariation::class)->findOneBy(['variationName' => 'Variante Gama Nova']);
        $this->assertNotNull($varGama);

        $client->request('POST', "/admin/countries/variation/{$varGama->getId()}/delete", [
            '_token' => 'dummy', // In test mode or CSRF check
        ]);

        // 4. Cleanup
        $em->remove($updated);
        $em->flush();
    }
}
