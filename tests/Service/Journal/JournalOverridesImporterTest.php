<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Entity\JournalNameCandidate;
use App\Entity\JournalVariation;
use App\Entity\QualisJournal;
use App\Service\Journal\Issn;
use App\Service\Journal\JournalOverridesImporter;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Overrides curados de periódicos (docs/evolutions/periodicos-por-base.md, A6.8): os quatro tipos, os pulos e a repetição.
 *
 * Os dados são criados com sufixo único (ISSNs e títulos aleatórios) dentro de uma transação revertida no tearDown. Os
 * savepoints deixam a simulação (--dry-run) reverter só o que ela gravou, mesmo dentro dessa transação.
 */
class JournalOverridesImporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private JournalOverridesImporter $importer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->importer = $container->get(JournalOverridesImporter::class);

        $this->conn->setNestTransactionsWithSavepoints(true);
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

    public function testAreaOverrideWritesManualAreasAndLocksTheFamily(): void
    {
        $t = $this->token();
        $n8 = $this->issn();
        $journal = $this->createJournal("REVISTA AREA $t", $n8);

        $report = $this->importOverrides([
            ['type' => 'area', 'journal' => ['issn' => Issn::format($n8)], 'primary' => 'educacao', 'secondary' => ['sociologia'], 'lock' => true],
        ]);

        $this->assertSame(1, $report['applied'], $this->describe($report));
        $areas = $this->conn->fetchAllAssociative(
            'SELECT a.slug, ja.is_primary, ja.method, ja.confidence
               FROM journal_areas ja JOIN capes_areas a ON a.id = ja.area_id
              WHERE ja.journal_id = ? ORDER BY a.slug',
            [$journal->getId()]
        );
        $this->assertSame([
            ['slug' => 'educacao', 'is_primary' => 1, 'method' => 'manual', 'confidence' => 'manual'],
            ['slug' => 'sociologia', 'is_primary' => 0, 'method' => 'manual', 'confidence' => 'manual'],
        ], $this->normalizeAreas($areas));
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$journal->getId()]));
    }

    public function testNameOverrideCreatesCuratedVariantAcceptsCandidateAndRepeatsSafely(): void
    {
        $t = $this->token();
        $title = "REVISTA NOME $t";
        $journal = $this->createJournal($title);
        $name = "Nome Curado $t";
        $candidate = $this->createCandidate($name);

        $line = ['type' => 'name', 'name' => $name, 'journal' => ['title' => $title]];
        $first = $this->importOverrides([$line]);
        $second = $this->importOverrides([$line]);

        $this->assertSame(1, $first['applied'], $this->describe($first));
        $this->assertSame(1, $second['applied'], $this->describe($second));
        $this->assertStringContainsString('variante curada criada', $first['lines'][0]['message']);
        $this->assertStringContainsString('já conhecia', $second['lines'][0]['message']);

        $variants = $this->conn->fetchAllAssociative(
            'SELECT journal_id, variation_type FROM journal_name_variants WHERE normalized_name = ?',
            [StringNormalizer::normalizeString($name, true)]
        );
        $this->assertSame([['journal_id' => $journal->getId(), 'variation_type' => 'curated']], $this->normalizeVariants($variants));

        $row = $this->conn->fetchAssociative(
            'SELECT status, resolved_journal_id, reviewed_by FROM journal_name_candidates WHERE id = ?',
            [$candidate->getId()]
        );
        $this->assertSame(JournalNameCandidate::STATUS_ACCEPTED, $row['status']);
        $this->assertSame($journal->getId(), (int) $row['resolved_journal_id']);
        $this->assertSame('overrides:import', $row['reviewed_by']);
    }

    public function testNameRejectOverrideClosesTheCandidateAndRepeatsSafely(): void
    {
        $name = 'Nome Sem Revista ' . $this->token();
        $candidate = $this->createCandidate($name);
        $line = ['type' => 'name_reject', 'name' => $name];

        $first = $this->importOverrides([$line]);
        $second = $this->importOverrides([$line]);
        $this->em->clear();
        $stored = $this->em->getRepository(JournalNameCandidate::class)->find($candidate->getId());

        $this->assertSame(1, $first['applied'], $this->describe($first));
        $this->assertSame(1, $second['applied'], $this->describe($second));
        $this->assertSame(JournalNameCandidate::STATUS_REJECTED, $stored->getStatus());
        $this->assertNotNull($stored->getReviewedAt());
    }

    public function testHowTheNameWasDecidedIsRecorded(): void
    {
        $t = $this->token();
        $title = "REVISTA MOTIVO $t";
        $this->createJournal($title);
        $accepted = $this->createCandidate("Nome Aceito Com Motivo $t");
        $rejected = $this->createCandidate("Nome Rejeitado Com Motivo $t");

        $report = $this->importOverrides([
            ['type' => 'name', 'name' => "Nome Aceito Com Motivo $t", 'journal' => ['title' => $title], 'how' => 'Regra: ISSN confirma e título parecido'],
            ['type' => 'name_reject', 'name' => "Nome Rejeitado Com Motivo $t", 'how' => 'Regra: DOI ou URL no lugar do nome'],
        ]);
        $this->em->clear();
        $repository = $this->em->getRepository(JournalNameCandidate::class);

        $this->assertSame(2, $report['applied'], $this->describe($report));
        $this->assertSame('Regra: ISSN confirma e título parecido', $repository->find($accepted->getId())->getReviewedBy());
        $this->assertSame('Regra: DOI ou URL no lugar do nome', $repository->find($rejected->getId())->getReviewedBy());
    }

    public function testNameRejectOverrideKeepsAcceptedNamesAndSkipsUnknownOnes(): void
    {
        $name = 'Nome Aceito ' . $this->token();
        $candidate = $this->createCandidate($name);
        $candidate->setStatus(JournalNameCandidate::STATUS_ACCEPTED);
        $this->em->flush();

        $report = $this->importOverrides([
            ['type' => 'name_reject', 'name' => $name],
            ['type' => 'name_reject', 'name' => 'Nome Fora Da Fila ' . $this->token()],
        ]);
        $this->em->clear();

        $this->assertSame(0, $report['applied'], $this->describe($report));
        $this->assertSame(2, $report['skipped'], $this->describe($report));
        $this->assertSame(
            JournalNameCandidate::STATUS_ACCEPTED,
            $this->em->getRepository(JournalNameCandidate::class)->find($candidate->getId())->getStatus()
        );
    }

    public function testNameOverrideIsSkippedWhenTheNameBelongsToAnotherFamily(): void
    {
        $t = $this->token();
        $other = $this->createJournal("REVISTA OUTRA $t");
        $name = "Grafia Disputada $t";
        $this->createVariant($other, $name);
        $journal = $this->createJournal("REVISTA DONA $t");

        $report = $this->importOverrides([
            ['type' => 'name', 'name' => $name, 'journal' => ['title' => "REVISTA DONA $t"]],
        ]);

        $this->assertSame(0, $report['applied']);
        $this->assertSame(1, $report['skipped']);
        $this->assertStringContainsString('outra família', $report['lines'][0]['message']);
        $this->assertSame(0, (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM journal_name_variants WHERE journal_id = ? AND variation_type = 'curated'",
            [$journal->getId()]
        ));
    }

    public function testFamilyOverrideMergesFamiliesAndMovesMembersOfTheFormerCanonicals(): void
    {
        $t = $this->token();
        $nA = $this->issn();
        $nB = $this->issn();
        $a = $this->createJournal("REVISTA A $t", $nA, null, null, 'Nome Canônico', 'scielo');
        $b = $this->createJournal("REVISTA B $t", $nB);
        $member = $this->createJournal("REVISTA M $t", null, $b, 'issn');
        $this->setDisplayTitle($member->getId(), 'Nome Manual', 'manual');

        $line = [
            'type' => 'family',
            'issns' => [Issn::format($nA), Issn::format($nB)],
            'canonical_issn' => Issn::format($nA),
        ];
        $first = $this->importOverrides([$line]);
        $this->assertSame(1, $first['applied'], $this->describe($first));

        $rows = $this->rowsById([$a->getId(), $b->getId(), $member->getId()]);
        // A vira canônica manual (era uma família própria); B passa a apontar para A e fica manual
        $this->assertNull($rows[$a->getId()]['canonical']);
        $this->assertSame('manual', $rows[$a->getId()]['method']);
        $this->assertSame($a->getId(), $rows[$b->getId()]['canonical']);
        $this->assertSame('manual', $rows[$b->getId()]['method']);
        // M era membro de B, que deixou de ser canônica: passa para A, mantendo o método dela
        $this->assertSame($a->getId(), $rows[$member->getId()]['canonical']);
        $this->assertSame('issn', $rows[$member->getId()]['method']);
        // Nome exibido copiado da canônica, sem tocar no nome manual
        $this->assertSame('Nome Canônico', $rows[$b->getId()]['displayTitle']);
        $this->assertSame('scielo', $rows[$b->getId()]['displayTitleSource']);
        $this->assertSame('Nome Manual', $rows[$member->getId()]['displayTitle']);
        $this->assertSame('manual', $rows[$member->getId()]['displayTitleSource']);

        // Repetir não muda nada
        $second = $this->importOverrides([$line]);
        $this->assertSame(1, $second['applied'], $this->describe($second));
        $this->assertSame($rows, $this->rowsById([$a->getId(), $b->getId(), $member->getId()]));
    }

    public function testFamilyOverridePromotesACanonicalAndLeavesOtherFamiliesAlone(): void
    {
        $t = $this->token();
        $nF = $this->issn();
        $nC = $this->issn();
        $nX = $this->issn();
        $familyCanonical = $this->createJournal("REVISTA F $t", $nF);
        $familyMember = $this->createJournal("REVISTA G $t", null, $familyCanonical, 'issn');
        $c = $this->createJournal("REVISTA C $t", $nC, $familyCanonical, 'issn');
        $x = $this->createJournal("REVISTA X $t", $nX);

        $report = $this->importOverrides([
            ['type' => 'family', 'issns' => [Issn::format($nC), Issn::format($nX)], 'canonical_issn' => Issn::format($nC)],
        ]);

        $this->assertSame(1, $report['applied'], $this->describe($report));
        $rows = $this->rowsById([$familyCanonical->getId(), $familyMember->getId(), $c->getId(), $x->getId()]);
        // C era membro de F: é promovida a canônica da nova família, e X entra nela
        $this->assertNull($rows[$c->getId()]['canonical']);
        $this->assertSame('manual', $rows[$c->getId()]['method']);
        $this->assertSame($c->getId(), $rows[$x->getId()]['canonical']);
        $this->assertSame('manual', $rows[$x->getId()]['method']);
        // F e o membro dela não têm ISSN na lista: não mudam de família
        $this->assertNull($rows[$familyCanonical->getId()]['canonical']);
        $this->assertSame($familyCanonical->getId(), $rows[$familyMember->getId()]['canonical']);
        $this->assertSame('issn', $rows[$familyMember->getId()]['method']);
    }

    public function testDisplayTitleOverrideWritesTheManualSourceToTheWholeFamily(): void
    {
        $t = $this->token();
        $n8 = $this->issn();
        $canonical = $this->createJournal("REVISTA DT $t", $n8, null, null, 'Antigo', 'scielo');
        $member = $this->createJournal("REVISTA DTM $t", null, $canonical, 'issn');

        $report = $this->importOverrides([
            ['type' => 'display_title', 'journal' => ['issn' => Issn::format($n8)], 'title' => 'Nome Manual Novo'],
        ]);

        $this->assertSame(1, $report['applied'], $this->describe($report));
        $rows = $this->rowsById([$canonical->getId(), $member->getId()]);
        foreach ($rows as $row) {
            $this->assertSame('Nome Manual Novo', $row['displayTitle']);
            $this->assertSame('manual', $row['displayTitleSource']);
        }
    }

    public function testLinesThatCannotBeAppliedAreSkippedWithoutWriting(): void
    {
        $t = $this->token();
        $n8 = $this->issn();
        $journal = $this->createJournal("REVISTA PULA $t", $this->issn());
        $shared = $this->issn();
        $this->createJournal("REVISTA PARES 1 $t", $shared);
        $this->createJournal("REVISTA PARES 2 $t", $shared);

        $report = $this->importLines([
            json_encode(['type' => 'area', 'journal' => ['issn' => Issn::format($n8)], 'primary' => 'educacao'], JSON_THROW_ON_ERROR),
            json_encode(['type' => 'display_title', 'journal' => ['issn' => Issn::format($shared)], 'title' => 'Ambíguo'], JSON_THROW_ON_ERROR),
            json_encode(['type' => 'area', 'journal' => ['title' => "REVISTA PULA $t"], 'primary' => 'area-inexistente'], JSON_THROW_ON_ERROR),
            '{"type": "name", "name": ',
            json_encode(['type' => 'tipo-que-nao-existe'], JSON_THROW_ON_ERROR),
        ]);

        $this->assertSame(0, $report['applied']);
        $this->assertSame(5, $report['skipped']);
        $messages = array_column($report['lines'], 'message');
        $this->assertStringContainsString('não encontrado', $messages[0]);
        $this->assertStringContainsString('2 famílias', $messages[1]);
        $this->assertStringContainsString('área principal desconhecida', $messages[2]);
        $this->assertStringContainsString('JSON inválido', $messages[3]);
        $this->assertStringContainsString('tipo desconhecido', $messages[4]);
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM journal_areas WHERE journal_id = ?', [$journal->getId()]));
    }

    public function testDryRunRollsBackEverythingItApplied(): void
    {
        $t = $this->token();
        $n8 = $this->issn();
        $journal = $this->createJournal("REVISTA SIMULA $t", $n8);

        $report = $this->importLines([
            json_encode(['type' => 'display_title', 'journal' => ['issn' => Issn::format($n8)], 'title' => 'Não gravado'], JSON_THROW_ON_ERROR),
        ], true);

        $this->assertSame(1, $report['applied'], $this->describe($report));
        $this->assertNull($this->conn->fetchOne('SELECT display_title FROM qualis_journals WHERE id = ?', [$journal->getId()]));
    }

    private function token(): string
    {
        return 'Zg' . bin2hex(random_bytes(5));
    }

    /**
     * ISSN aleatório na forma normalizada (8 caracteres) com dígito verificador válido.
     */
    private function issn(): string
    {
        $digits = (string) random_int(1000000, 9999999);
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $digits[$i] * (8 - $i);
        }
        $check = (11 - ($sum % 11)) % 11;

        return $digits . ($check === 10 ? 'X' : (string) $check);
    }

    private function createJournal(
        string $title,
        ?string $n8 = null,
        ?QualisJournal $canonical = null,
        ?string $method = null,
        ?string $displayTitle = null,
        ?string $displaySource = null,
    ): QualisJournal {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        if ($n8 !== null) {
            $journal->setIssn(Issn::format($n8));
        }
        if ($canonical !== null) {
            $journal->setCanonical($canonical);
        }
        $journal->setCanonicalMethod($method);
        $journal->setDisplayTitle($displayTitle);
        $journal->setDisplayTitleSource($displaySource);
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    /** Variante direto pela entidade, sem passar pelo importador, para montar o cenário. */
    private function createVariant(QualisJournal $journal, string $name): void
    {
        $variation = new JournalVariation();
        $variation->setJournal($journal);
        $variation->setVariationName($name);
        $variation->setNormalizedName(StringNormalizer::normalizeString($name, true));
        $variation->setVariationType('alternative');
        $variation->setStatus(true);
        $this->em->persist($variation);
        $this->em->flush();
    }

    private function createCandidate(string $name): JournalNameCandidate
    {
        $candidate = new JournalNameCandidate();
        $candidate->setNormalizedName(StringNormalizer::normalizeString($name, true));
        $candidate->setRawName($name);
        $candidate->setFirstSeenAt(new \DateTimeImmutable());
        $candidate->setLastSeenAt(new \DateTimeImmutable());
        $this->em->persist($candidate);
        $this->em->flush();

        return $candidate;
    }

    private function setDisplayTitle(int $id, string $title, string $source): void
    {
        $this->conn->executeStatement(
            'UPDATE qualis_journals SET display_title = ?, display_title_source = ? WHERE id = ?',
            [$title, $source, $id]
        );
    }

    /**
     * @param list<array<string, mixed>> $overrides
     *
     * @return array{lines: list<array{line: int, type: string, status: string, message: string}>, applied: int, skipped: int}
     */
    private function importOverrides(array $overrides, bool $dryRun = false): array
    {
        return $this->importLines(
            array_map(static fn (array $override): string => json_encode($override, JSON_THROW_ON_ERROR), $overrides),
            $dryRun
        );
    }

    /**
     * @param list<string> $lines
     *
     * @return array{lines: list<array{line: int, type: string, status: string, message: string}>, applied: int, skipped: int}
     */
    private function importLines(array $lines, bool $dryRun = false): array
    {
        return $this->importer->importLines($lines, $dryRun);
    }

    /**
     * @param array{lines: list<array{line: int, type: string, status: string, message: string}>, applied: int, skipped: int} $report
     */
    private function describe(array $report): string
    {
        return json_encode($report['lines'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<int|string> $ids
     *
     * @return array<int, array{canonical: ?int, method: ?string, displayTitle: ?string, displayTitleSource: ?string}>
     */
    private function rowsById(array $ids): array
    {
        $rows = [];
        $fetched = $this->conn->fetchAllAssociative(
            'SELECT id, canonical_journal_id, canonical_method, display_title, display_title_source
               FROM qualis_journals WHERE id IN (?)',
            [array_map('intval', $ids)],
            [ArrayParameterType::INTEGER]
        );
        foreach ($fetched as $row) {
            $rows[(int) $row['id']] = [
                'canonical' => $row['canonical_journal_id'] !== null ? (int) $row['canonical_journal_id'] : null,
                'method' => $row['canonical_method'] !== null ? (string) $row['canonical_method'] : null,
                'displayTitle' => $row['display_title'] !== null ? (string) $row['display_title'] : null,
                'displayTitleSource' => $row['display_title_source'] !== null ? (string) $row['display_title_source'] : null,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $areas
     *
     * @return list<array{slug: string, is_primary: int, method: string, confidence: string}>
     */
    private function normalizeAreas(array $areas): array
    {
        return array_map(static fn (array $row): array => [
            'slug' => (string) $row['slug'],
            'is_primary' => (int) $row['is_primary'],
            'method' => (string) $row['method'],
            'confidence' => (string) $row['confidence'],
        ], $areas);
    }

    /**
     * @param list<array<string, mixed>> $variants
     *
     * @return list<array{journal_id: int, variation_type: string}>
     */
    private function normalizeVariants(array $variants): array
    {
        return array_map(static fn (array $row): array => [
            'journal_id' => (int) $row['journal_id'],
            'variation_type' => (string) $row['variation_type'],
        ], $variants);
    }
}
