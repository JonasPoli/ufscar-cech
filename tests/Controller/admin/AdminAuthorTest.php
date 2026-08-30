<?php

namespace App\Tests\Controller\admin;

use App\Entity\AuthorIdentity;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AdminAuthorTest extends WebTestCase
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

    public function testImportAuthorThesaurusFile(): void
    {
        $client = $this->getAuthenticatedClient();
        $theFile = '/Users/jonaspoli/work/html/ufscar-cech/docs/banco/2026-08-29 - Tesauro - nomes padronizados docentes do CECH.the';

        $uploadedFile = new UploadedFile(
            $theFile,
            '2026-08-29 - Tesauro - nomes padronizados docentes do CECH.the',
            'text/plain',
            null,
            true
        );

        $client->request(
            'POST',
            '/admin/authors/import',
            [],
            ['thesaurus_file' => $uploadedFile]
        );

        $this->assertResponseRedirects('/admin/authors/');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $authorRepo = $em->getRepository(AuthorIdentity::class);

        $author = $authorRepo->findOneBy(['preferredName' => 'Ademir Donizeti Caldeira']);
        $this->assertNotNull($author, 'Author "Ademir Donizeti Caldeira" should be created from thesaurus import.');
        $this->assertCount(1, $author->getVariations());
        $this->assertSame('Ademir Dionizete Caldeira', $author->getVariations()[0]->getOriginalName());
    }
}
