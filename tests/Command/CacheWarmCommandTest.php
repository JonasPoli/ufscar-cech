<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** app:cache:warm: sem URL pede a URL; em processo percorre as páginas pedidas; uma URL que não responde não para o resto. */
class CacheWarmCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        self::bootKernel();

        return new CommandTester((new Application(self::$kernel))->find('app:cache:warm'));
    }

    public function testWithoutUrlItAsksForOne(): void
    {
        $tester = $this->tester();
        $status = $tester->execute([], ['capture_stderr_separately' => false]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('--url', $tester->getDisplay());
    }

    public function testInProcessWarmsTheRequestedPages(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['--em-processo' => true, '--apenas' => '/sobre', '--pausa' => '0']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status, $display);
        $this->assertStringContainsString('/sobre-os-dados', $display);
        $this->assertStringContainsString('200', $display);
    }

    public function testAnUnreachableUrlIsReportedForEveryPageWithoutStopping(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['--url' => 'http://127.0.0.1:1', '--apenas' => '/sobre', '--pausa' => '0']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame(2, substr_count($display, 'erro  '), $display);
        $this->assertStringContainsString('2 com falha', $display);
    }
}
