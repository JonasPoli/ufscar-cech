<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Repository\ProductionItemRepository;
use App\Repository\QualisJournalRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Busca de produções por nome de periódico (`searchProductions`, `countSearchProductions`) e nome usual do periódico
 * dos artigos de um docente (`getUsualJournalNamesForResearcher`).
 *
 * Os dados usam sufixo único e ficam dentro de uma transação revertida no tearDown: nada é gravado em `cech_test`.
 */
class ProductionItemRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private ProductionItemRepository $repo;
    private QualisJournalRepository $journals;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->repo = $this->em->getRepository(ProductionItem::class);
        $this->journals = $this->em->getRepository(QualisJournal::class);

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

    public function testSearchFindsArticlesByAnyNameOfTheirJournalFamilyAndKeepsTheOldFilter(): void
    {
        $t = $this->token();
        $term = "Revista Nossa $t";
        $canonical = $this->createJournal("REVISTA NOSSA $t");
        $member = $this->createJournal("NOSSA ONLINE $t", $canonical);
        $researcher = $this->createResearcher();

        // Escritos de outro jeito que o termo, mas ligados à família: achados pelo nome da família
        $byCanonical = $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Estudo A $t", "Rev. Nossa $t", $canonical);
        $byMember = $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Estudo B $t", 'Outra grafia qualquer', $member);
        // O nome do Lattes contém o termo e não há vínculo: continua achado pelo filtro antigo
        $byRawName = $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Estudo C $t", "Revista Nossa $t (Online)", null);
        // Capítulo ligado à família (vínculo antigo): o vínculo só vale para artigo
        $chapter = $this->createItem($researcher, ProductionItem::TYPE_CAPITULO, "Capítulo D $t", 'Livro qualquer', $canonical);
        // Outro periódico, sem relação
        $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Estudo E $t", "Revista Alheia $t", $this->createJournal("REVISTA ALHEIA $t"));

        // Sem ids, a consulta é a de sempre: só o nome do Lattes
        $this->assertSame([$byRawName->getId()], $this->ids($this->repo->searchProductions($term)));
        $this->assertSame(1, $this->repo->countSearchProductions($term));

        // Com os ids da família, os artigos ligados a ela também entram, e o capítulo não
        $rowIds = $this->journals->findFamilyRowIdsByName($term);
        $this->assertSame([$canonical->getId(), $member->getId()], $rowIds);

        $found = $this->ids($this->repo->searchProductions($term, null, 1, 20, $rowIds));
        sort($found);
        $this->assertSame([$byCanonical->getId(), $byMember->getId(), $byRawName->getId()], $found);
        $this->assertSame(3, $this->repo->countSearchProductions($term, null, $rowIds));
        $this->assertNotContains($chapter->getId(), $found);
    }

    public function testSearchWithJournalIdsStillRespectsTheDepartmentFilterAndPagination(): void
    {
        $t = $this->token();
        $term = "Revista Nossa $t";
        $journal = $this->createJournal("REVISTA NOSSA $t");
        $inside = $this->createResearcher("Departamento Teste $t");
        $outside = $this->createResearcher("Departamento Outro $t");

        $first = $this->createItem($inside, ProductionItem::TYPE_ARTIGO, "Estudo A $t", "Rev. N. $t", $journal, 2022);
        $second = $this->createItem($inside, ProductionItem::TYPE_ARTIGO, "Estudo B $t", "Rev. N. $t", $journal, 2021);
        $this->createItem($outside, ProductionItem::TYPE_ARTIGO, "Estudo C $t", "Rev. N. $t", $journal, 2023);

        $rowIds = $this->journals->findFamilyRowIdsByName($term);

        // O OR da família não vaza por cima do filtro de departamento
        $this->assertSame(
            [$first->getId(), $second->getId()],
            $this->ids($this->repo->searchProductions($term, "Departamento Teste $t", 1, 20, $rowIds))
        );
        $this->assertSame(2, $this->repo->countSearchProductions($term, "Departamento Teste $t", $rowIds));
        $this->assertSame(3, $this->repo->countSearchProductions($term, null, $rowIds));

        // Paginação: uma por página, da mais recente para a mais antiga
        $this->assertSame([$first->getId()], $this->ids($this->repo->searchProductions($term, "Departamento Teste $t", 1, 1, $rowIds)));
        $this->assertSame([$second->getId()], $this->ids($this->repo->searchProductions($term, "Departamento Teste $t", 2, 1, $rowIds)));
    }

    public function testUsualJournalNamesComeFromTheCanonicalOfEachArticleInOneQuery(): void
    {
        $t = $this->token();
        $canonical = $this->createJournal("REVISTA USUAL $t", null, "Revista Usual $t");
        $member = $this->createJournal("USUAL ONLINE $t", $canonical, null);
        $noDisplay = $this->createJournal("SEM NOME DE EXIBICAO $t", null, null);
        $researcher = $this->createResearcher();
        $other = $this->createResearcher();

        $viaCanonical = $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Estudo A $t", 'Rev. Usual', $canonical);
        $viaMember = $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Estudo B $t", 'Usual Online', $member);
        $withoutDisplay = $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Estudo C $t", 'Sem nome', $noDisplay);
        $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Estudo D $t", 'Sem vínculo', null);
        $this->createItem($researcher, ProductionItem::TYPE_CAPITULO, "Capítulo E $t", 'Livro', $canonical);
        $this->createItem($other, ProductionItem::TYPE_ARTIGO, "Estudo F $t", 'De outro docente', $canonical);

        $names = $this->repo->getUsualJournalNamesForResearcher($researcher->getId());

        $this->assertSame(
            [
                $viaCanonical->getId() => "Revista Usual $t",
                $viaMember->getId() => "Revista Usual $t",
                $withoutDisplay->getId() => "SEM NOME DE EXIBICAO $t",
            ],
            $names
        );
    }

    // ------------------------------------------------------------------------------------------------

    /** Só letras: o termo da busca por nome de periódico precisa de 3 letras. */
    private function token(): string
    {
        return 'zq' . strtr(bin2hex(random_bytes(5)), '0123456789', 'ghijklmnop');
    }

    private function createJournal(string $title, ?QualisJournal $canonical = null, ?string $displayTitle = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        $journal->setDisplayTitle($displayTitle);
        if ($canonical !== null) {
            $journal->setCanonical($canonical);
            $journal->setCanonicalMethod('manual');
        }
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    private function createResearcher(?string $department = null): Researcher
    {
        $researcher = new Researcher();
        $researcher->setIdLattes('9955' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT));
        $researcher->setFullName('Docente Teste Repositório de Produções');
        $researcher->setSlug('docente-teste-repo-producoes-' . bin2hex(random_bytes(4)));
        $researcher->setDepartment($department);
        $this->em->persist($researcher);
        $this->em->flush();

        return $researcher;
    }

    private function createItem(Researcher $researcher, string $type, string $title, ?string $journalName, ?QualisJournal $linkedTo, int $year = 2020): ProductionItem
    {
        $item = new ProductionItem();
        $item->setItemType($type);
        $item->setTitle($title);
        $item->setYear($year);
        $item->setJournalName($journalName);
        $item->setQualisJournal($linkedTo);
        $researcher->addProduction($item);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /**
     * @param ProductionItem[] $items
     *
     * @return list<int>
     */
    private function ids(array $items): array
    {
        return array_map(static fn (ProductionItem $item): int => (int)$item->getId(), $items);
    }
}
