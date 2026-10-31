<?php

declare(strict_types=1);

namespace App\Tests\Service\Indexing;

use App\Entity\ProductionItem;
use App\Entity\Researcher;
use App\Service\Indexing\ThematicTermIndexService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A reconstrução do índice temático grava em tabelas de trabalho e as troca por RENAME TABLE: uma falha no meio não pode
 * deixar a busca vazia, e a troca não pode levar embora as chaves estrangeiras nem os índices.
 *
 * Os dados de teste usam sufixo único e saem no tearDown (cech_test é compartilhado com outros testes e agentes).
 */
class ThematicTermIndexServiceTest extends KernelTestCase
{
    private ThematicTermIndexService $service;
    private EntityManagerInterface $em;
    private Connection $conn;
    private string $uniq;

    /** @var int[] */
    private array $researcherIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->service = $container->get(ThematicTermIndexService::class);
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->uniq = 'qz' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            // Os vínculos e os artigos de teste saem junto com o docente (ON DELETE CASCADE)
            foreach ($this->researcherIds as $id) {
                $this->conn->executeStatement('DELETE FROM researchers WHERE id = ?', [$id]);
            }
            $this->conn->executeStatement('DELETE FROM thematic_terms WHERE slug LIKE ?', ['%' . $this->uniq . '%']);
            foreach (['thematic_term_researchers_new', 'thematic_terms_new', 'thematic_term_researchers_old', 'thematic_terms_old'] as $table) {
                $this->conn->executeStatement('DROP TABLE IF EXISTS ' . $table);
            }
        }
        if (isset($this->em)) {
            $this->em->clear();
        }

        parent::tearDown();
    }

    public function testNormalizeStringRemovesAccentsAndPunctuation(): void
    {
        $this->assertEquals('educacao especial', $this->service->normalizeString('Educação Especial!'));
        $this->assertEquals('inteligencia artificial redes neurais', $this->service->normalizeString('Inteligência Artificial & Redes Neurais'));
        $this->assertEquals('politicas publicas', $this->service->normalizeString('  POLÍTICAS PÚBLICAS  '));
        $this->assertEquals('ciencias humanas sociais', $this->service->normalizeString('Ciências Humanas / Sociais'));
    }

    public function testIndexAllProducesValidOutput(): void
    {
        // Executa indexAll e verifica a consistência do array de retorno
        $stats = $this->service->indexAll();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('totalTerms', $stats);
        $this->assertArrayHasKey('totalLinks', $stats);
        $this->assertArrayHasKey('totalResearchers', $stats);
        $this->assertArrayHasKey('executionTime', $stats);

        $this->assertGreaterThan(0, $stats['totalTerms']);
        $this->assertGreaterThan(0, $stats['totalLinks']);
        $this->assertGreaterThan(0, $stats['totalResearchers']);
    }

    public function testRebuildReplacesTheIndexAndKeepsKeysAndIndexes(): void
    {
        $researcherId = $this->createResearcherWithKeyword();
        $this->createSentinelTerm($researcherId);

        $stats = $this->service->indexAll();

        // O índice novo reaproveita os ids (a tabela nasce do zero): o termo antigo se reconhece pelo slug, não pelo id
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM thematic_terms WHERE slug = ?', ["zz-sentinela-{$this->uniq}"]), 'O índice antigo foi substituído.');
        $this->assertSame(
            1,
            (int)$this->conn->fetchOne(
                'SELECT COUNT(*) FROM thematic_terms t JOIN thematic_term_researchers l ON l.term_id = t.id WHERE t.slug = ? AND l.researcher_id = ?',
                ["termo-{$this->uniq}", $researcherId]
            ),
            'O termo do docente de teste entrou no índice novo, com o vínculo.'
        );
        $this->assertSame($stats['totalTerms'], (int)$this->conn->fetchOne('SELECT COUNT(*) FROM thematic_terms'));
        $this->assertSame($stats['totalLinks'], (int)$this->conn->fetchOne('SELECT COUNT(*) FROM thematic_term_researchers'));

        $this->assertSame([], $this->leftoverTables(), 'Nenhuma tabela de trabalho ou antiga sobra depois da troca.');
        $this->assertLiveSchemaIsIntact();

        // A cascata continua valendo na tabela nova: apagar o docente leva os vínculos junto
        $this->conn->executeStatement('DELETE FROM researchers WHERE id = ?', [$researcherId]);
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM thematic_term_researchers WHERE researcher_id = ?', [$researcherId]));
    }

    public function testFailureInTheMiddleOfTheRebuildKeepsTheOldIndex(): void
    {
        $researcherId = $this->createResearcherWithKeyword();
        $sentinelTermId = $this->createSentinelTerm($researcherId);
        $termsBefore = (int)$this->conn->fetchOne('SELECT COUNT(*) FROM thematic_terms');
        $linksBefore = (int)$this->conn->fetchOne('SELECT COUNT(*) FROM thematic_term_researchers');

        // Os termos já foram gravados na tabela de trabalho quando a carga dos vínculos cai
        $failing = new class($this->em, new NullLogger()) extends ThematicTermIndexService {
            protected function insertBatchLinks(Connection $conn, array $batch): void
            {
                throw new \RuntimeException('falha simulada na carga dos vínculos');
            }
        };

        try {
            $failing->indexAll();
            $this->fail('A falha simulada deveria interromper a reconstrução.');
        } catch (\RuntimeException $e) {
            $this->assertSame('falha simulada na carga dos vínculos', $e->getMessage());
        }

        $this->assertSame($termsBefore, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM thematic_terms'), 'A busca temática segue com os termos antigos.');
        $this->assertSame($linksBefore, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM thematic_term_researchers'));
        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM thematic_terms WHERE id = ?', [$sentinelTermId]));
        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM thematic_term_researchers WHERE term_id = ?', [$sentinelTermId]));
        $this->assertSame([], $this->leftoverTables(), 'As tabelas de trabalho foram descartadas.');
        $this->assertLiveSchemaIsIntact();
    }

    public function testLeftoversFromAnInterruptedRunAreDiscardedBeforeTheNextOne(): void
    {
        $this->createResearcherWithKeyword();
        // Uma execução que morreu no meio deixa as tabelas de trabalho (aqui, com estrutura qualquer)
        $this->conn->executeStatement('CREATE TABLE thematic_terms_new (lixo INT)');
        $this->conn->executeStatement('CREATE TABLE thematic_term_researchers_old (lixo INT)');

        $stats = $this->service->indexAll();

        $this->assertGreaterThan(0, $stats['totalTerms']);
        $this->assertSame([], $this->leftoverTables());
        $this->assertLiveSchemaIsIntact();
    }

    /**
     * Docente com um artigo cuja palavra-chave é única do teste: um termo declarado vale 2 ocorrências, o mínimo do índice.
     */
    private function createResearcherWithKeyword(): int
    {
        $researcher = new Researcher();
        $researcher->setIdLattes('9988' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT));
        $researcher->setFullName("Docente Teste Temas {$this->uniq}");
        $researcher->setSlug("docente-teste-temas-{$this->uniq}");
        $this->em->persist($researcher);
        $this->em->flush();
        $this->researcherIds[] = $researcher->getId();

        $article = new ProductionItem();
        $article->setItemType(ProductionItem::TYPE_ARTIGO);
        $article->setTitle("Artigo de teste {$this->uniq}");
        $article->setYear(2024);
        $article->setKeywords(["Termo {$this->uniq}"]);
        $researcher->addProduction($article);
        $this->em->persist($article);
        $this->em->flush();

        return (int)$researcher->getId();
    }

    /** Termo e vínculo "antigos" no índice que está no ar, para provar que a reconstrução os troca ou os preserva. */
    private function createSentinelTerm(int $researcherId): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->insert('thematic_terms', [
            'term' => "Sentinela {$this->uniq}",
            'slug' => "zz-sentinela-{$this->uniq}",
            'normalized_term' => "sentinela {$this->uniq}",
            'total_occurrences' => 5,
            'researcher_count' => 1,
            'source_type' => 'keyword',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $termId = (int)$this->conn->lastInsertId();
        $this->conn->insert('thematic_term_researchers', [
            'term_id' => $termId,
            'researcher_id' => $researcherId,
            'occurrences' => 5,
            'sample_titles' => '[]',
            'created_at' => $now,
        ]);

        return $termId;
    }

    /** @return string[] */
    private function leftoverTables(): array
    {
        return $this->conn->fetchFirstColumn(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('thematic_terms_new', 'thematic_term_researchers_new', 'thematic_terms_old', 'thematic_term_researchers_old') ORDER BY TABLE_NAME"
        );
    }

    /** As tabelas do ar mantêm as chaves estrangeiras (com os nomes do Doctrine) e os índices das migrations. */
    private function assertLiveSchemaIsIntact(): void
    {
        $foreignKeys = $this->conn->fetchAllKeyValue(
            "SELECT CONSTRAINT_NAME, CONCAT(REFERENCED_TABLE_NAME, ':', DELETE_RULE) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'thematic_term_researchers' ORDER BY CONSTRAINT_NAME"
        );
        $this->assertSame(
            ['FK_49F94465C7533BDE' => 'researchers:CASCADE', 'FK_49F94465E2C35FC' => 'thematic_terms:CASCADE'],
            $foreignKeys
        );

        $this->assertSame(
            ['IDX_49F94465E2C35FC', 'PRIMARY', 'idx_researcher_term', 'idx_term_occurrences', 'uniq_term_researcher'],
            $this->indexNames('thematic_term_researchers')
        );
        $this->assertSame(
            ['PRIMARY', 'UNIQ_8F1EEBC989D9B62', 'idx_thematic_term_normalized', 'idx_thematic_term_occurrences'],
            $this->indexNames('thematic_terms')
        );
    }

    /** @return string[] */
    private function indexNames(string $table): array
    {
        $names = $this->conn->fetchFirstColumn(
            'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        sort($names, SORT_STRING);

        return $names;
    }
}
