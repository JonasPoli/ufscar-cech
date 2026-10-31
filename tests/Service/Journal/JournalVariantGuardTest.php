<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Entity\JournalVariation;
use App\Entity\QualisJournal;
use App\Service\Journal\JournalVariantConflictException;
use App\Service\Journal\JournalVariantGuard;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Trava de integridade das variantes de periódicos (docs/evolutions/periodicos-por-base.md, A5.3).
 *
 * Os dados são criados com sufixo único dentro de uma transação revertida no tearDown: nada fica gravado em
 * `cech_test`, que outros testes usam ao mesmo tempo.
 */
class JournalVariantGuardTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private JournalVariantGuard $guard;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->guard = $container->get(JournalVariantGuard::class);

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

    public function testFindConflictBetweenFamiliesButNotWithinTheSameFamily(): void
    {
        $t = $this->token();
        $alfa = $this->createJournal("REVISTA ALFA $t");
        $alfaPrint = $this->createJournal("REVISTA ALFA $t IMPRESSO", $alfa);
        $beta = $this->createJournal("REVISTA BETA $t");
        $this->createVariant($alfa, "Nome Alternativo $t");
        $alternative = self::norm("Nome Alternativo $t");

        // Variante da família A, vista de outra família
        $this->assertSame(
            ['kind' => 'variant', 'journalId' => $alfa->getId(), 'title' => "REVISTA ALFA $t"],
            $this->guard->findConflict($alternative, $beta->getId())
        );

        // Dentro da família (a canônica e o membro) não é conflito
        $this->assertNull($this->guard->findConflict($alternative, $alfa->getId()));
        $this->assertNull($this->guard->findConflict($alternative, $alfaPrint->getId()));

        // Título de outra família
        $this->assertSame(
            ['kind' => 'title', 'journalId' => $beta->getId(), 'title' => "REVISTA BETA $t"],
            $this->guard->findConflict(self::norm("REVISTA BETA $t"), $alfa->getId())
        );

        // Título de um membro da família A: conflito para B (com o id do membro), não para a canônica de A
        $this->assertSame(
            ['kind' => 'title', 'journalId' => $alfaPrint->getId(), 'title' => "REVISTA ALFA $t IMPRESSO"],
            $this->guard->findConflict(self::norm("REVISTA ALFA $t IMPRESSO"), $beta->getId())
        );
        $this->assertNull($this->guard->findConflict(self::norm("REVISTA ALFA $t IMPRESSO"), $alfa->getId()));

        // Periódico que ainda não existe: toda ocorrência é conflito
        $this->assertNotNull($this->guard->findConflict($alternative, null));

        // Nome livre, vazio ou sem letras
        $this->assertNull($this->guard->findConflict(self::norm("Nome Livre $t"), $beta->getId()));
        $this->assertNull($this->guard->findConflict('', $beta->getId()));
        $this->assertNull($this->guard->findConflict(' - ', $beta->getId()));
    }

    public function testFindConflictAcceptsRawSpellings(): void
    {
        $t = $this->token();
        $alfa = $this->createJournal("REVISTA ALFA $t");
        $beta = $this->createJournal("REVISTA BETA $t");
        $this->createVariant($alfa, "Cadernos de Estudos: Série $t");

        // Grafia bruta, com acento, pontuação e caixa diferentes: a função normaliza a entrada
        $conflict = $this->guard->findConflict("cadernos de ESTUDOS - serie $t", $beta->getId());
        $this->assertNotNull($conflict);
        $this->assertSame('variant', $conflict['kind']);
        $this->assertSame($alfa->getId(), $conflict['journalId']);
    }

    public function testAddVariantWritesOnTheCanonicalRowAndIgnoresNamesTheFamilyAlreadyKnows(): void
    {
        $t = $this->token();
        $alfa = $this->createJournal("REVISTA ALFA $t");
        $alfaPrint = $this->createJournal("REVISTA ALFA $t IMPRESSO", $alfa);

        // Pedida pelo membro, gravada na canônica
        $variation = $this->guard->addVariant($alfaPrint, "  Rev. Alfa $t ", 'lattes');
        $this->assertNotNull($variation);
        $this->em->flush();

        $this->assertSame($alfa->getId(), $variation->getJournal()?->getId());
        $row = $this->conn->fetchAssociative(
            'SELECT journal_id, variation_name, normalized_name, variation_type, status FROM journal_name_variants WHERE id = ?',
            [$variation->getId()]
        );
        $this->assertSame($alfa->getId(), (int)$row['journal_id']);
        $this->assertSame("Rev. Alfa $t", $row['variation_name']);
        $this->assertSame(self::norm("Rev. Alfa $t"), $row['normalized_name']);
        $this->assertSame('lattes', $row['variation_type']);
        $this->assertSame(1, (int)$row['status']);

        // Mesma família: outra grafia equivalente, pela canônica ou pelo membro, e os títulos das duas linhas
        $this->assertNull($this->guard->addVariant($alfa, "REV ALFA $t", 'curated'));
        $this->assertNull($this->guard->addVariant($alfaPrint, "rev. alfa $t", 'curated'));
        $this->assertNull($this->guard->addVariant($alfa, "Revista Alfa $t Impresso", 'lattes'));
        $this->assertNull($this->guard->addVariant($alfaPrint, "Revista Alfa $t", 'lattes'));

        // Nome sem letras nem números
        $this->assertNull($this->guard->addVariant($alfa, ' -- ', 'lattes'));

        $this->em->flush();
        $this->assertSame(1, $this->countVariantsOf([$alfa, $alfaPrint]));
    }

    public function testAddVariantRefusesNamesOfOtherFamilies(): void
    {
        $t = $this->token();
        $alfa = $this->createJournal("REVISTA ALFA $t");
        $beta = $this->createJournal("REVISTA BETA $t");
        $this->createVariant($alfa, "Nome do A $t");

        // Variante de outra família
        try {
            $this->guard->addVariant($beta, "NOME DO A $t", 'curated');
            $this->fail('Era esperado JournalVariantConflictException');
        } catch (JournalVariantConflictException $e) {
            $this->assertSame(['kind' => 'variant', 'journalId' => $alfa->getId(), 'title' => "REVISTA ALFA $t"], $e->getConflict());
            $this->assertSame("NOME DO A $t", $e->getName());
            $this->assertSame(
                sprintf('A variação «NOME DO A %s» já pertence ao periódico #%d «REVISTA ALFA %s». Remova-a daqui ou mescle os periódicos.', $t, $alfa->getId(), $t),
                $e->getMessage()
            );
        }

        // Título de outra família
        try {
            $this->guard->addVariant($alfa, "Revista Beta $t", 'lattes');
            $this->fail('Era esperado JournalVariantConflictException');
        } catch (JournalVariantConflictException $e) {
            $this->assertSame(['kind' => 'title', 'journalId' => $beta->getId(), 'title' => "REVISTA BETA $t"], $e->getConflict());
        }

        // Nada foi gravado nem ficou pendente
        $this->em->flush();
        $this->assertSame(0, $this->countVariantsOf([$beta]));
        $this->assertSame(1, $this->countVariantsOf([$alfa]));
    }

    public function testVariantsNotYetFlushedAreSeenByTheGuard(): void
    {
        $t = $this->token();
        $alfa = $this->createJournal("REVISTA ALFA $t");
        $alfaPrint = $this->createJournal("REVISTA ALFA $t IMPRESSO", $alfa);
        $beta = $this->createJournal("REVISTA BETA $t");

        $first = $this->guard->addVariant($alfa, "Pendente $t", 'lattes');
        $this->assertNotNull($first);

        // Sem flush no meio: a mesma família não duplica e outra família é recusada
        $this->assertNull($this->guard->addVariant($alfaPrint, "PENDENTE $t", 'lattes'));
        $this->assertNotNull($this->guard->findConflict(self::norm("Pendente $t"), $beta->getId()));
        $this->assertNull($this->guard->findConflict(self::norm("Pendente $t"), $alfa->getId()));
        try {
            $this->guard->addVariant($beta, "Pendente $t", 'curated');
            $this->fail('Era esperado JournalVariantConflictException');
        } catch (JournalVariantConflictException $e) {
            $this->assertSame('variant', $e->getConflict()['kind']);
            $this->assertSame($alfa->getId(), $e->getConflict()['journalId']);
        }

        // Um único INSERT, sem violar o índice único
        $this->em->flush();
        $this->assertSame(1, $this->countVariantsOf([$alfa]));
    }

    public function testDeactivatedVariantStillBelongsToItsFamilyAndIsNotRevived(): void
    {
        $t = $this->token();
        $alfa = $this->createJournal("REVISTA ALFA $t");
        $beta = $this->createJournal("REVISTA BETA $t");
        $this->createVariant($beta, "Desativada $t", false);

        // O índice único vale para variantes desativadas: outra família não pode usar o nome
        $this->assertNotNull($this->guard->findConflict(self::norm("Desativada $t"), $alfa->getId()));
        try {
            $this->guard->addVariant($alfa, "Desativada $t", 'lattes');
            $this->fail('Era esperado JournalVariantConflictException');
        } catch (JournalVariantConflictException $e) {
            $this->assertSame($beta->getId(), $e->getConflict()['journalId']);
        }

        // A própria família a conhece: não cria outra nem a reativa
        $this->assertNull($this->guard->addVariant($beta, "Desativada $t", 'manual'));
        $this->em->flush();
        $this->assertSame(1, $this->countVariantsOf([$beta]));
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT status FROM journal_name_variants WHERE journal_id = ?', [$beta->getId()]));
    }

    // ------------------------------------------------------------------------------------------------

    /**
     * @dataProvider notANameProvider
     */
    public function testTextThatIsCodeNotANameIsRefused(string $text): void
    {
        $journal = new \App\Entity\QualisJournal();
        $journal->setTitle('Revista Teste Trava ' . bin2hex(random_bytes(4)));
        $this->em->persist($journal);
        $this->em->flush();

        $this->assertNull($this->guard->addVariant($journal, $text, 'alternative'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function notANameProvider(): iterable
    {
        yield 'HTML' => ['HTTP 500 <small>Internal Server Error</small>'];
        yield 'CSS' => ['--highlight-default: #222222;'];
        yield 'seletor CSS' => ['.filter-list li.active {'];
        yield 'pilha de erro' => ['#13 /home/runcloud/webapps/app-bibliomap/vendor/symfony/error-handler/ErrorHandler.php'];
    }

    public function testRealNamesWithPunctuationAreAccepted(): void
    {
        $journal = new \App\Entity\QualisJournal();
        $journal->setTitle('Revista Teste Trava ' . bin2hex(random_bytes(4)));
        $this->em->persist($journal);
        $this->em->flush();

        foreach (['Cadernos & Debates: Educação (Online), n. %s', '#PerDebate %s', 'Stimmen der Zeit %s <Freiburg, Breisgau>', 'Revue de médecine navale %s (outre-mer);'] as $pattern) {
            $name = sprintf($pattern, bin2hex(random_bytes(4)));
            $this->assertNotNull($this->guard->addVariant($journal, $name, 'alternative'), $name);
        }
    }

    private function token(): string
    {
        return 'Zg' . bin2hex(random_bytes(5));
    }

    private static function norm(string $name): string
    {
        return StringNormalizer::normalizeString($name, true);
    }

    private function createJournal(string $title, ?QualisJournal $canonical = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        if ($canonical !== null) {
            $journal->setCanonical($canonical);
            $journal->setCanonicalMethod('manual');
        }
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    /** Cria a variante direto pela entidade, sem passar pela trava, para montar o cenário. */
    private function createVariant(QualisJournal $journal, string $name, bool $active = true): JournalVariation
    {
        $variation = new JournalVariation();
        $variation->setJournal($journal);
        $variation->setVariationName($name);
        $variation->setNormalizedName(self::norm($name));
        $variation->setVariationType('alternative');
        $variation->setStatus($active);
        $this->em->persist($variation);
        $this->em->flush();

        return $variation;
    }

    /** @param list<QualisJournal> $journals */
    private function countVariantsOf(array $journals): int
    {
        $ids = array_map(static fn (QualisJournal $j): int => (int)$j->getId(), $journals);

        return (int)$this->conn->fetchOne(
            'SELECT COUNT(*) FROM journal_name_variants WHERE journal_id IN (' . implode(', ', $ids) . ')'
        );
    }
}
