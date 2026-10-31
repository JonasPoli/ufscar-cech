<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SyncKeywordsCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:sync:keywords` serve só para dados antigos e não pode corromper palavras-chave: título repetido dentro do mesmo
 * docente é ignorado, item que já tem palavras-chave não é sobrescrito e uma falha vira código de saída de erro.
 *
 * O docente de teste tem ID Lattes e título com sufixo único e sai no tearDown (cech_test é compartilhado).
 */
class SyncKeywordsCommandTest extends KernelTestCase
{
    private Connection $conn;
    private string $uniq;
    private string $idLattes;
    private int $researcherId;
    private string $dir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(Connection::class);
        $this->uniq = 'qz' . bin2hex(random_bytes(4));
        $this->idLattes = '9955' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->insert('researchers', [
            'id_lattes' => $this->idLattes,
            'full_name' => "Docente Palavras {$this->uniq}",
            'slug' => "docente-palavras-{$this->uniq}",
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->researcherId = (int)$this->conn->lastInsertId();

        $this->dir = sys_get_temp_dir() . '/keywords' . $this->uniq;
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Os itens de teste saem junto com o docente (ON DELETE CASCADE)
        $this->conn->executeStatement('DELETE FROM researchers WHERE id = ?', [$this->researcherId]);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    public function testUnambiguousItemReceivesTheKeywordsFromTheXml(): void
    {
        $id = $this->insertItem("Artigo único {$this->uniq}");
        $this->writeXml('curriculo.xml', [['title' => "Artigo único {$this->uniq}", 'keywords' => ['Educação', 'Inclusão']]]);

        $tester = $this->runCommand(['--file' => "{$this->dir}/curriculo.xml"]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame(['Educação', 'Inclusão'], $this->keywords($id));
    }

    public function testRepeatedTitleInTheDatabaseIsIgnored(): void
    {
        // Dois itens do mesmo docente com o mesmo título: a chave por título levava as palavras para o último
        $first = $this->insertItem("Resenha {$this->uniq}");
        $second = $this->insertItem("Resenha {$this->uniq}");
        $this->writeXml('curriculo.xml', [['title' => "Resenha {$this->uniq}", 'keywords' => ['Literatura']]]);

        $tester = $this->runCommand(['--file' => "{$this->dir}/curriculo.xml"]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame([], $this->keywords($first));
        $this->assertSame([], $this->keywords($second));
        $this->assertStringContainsString('repetido', $tester->getDisplay());
    }

    public function testRepeatedTitleInTheXmlIsIgnored(): void
    {
        $id = $this->insertItem("Entrevista {$this->uniq}");
        $this->writeXml('curriculo.xml', [
            ['title' => "Entrevista {$this->uniq}", 'keywords' => ['Política']],
            ['title' => "Entrevista {$this->uniq}", 'keywords' => ['Economia']],
        ]);

        $tester = $this->runCommand(['--file' => "{$this->dir}/curriculo.xml"]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame([], $this->keywords($id), 'Não há como saber qual das duas entrevistas é a do banco.');
    }

    public function testItemThatAlreadyHasKeywordsIsKeptAsIs(): void
    {
        $id = $this->insertItem("Artigo com palavras {$this->uniq}", ['Gravadas pelo importador']);
        $this->writeXml('curriculo.xml', [['title' => "Artigo com palavras {$this->uniq}", 'keywords' => ['Outras', 'Palavras']]]);

        $tester = $this->runCommand(['--file' => "{$this->dir}/curriculo.xml"]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame(['Gravadas pelo importador'], $this->keywords($id));
    }

    public function testExistingExtraDataIsPreservedWhenKeywordsAreAdded(): void
    {
        $id = $this->insertItem("Artigo com extra {$this->uniq}", null, ['outro_campo' => 'valor']);
        $this->writeXml('curriculo.xml', [['title' => "Artigo com extra {$this->uniq}", 'keywords' => ['Tema']]]);

        $this->runCommand(['--file' => "{$this->dir}/curriculo.xml"])->assertCommandIsSuccessful();

        $extra = json_decode((string)$this->conn->fetchOne('SELECT extra_data FROM production_items WHERE id = ?', [$id]), true);
        // O tipo JSON do MySQL reordena as chaves, por isso a comparação não olha a ordem
        $this->assertEqualsCanonicalizing(['outro_campo' => 'valor', 'keywords' => ['Tema']], $extra);
    }

    public function testFileThatFailsMakesTheCommandFailButTheOthersAreProcessed(): void
    {
        $id = $this->insertItem("Artigo do segundo arquivo {$this->uniq}");
        // "a" vem antes de "b" na ordem de leitura; o XML quebrado não pode impedir o seguinte
        file_put_contents("{$this->dir}/a-quebrado.xml", '<?xml version="1.0"?><CURRICULO-VITAE><sem-fechar>');
        $this->writeXml('b-valido.xml', [['title' => "Artigo do segundo arquivo {$this->uniq}", 'keywords' => ['Seguinte']]]);

        $tester = $this->runCommand(['--dir' => $this->dir]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'Quem chama o comando precisa saber que um arquivo falhou.');
        $this->assertStringContainsString('a-quebrado.xml', $tester->getDisplay());
        $this->assertSame(['Seguinte'], $this->keywords($id));
    }

    public function testDatabaseExceptionMakesTheCommandFail(): void
    {
        $this->writeXml('curriculo.xml', [['title' => 'Qualquer', 'keywords' => ['Tema']]]);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('banco indisponível'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $tester = new CommandTester(new SyncKeywordsCommand($em));
        $tester->execute(['--file' => "{$this->dir}/curriculo.xml"]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'A exceção não pode ser engolida.');
        $this->assertStringContainsString('banco indisponível', $tester->getDisplay());
    }

    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:sync:keywords'));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @param string[]|null $keywords
     * @param array<string, mixed> $extra
     */
    private function insertItem(string $title, ?array $keywords = null, array $extra = []): int
    {
        if ($keywords !== null) {
            $extra['keywords'] = $keywords;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->insert('production_items', [
            'researcher_id' => $this->researcherId,
            'item_type' => 'ARTIGO',
            'title' => $title,
            'extra_data' => $extra === [] ? null : json_encode($extra, JSON_UNESCAPED_UNICODE),
            'is_scientific_dissemination' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    /** @return string[] */
    private function keywords(int $itemId): array
    {
        $extra = $this->conn->fetchOne('SELECT extra_data FROM production_items WHERE id = ?', [$itemId]);

        return $extra ? (json_decode((string)$extra, true)['keywords'] ?? []) : [];
    }

    /**
     * @param list<array{title: string, keywords: string[]}> $articles
     */
    private function writeXml(string $name, array $articles): void
    {
        $nodes = '';
        foreach ($articles as $article) {
            $attributes = '';
            foreach (array_values($article['keywords']) as $i => $keyword) {
                $attributes .= sprintf(' PALAVRA-CHAVE-%d="%s"', $i + 1, htmlspecialchars($keyword, ENT_XML1 | ENT_QUOTES));
            }
            $nodes .= sprintf(
                '<ARTIGO-PUBLICADO><DADOS-BASICOS-DO-ARTIGO TITULO-DO-ARTIGO="%s"/><PALAVRAS-CHAVE%s/></ARTIGO-PUBLICADO>',
                htmlspecialchars($article['title'], ENT_XML1 | ENT_QUOTES),
                $attributes
            );
        }

        file_put_contents("{$this->dir}/{$name}", sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE NUMERO-IDENTIFICADOR="%s"><PRODUCAO-BIBLIOGRAFICA><ARTIGOS-PUBLICADOS>%s</ARTIGOS-PUBLICADOS></PRODUCAO-BIBLIOGRAFICA></CURRICULO-VITAE>',
            $this->idLattes,
            $nodes
        ));
    }
}
