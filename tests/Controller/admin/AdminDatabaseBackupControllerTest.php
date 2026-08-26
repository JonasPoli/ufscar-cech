<?php

namespace App\Tests\Controller\admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminDatabaseBackupControllerTest extends WebTestCase
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

    public function testAdminDatabaseBackupIndex(): void
    {
        $this->client->request('GET', '/admin/database/backup');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Backup & Superdump do Sistema');
        $this->assertStringContainsString('Total de Tabelas', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Iniciar Exportação (.sql.zip)', $this->client->getResponse()->getContent());
    }

    public function testAdminDatabaseBackupGenerateAndDownload(): void
    {
        // Request generation
        $this->client->request('POST', '/admin/database/backup/generate', ['zip' => '1']);
        $this->assertResponseRedirects('/admin/database/backup');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Superdump gerado com sucesso', $content);

        // Test download latest
        $this->client->request('GET', '/admin/database/backup/download');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('attachment;', $this->client->getResponse()->headers->get('Content-Disposition'));

        // Test fallback from /admin/database/undefined
        $this->client->request('GET', '/admin/database/undefined');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('attachment;', $this->client->getResponse()->headers->get('Content-Disposition'));

        // Test /admin/database root
        $this->client->request('GET', '/admin/database');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Backup & Superdump do Sistema');
    }
}
