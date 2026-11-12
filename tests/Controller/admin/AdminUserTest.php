<?php

declare(strict_types=1);

namespace App\Tests\Controller\admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminUserTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        $admin = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        if (!$admin) {
            $admin = (new User())->setUsername('admin')->setRoles(['ROLE_ADMIN']);
            $admin->setPassword($this->hash($admin, 'senha-do-admin-de-teste'));
            $this->em->persist($admin);
            $this->em->flush();
        }
        $this->client->loginUser($admin);
    }

    public function testCreatingAUserWithATakenNameShowsAMessageInsteadOfError500(): void
    {
        $this->client->request('POST', '/admin/users/new', [
            '_token' => $this->formToken('/admin/users/new'),
            'username' => 'admin',
            'password' => 'outra-senha-bem-longa',
            'role' => 'ROLE_ADMIN',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Já existe um usuário "admin"');
        $this->em->clear();
        $this->assertCount(1, $this->em->getRepository(User::class)->findBy(['username' => 'admin']));
    }

    public function testCreatingAUserWithAShortPasswordShowsAMessage(): void
    {
        $username = 'curta_' . uniqid();

        $this->client->request('POST', '/admin/users/new', [
            '_token' => $this->formToken('/admin/users/new'),
            'username' => $username,
            'password' => '12345678901',
            'role' => 'ROLE_USER',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'A senha precisa ter pelo menos 12 caracteres.');
        $this->em->clear();
        $this->assertNull($this->em->getRepository(User::class)->findOneBy(['username' => $username]));
    }

    public function testCreatingAUserWithALongPasswordWorks(): void
    {
        $username = 'longa_' . uniqid();

        $this->client->request('POST', '/admin/users/new', [
            '_token' => $this->formToken('/admin/users/new'),
            'username' => $username,
            'password' => '123456789012',
            'role' => 'ROLE_USER',
        ]);

        $this->assertResponseRedirects('/admin/users/');
        $this->em->clear();
        $created = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
        $this->assertNotNull($created);

        $this->em->remove($created);
        $this->em->flush();
    }

    public function testEditingWithAShortPasswordKeepsTheCurrentOne(): void
    {
        $user = (new User())->setUsername('edicao_' . uniqid())->setRoles(['ROLE_USER']);
        $user->setPassword($this->hash($user, 'senha-original-longa'));
        $this->em->persist($user);
        $this->em->flush();
        $id = $user->getId();
        $originalHash = $user->getPassword();
        $url = '/admin/users/' . $id . '/edit';

        // Senha nova curta: recusada, com a mensagem, e nada muda
        $this->client->request('POST', $url, [
            '_token' => $this->formToken($url),
            'username' => $user->getUsername(),
            'password' => 'curta',
            'role' => 'ROLE_USER',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'A senha precisa ter pelo menos 12 caracteres.');
        $this->em->clear();
        $this->assertSame($originalHash, $this->em->find(User::class, $id)->getPassword());

        // Senha em branco: mantém a atual e salva o resto
        $this->client->request('POST', $url, [
            '_token' => $this->formToken($url),
            'username' => $user->getUsername(),
            'password' => '',
            'role' => 'ROLE_USER',
        ]);
        $this->assertResponseRedirects('/admin/users/');
        $this->em->clear();
        $this->assertSame($originalHash, $this->em->find(User::class, $id)->getPassword());

        $this->em->remove($this->em->find(User::class, $id));
        $this->em->flush();
    }

    private function formToken(string $url): string
    {
        $crawler = $this->client->request('GET', $url);

        return (string)$crawler->filter('input[name="_token"]')->attr('value');
    }

    private function hash(User $user, string $plain): string
    {
        return static::getContainer()->get('security.user_password_hasher')->hashPassword($user, $plain);
    }
}
