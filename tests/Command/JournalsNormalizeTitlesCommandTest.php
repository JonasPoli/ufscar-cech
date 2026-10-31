<?php

namespace App\Tests\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class JournalsNormalizeTitlesCommandTest extends KernelTestCase
{
    private Connection $conn;
    private ?int $id = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(Connection::class);
    }

    protected function tearDown(): void
    {
        if ($this->id !== null) {
            $this->conn->delete('qualis_journals', ['id' => $this->id]);
        }
        parent::tearDown();
    }

    public function testGluedIssnsAreSeparatedInTheSearchColumnsAndTheOriginalTextIsKept(): void
    {
        $this->insertJournal([
            'issn' => '1984-6444, 0102-5473',
            'issn_imp' => '1984-6444, 0102-5473',
            'normalized_issn' => '1984644401025473',
            'normalized_issn_imp' => '1984644401025473',
        ]);

        $this->runCommand();
        $row = $this->row();

        $this->assertSame('19846444', $row['normalized_issn']);
        $this->assertSame('01025473', $row['normalized_issn_imp']);
        $this->assertSame('1984-6444, 0102-5473', $row['issn'], 'O texto original não muda.');
        $this->assertSame('1984-6444, 0102-5473', $row['issn_imp'], 'O texto original não muda.');

        $this->runCommand();
        $this->assertSame($row, $this->row(), 'Rodar de novo não muda nada.');
    }

    public function testAColumnThatAlreadyHasOneIssnIsKept(): void
    {
        $this->insertJournal([
            'normalized_issn' => '1984644401025473',
            'normalized_issn_e' => '19846444',
        ]);

        $this->runCommand();
        $row = $this->row();

        $this->assertSame('01025473', $row['normalized_issn']);
        $this->assertSame('19846444', $row['normalized_issn_e']);
        $this->assertNull($row['normalized_issn_imp']);
    }

    /** @param array<string, string> $values */
    private function insertJournal(array $values): void
    {
        $this->conn->insert('qualis_journals', ['title' => 'Revista Colada ' . uniqid('', true), 'areas_locked' => 0] + $values);
        $this->id = (int) $this->conn->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return $this->conn->fetchAssociative(
            'SELECT issn, issn_imp, normalized_issn, normalized_issn_imp, normalized_issn_e, normalized_issn_l FROM qualis_journals WHERE id = ?',
            [$this->id]
        );
    }

    private function runCommand(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:journals:normalize-titles'));
        $tester->execute(['--only-missing' => true]);
        $this->assertSame(0, $tester->getStatusCode());
    }
}
