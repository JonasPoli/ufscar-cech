<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Entity\ThematicTerm;
use App\Repository\ThematicTermRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * "Top revistas do tema" (`getEditorialAnalytics`): periódicos por família, só artigos, melhor estrato.
 *
 * O repositório é criado sem cache, para medir a consulta. Os dados usam sufixo único (o termo do tema é o próprio
 * sufixo, então só as linhas criadas aqui entram) e ficam dentro de uma transação revertida no tearDown.
 */
class ThematicTermRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();

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

    public function testTopJournalsGroupByFamilyCountOnlyArticlesAndShowTheBestStratum(): void
    {
        $t = $this->token();
        $canonical = $this->createJournal("REVISTA FAMILIA $t", null, "Revista Família $t");
        $member = $this->createJournal("FAMILIA ONLINE $t", $canonical, "Revista Família $t Online");
        $gama = $this->createJournal("BOLETIM GAMA $t", null, null);
        $r1 = $this->createResearcher();
        $r2 = $this->createResearcher();

        // Duas grafias da mesma família (uma delas ligada à linha-membro): uma linha só. B2 e A1: vence A1.
        $this->createItem($r1, ProductionItem::TYPE_ARTIGO, "Estudo A $t", "Rev. Família $t", $canonical, 'B2', '10.1/aaa', 2020);
        $this->createItem($r1, ProductionItem::TYPE_ARTIGO, "Estudo B $t", "REVISTA FAMILIA $t ONLINE", $member, 'A1', '10.1/bbb', 2021);
        // Coautoria entre docentes (mesmo DOI) e artigo sem DOI repetido (mesmo ano e título) contam uma vez
        $this->createItem($r2, ProductionItem::TYPE_ARTIGO, "Estudo A (cópia) $t", "Rev. Família $t", $canonical, 'B2', '10.1/AAA', 2020);
        $this->createItem($r1, ProductionItem::TYPE_ARTIGO, "Estudo Y $t", "Rev. Família $t", $canonical, 'B1', null, 2020);
        $this->createItem($r2, ProductionItem::TYPE_ARTIGO, "estudo y $t", "Rev. Família $t", $canonical, 'B1', null, 2020);
        $this->createItem($r1, ProductionItem::TYPE_ARTIGO, "Estudo Y $t", "Rev. Família $t", $canonical, 'B1', null, 2022);

        // Capítulo de livro ligado à família (vínculo antigo) e com Qualis A1: não entra, nem na contagem nem no estrato
        $this->createItem($r1, ProductionItem::TYPE_CAPITULO, "Capítulo Z $t", "Boletim Gama $t", $gama, 'A1', null, 2020);
        $this->createItem($r1, ProductionItem::TYPE_ARTIGO, "Estudo G1 $t", "Boletim Gama $t", $gama, '', null, 2020);
        $this->createItem($r1, ProductionItem::TYPE_ARTIGO, "Estudo G2 $t", "Boletim Gama $t", $gama, 'C', null, 2021);

        // Sem vínculo: agrupa pelo nome normalizado e mostra a grafia do Lattes mais frequente
        $this->createItem($r1, ProductionItem::TYPE_ARTIGO, "Estudo U1 $t", "Anais Livres $t", null, null, null, 2020);
        $this->createItem($r1, ProductionItem::TYPE_ARTIGO, "Estudo U2 $t", "Anais Livres $t", null, null, null, 2021);
        $this->createItem($r2, ProductionItem::TYPE_ARTIGO, "Estudo U3 $t", "ANAIS LIVRES $t", null, null, null, 2022);
        // Sem vínculo e sem nome de periódico: não forma linha
        $this->createItem($r2, ProductionItem::TYPE_ARTIGO, "Estudo U4 $t", null, null, null, null, 2022);

        $top = $this->analytics($t)['topJournals'];

        $this->assertSame(
            [
                ['journalName' => "Revista Família $t", 'qualis' => 'A1', 'count' => 4],
                ['journalName' => "Anais Livres $t", 'qualis' => null, 'count' => 3],
                ['journalName' => "BOLETIM GAMA $t", 'qualis' => 'C', 'count' => 2],
            ],
            $top
        );
    }

    public function testTheLimitKeepsTheJournalsWithMoreArticles(): void
    {
        $t = $this->token();
        $first = $this->createJournal("REVISTA PRIMEIRA $t", null, "Revista Primeira $t");
        $second = $this->createJournal("REVISTA SEGUNDA $t", null, "Revista Segunda $t");
        $researcher = $this->createResearcher();

        $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Estudo 1 $t", 'x', $second, 'A2', null, 2020);
        $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Estudo 2 $t", 'x', $first, 'B1', null, 2020);
        $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Estudo 3 $t", 'x', $first, 'B1', null, 2021);

        $top = $this->analytics($t, 1)['topJournals'];

        $this->assertSame([['journalName' => "Revista Primeira $t", 'qualis' => 'B1', 'count' => 2]], $top);
    }

    public function testAnEntryCachedInTheOldFormatIsNotServed(): void
    {
        $t = $this->token();
        $journal = $this->createJournal("REVISTA NOVA $t", null, "Revista Nova $t");
        $researcher = $this->createResearcher();
        $this->createItem($researcher, ProductionItem::TYPE_ARTIGO, "Estudo 1 $t", 'x', $journal, 'A1', null, 2020);

        $term = $this->term($t);
        $cache = new ArrayAdapter();
        $repository = new ThematicTermRepository(static::getContainer()->get('doctrine'), $cache);

        // O que o formato antigo deixou no cache (chave sem versão): por grafia do Lattes, com o pior estrato
        $cache->get('thematic_editorial_' . $term->getSlug(), static fn (): array => [
            'qualisDistribution' => [],
            'totalArticlesWithQualis' => 0,
            'topQualisPercentage' => 0.0,
            'topJournals' => [['journalName' => 'FORMATO ANTIGO', 'qualis' => 'B2', 'count' => 99]],
        ]);

        $result = $repository->getEditorialAnalytics($term, 6);

        $this->assertSame([['journalName' => "Revista Nova $t", 'qualis' => 'A1', 'count' => 1]], $result['topJournals']);
        $this->assertTrue($cache->hasItem('thematic_editorial_v2_' . $term->getSlug()), 'O formato novo usa a chave com versão');
    }

    // ------------------------------------------------------------------------------------------------

    /** @return array{qualisDistribution: array<string, int>, totalArticlesWithQualis: int, topQualisPercentage: float, topJournals: list<array{journalName: string, qualis: ?string, count: int}>} */
    private function analytics(string $token, int $limit = 10): array
    {
        $repository = new ThematicTermRepository(static::getContainer()->get('doctrine'), null);

        return $repository->getEditorialAnalytics($this->term($token), $limit);
    }

    private function term(string $token): ThematicTerm
    {
        return (new ThematicTerm())
            ->setTerm($token)
            ->setNormalizedTerm(strtolower($token))
            ->setSlug('teste-' . $token);
    }

    /** Só letras minúsculas: é o termo do tema e entra no título dos artigos. */
    private function token(): string
    {
        return 'zq' . strtr(bin2hex(random_bytes(5)), '0123456789', 'ghijklmnop');
    }

    private function createJournal(string $title, ?QualisJournal $canonical, ?string $displayTitle): QualisJournal
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

    private function createResearcher(): Researcher
    {
        $researcher = new Researcher();
        $researcher->setIdLattes('9944' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT));
        $researcher->setFullName('Docente Teste Top Revistas do Tema');
        $researcher->setSlug('docente-teste-top-revistas-' . bin2hex(random_bytes(4)));
        $this->em->persist($researcher);
        $this->em->flush();

        return $researcher;
    }

    private function createItem(Researcher $researcher, string $type, string $title, ?string $journalName, ?QualisJournal $linkedTo, ?string $qualis, ?string $doi, int $year): ProductionItem
    {
        $item = new ProductionItem();
        $item->setItemType($type);
        $item->setTitle($title);
        $item->setYear($year);
        $item->setDoi($doi);
        $item->setJournalName($journalName);
        $item->setQualisJournal($linkedTo);
        $item->setQualis($qualis);
        $researcher->addProduction($item);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }
}
