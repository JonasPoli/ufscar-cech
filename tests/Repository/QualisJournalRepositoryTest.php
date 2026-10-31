<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\JournalVariation;
use App\Entity\QualisJournal;
use App\Repository\QualisJournalRepository;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Busca de famílias de periódicos pelo nome (`findFamilyRowIdsByName`).
 *
 * Os dados usam sufixo único (só letras, porque o termo precisa de 3 letras) e ficam dentro de uma transação revertida
 * no tearDown: nada é gravado em `cech_test`, que outros testes usam ao mesmo tempo.
 */
class QualisJournalRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private QualisJournalRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->repo = $this->em->getRepository(QualisJournal::class);

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

    public function testFindsTheFamilyByTitleDisplayTitleAndActiveVariantButNotByAnInactiveOne(): void
    {
        $t = $this->token();
        $alfa = $this->createJournal("REVISTA ALFA $t", displayTitle: "Estudos de Alfa $t");
        $alfaOnline = $this->createJournal("ALFA ONLINE $t", canonical: $alfa);
        $beta = $this->createJournal("BOLETIM BETA $t");
        $this->createVariant($beta, "Bol. Beta $t");
        $this->createVariant($beta, "Bol. Inativo $t", false);

        // Título da linha canônica
        $this->assertSame([$alfa->getId(), $alfaOnline->getId()], $this->repo->findFamilyRowIdsByName("Revista Alfa $t"));
        // Nome de exibição (não está em nenhum título nem variante da família)
        $this->assertSame([$alfa->getId(), $alfaOnline->getId()], $this->repo->findFamilyRowIdsByName("estudos de alfa $t"));
        // Variante ativa
        $this->assertSame([$beta->getId()], $this->repo->findFamilyRowIdsByName("Bol. Beta $t"));
        // Variante inativa não conta
        $this->assertSame([], $this->repo->findFamilyRowIdsByName("Bol. Inativo $t"));
        // Nada com esse nome
        $this->assertSame([], $this->repo->findFamilyRowIdsByName("Revista Inexistente $t"));
    }

    public function testComparesInTheThesaurusFormIgnoringCaseAccentsAndPunctuation(): void
    {
        $t = $this->token();
        $journal = $this->createJournal("REVISTA AÇÃO & REFLEXÃO $t");

        foreach (["Revista Ação & Reflexão $t", "revista acao reflexao $t", "ACAO   REFLEXAO $t", "Revista: Ação & Reflexão — $t", "reflexão $t"] as $term) {
            $this->assertSame([$journal->getId()], $this->repo->findFamilyRowIdsByName($term), $term);
        }

        // O «&» some na forma do tesauro, e «e» não é «&»: a busca é por trecho do nome, não por sinônimo
        $this->assertSame([], $this->repo->findFamilyRowIdsByName("ação e reflexão $t"));
    }

    public function testAMatchOnAMemberRowReturnsTheWholeFamilyWithTheCanonicalRowFirst(): void
    {
        $t = $this->token();
        // A linha-membro é criada antes da canônica (id menor): a canônica ainda assim vem primeiro
        $member = $this->createJournal("CADERNOS MEMBRO $t");
        $other = $this->createJournal("CADERNOS OUTRO MEMBRO $t");
        $canonical = $this->createJournal("CADERNOS CANONICO $t");
        $member->setCanonical($canonical);
        $other->setCanonical($canonical);
        $this->em->flush();
        $this->assertLessThan($canonical->getId(), $member->getId());

        $this->assertSame(
            [$canonical->getId(), $member->getId(), $other->getId()],
            $this->repo->findFamilyRowIdsByName("Cadernos Membro $t")
        );
    }

    public function testTermsWithFewerThanThreeLettersReturnNothing(): void
    {
        $t = $this->token();
        $this->createJournal("AB $t");

        $this->assertSame([], $this->repo->findFamilyRowIdsByName(''));
        $this->assertSame([], $this->repo->findFamilyRowIdsByName('ab'));
        $this->assertSame([], $this->repo->findFamilyRowIdsByName(' a b '));
        $this->assertSame([], $this->repo->findFamilyRowIdsByName('12 34'), 'Algarismos não contam como letras');
        $this->assertSame([], $this->repo->findFamilyRowIdsByName('%_%'));
        $this->assertSame([], $this->repo->findFamilyRowIdsByName("Revista $t", 0));
    }

    public function testFamiliesComeByRelevanceAndTheLimitCutsFamiliesNotRows(): void
    {
        $t = $this->token();
        // Inserção fora da ordem de relevância: o id não manda
        $contains = $this->createJournal("REVISTA DO $t");
        $prefix = $this->createJournal("$t REVISTA");
        $prefixMember = $this->createJournal("$t REVISTA ONLINE", canonical: $prefix);
        $exact = $this->createJournal($t);

        $this->assertSame(
            [$exact->getId(), $prefix->getId(), $prefixMember->getId(), $contains->getId()],
            $this->repo->findFamilyRowIdsByName($t)
        );

        // Limite de 2 famílias: a igual e a que começa pelo termo, esta com as duas linhas
        $this->assertSame(
            [$exact->getId(), $prefix->getId(), $prefixMember->getId()],
            $this->repo->findFamilyRowIdsByName($t, 2)
        );
        $this->assertSame([$exact->getId()], $this->repo->findFamilyRowIdsByName($t, 1));
    }

    public function testAFamilyFoundTwiceIsReturnedOnce(): void
    {
        $t = $this->token();
        $canonical = $this->createJournal("REVISTA DUPLA $t", displayTitle: "Revista Dupla $t");
        $member = $this->createJournal("DUPLA MEMBRO $t", canonical: $canonical);
        $this->createVariant($canonical, "Rev. Dupla $t");
        $this->createVariant($member, "Rev. Dupla Membro $t");

        $this->assertSame([$canonical->getId(), $member->getId()], $this->repo->findFamilyRowIdsByName("Dupla $t"));
    }

    // ------------------------------------------------------------------------------------------------

    /** Só letras: o termo da busca precisa de 3 letras e o sufixo sozinho já as garante. */
    private function token(): string
    {
        return 'zq' . strtr(bin2hex(random_bytes(5)), '0123456789', 'ghijklmnop');
    }

    private function createJournal(string $title, ?QualisJournal $canonical = null, ?string $displayTitle = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        $journal->setDisplayTitle($displayTitle ?? $title);
        if ($canonical !== null) {
            $journal->setCanonical($canonical);
            $journal->setCanonicalMethod('manual');
        }
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    private function createVariant(QualisJournal $journal, string $name, bool $active = true): void
    {
        $variation = new JournalVariation();
        $variation->setJournal($journal);
        $variation->setVariationName($name);
        $variation->setNormalizedName(StringNormalizer::normalizeString($name, true));
        $variation->setVariationType('alternative');
        $variation->setStatus($active);
        $this->em->persist($variation);
        $this->em->flush();
    }
}
