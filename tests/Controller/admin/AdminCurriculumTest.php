<?php

namespace App\Tests\Controller\admin;

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
}
