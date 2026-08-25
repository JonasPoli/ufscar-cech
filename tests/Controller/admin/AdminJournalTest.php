<?php

namespace App\Tests\Controller\admin;

use App\Entity\QualisJournal;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminJournalTest extends WebTestCase
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

    public function testAdminJournalsIndex(): void
    {
        $client = $this->getAuthenticatedClient();
        $client->request('GET', '/admin/journals/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Periódicos & Qualis CAPES');
    }

    public function testAdminJournalNewAndEdit(): void
    {
        $client = $this->getAuthenticatedClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        // New page
        $client->request('GET', '/admin/journals/new');
        $this->assertResponseIsSuccessful();

        // Create
        $client->request('POST', '/admin/journals/new', [
            'title' => 'Test Journal CECH Studies',
            'issn' => '9999-8888',
            'qualis' => 'A1',
            'area' => 'INTERDISCIPLINAR',
            'variations' => "TJCS\nTest Journal of CECH",
        ]);

        $this->assertResponseRedirects('/admin/journals/');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'cadastrado com sucesso');

        $created = $em->getRepository(QualisJournal::class)->findOneBy(['title' => 'Test Journal CECH Studies']);
        $this->assertNotNull($created);
        $this->assertEquals('A1', $created->getQualis());
        $this->assertCount(2, $created->getVariations());
    }

    public function testAdminJournalExports(): void
    {
        $client = $this->getAuthenticatedClient();

        // CSV export
        $client->request('GET', '/admin/journals/export');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/csv; charset=utf-8');

        // Thesaurus export
        $client->request('GET', '/admin/journals/export-thesaurus?format=the');
        $this->assertResponseIsSuccessful();
    }
}
