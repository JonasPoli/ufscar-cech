<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AbstractBibliomapImportCommand;
use App\Command\ImportBibliomapDatabasesCommand;
use App\Command\ImportBibliomapJournalsCommand;
use App\Command\ImportBibliomapThesaurusCommand;
use App\Service\Journal\JournalVariantGuard;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Os comandos que copiam do BiblioMap limpam as tabelas de destino com TRUNCATE, então só podem rodar sobre tabelas vazias,
 * ou com `--force` e uma confirmação (`--no-interaction` vale como "não").
 *
 * O comportamento dos comandos é testado com uma conexão simulada: nenhum teste aqui apaga nada de verdade, porque o
 * `cech_test` é compartilhado com outros testes e agentes. O SQL da conferência das tabelas e os nomes das tabelas de
 * destino são conferidos no banco de teste, só com leituras.
 */
class ImportBibliomapCommandsTest extends KernelTestCase
{
    /** @var list<string> */
    private array $statements = [];

    /** @var list<string> */
    private array $queries = [];

    protected function tearDown(): void
    {
        $this->statements = [];
        $this->queries = [];
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}> comando e tabelas de destino
     */
    public static function commandProvider(): iterable
    {
        yield 'periódicos' => ['journals', ['qualis_journals', 'journal_name_variants']];
        yield 'bases' => ['databases', ['academic_database', 'qualis_journal_academic_database']];
        yield 'tesauro' => ['thesaurus', ['countries', 'author_identities']];
    }

    /**
     * @dataProvider commandProvider
     * @param list<string> $filled tabelas que têm dados
     */
    public function testRefusesWhenTheDestinationTablesHaveData(string $kind, array $filled): void
    {
        $tester = new CommandTester($this->command($kind, $this->connection($filled)));

        $status = $tester->execute([], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $status);
        $display = $this->flat($tester->getDisplay());
        $this->assertStringContainsString('Importação recusada', $display);
        $this->assertStringContainsString($filled[0] . ': 7 linhas', $display);
        $this->assertStringContainsString('--force', $display);
        $this->assertSame([], $this->statements, 'Nada foi alterado: nenhum TRUNCATE nem INSERT');
        $this->assertSame([], $this->queries, 'Nem a origem foi lida');
    }

    /**
     * @dataProvider commandProvider
     * @param list<string> $filled
     */
    public function testForceAloneNeverErasesWithoutAConfirmation(string $kind, array $filled): void
    {
        // Modo não interativo: a confirmação vale como "não"
        $tester = new CommandTester($this->command($kind, $this->connection($filled)));
        $status = $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Cancelado', $this->flat($tester->getDisplay()));
        $this->assertSame([], $this->statements);
        $this->assertSame([], $this->queries);

        // Interativo, respondendo "não"
        $tester = new CommandTester($this->command($kind, $this->connection($filled)));
        $tester->setInputs(['no']);
        $status = $tester->execute(['--force' => true], ['interactive' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Apagar as tabelas de destino', $this->flat($tester->getDisplay()), 'A confirmação foi pedida');
        $this->assertSame([], $this->statements);
        $this->assertSame([], $this->queries);
    }

    /**
     * @dataProvider commandProvider
     * @param list<string> $filled
     */
    public function testForceWithAConfirmationSubstitutesTheTables(string $kind, array $filled): void
    {
        $tester = new CommandTester($this->command($kind, $this->connection($filled)));
        $tester->setInputs(['yes']);

        $status = $tester->execute(['--force' => true], ['interactive' => true]);

        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->assertStringContainsString('Apagar as tabelas de destino', $this->flat($tester->getDisplay()));
        $truncated = array_values(array_filter($this->statements, static fn (string $sql): bool => str_starts_with($sql, 'TRUNCATE')));
        $this->assertNotEmpty($truncated, 'Com a confirmação, as tabelas são limpas e a importação segue');
        foreach ($filled as $table) {
            $this->assertContains("TRUNCATE TABLE {$table}", $truncated);
        }
    }

    /**
     * @dataProvider commandProvider
     * @param list<string> $filled
     */
    public function testEmptyDestinationTablesNeedNeitherForceNorConfirmation(string $kind, array $filled): void
    {
        $tester = new CommandTester($this->command($kind, $this->connection([])));

        $status = $tester->execute([], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->assertStringNotContainsString('Importação recusada', $tester->getDisplay());
        $truncated = array_values(array_filter($this->statements, static fn (string $sql): bool => str_starts_with($sql, 'TRUNCATE')));
        $this->assertNotEmpty($truncated);
    }

    public function testTheDestinationTablesExistAndTheCheckCountsRealRows(): void
    {
        self::bootKernel();
        $conn = static::getContainer()->get(Connection::class);

        // Os nomes das tabelas de destino de cada comando existem no banco (a conferência é só leitura)
        foreach ([ImportBibliomapJournalsCommand::class, ImportBibliomapDatabasesCommand::class, ImportBibliomapThesaurusCommand::class] as $class) {
            $tables = (new \ReflectionClassConstant($class, 'DESTINATION_TABLES'))->getValue();
            $this->assertNotEmpty($tables);
            $found = AbstractBibliomapImportCommand::tablesWithData($conn, $tables);
            $this->assertEmpty(array_diff(array_keys($found), $tables));
        }

        // Tabela com dados aparece com a contagem; tabela vazia não aparece (tabela temporária: some com a conexão)
        $marker = 'Revista Marcador ' . bin2hex(random_bytes(4));
        $empty = 'qz_vazia_' . bin2hex(random_bytes(4));
        $conn->executeStatement("CREATE TEMPORARY TABLE `{$empty}` (id INT)");
        $conn->insert('qualis_journals', ['title' => $marker, 'areas_locked' => 0]);
        try {
            $found = AbstractBibliomapImportCommand::tablesWithData($conn, ['qualis_journals', $empty]);
            $this->assertArrayHasKey('qualis_journals', $found);
            $this->assertGreaterThanOrEqual(1, $found['qualis_journals']);
            $this->assertSame((int)$conn->fetchOne('SELECT COUNT(*) FROM qualis_journals'), $found['qualis_journals']);
            $this->assertArrayNotHasKey($empty, $found);

            $conn->executeStatement("INSERT INTO `{$empty}` (id) VALUES (1), (2)");
            $this->assertSame([$empty => 2], AbstractBibliomapImportCommand::tablesWithData($conn, [$empty]));
        } finally {
            $conn->delete('qualis_journals', ['title' => $marker]);
            $conn->executeStatement("DROP TEMPORARY TABLE IF EXISTS `{$empty}`");
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Periódicos: normalized_title e variantes pela trava
    // ------------------------------------------------------------------------------------------------------------

    public function testJournalRecordsCarryTheNormalizedTitleThatTheGuardNeeds(): void
    {
        $record = ImportBibliomapJournalsCommand::journalRecord(['id' => '22013', 'title' => ' Revista Ciência & Educação ', 'issn' => ' 0103-6564 ', 'normalized_issn' => null, 'qualis' => ' a2 ']);
        $this->assertSame(
            ['id' => 22013, 'title' => 'Revista Ciência & Educação', 'normalized_title' => 'REVISTA CIENCIA EDUCACAO', 'issn' => '0103-6564', 'normalized_issn' => '01036564', 'qualis' => 'A2'],
            $record
        );

        // Título em ISO-8859-1 (mojibake de origem) volta a UTF-8, e o normalized_title acompanha
        $latin1 = mb_convert_encoding('Educação e Sociedade', 'ISO-8859-1', 'UTF-8');
        $record = ImportBibliomapJournalsCommand::journalRecord(['id' => 1, 'title' => $latin1, 'issn' => null, 'normalized_issn' => '12345678', 'qualis' => null]);
        $this->assertSame('Educação e Sociedade', $record['title']);
        $this->assertSame('EDUCACAO E SOCIEDADE', $record['normalized_title']);
        $this->assertSame([null, '12345678', null], [$record['issn'], $record['normalized_issn'], $record['qualis']]);

        // Título só com símbolos não tem forma normalizada; título longo é cortado no tamanho da coluna
        $this->assertNull(ImportBibliomapJournalsCommand::journalRecord(['id' => 2, 'title' => '???', 'issn' => null, 'normalized_issn' => null, 'qualis' => null])['normalized_title']);
        $long = ImportBibliomapJournalsCommand::journalRecord(['id' => 3, 'title' => str_repeat('Ab ', 300), 'issn' => null, 'normalized_issn' => null, 'qualis' => null]);
        $this->assertSame(500, mb_strlen($long['title']));
        $this->assertLessThanOrEqual(500, mb_strlen((string)$long['normalized_title']));
    }

    public function testVariantsAreSavedThroughTheGuardAndAnErrorPageDoesNotEnter(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $command = new ImportBibliomapJournalsCommand($em, $container->get(JournalVariantGuard::class));

        $uniq = 'qz' . bin2hex(random_bytes(4));
        $insert = static function (string $title) use ($conn): int {
            $conn->insert('qualis_journals', ['title' => $title, 'normalized_title' => \App\Service\Thesaurus\StringNormalizer::normalizeString($title, true), 'areas_locked' => 0]);

            return (int)$conn->lastInsertId();
        };

        try {
            $a = $insert("Revista A {$uniq}");
            $b = $insert("Revista B {$uniq}");
            $row = static fn (int $journal, string $name, string $type = 'alternative', int $status = 1): array => [
                'journal_id' => $journal, 'variation_name' => $name, 'variation_type' => $type, 'status' => $status,
                'created_at' => '2020-01-02 03:04:05', 'updated_at' => '2021-02-03 04:05:06',
            ];
            $errorPage = [
                '<!DOCTYPE html>',
                '<b>Fatal error</b>:  Uncaught Error in /var/www/html/vendor/symfony/x.php:12',
                '#0 /var/www/html/vendor/symfony/http-kernel/Kernel.php(123): App\Foo->bar()',
                '<br />',
                'HTTP 500 Internal Server Error',
                '.error-page { color: red; }',
            ];
            $rows = [
                $row($a, "Rev A Abreviada {$uniq}", 'abbreviation'),
                $row($a, "Nome Antigo Inativo {$uniq}", 'alternative', 0),
                ...array_map(fn (string $line): array => $row($a, $line), $errorPage),
                $row($a, "Revista A {$uniq}"),                         // o título da própria família: já conhecido
                $row($b, "REVISTA A {$uniq}"),                         // título de outra família: conflito
                $row(999999999, "Variante de Periódico Ausente {$uniq}"),  // periódico que não foi importado
            ];

            $result = $command->importVariants($rows, [$a => $a, $b => $b]);

            $this->assertSame(['added' => 2, 'skipped' => count($errorPage) + 1, 'conflicts' => 1], $result);

            $saved = $conn->fetchAllAssociative('SELECT journal_id, variation_name, variation_type, status, created_at, updated_at FROM journal_name_variants WHERE journal_id IN (?, ?) ORDER BY id', [$a, $b]);
            $this->assertSame(["Rev A Abreviada {$uniq}", "Nome Antigo Inativo {$uniq}"], array_column($saved, 'variation_name'), 'Só os nomes entraram; a página de erro não');
            $this->assertSame([$a, $a], array_map('intval', array_column($saved, 'journal_id')));
            $this->assertSame(['abbreviation', 'alternative'], array_column($saved, 'variation_type'), 'O tipo da origem vale');
            $this->assertSame([1, 0], array_map('intval', array_column($saved, 'status')), 'O estado da origem vale: a trava cria a variante ativa');
            $this->assertSame('2020-01-02 03:04:05', $saved[0]['created_at']);
            $this->assertSame('2021-02-03 04:05:06', $saved[0]['updated_at']);
            $this->assertSame(0, (int)$conn->fetchOne("SELECT COUNT(*) FROM journal_name_variants WHERE variation_name LIKE '%<%' OR variation_name LIKE '%vendor%'"), 'Nenhum código no tesauro');
        } finally {
            $conn->executeStatement('DELETE FROM qualis_journals WHERE title LIKE ?', ['%' . $uniq . '%']);
        }
    }

    // ------------------------------------------------------------------------------------------------------------

    private function command(string $kind, Connection $conn): Command
    {
        if ($kind === 'thesaurus') {
            return new ImportBibliomapThesaurusCommand($conn);
        }

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        return match ($kind) {
            'journals' => new ImportBibliomapJournalsCommand($em, $this->createMock(JournalVariantGuard::class)),
            'databases' => new ImportBibliomapDatabasesCommand($em),
            default => throw new \InvalidArgumentException($kind),
        };
    }

    /**
     * Conexão simulada: `COUNT(*)` devolve 7 para as tabelas de `$filled` e 0 para as outras, as leituras da origem voltam
     * vazias, e tudo o que seria gravado é só anotado em `$this->statements`.
     *
     * @param list<string> $filled
     */
    private function connection(array $filled): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturnCallback(static function (string $sql) use ($filled): int {
            foreach ($filled as $table) {
                if (str_contains($sql, '`' . $table . '`')) {
                    return 7;
                }
            }

            return 0;
        });
        $conn->method('executeStatement')->willReturnCallback(function (string $sql): int {
            $this->statements[] = preg_replace('/\s+/', ' ', trim($sql));

            return 0;
        });
        $conn->method('executeQuery')->willReturnCallback(function (string $sql): Result {
            $this->queries[] = $sql;
            $result = $this->createMock(Result::class);
            $result->method('fetchAllAssociative')->willReturn([]);

            return $result;
        });

        return $conn;
    }

    /**
     * Texto da saída sem as quebras de linha da formatação dos blocos.
     */
    private function flat(string $display): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $display));
    }
}
