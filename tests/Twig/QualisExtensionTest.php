<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\AcademicDatabase;
use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Twig\QualisExtension;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Selos de Qualis e de bases de indexação (`QualisExtension`): um item mostra só o que a indexação gravou nele.
 *
 * Os dados usam sufixo único e ficam dentro de uma transação revertida no tearDown: nada é gravado em `cech_test`.
 */
class QualisExtensionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private QualisExtension $extension;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->extension = $container->get(QualisExtension::class);

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

    public function testAnItemShowsTheQualisAndTheDatabasesStoredInIt(): void
    {
        $item = new ProductionItem();
        $item->setJournalName('Revista qualquer');
        $item->setQualis('A2');
        $item->setIndexedDatabases([['id' => 1, 'name' => 'Scopus', 'acronym' => 'scopus', 'logo' => null, 'url' => null, 'color' => '#ea580c']]);

        $this->assertSame('A2', $this->extension->resolveQualis($item));
        $this->assertSame('scopus', $this->extension->resolveDatabases($item)[0]['acronym']);

        $badges = $this->extension->renderJournalBadges($item, null, null);
        $this->assertStringContainsString('Qualis A2', $badges);
        $this->assertStringContainsString('Scopus', $badges);

        // O estrato de reserva do template só vale quando o item não tem o seu
        $this->assertStringContainsString('Qualis A2', $this->extension->renderQualisBadge($item, null, 'B1'));
    }

    public function testAnItemWithoutStoredValuesIsNotResolvedAgainByNameOrIssn(): void
    {
        [$title, $issn] = $this->createIndexedJournal('A1');

        // O resolver acharia o periódico e o estrato por esse nome e por esse ISSN...
        $resolver = static::getContainer()->get(\App\Service\Thesaurus\JournalResolverService::class);
        $this->assertSame('A1', $resolver->resolveQualis($title, $issn));
        $this->assertNotEmpty($resolver->resolveDatabases($title, $issn));

        // ...mas o item não tem vínculo gravado: nenhum selo é inventado na hora de renderizar
        $item = new ProductionItem();
        $item->setJournalName($title);
        $item->setIssn($issn);

        $this->assertNull($this->extension->resolveQualis($item));
        $this->assertSame([], $this->extension->resolveDatabases($item));
        $this->assertSame('', $this->extension->renderQualisBadge($item, $issn, null));
        $this->assertSame('', $this->extension->renderDatabaseBadges($item, $issn));
        $this->assertSame('', $this->extension->renderJournalBadges($item, $issn, null));
    }

    public function testABookChapterWhoseTitleEqualsAJournalGetsNoBadge(): void
    {
        [$title] = $this->createIndexedJournal('A1');
        $researcher = new Researcher();
        $researcher->setIdLattes('9933' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT));
        $researcher->setFullName('Docente Teste Selo');
        $researcher->setSlug('docente-teste-selo-' . bin2hex(random_bytes(4)));

        $chapter = new ProductionItem();
        $chapter->setItemType(ProductionItem::TYPE_CAPITULO);
        $chapter->setTitle('Capítulo de livro');
        $chapter->setJournalName($title);   // o título do livro coincide com o de uma revista
        $researcher->addProduction($chapter);
        $this->em->persist($researcher);
        $this->em->persist($chapter);
        $this->em->flush();

        $this->assertSame('', $this->extension->renderJournalBadges($chapter, $chapter->getIssn(), $chapter->getQualis()));
    }

    public function testPlainTextWithoutAnItemStillGoesThroughTheResolver(): void
    {
        [$title, $issn] = $this->createIndexedJournal('B1');

        // Nenhum template chama com texto; quem chama (nome e ISSN, sem item) recebe a resolução do cadastro
        $this->assertSame('B1', $this->extension->resolveQualis($title, $issn));
        $this->assertStringContainsString('Qualis B1', $this->extension->renderJournalBadges($title, $issn));
        $this->assertStringContainsString('Scopus', $this->extension->renderDatabaseBadges($title, $issn));
        $this->assertNull($this->extension->resolveQualis(42));
        $this->assertSame([], $this->extension->resolveDatabases(null));
    }

    // ------------------------------------------------------------------------------------------------

    /**
     * Periódico com Qualis e indexado na Scopus, com título e ISSN únicos (a linha da Scopus é criada dentro da
     * transação quando o banco de testes não a tem).
     *
     * @return array{0: string, 1: string} título e ISSN
     */
    private function createIndexedJournal(string $qualis): array
    {
        $title = 'REVISTA SELO ' . strtoupper(bin2hex(random_bytes(5)));
        $issn = $this->issn();

        $scopus = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => 'scopus']);
        if ($scopus === null) {
            $scopus = new AcademicDatabase();
            $scopus->setName('Scopus');
            $scopus->setAcronym('scopus');
            $this->em->persist($scopus);
        }

        $journal = new QualisJournal();
        $journal->setTitle($title);
        $journal->setIssnImp($issn);
        $journal->setQualis($qualis);
        $journal->addAcademicDatabase($scopus);
        $this->em->persist($journal);
        $this->em->flush();

        return [$title, $issn];
    }

    /** ISSN válido (dígito verificador conferido), no formato 1234-5679. */
    private function issn(): string
    {
        $digits = str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += (int)$digits[$i] * (8 - $i);
        }
        $check = (11 - ($sum % 11)) % 11;

        return substr($digits, 0, 4) . '-' . substr($digits, 4) . ($check === 10 ? 'X' : (string)$check);
    }
}
