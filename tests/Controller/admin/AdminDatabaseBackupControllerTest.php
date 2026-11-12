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

        // Download pelo nome do arquivo
        $backupService = static::getContainer()->get(\App\Service\Backup\DatabaseBackupService::class);
        $latest = $backupService->listBackups()[0]['filename'];
        $this->client->request('GET', '/admin/database/backup/download/' . $latest);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($latest, (string)$this->client->getResponse()->headers->get('Content-Disposition'));

        // Arquivo inexistente responde 404, sem trocar pelo backup mais recente
        $this->client->request('GET', '/admin/database/backup/download/inexistente_' . uniqid() . '.zip');
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/admin/database/backup/download/undefined');
        $this->assertResponseStatusCodeSame(404);

        // A rota genérica /admin/database/{filename} não existe mais
        $this->client->request('GET', '/admin/database/undefined');
        $this->assertResponseStatusCodeSame(404);

        // Test /admin/database root
        $this->client->request('GET', '/admin/database');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Backup & Superdump do Sistema');

        // Cleanup: o dump gerado por este teste
        $backupService->deleteBackup($latest);
    }

    public function testAdminDatabaseBackupRestoreExisting(): void
    {
        $backupService = static::getContainer()->get(\App\Service\Backup\DatabaseBackupService::class);
        $customName = 'test_ctrl_restore_' . uniqid();
        $exportResult = $backupService->exportDatabase(true, null, $customName);
        $this->assertTrue($exportResult['success']);

        // Get token from crawler
        $crawler = $this->client->request('GET', '/admin/database/backup');
        $tokenInput = $crawler->filter('form[action*="' . $exportResult['filename'] . '"] input[name="_token"]');
        $token = $tokenInput->attr('value');

        $this->client->request('POST', '/admin/database/backup/restore/' . $exportResult['filename'], [
            '_token' => $token,
        ]);

        // Expect redirect to login with ?restored=1
        $this->assertResponseRedirects('/login?restored=1');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Base de dados restaurada com sucesso!', $this->client->getResponse()->getContent());

        // Cleanup
        $backupService->deleteBackup($exportResult['filename']);
    }
}

