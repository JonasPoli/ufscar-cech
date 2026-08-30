<?php

namespace App\Tests\Controller\admin;

use App\Entity\Institution;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AdminInstitutionTest extends WebTestCase
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

    public function testImportInstitutionThesaurusFile(): void
    {
        $client = $this->getAuthenticatedClient();
        $theFile = '/Users/jonaspoli/work/html/ufscar-cech/docs/banco/2026-08-12 - Tesauro - nomes padronizados instituições.the';

        $uploadedFile = new UploadedFile(
            $theFile,
            '2026-08-12 - Tesauro - nomes padronizados instituições.the',
            'text/plain',
            null,
            true
        );

        $client->request(
            'POST',
            '/admin/institutions/import',
            [],
            ['thesaurus_file' => $uploadedFile]
        );

        $this->assertResponseRedirects('/admin/institutions/');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $instRepo = $em->getRepository(Institution::class);

        $inst = $instRepo->findOneBy(['officialName' => 'aarhus univ']);
        $this->assertNotNull($inst, 'Institution "aarhus univ" should be created from thesaurus import.');
        $this->assertGreaterThanOrEqual(1, count($inst->getVariations()));
    }
}
