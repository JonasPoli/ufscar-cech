<?php

namespace App\Tests\Controller\admin;

use App\Entity\AcademicDatabase;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminAcademicDatabaseTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Ensure admin user
        $userRepo = $this->em->getRepository(User::class);
        $admin = $userRepo->findOneBy(['username' => 'admin_test']);
        if (!$admin) {
            $admin = new User();
            $admin->setUsername('admin_test');
            $admin->setPassword('password');
            $admin->setRoles(['ROLE_ADMIN']);
            $this->em->persist($admin);
            $this->em->flush();
        }
        $this->client->loginUser($admin);
    }

    public function testAdminAcademicDatabaseIndex(): void
    {
        $this->client->request('GET', '/admin/academic-databases/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Bases de Indexação Acadêmica');
    }

    public function testCreateAndEditAcademicDatabase(): void
    {
        $uniqueAcronym = 'testdb_' . uniqid();
        
        // 1. Create
        $this->client->request('GET', '/admin/academic-databases/new');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Cadastrar Base', [
            'name' => 'Database Test Name',
            'acronym' => $uniqueAcronym,
            'url' => 'https://testdb.example.org',
            'file_formats' => 'csv, xlsx',
            'signature_columns' => 'TestID, Title',
            'description' => 'A test database description',
        ]);

        $this->assertResponseRedirects('/admin/academic-databases/');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Database Test Name');

        // 2. Verify in DB
        $db = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => $uniqueAcronym]);
        $this->assertNotNull($db);
        $this->assertEquals('Database Test Name', $db->getName());

        // 3. Edit
        $this->client->request('GET', '/admin/academic-databases/' . $db->getId() . '/edit');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Salvar Alterações', [
            'name' => 'Database Test Name Updated',
            'acronym' => $uniqueAcronym,
            'url' => 'https://testdb-updated.example.org',
            'file_formats' => 'csv, json',
            'signature_columns' => 'TestID, Title, Abstract',
            'description' => 'Updated description',
        ]);

        $this->assertResponseRedirects('/admin/academic-databases/');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Database Test Name Updated');
    }
}
