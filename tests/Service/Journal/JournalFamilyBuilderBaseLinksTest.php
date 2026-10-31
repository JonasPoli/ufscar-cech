<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Service\Journal\JournalFamilyBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `JournalFamilyBuilder::propagateBaseLinks()` replica os vínculos com as bases às linhas da família. É público para quem
 * muda vínculos fora da reconstrução de famílias (edição de um periódico no admin, mesclagem, importação de lista): com o id
 * da família age só nela; sem ele, em todas.
 *
 * Os dados usam sufixo único e saem no tearDown (cech_test é compartilhado com outros testes e agentes).
 */
class JournalFamilyBuilderBaseLinksTest extends KernelTestCase
{
    private Connection $conn;
    private JournalFamilyBuilder $builder;
    private string $uniq;

    /** @var string[] */
    private array $acronyms = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->conn = $container->get(Connection::class);
        $this->builder = $container->get(JournalFamilyBuilder::class);
        $this->uniq = 'qz' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM qualis_journals WHERE title LIKE ?', ['%' . $this->uniq . '%']);
        foreach ($this->acronyms as $acronym) {
            $this->conn->executeStatement('DELETE FROM academic_database WHERE acronym = ?', [$acronym]);
        }

        parent::tearDown();
    }

    public function testWithAFamilyIdItReplicatesOnlyInThatFamilyAndWithoutItInAllOfThem(): void
    {
        $dbX = $this->database('x');
        $dbY = $this->database('y');

        // Duas famílias (cabeça e dois membros cada); o vínculo está só em um membro de cada uma
        $head1 = $this->journal('Cabeça Um');
        $member1 = $this->journal('Membro Um', $head1);
        $other1 = $this->journal('Outro Um', $head1);
        $head2 = $this->journal('Cabeça Dois');
        $member2 = $this->journal('Membro Dois', $head2);
        $alone = $this->journal('Sozinha');
        $this->link($member1, $dbX);
        $this->link($member2, $dbY);
        $this->link($alone, $dbX);

        $added = $this->builder->propagateBaseLinks($head1);

        $this->assertSame(2, $added, 'A cabeça e o outro membro da primeira família');
        $this->assertSame([$dbX], $this->linked($head1));
        $this->assertSame([$dbX], $this->linked($member1));
        $this->assertSame([$dbX], $this->linked($other1));
        $this->assertSame([], $this->linked($head2), 'A segunda família não foi tocada');
        $this->assertSame([$dbY], $this->linked($member2));
        $this->assertSame([$dbX], $this->linked($alone), 'Linha sem família não recebe nem perde nada');

        // Idempotente
        $this->assertSame(0, $this->builder->propagateBaseLinks($head1));

        // Sem o id, todas as famílias (o banco compartilhado pode ter outras fora de sincronia: pelo menos esta)
        $this->assertGreaterThanOrEqual(1, $this->builder->propagateBaseLinks());
        $this->assertSame([$dbY], $this->linked($head2));
        $this->assertSame([$dbX], $this->linked($head1));
    }

    private function journal(string $title, ?int $canonicalId = null): int
    {
        $this->conn->insert('qualis_journals', [
            'title' => "{$title} {$this->uniq}",
            'canonical_journal_id' => $canonicalId,
            'areas_locked' => 0,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    private function database(string $suffix): int
    {
        $acronym = "{$this->uniq}{$suffix}";
        $this->conn->insert('academic_database', ['name' => "Base teste {$suffix} {$this->uniq}", 'acronym' => $acronym]);
        $this->acronyms[] = $acronym;

        return (int)$this->conn->lastInsertId();
    }

    private function link(int $journalId, int $databaseId): void
    {
        $this->conn->insert('qualis_journal_academic_database', ['qualis_journal_id' => $journalId, 'academic_database_id' => $databaseId]);
    }

    /**
     * @return list<int>
     */
    private function linked(int $journalId): array
    {
        return array_map('intval', $this->conn->fetchFirstColumn(
            'SELECT academic_database_id FROM qualis_journal_academic_database WHERE qualis_journal_id = ? ORDER BY academic_database_id',
            [$journalId]
        ));
    }
}
