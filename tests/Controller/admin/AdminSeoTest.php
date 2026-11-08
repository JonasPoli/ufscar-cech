<?php

declare(strict_types=1);

namespace App\Tests\Controller\admin;

use App\Entity\User;
use App\Repository\SiteSettingRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminSeoTest extends WebTestCase
{
    public function testPostWithoutCsrfTokenChangesNothing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        if (!$user) {
            $user = (new User())->setUsername('admin')->setRoles(['ROLE_ADMIN']);
            $user->setPassword(static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'admin123'));
            $em->persist($user);
            $em->flush();
        }
        $client->loginUser($user);

        $before = static::getContainer()->get(SiteSettingRepository::class)->getSettings()->getSeoTitle();

        $client->request('POST', '/admin/seo/', ['seoTitle' => 'Título trocado sem token ' . uniqid()]);
        $this->assertResponseRedirects('/admin/seo/');

        $em->clear();
        $this->assertSame($before, static::getContainer()->get(SiteSettingRepository::class)->getSettings()->getSeoTitle());
    }
}
