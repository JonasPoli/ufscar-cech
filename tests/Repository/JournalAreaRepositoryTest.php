<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\JournalArea;
use App\Entity\QualisJournal;
use App\Repository\JournalAreaRepository;
use App\Service\Journal\CapesTaxonomy;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Curadoria manual das áreas CAPES (docs/evolutions/periodicos-por-base.md, A3 e A6.8): `replaceManual`, `clearManual`
 * e as consultas do admin.
 *
 * Os dados usam sufixo único e saem no tearDown (`cech_testp` é compartilhado com outros testes e agentes).
 */
class JournalAreaRepositoryTest extends KernelTestCase
{
    private const LABEL_EDUCACAO = 'EDUCAÇÃO / CIÊNCIAS HUMANAS';

    private EntityManagerInterface $em;
    private Connection $conn;
    private JournalAreaRepository $repo;
    private CapesTaxonomy $taxonomy;
    private string $uniq;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->repo = $container->get(JournalAreaRepository::class);
        $this->taxonomy = $container->get(CapesTaxonomy::class);
        $this->uniq = 'qz' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            // Evidências, áreas e variantes saem em cascata; a ordem não importa porque as famílias se desfazem por SET NULL
            $this->conn->executeStatement('DELETE FROM qualis_journals WHERE title LIKE ?', ['%' . $this->uniq . '%']);
        }
        if (isset($this->em)) {
            $this->em->clear();
        }

        parent::tearDown();
    }

    public function testReplaceManualWritesEveryRowOfTheFamilyAndLocksOnlyTheCanonical(): void
    {
        $head = $this->createJournal("Revista Alfa {$this->uniq}", 'Texto original', null);
        $member = $this->createJournal("Revista Alfa Online {$this->uniq}", null, null, $head);
        $other = $this->createJournal("Revista Outra {$this->uniq}");

        $this->repo->replaceManual($head, ['sociologia', 'educacao'], 'educacao');

        $expected = [
            ['slug' => 'educacao', 'primary' => 1, 'score' => '1.0000', 'confidence' => 'manual', 'method' => 'manual', 'run_id' => null],
            ['slug' => 'sociologia', 'primary' => 0, 'score' => '1.0000', 'confidence' => 'manual', 'method' => 'manual', 'run_id' => null],
        ];
        $this->assertEquals($expected, $this->areasOf($head->getId()));
        $this->assertEquals($expected, $this->areasOf($member->getId()));
        $this->assertSame([], $this->areasOf($other->getId()), 'Outra família não é tocada');

        $this->assertSame(1, $this->lockedOf($head->getId()));
        $this->assertSame(0, $this->lockedOf($member->getId()), 'A trava é só da canônica');

        // `area` ganha o rótulo canônico em todas as linhas; o texto que havia vai para `area_raw`
        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => 'Texto original'], $this->columnsOf($head->getId()));
        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => null], $this->columnsOf($member->getId()));
        $this->assertSame(['area' => null, 'area_raw' => null], $this->columnsOf($other->getId()));
    }

    public function testReplaceManualReplacesAutomaticAndEarlierManualRowsOfTheWholeFamily(): void
    {
        $head = $this->createJournal("Revista Beta {$this->uniq}");
        $member = $this->createJournal("Revista Beta Online {$this->uniq}", null, null, $head);
        $this->insertArea($head->getId(), 'psicologia', 1, 'auto', 'high');
        $this->insertArea($member->getId(), 'psicologia', 1, 'auto', 'high');
        $this->insertArea($member->getId(), 'direito', 0, 'manual', 'manual');

        $this->repo->replaceManual($head, ['educacao'], 'educacao');

        $this->assertSame(['educacao'], array_column($this->areasOf($head->getId()), 'slug'));
        $this->assertSame(['educacao'], array_column($this->areasOf($member->getId()), 'slug'));

        // De novo, com outra escolha: nada da anterior sobra e nada duplica
        $this->repo->replaceManual($head, ['sociologia', 'antropologia-arqueologia'], 'antropologia-arqueologia');
        $this->assertSame(['antropologia-arqueologia', 'sociologia'], array_column($this->areasOf($member->getId()), 'slug'));
        $this->assertSame(2, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM journal_areas WHERE journal_id = ?', [$head->getId()]));
    }

    public function testAreaRawKeepsTheOriginalTextAndNeverReceivesACanonicalLabel(): void
    {
        $journal = $this->createJournal("Revista Gama {$this->uniq}", 'Social Sciences', null);

        $this->repo->replaceManual($journal, ['educacao'], 'educacao');
        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => 'Social Sciences'], $this->columnsOf($journal->getId()));

        // Segunda curadoria: `area` já tem o rótulo canônico e `area_raw` continua com o texto original
        $this->repo->replaceManual($journal, ['sociologia'], 'sociologia');
        $this->assertSame(['area' => 'SOCIOLOGIA / CIÊNCIAS HUMANAS', 'area_raw' => 'Social Sciences'], $this->columnsOf($journal->getId()));

        // Grande área sozinha: rótulo sem barra
        $this->repo->replaceManual($journal, ['ciencias-humanas'], 'ciencias-humanas');
        $this->assertSame(['area' => 'CIÊNCIAS HUMANAS', 'area_raw' => 'Social Sciences'], $this->columnsOf($journal->getId()));
    }

    public function testPrimaryDefaultsToTheFirstAreaAndRepeatedSlugsCountOnce(): void
    {
        $journal = $this->createJournal("Revista Delta {$this->uniq}");

        // Principal fora da seleção (ou vazia): vale a primeira da lista
        $this->repo->replaceManual($journal, ['direito', 'educacao', 'direito'], 'sociologia');

        $rows = $this->areasOf($journal->getId());
        $this->assertSame(['direito', 'educacao'], array_column($rows, 'slug'));
        $this->assertSame([1, 0], array_map('intval', array_column($rows, 'primary')));
        $this->assertSame('DIREITO / CIÊNCIAS SOCIAIS APLICADAS', $this->columnsOf($journal->getId())['area']);

        $this->repo->replaceManual($journal, ['educacao', 'direito'], '');
        $this->assertSame(['educacao'], array_column(array_filter($this->areasOf($journal->getId()), static fn (array $r): bool => $r['primary'] === 1), 'slug'));
    }

    public function testInvalidSelectionThrowsAndChangesNothing(): void
    {
        $journal = $this->createJournal("Revista Épsilon {$this->uniq}", 'Texto');
        $this->repo->replaceManual($journal, ['educacao'], 'educacao');

        try {
            $this->repo->replaceManual($journal, ['sociologia', 'nao-existe'], 'sociologia');
            $this->fail('Slug desconhecido deveria falhar');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('nao-existe', $e->getMessage());
        }
        try {
            $this->repo->replaceManual($journal, ['', '  '], 'educacao');
            $this->fail('Lista vazia deveria falhar');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(['educacao'], array_column($this->areasOf($journal->getId()), 'slug'));
        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => 'Texto'], $this->columnsOf($journal->getId()));
    }

    public function testCallingWithAMemberRowWorksOnTheCanonicalFamily(): void
    {
        $head = $this->createJournal("Revista Zeta {$this->uniq}");
        $member = $this->createJournal("Revista Zeta Online {$this->uniq}", null, null, $head);

        $this->repo->replaceManual($member, ['educacao'], 'educacao');

        $this->assertSame(1, $this->lockedOf($head->getId()));
        $this->assertSame(0, $this->lockedOf($member->getId()));
        $this->assertSame(['educacao'], array_column($this->areasOf($head->getId()), 'slug'));
        $this->assertSame(['educacao'], array_column($this->areasOf($member->getId()), 'slug'));
    }

    public function testManagedEntitiesAreRefreshedAfterTheSqlWrite(): void
    {
        $head = $this->createJournal("Revista Eta {$this->uniq}", 'Original');
        $member = $this->createJournal("Revista Eta Online {$this->uniq}", null, null, $head);
        // Carrega a coleção antes: o refresh precisa zerá-la
        $this->assertCount(0, $head->getAreas());

        $this->repo->replaceManual($head, ['educacao', 'sociologia'], 'educacao');

        $this->assertSame(self::LABEL_EDUCACAO, $head->getArea());
        $this->assertSame('Original', $head->getAreaRaw());
        $this->assertTrue($head->isAreasLocked());
        $this->assertSame(self::LABEL_EDUCACAO, $member->getArea());
        $this->assertCount(2, $head->getAreas());
        $this->assertSame('educacao', $head->getPrimaryArea()?->getArea()?->getSlug());
    }

    public function testClearManualRemovesOnlyNonAutomaticRowsAndUnlocks(): void
    {
        $head = $this->createJournal("Revista Teta {$this->uniq}");
        $member = $this->createJournal("Revista Teta Online {$this->uniq}", null, null, $head);
        $this->repo->replaceManual($head, ['educacao'], 'educacao');
        // Uma linha automática convive em outra linha da família (estado misto)
        $this->insertArea($member->getId(), 'direito', 0, 'auto', 'low');

        $removed = $this->repo->clearManual($head);

        $this->assertSame(2, $removed, 'As duas linhas manuais (canônica e membro)');
        $this->assertSame([], $this->areasOf($head->getId()));
        $this->assertSame(['direito'], array_column($this->areasOf($member->getId()), 'slug'));
        $this->assertSame(0, $this->lockedOf($head->getId()));
        $this->assertFalse($head->isAreasLocked(), 'A entidade carregada também é atualizada');
    }

    public function testResolveSlugsAcceptsSlugNameAndCanonicalLabelOfEveryArea(): void
    {
        foreach ($this->taxonomy->all() as $area) {
            $slug = $area['slug'];
            foreach ([$slug, $area['name'], mb_strtoupper($area['name'], 'UTF-8'), $this->taxonomy->areaLabel($slug)] as $text) {
                $this->assertSame(['slugs' => [$slug], 'unknown' => []], $this->repo->resolveSlugs($text), "«{$text}» deveria resolver para {$slug}");
            }
        }

        // Várias, sem acento e fora de ordem; repetida conta uma vez; o que não é área vem em `unknown`
        $this->assertSame(
            ['slugs' => ['sociologia', 'educacao'], 'unknown' => ['Astrologia']],
            $this->repo->resolveSlugs(' sociologia ; EDUCACAO ; Astrologia ; educação ; ')
        );
        $this->assertSame(['slugs' => [], 'unknown' => []], $this->repo->resolveSlugs(' ; ;'));
    }

    public function testPrimaryByJournalPrefersManualAndNamesByJournalListPrimaryFirst(): void
    {
        $manual = $this->createJournal("Revista Iota {$this->uniq}");
        $auto = $this->createJournal("Revista Kapa {$this->uniq}");
        $none = $this->createJournal("Revista Lambda {$this->uniq}");
        $this->insertArea($auto->getId(), 'sociologia', 0, 'auto', 'medium', '0.3000');
        $this->insertArea($auto->getId(), 'educacao', 1, 'auto', 'high', '0.7000');
        // Estado misto: uma principal automática e uma manual na mesma linha
        $this->insertArea($manual->getId(), 'psicologia', 1, 'auto', 'low');
        $this->insertArea($manual->getId(), 'direito', 1, 'manual', 'manual');

        $primary = $this->repo->primaryByJournal([$manual->getId(), $auto->getId(), $none->getId(), 0]);

        $this->assertSame(['slug' => 'direito', 'name' => 'Direito', 'level' => 2, 'confidence' => 'manual', 'method' => 'manual'], $primary[$manual->getId()]);
        $this->assertSame('educacao', $primary[$auto->getId()]['slug']);
        $this->assertSame('high', $primary[$auto->getId()]['confidence']);
        $this->assertArrayNotHasKey($none->getId(), $primary);
        $this->assertSame([], $this->repo->primaryByJournal([]));

        $names = $this->repo->namesByJournal();
        $this->assertSame(['primary' => 'Educação', 'all' => ['Educação', 'Sociologia']], $names[$auto->getId()]);
        $this->assertArrayNotHasKey($none->getId(), $names);
    }

    // ------------------------------------------------------------------------------------------------------------

    private function createJournal(string $title, ?string $area = null, ?string $areaRaw = null, ?QualisJournal $canonical = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        $journal->setArea($area);
        $journal->setAreaRaw($areaRaw);
        $journal->setCanonical($canonical);
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    private function insertArea(int $journalId, string $slug, int $primary, string $method, string $confidence, string $score = '0.5000'): void
    {
        $this->conn->insert('journal_areas', [
            'journal_id' => $journalId,
            'area_id' => $this->taxonomy->bySlug($slug)['id'],
            'is_primary' => $primary,
            'score' => $score,
            'confidence' => $confidence,
            'method' => $method,
            'run_id' => $method === JournalArea::METHOD_AUTO ? 'teste' : null,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<array{slug: string, primary: int, score: string, confidence: string, method: string, run_id: ?string}>
     */
    private function areasOf(int $journalId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT a.slug, ja.is_primary, ja.score, ja.confidence, ja.method, ja.run_id
               FROM journal_areas ja JOIN capes_areas a ON a.id = ja.area_id
              WHERE ja.journal_id = ? ORDER BY ja.is_primary DESC, a.slug',
            [$journalId]
        );

        return array_map(static fn (array $r): array => [
            'slug' => $r['slug'],
            'primary' => (int)$r['is_primary'],
            'score' => $r['score'],
            'confidence' => $r['confidence'],
            'method' => $r['method'],
            'run_id' => $r['run_id'],
        ], $rows);
    }

    /**
     * @return array{area: ?string, area_raw: ?string}
     */
    private function columnsOf(int $journalId): array
    {
        $row = $this->conn->fetchAssociative('SELECT area, area_raw FROM qualis_journals WHERE id = ?', [$journalId]);

        return ['area' => $row['area'], 'area_raw' => $row['area_raw']];
    }

    private function lockedOf(int $journalId): int
    {
        return (int)$this->conn->fetchOne('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$journalId]);
    }
}
