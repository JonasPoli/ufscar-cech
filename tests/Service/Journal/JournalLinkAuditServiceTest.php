<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Service\Journal\JournalLinkAuditService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\TestCase;

class JournalLinkAuditServiceTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'link-audit-');
        self::assertIsString($file);
        $this->file = $file;
    }

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testSnapshotThenCompareCountsChangedGainedAndLostArticles(): void
    {
        $service = $this->serviceWith(
            $this->source(
                [
                    $this->article(1, 'Revista Alfa', 'A1', 10),
                    $this->article(2, 'Revista Beta', null, null),
                    $this->article(3, 'Revista Gama', 'B2', 12),
                ],
                [10 => 'Alfa', 11 => 'Beta', 12 => 'Gama'],
                [10 => ['scopus'], 11 => ['wos'], 12 => ['scielo']]
            ),
            $this->source(
                [
                    $this->article(1, 'Revista Alfa', 'A2', 11),
                    $this->article(2, 'Revista Beta', null, 11),
                    $this->article(3, 'Revista Gama', 'B2', null),
                ],
                [10 => 'Alfa', 11 => 'Beta', 12 => 'Gama'],
                [10 => ['scopus'], 11 => ['wos'], 12 => ['scielo']]
            )
        );

        $snapshot = $service->snapshot($this->file);
        $this->assertSame(2, $snapshot['totals']['linked']);
        $this->assertSame(['scielo' => 1, 'scopus' => 1], $snapshot['totals']['by_db']);

        $result = $service->compare($this->file);

        $this->assertSame(
            ['same' => 0, 'changed' => 1, 'gained' => 1, 'lost' => 1, 'same_journal_other_changes' => 0],
            [
                'same' => $result['same'],
                'changed' => $result['changed'],
                'gained' => $result['gained'],
                'lost' => $result['lost'],
                'same_journal_other_changes' => $result['same_journal_other_changes'],
            ]
        );
        $this->assertSame(['before' => 3, 'after' => 3, 'new' => 0, 'removed' => 0], $result['articles']);

        $pairs = [];
        foreach ($result['pairs'] as $pair) {
            $pairs[$pair['from'] . ' -> ' . $pair['to']] = $pair['n'];
        }
        $this->assertEqualsCanonicalizing(
            ['Alfa -> Beta' => 1, '(sem vínculo) -> Beta' => 1, 'Gama -> (sem vínculo)' => 1],
            $pairs
        );

        $this->assertSame(['before' => 1, 'after' => 0, 'delta' => -1], $result['by_qualis']['A1']);
        $this->assertSame(['before' => 0, 'after' => 1, 'delta' => 1], $result['by_qualis']['A2']);
        $this->assertSame(['before' => 1, 'after' => 1, 'delta' => 0], $result['by_qualis']['B2']);
        $this->assertSame(['before' => 1, 'after' => 0, 'delta' => -1], $result['by_db']['scopus']);
        $this->assertSame(['before' => 1, 'after' => 0, 'delta' => -1], $result['by_db']['scielo']);
        $this->assertSame(['before' => 0, 'after' => 2, 'delta' => 2], $result['by_db']['wos']);
    }

    public function testSameJournalNewAndRemovedArticlesAreKeptApart(): void
    {
        $service = $this->serviceWith(
            $this->source(
                [
                    $this->article(1, 'Revista Alfa', 'A1', 10),
                    $this->article(2, 'Revista Alfa', 'A1', 10),
                ],
                [10 => 'Alfa'],
                [10 => ['scopus']]
            ),
            $this->source(
                [
                    $this->article(1, 'Revista Alfa', 'A2', 10),
                    $this->article(3, 'Revista Alfa', 'A1', 10),
                ],
                [10 => 'Alfa'],
                [10 => ['scopus']]
            )
        );

        $service->snapshot($this->file);
        $result = $service->compare($this->file);

        $this->assertSame(1, $result['same']);
        $this->assertSame(1, $result['same_journal_other_changes']);
        $this->assertSame(0, $result['changed'] + $result['gained'] + $result['lost']);
        $this->assertSame(['before' => 2, 'after' => 2, 'new' => 1, 'removed' => 1], $result['articles']);
        $this->assertSame([], $result['pairs']);
    }

    public function testKnownViolationCasesAreCountedAndExamplesKept(): void
    {
        $service = $this->serviceWith($this->source(
            [
                $this->article(1, 'Avaliação Psicológica', 'B1', 20),
                $this->article(2, 'Ciência e Educação', 'A4', 30),
                $this->article(3, 'História da Educação', 'B2', 30),
                $this->article(4, 'Educação Matemática em Revista', 'A3', 40),
                $this->article(5, 'Avaliação Psicológica', 'B1', 21),
                $this->article(6, 'Ciência e Educação', 'A4', null),
                $this->article(7, 'Outro nome', 'B1', 20, 'avaliacao psicologica'),
            ],
            [
                20 => 'CONSTRUÇÃO PSICOPEDAGÓGICA (IMPRESSO)',
                21 => 'Revista de Psicologia',
                30 => 'Turismo: Visão e Ação',
                40 => 'Caminhos da Educação Matemática em Revista',
            ],
            []
        ));

        $state = $service->snapshot($this->file);

        $this->assertSame(2, $state['checks']['turismo_visao_acao']['count']);
        $this->assertSame(2, $state['checks']['construcao_psicopedagogica']['count']);
        $this->assertSame(1, $state['checks']['caminhos_educacao_matematica']['count']);
        $this->assertSame([1, 7], array_column($state['checks']['construcao_psicopedagogica']['examples'], 'id'));
        $this->assertNotSame('', $state['checks']['turismo_visao_acao']['label']);
    }

    public function testCompareRejectsFileThatIsNotASnapshot(): void
    {
        file_put_contents($this->file, '{"foo": 1}');
        $service = new JournalLinkAuditService($this->createStub(Connection::class));

        $this->expectException(\RuntimeException::class);
        $service->compare($this->file);
    }

    /**
     * journal_name_normalized é opcional: sem a coluna, a leitura usa NULL e a comparação cai no journal_name.
     *
     * @dataProvider normalizedColumnProvider
     *
     * @param list<string> $columns colunas que o banco tem em production_items
     */
    public function testLoadRowsReadsOptionalNormalizedColumnOnlyWhenItExists(array $columns, string $expectedSelect): void
    {
        $schema = $this->createStub(AbstractSchemaManager::class);
        $schema->method('listTableColumns')->willReturn(array_fill_keys($columns, null));

        $queries = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->expects($this->exactly(3))
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql) use (&$queries): array {
                $queries[] = $sql;

                return [];
            });

        $service = new JournalLinkAuditService($connection);
        (new \ReflectionMethod($service, 'loadRows'))->invoke($service);

        $this->assertStringContainsString($expectedSelect, $queries[0]);
    }

    /** @return iterable<string, array{0: list<string>, 1: string}> */
    public static function normalizedColumnProvider(): iterable
    {
        yield 'coluna existe' => [
            ['id', 'journal_name', 'journal_name_normalized'],
            'pi.journal_name_normalized AS journal_name_normalized',
        ];
        yield 'coluna ausente' => [['id', 'journal_name'], 'NULL AS journal_name_normalized'];
    }

    /**
     * Devolve um serviço cujo `loadRows()` entrega as fontes na ordem dada (uma por chamada ao banco).
     *
     * @param array<string, mixed> ...$sources
     */
    private function serviceWith(array ...$sources): JournalLinkAuditService
    {
        $service = $this->getMockBuilder(JournalLinkAuditService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadRows'])
            ->getMock();
        $service->expects($this->exactly(count($sources)))
            ->method('loadRows')
            ->willReturnOnConsecutiveCalls(...$sources);

        return $service;
    }

    /**
     * @param array<int, string>        $journals id do periódico => título
     * @param array<int, list<string>>  $bases    id do periódico => acrônimos das bases
     *
     * @return array{articles: list<array<string, mixed>>, journals: list<array<string, mixed>>, bases: list<array<string, mixed>>}
     */
    private function source(array $articles, array $journals, array $bases): array
    {
        $journalRows = [];
        foreach ($journals as $id => $title) {
            $journalRows[] = ['id' => $id, 'title' => $title, 'normalized_title' => null];
        }

        $baseRows = [];
        foreach ($bases as $journalId => $acronyms) {
            foreach ($acronyms as $acronym) {
                $baseRows[] = ['qualis_journal_id' => $journalId, 'acronym' => $acronym];
            }
        }

        return ['articles' => $articles, 'journals' => $journalRows, 'bases' => $baseRows];
    }

    /** @return array<string, mixed> */
    private function article(int $id, string $journalName, ?string $qualis, ?int $journalId, ?string $normalizedName = null): array
    {
        return [
            'id' => $id,
            'journal_name' => $journalName,
            'journal_name_normalized' => $normalizedName,
            'qualis' => $qualis,
            'qualis_journal_id' => $journalId,
        ];
    }
}
