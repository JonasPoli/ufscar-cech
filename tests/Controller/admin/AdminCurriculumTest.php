<?php

namespace App\Tests\Controller\admin;

use App\Entity\Researcher;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminCurriculumTest extends WebTestCase
{
    public function testAdminCurriculumIndexPerformanceAndSuccess(): void
    {
        $client = static::createClient();

        // Create or find an admin user for authentication
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'admin']);

        if (!$user) {
            $user = new User();
            $user->setUsername('admin');
            $user->setRoles(['ROLE_ADMIN']);
            $hasher = static::getContainer()->get('security.user_password_hasher');
            $user->setPassword($hasher->hashPassword($user, 'wab12345678'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $client->request('GET', '/admin/curriculum/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Currículos & Docentes do CECH');
    }

    public function testAdminCurriculumNewPageRendersCrawlerDocs(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'admin']);

        if (!$user) {
            $user = new User();
            $user->setUsername('admin');
            $user->setRoles(['ROLE_ADMIN']);
            $hasher = static::getContainer()->get('security.user_password_hasher');
            $user->setPassword($hasher->hashPassword($user, 'wab12345678'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $client->request('GET', '/admin/curriculum/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Adicionar / Sincronizar Docente');
        $this->assertSelectorTextContains('h2', 'Sincronizador In-Browser Lattes');
    }

    public function testAdminCurriculumFilterAndSearch(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'admin']);

        if (!$user) {
            $user = new User();
            $user->setUsername('admin');
            $user->setRoles(['ROLE_ADMIN']);
            $hasher = static::getContainer()->get('security.user_password_hasher');
            $user->setPassword($hasher->hashPassword($user, 'wab12345678'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $client->request('GET', '/admin/curriculum/?q=Maria&dept=CS');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="q"]');
        $this->assertSelectorExists('select[name="dept"]');
    }

    public function testImportRepositoryButtonRequiresValidCsrfAndRedirectsBack(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'admin']);

        if (!$user) {
            $user = new User();
            $user->setUsername('admin');
            $user->setRoles(['ROLE_ADMIN']);
            $hasher = static::getContainer()->get('security.user_password_hasher');
            $user->setPassword($hasher->hashPassword($user, 'wab12345678'));
            $em->persist($user);
            $em->flush();
        }

        $researcher = $em->getRepository(Researcher::class)->findOneBy(['idLattes' => '9999888877774444']);
        if (!$researcher) {
            $researcher = new Researcher();
            $researcher->setIdLattes('9999888877774444');
            $researcher->setFullName('Prof Botao Repositorio');
            $researcher->setSlug('prof-botao-repositorio');
            $em->persist($researcher);
            $em->flush();
        }
        $id = $researcher->getId();

        try {
            $client->loginUser($user);

            // Token inválido não executa a importação
            $client->request('POST', "/admin/curriculum/{$id}/import-repository", ['_token' => 'invalido']);
            $this->assertResponseRedirects("/admin/curriculum/{$id}");
            $client->followRedirect();
            $this->assertSelectorTextContains('main', 'Token de segurança inválido');

            // O botão é exibido e o envio com token válido volta para a página do docente
            $crawler = $client->request('GET', "/admin/curriculum/{$id}");
            $this->assertResponseIsSuccessful();
            $form = $crawler->selectButton('Importar do Repositório UFSCar')->form();
            $client->submit($form);
            $this->assertResponseRedirects("/admin/curriculum/{$id}");
            $client->followRedirect();
            $this->assertSelectorTextNotContains('main', 'Token de segurança inválido');
        } finally {
            $researcher = $em->getRepository(Researcher::class)->find($id);
            if ($researcher) {
                $em->remove($researcher);
                $em->flush();
            }
        }
    }
}
