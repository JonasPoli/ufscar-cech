<?php

declare(strict_types=1);

namespace App\Tests\Service\Thesaurus;

use App\Entity\AcademicDatabase;
use App\Entity\JournalVariation;
use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Service\Thesaurus\EntityMergeService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Merge físico de periódicos (docs/evolutions/periodicos-por-base.md, A5.3): apagar a linha de um periódico não pode
 * levar junto artigos, famílias, variantes, evidências nem bases.
 *
 * Os dados usam sufixo único e saem no tearDown (cech_test é compartilhado com outros testes e agentes). Gravam de
 * verdade (sem transação de teste) para que o commit e o rollback da mesclagem sejam os reais.
 */
class EntityMergeServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private EntityMergeService $merge;
    private string $uniq;

    /** @var int[] */
    private array $researcherIds = [];
    /** @var string[] */
    private array $databaseAcronyms = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->merge = $container->get(EntityMergeService::class);
        $this->uniq = 'qz' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            $this->conn->executeStatement('DELETE FROM journal_name_candidates WHERE normalized_name LIKE ?', ['%' . strtoupper($this->uniq) . '%']);
            foreach ($this->researcherIds as $id) {
                // Os artigos de teste saem junto com o docente (ON DELETE CASCADE)
                $this->conn->executeStatement('DELETE FROM researchers WHERE id = ?', [$id]);
            }
            // Áreas, evidências, variantes e bases saem em cascata; as famílias se desfazem por SET NULL
            $this->conn->executeStatement('DELETE FROM qualis_journals WHERE title LIKE ?', ['%' . $this->uniq . '%']);
            foreach ($this->databaseAcronyms as $acronym) {
                $this->conn->executeStatement('DELETE FROM academic_database WHERE acronym = ?', [$acronym]);
            }
        }
        if (isset($this->em)) {
            $this->em->clear();
        }

        parent::tearDown();
    }

    public function testMergeMovesArticlesVariantsEvidenceAndBasesAndKeepsTheFamilyOfTheSource(): void
    {
        $a = $this->createJournal("Revista Fonte {$this->uniq}");
        $memberOfA = $this->createJournal("Revista Fonte Online {$this->uniq}", $a);
        $b = $this->createJournal("Revista Mestre {$this->uniq}");
        $aId = $a->getId();
        $bId = $b->getId();
        $memberId = $memberOfA->getId();

        $articleA1 = $this->createArticle($a, 'Artigo A1');
        $articleA2 = $this->createArticle($a, 'Artigo A2');
        $articleMember = $this->createArticle($memberOfA, 'Artigo do membro');
        $articleB = $this->createArticle($b, 'Artigo B');

        $this->createVariant($a, "Fonte Alt Um {$this->uniq}");
        $this->createVariant($a, "Fonte Alt Dois {$this->uniq}");
        $this->createVariant($b, "Mestre Alt {$this->uniq}");

        // Evidências: (wos, W1, Law) existe nos dois (duplicata) e a de A em Scopus só em A
        $this->insertEvidence($aId, 'wos', 'W1', 'Law');
        $this->insertEvidence($aId, 'scopus', 'S1', '3304');
        $this->insertEvidence($bId, 'wos', 'W1', 'Law');
        $this->insertEvidence($bId, 'doaj', 'D1', 'Education');

        // Áreas: as da fonte somem e as do master ficam
        $this->insertArea($aId, 404, 1);
        $this->insertArea($bId, 405, 1);

        // Bases: A está na X, B na Y: o master passa a ter as duas
        $dbX = $this->createDatabase('x');
        $dbY = $this->createDatabase('y');
        $this->linkBase($aId, $dbX);
        $this->linkBase($bId, $dbY);

        // Fila de nomes: sugestão e resolução apontavam para a fonte
        $this->createCandidate('suggested', $aId, null);
        $this->createCandidate('resolved', null, $aId);

        $master = $this->merge->mergeJournals($bId, [$aId]);

        $this->assertSame($bId, $master->getId());
        $this->assertSame(0, $this->scalarCount('SELECT COUNT(*) FROM qualis_journals WHERE id = ?', [$aId]), 'A fonte foi removida');

        // Artigos: os da fonte vão para o master; os de outras linhas ficam onde estavam
        foreach ([$articleA1, $articleA2, $articleB] as $articleId) {
            $this->assertSame($bId, $this->articleJournal($articleId));
        }
        $this->assertSame($memberId, $this->articleJournal($articleMember));

        // Família da fonte: o membro continua existindo e passa a pertencer à família do master
        $member = $this->conn->fetchAssociative('SELECT canonical_journal_id, canonical_method FROM qualis_journals WHERE id = ?', [$memberId]);
        $this->assertNotFalse($member, 'O membro da família da fonte não pode se perder');
        $this->assertSame($bId, (int)$member['canonical_journal_id']);
        $this->assertSame('manual', $member['canonical_method'], 'A decisão da mesclagem não pode ser desfeita pela reconstrução de famílias');

        // Variantes: reaproveitadas (mesmos registros, agora do master), sem duplicata, e o título da fonte virou variante
        $variants = $this->conn->fetchAllKeyValue('SELECT normalized_name, journal_id FROM journal_name_variants WHERE normalized_name LIKE ?', ['%' . strtoupper($this->uniq) . '%']);
        $this->assertSame([$bId], array_values(array_unique(array_map('intval', $variants))), 'Todas as variantes ficaram no master');
        $this->assertCount(4, $variants, 'Duas do A, uma do B e o título do A');
        $this->assertArrayHasKey(self::norm("Revista Fonte {$this->uniq}"), $variants);
        $this->assertSame('alternative', $this->conn->fetchOne('SELECT variation_type FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Revista Fonte {$this->uniq}")]));
        $this->assertSame(
            1,
            $this->scalarCount('SELECT COUNT(*) FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Fonte Alt Um {$this->uniq}")])
        );

        // Evidências: sem a duplicata do master, com a de A que o master não tinha
        $evidence = $this->conn->fetchAllAssociative('SELECT source, record_key, label FROM journal_area_evidence WHERE journal_id = ? ORDER BY source', [$bId]);
        $this->assertSame(['doaj', 'scopus', 'wos'], array_column($evidence, 'source'));

        // Áreas do master intactas
        $this->assertSame([405], array_map('intval', $this->conn->fetchFirstColumn('SELECT area_id FROM journal_areas WHERE journal_id = ?', [$bId])));

        // Bases somadas
        $this->assertSame(
            [$dbX, $dbY],
            array_map('intval', $this->conn->fetchFirstColumn('SELECT academic_database_id FROM qualis_journal_academic_database WHERE qualis_journal_id = ? ORDER BY academic_database_id', [$bId]))
        );

        // Fila de nomes: aponta para o master em vez de perder o vínculo
        $this->assertSame($bId, (int)$this->conn->fetchOne('SELECT suggested_journal_id FROM journal_name_candidates WHERE normalized_name = ?', [$this->candidateName('suggested')]));
        $this->assertSame($bId, (int)$this->conn->fetchOne('SELECT resolved_journal_id FROM journal_name_candidates WHERE normalized_name = ?', [$this->candidateName('resolved')]));
    }

    public function testSourceTitleThatBelongsToAnotherJournalDoesNotBecomeAVariant(): void
    {
        $shared = "Titulo Compartilhado {$this->uniq}";
        $a = $this->createJournal($shared);
        $b = $this->createJournal("Revista Mestre {$this->uniq}");
        $third = $this->createJournal("Revista Terceira {$this->uniq}");
        // O título de A já é variante de outro periódico: continua sendo dele
        $this->createVariant($third, $shared);
        $this->createVariant($a, "Fonte Alt {$this->uniq}");

        $this->merge->mergeJournals($b->getId(), [$a->getId()]);

        $owner = (int)$this->conn->fetchOne('SELECT journal_id FROM journal_name_variants WHERE normalized_name = ?', [self::norm($shared)]);
        $this->assertSame($third->getId(), $owner);
        $this->assertSame($b->getId(), (int)$this->conn->fetchOne('SELECT journal_id FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Fonte Alt {$this->uniq}")]));
    }

    public function testSeveralSourcesAtOnceDeduplicateEvidenceAndNamesAlreadyKnownByTheMaster(): void
    {
        $a1 = $this->createJournal("Fonte Um {$this->uniq}");
        $a2 = $this->createJournal("Fonte Dois {$this->uniq}");
        $b = $this->createJournal("Revista Mestre {$this->uniq}");
        // O título da fonte 2 já é variante do master
        $this->createVariant($b, "Fonte Dois {$this->uniq}");
        // A mesma evidência nas duas fontes: uma só chega ao master
        $this->insertEvidence($a1->getId(), 'latindex', 'L1', 'Educación');
        $this->insertEvidence($a2->getId(), 'latindex', 'L1', 'Educación');
        $this->insertEvidence($a2->getId(), 'latindex', 'L1', 'Psicología');
        $article = $this->createArticle($a2, 'Artigo da fonte 2');

        $this->merge->mergeJournals($b->getId(), [$a1->getId(), (string)$a2->getId(), $a1->getId(), 999999999, $b->getId()]);

        $this->assertSame(0, $this->scalarCount('SELECT COUNT(*) FROM qualis_journals WHERE id IN (?, ?)', [$a1->getId(), $a2->getId()]));
        $this->assertSame($b->getId(), $this->articleJournal($article));
        $labels = $this->conn->fetchFirstColumn('SELECT label FROM journal_area_evidence WHERE journal_id = ? ORDER BY label', [$b->getId()]);
        $this->assertSame(['Educación', 'Psicología'], $labels);
        $this->assertSame(2, $this->scalarCount('SELECT COUNT(*) FROM journal_name_variants WHERE journal_id = ?', [$b->getId()]), 'Fonte Um é nova; Fonte Dois já existia, sem duplicar');
    }

    public function testMasterThatIsAMemberOfTheMergedFamilyBecomesItsCanonical(): void
    {
        $source = $this->createJournal("Revista Canonica {$this->uniq}");
        $master = $this->createJournal("Revista Mestre Membro {$this->uniq}", $source);
        $sibling = $this->createJournal("Revista Irma {$this->uniq}", $source);
        $article = $this->createArticle($source, 'Artigo da canônica');

        $this->merge->mergeJournals($master->getId(), [$source->getId()]);

        $this->assertSame(0, $this->scalarCount('SELECT COUNT(*) FROM qualis_journals WHERE id = ?', [$source->getId()]));
        $this->assertNull($this->canonicalOf($master->getId()), 'O mestre passa a ser a canônica da família');
        $this->assertSame($master->getId(), $this->canonicalOf($sibling->getId()));
        $this->assertSame($master->getId(), $this->articleJournal($article));
    }

    public function testRowsOfTheSourceFamilyJoinTheCanonicalOfAMasterThatIsAMember(): void
    {
        $head = $this->createJournal("Revista Cabeca {$this->uniq}");
        $master = $this->createJournal("Revista Mestre Filho {$this->uniq}", $head);
        $source = $this->createJournal("Revista Fonte {$this->uniq}");
        $memberOfSource = $this->createJournal("Revista Fonte Membro {$this->uniq}", $source);

        $this->merge->mergeJournals($master->getId(), [$source->getId()]);

        // Sem cadeia membro -> membro: o membro da fonte entra na família da cabeça, não na do mestre
        $this->assertSame($head->getId(), $this->canonicalOf($memberOfSource->getId()));
        $this->assertSame($head->getId(), $this->canonicalOf($master->getId()));
        $this->assertNull($this->canonicalOf($head->getId()));
    }

    public function testFailureRollsEverythingBack(): void
    {
        $a = $this->createJournal("Revista Fonte {$this->uniq}");
        $member = $this->createJournal("Revista Fonte Membro {$this->uniq}", $a);
        $b = $this->createJournal("Revista Mestre {$this->uniq}");
        $aId = $a->getId();
        $bId = $b->getId();
        $article = $this->createArticle($a, 'Artigo');
        $this->createVariant($a, "Fonte Alt {$this->uniq}");
        $this->insertEvidence($aId, 'wos', 'W1', 'Law');
        $this->insertArea($aId, 404, 1);

        // Título maior que a coluna: o flush final falha depois de todas as correções por SQL
        $failed = false;
        try {
            $this->merge->mergeJournals($bId, [$aId], ['title' => str_repeat('X', 600)]);
        } catch (\Throwable $e) {
            $failed = true;
        }
        $this->assertTrue($failed, 'A mesclagem deveria falhar');
        $this->assertFalse($this->conn->isTransactionActive(), 'A transação não pode ficar aberta');

        $this->assertSame(1, $this->scalarCount('SELECT COUNT(*) FROM qualis_journals WHERE id = ?', [$aId]), 'A fonte continua existindo');
        $this->assertSame($aId, $this->articleJournal($article));
        $this->assertSame($aId, $this->canonicalOf($member->getId()));
        $this->assertNull($this->conn->fetchOne('SELECT canonical_method FROM qualis_journals WHERE id = ?', [$member->getId()]));
        $this->assertSame($aId, (int)$this->conn->fetchOne('SELECT journal_id FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Fonte Alt {$this->uniq}")]));
        $this->assertSame(1, $this->scalarCount('SELECT COUNT(*) FROM journal_area_evidence WHERE journal_id = ?', [$aId]));
        $this->assertSame(1, $this->scalarCount('SELECT COUNT(*) FROM journal_areas WHERE journal_id = ?', [$aId]));
        $this->assertSame(self::norm("Revista Fonte {$this->uniq}"), $this->conn->fetchOne('SELECT normalized_title FROM qualis_journals WHERE id = ?', [$aId]), 'O título normalizado da fonte volta ao que era');
        $this->assertSame(0, $this->scalarCount('SELECT COUNT(*) FROM journal_name_variants WHERE journal_id = ?', [$bId]));
    }

    public function testNothingToMergeOnlyAppliesTheChosenFieldsAndUnknownMasterFails(): void
    {
        $b = $this->createJournal("Revista Mestre {$this->uniq}");
        $this->createVariant($b, "Mestre Alt {$this->uniq}");
        $article = $this->createArticle($b, 'Artigo');

        $result = $this->merge->mergeJournals($b->getId(), [$b->getId(), 999999999], ['qualis' => 'a2']);

        $this->assertSame($b->getId(), $result->getId());
        $this->assertSame('A2', $this->conn->fetchOne('SELECT qualis FROM qualis_journals WHERE id = ?', [$b->getId()]));
        $this->assertSame($b->getId(), $this->articleJournal($article));
        $this->assertSame(1, $this->scalarCount('SELECT COUNT(*) FROM journal_name_variants WHERE journal_id = ?', [$b->getId()]));

        $this->expectException(\InvalidArgumentException::class);
        $this->merge->mergeJournals(999999999, [$b->getId()]);
    }

    public function testChosenTitleReplacesTheMasterTitleAndTheOldOneBecomesAVariant(): void
    {
        $a = $this->createJournal("Revista Fonte {$this->uniq}");
        $b = $this->createJournal("Revista Mestre {$this->uniq}");

        $this->merge->mergeJournals($b->getId(), [$a->getId()], ['title' => "Revista Escolhida {$this->uniq}"]);

        $this->assertSame("Revista Escolhida {$this->uniq}", $this->conn->fetchOne('SELECT title FROM qualis_journals WHERE id = ?', [$b->getId()]));
        $this->assertSame(self::norm("Revista Escolhida {$this->uniq}"), $this->conn->fetchOne('SELECT normalized_title FROM qualis_journals WHERE id = ?', [$b->getId()]));
        $names = $this->conn->fetchFirstColumn('SELECT normalized_name FROM journal_name_variants WHERE journal_id = ? ORDER BY normalized_name', [$b->getId()]);
        $this->assertSame(
            [self::norm("Revista Fonte {$this->uniq}"), self::norm("Revista Mestre {$this->uniq}")],
            $names,
            'O título da fonte e o título antigo do mestre ficam como variantes'
        );
    }

    // ------------------------------------------------------------------------------------------------------------

    private static function norm(string $name): string
    {
        return StringNormalizer::normalizeString($name, true);
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function scalarCount(string $sql, array $params): int
    {
        return (int)$this->conn->fetchOne($sql, $params);
    }

    private function articleJournal(int $articleId): ?int
    {
        $value = $this->conn->fetchOne('SELECT qualis_journal_id FROM production_items WHERE id = ?', [$articleId]);

        return $value === false || $value === null ? null : (int)$value;
    }

    private function canonicalOf(int $journalId): ?int
    {
        $value = $this->conn->fetchOne('SELECT canonical_journal_id FROM qualis_journals WHERE id = ?', [$journalId]);

        return $value === false || $value === null ? null : (int)$value;
    }

    private function createJournal(string $title, ?QualisJournal $canonical = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        $journal->setCanonical($canonical);
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    private function createVariant(QualisJournal $journal, string $name): void
    {
        $variation = new JournalVariation();
        $variation->setJournal($journal);
        $variation->setVariationName($name);
        $variation->setNormalizedName(self::norm($name));
        $variation->setVariationType('alternative');
        $variation->setStatus(true);
        $this->em->persist($variation);
        $this->em->flush();
    }

    private function createArticle(QualisJournal $journal, string $title): int
    {
        $researcher = new Researcher();
        $researcher->setIdLattes('9977' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT));
        $researcher->setFullName('Docente Teste Merge ' . $this->uniq);
        $researcher->setSlug('docente-teste-merge-' . bin2hex(random_bytes(4)));
        $this->em->persist($researcher);
        $this->em->flush();
        $this->researcherIds[] = $researcher->getId();

        $article = new ProductionItem();
        $article->setItemType(ProductionItem::TYPE_ARTIGO);
        $article->setTitle($title);
        $article->setYear(2024);
        $article->setJournalName($journal->getTitle());
        $researcher->addProduction($article);
        $this->em->persist($article);
        $this->em->flush();

        // O vínculo do artigo com o periódico (a indexação faz isso na vida real)
        $this->conn->executeStatement('UPDATE production_items SET qualis_journal_id = ? WHERE id = ?', [$journal->getId(), $article->getId()]);

        return (int)$article->getId();
    }

    private function insertEvidence(int $journalId, string $source, string $recordKey, string $label): void
    {
        $this->conn->insert('journal_area_evidence', [
            'journal_id' => $journalId,
            'source' => $source,
            'record_key' => $recordKey,
            'label' => $label,
            'weight' => '1.0000',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function insertArea(int $journalId, int $areaId, int $primary): void
    {
        $this->conn->insert('journal_areas', [
            'journal_id' => $journalId,
            'area_id' => $areaId,
            'is_primary' => $primary,
            'score' => '1.0000',
            'confidence' => 'manual',
            'method' => 'manual',
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function createDatabase(string $suffix): int
    {
        $database = new AcademicDatabase();
        $database->setName("Base teste {$suffix} {$this->uniq}");
        $database->setAcronym("{$this->uniq}{$suffix}");
        $this->em->persist($database);
        $this->em->flush();
        $this->databaseAcronyms[] = $database->getAcronym();

        return (int)$database->getId();
    }

    private function linkBase(int $journalId, int $databaseId): void
    {
        $this->conn->insert('qualis_journal_academic_database', ['qualis_journal_id' => $journalId, 'academic_database_id' => $databaseId]);
    }

    private function candidateName(string $kind): string
    {
        return strtoupper("candidato {$kind} {$this->uniq}");
    }

    private function createCandidate(string $kind, ?int $suggestedId, ?int $resolvedId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->insert('journal_name_candidates', [
            'normalized_name' => $this->candidateName($kind),
            'raw_name' => "candidato {$kind} {$this->uniq}",
            'articles_count' => 1,
            'researchers_count' => 1,
            'suggested_journal_id' => $suggestedId,
            'resolved_journal_id' => $resolvedId,
            'status' => 'pending',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }
}
