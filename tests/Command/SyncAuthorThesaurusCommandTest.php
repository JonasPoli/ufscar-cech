<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SyncAuthorThesaurusCommand;
use App\Service\Thesaurus\AuthorThesaurusService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * O comando só apaga o tesauro de autores com --clean e depois de uma confirmação; sem --clean ele apenas acrescenta.
 * O serviço é simulado: TRUNCATE faz commit implícito e esvaziaria o tesauro de cech_test, que é compartilhado.
 */
class SyncAuthorThesaurusCommandTest extends TestCase
{
    private const SYNC_STATS = ['researchersProcessed' => 2, 'identitiesCreated' => 3, 'variantsCreated' => 5, 'coauthorsProcessed' => 7];

    public function testWithoutCleanNothingIsDeletedAndNoQuestionIsAsked(): void
    {
        $service = $this->createMock(AuthorThesaurusService::class);
        $service->expects($this->never())->method('truncateAuthorThesaurus');
        $service->expects($this->never())->method('rebuildAuthorThesaurus');
        $service->expects($this->once())->method('syncAuthorThesaurus')->willReturn(self::SYNC_STATS);

        $tester = new CommandTester(new SyncAuthorThesaurusCommand($service));
        $tester->execute([], ['interactive' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringNotContainsString('Apagar todo o tesauro', $tester->getDisplay());
        $this->assertStringContainsString('completado', $tester->getDisplay());
    }

    public function testCleanAnsweredNoDeletesNothingAndFails(): void
    {
        $service = $this->createMock(AuthorThesaurusService::class);
        $service->expects($this->never())->method('truncateAuthorThesaurus');
        $service->expects($this->never())->method('syncAuthorThesaurus');
        $service->expects($this->never())->method('syncAllResearchers');

        $tester = new CommandTester(new SyncAuthorThesaurusCommand($service));
        $tester->setInputs(['no']);
        $tester->execute(['--clean' => true], ['interactive' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'Quem chama o comando precisa saber que a limpeza não foi feita.');
        $this->assertStringContainsString('Apagar todo o tesauro', $tester->getDisplay());
        $this->assertStringContainsString('Nada foi apagado', $tester->getDisplay());
    }

    public function testCleanWithNoInteractionCountsAsNo(): void
    {
        $service = $this->createMock(AuthorThesaurusService::class);
        $service->expects($this->never())->method('truncateAuthorThesaurus');
        $service->expects($this->never())->method('syncAuthorThesaurus');

        $tester = new CommandTester(new SyncAuthorThesaurusCommand($service));
        $tester->execute(['--clean' => true], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Nada foi apagado', $tester->getDisplay());
    }

    public function testCleanConfirmedWipesRebuildsAndTellsToRunTheNormalization(): void
    {
        $calls = [];
        $service = $this->createMock(AuthorThesaurusService::class);
        $service->expects($this->once())->method('truncateAuthorThesaurus')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'truncate';
        });
        $service->expects($this->once())->method('syncAuthorThesaurus')->willReturnCallback(function () use (&$calls): array {
            $calls[] = 'sync';

            return self::SYNC_STATS;
        });

        $tester = new CommandTester(new SyncAuthorThesaurusCommand($service));
        $tester->setInputs(['yes']);
        $tester->execute(['--clean' => true], ['interactive' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame(['truncate', 'sync'], $calls, 'Apaga primeiro e só depois recria.');
        $this->assertStringContainsString('app:curriculum:normalize', $tester->getDisplay(), 'A limpeza zera production_authors.author_identity_id: o aviso manda religar.');
    }

    public function testResearchersOnlySyncsJustTheResearchers(): void
    {
        $service = $this->createMock(AuthorThesaurusService::class);
        $service->expects($this->never())->method('truncateAuthorThesaurus');
        $service->expects($this->never())->method('syncAuthorThesaurus');
        $service->expects($this->once())->method('syncAllResearchers')->willReturn(
            ['researchersProcessed' => 2, 'identitiesCreated' => 0, 'variantsAdded' => 1, 'variantsChecked' => 4]
        );

        $tester = new CommandTester(new SyncAuthorThesaurusCommand($service));
        $tester->execute(['--researchers-only' => true]);

        $tester->assertCommandIsSuccessful();
    }
}
