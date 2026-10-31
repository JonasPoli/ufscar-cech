<?php

declare(strict_types=1);

namespace App\Tests\Service\Indexing;

use App\Entity\AcademicDatabase;
use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Service\Indexing\CurriculumNormalizationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Indexação de periódicos: só artigo é resolvido, nos dois modos (por docente e em lote), e o vínculo de item que não é
 * artigo (capítulo, evento, texto em jornal...) sai, porque o `journal_name` deles é o título do livro, do congresso ou
 * do jornal, que pode coincidir com o de uma revista.
 *
 * Os dados usam sufixo único e ficam dentro de uma transação revertida no tearDown: nada é gravado em `cech_test`,
 * inclusive o que a indexação em lote e a fila de nomes gravam. O `journal_name` do Lattes nunca é alterado.
 */
class CurriculumNormalizationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private CurriculumNormalizationService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->service = $container->get(CurriculumNormalizationService::class);

        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        if (isset($this->em)) {
            $this->em->clear();
        }

        parent::tearDown();
    }

    public function testNormalizeResearcherResolvesOnlyArticlesAndClearsTheLinkOfOtherTypes(): void
    {
        $t = $this->token();
        $journal = $this->createIndexedJournal("REVISTA SELO $t", 'A1');
        $researcher = $this->createResearcher();

        $article = $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Revista Selo $t");
        $chapter = $this->createStaleItem($researcher, ProductionItem::TYPE_CAPITULO, "Revista Selo $t", $journal);
        $event = $this->createStaleItem($researcher, ProductionItem::TYPE_EVENTO, "Revista Selo $t", $journal);
        $newspaper = $this->createStaleItem($researcher, ProductionItem::TYPE_TEXTO_JORNAL, "Revista Selo $t", $journal);

        $stats = $this->service->normalizeResearcher($researcher);

        $this->assertSame(4, $stats['productionsProcessed']);
        $this->assertSame(1, $stats['qualisResolved'], 'Só o artigo é resolvido');

        $row = $this->row($article);
        $this->assertSame($journal->getId(), (int)$row['qualis_journal_id']);
        $this->assertSame('A1', $row['qualis']);
        $this->assertNotNull($row['indexed_databases']);

        foreach ([$chapter, $event, $newspaper] as $item) {
            $row = $this->row($item);
            $this->assertNull($row['qualis_journal_id']);
            $this->assertNull($row['qualis']);
            $this->assertNull($row['indexed_databases']);
            $this->assertSame("Revista Selo $t", $row['journal_name'], 'O journal_name do Lattes continua intacto');
        }
    }

    public function testBatchIndexingResolvesOnlyArticlesAndClearsTheLinkOfOtherTypes(): void
    {
        $t = $this->token();
        $journal = $this->createIndexedJournal("REVISTA LOTE $t", 'B1');
        $researcher = $this->createResearcher();

        $article = $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Revista Lote $t");
        $chapter = $this->createStaleItem($researcher, ProductionItem::TYPE_CAPITULO, "Revista Lote $t", $journal);
        $event = $this->createStaleItem($researcher, ProductionItem::TYPE_EVENTO, "Revista Lote $t", $journal);
        $book = $this->createItem($researcher, ProductionItem::TYPE_LIVRO, "Revista Lote $t");

        $stats = $this->service->indexAllProductionsJournalAndDatabases();

        $this->assertGreaterThanOrEqual(2, $stats['nonArticlesCleared'], 'Capítulo e evento tinham vínculo gravado');
        $this->assertGreaterThanOrEqual(1, $stats['journalsMatched']);

        $row = $this->row($article);
        $this->assertSame($journal->getId(), (int)$row['qualis_journal_id']);
        $this->assertSame('B1', $row['qualis']);

        foreach ([$chapter, $event, $book] as $item) {
            $row = $this->row($item);
            $this->assertNull($row['qualis_journal_id']);
            $this->assertNull($row['qualis']);
            $this->assertNull($row['indexed_databases']);
            $this->assertSame("Revista Lote $t", $row['journal_name'], 'O journal_name do Lattes continua intacto');
        }
    }

    // ------------------------------------------------------------------------------------------------

    private function token(): string
    {
        return 'Zq' . bin2hex(random_bytes(5));
    }

    /** Periódico com Qualis e indexado na Scopus (a linha da Scopus é criada dentro da transação, se faltar). */
    private function createIndexedJournal(string $title, string $qualis): QualisJournal
    {
        $scopus = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => 'scopus']);
        if ($scopus === null) {
            $scopus = new AcademicDatabase();
            $scopus->setName('Scopus');
            $scopus->setAcronym('scopus');
            $this->em->persist($scopus);
        }

        $journal = new QualisJournal();
        $journal->setTitle($title);
        $journal->setQualis($qualis);
        $journal->addAcademicDatabase($scopus);
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    private function createResearcher(): Researcher
    {
        $researcher = new Researcher();
        $researcher->setIdLattes('9922' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT));
        $researcher->setFullName('Docente Teste Indexação por Tipo');
        $researcher->setSlug('docente-teste-indexacao-tipo-' . bin2hex(random_bytes(4)));
        $this->em->persist($researcher);
        $this->em->flush();

        return $researcher;
    }

    private function createItem(Researcher $researcher, string $type, string $journalName): ProductionItem
    {
        $item = new ProductionItem();
        $item->setItemType($type);
        $item->setTitle('Item de teste ' . $type);
        $item->setYear(2020);
        $item->setJournalName($journalName);
        $researcher->addProduction($item);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /** Item que não é artigo mas já tem vínculo, Qualis e bases gravados por uma indexação anterior. */
    private function createStaleItem(Researcher $researcher, string $type, string $journalName, QualisJournal $journal): ProductionItem
    {
        $item = $this->createItem($researcher, $type, $journalName);
        $item->setQualisJournal($journal);
        $item->setQualis($journal->getQualis());
        $item->setIndexedDatabases([['id' => 1, 'name' => 'Scopus', 'acronym' => 'scopus']]);
        $this->em->flush();

        return $item;
    }

    /** @return array<string, mixed> */
    private function row(ProductionItem $item): array
    {
        return $this->conn->fetchAssociative(
            'SELECT journal_name, qualis, qualis_journal_id, indexed_databases FROM production_items WHERE id = ?',
            [$item->getId()]
        );
    }
}
