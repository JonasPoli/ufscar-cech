<?php

declare(strict_types=1);

namespace App\Tests\Controller\admin;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminUserTest extends WebTestCase
{
    public function testCreatingAUserWithATakenNameShowsAMessageInsteadOfError500(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $admin = $em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        if (!$admin) {
            $admin = (new User())->setUsername('admin')->setRoles(['ROLE_ADMIN']);
            $admin->setPassword(static::getContainer()->get('security.user_password_hasher')->hashPassword($admin, 'admin123'));
            $em->persist($admin);
            $em->flush();
        }
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/users/new');
        $token = (string)$crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/users/new', ['_token' => $token, 'username' => 'admin', 'password' => 'outra-senha', 'role' => 'ROLE_ADMIN']);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Já existe um usuário "admin"');
        $em->clear();
        $this->assertCount(1, $em->getRepository(User::class)->findBy(['username' => 'admin']));
    }
}
