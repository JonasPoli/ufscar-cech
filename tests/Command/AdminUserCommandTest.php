<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:admin-user` segue a mesma regra de senha do painel: uma senha com menos de 12 caracteres é recusada antes de
 * criar ou alterar o usuário. O nome de usuário tem sufixo único (cech_test é compartilhado).
 */
class AdminUserCommandTest extends KernelTestCase
{
    public function testShortPasswordIsRejectedWithoutCreatingTheUser(): void
    {
        self::bootKernel();
        $username = 'cmd_curta_' . uniqid();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:admin-user'));
        $exitCode = $tester->execute(['arg1' => $username, 'arg2' => '12345678901'], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('pelo menos 12 caracteres', $tester->getDisplay());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->assertNull($em->getRepository(User::class)->findOneBy(['username' => $username]));
    }
}
