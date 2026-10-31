<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Entity\AcademicDatabase;
use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Service\Journal\JournalAreaClassifier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Gravação da classificação de áreas (A6.4 "Gravação" e A3 de docs/evolutions/periodicos-por-base.md).
 *
 * Cada teste roda numa transação revertida no tearDown: nada fica gravado em `cech_test`. As asserções olham os ids
 * criados aqui; `classifyAll` processa o banco de testes inteiro, e os testes dele comparam diferenças (antes e depois
 * de criar as famílias) em vez de totais.
 */
class JournalAreaClassifierTest extends KernelTestCase
{
    private const LABEL_EDUCACAO = 'EDUCAÇÃO / CIÊNCIAS HUMANAS';

    private EntityManagerInterface $em;
    private Connection $conn;
    private JournalAreaClassifier $classifier;
    private string $uniq;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->classifier = $container->get(JournalAreaClassifier::class);
        $this->uniq = 'qz' . random_int(100000, 999999);

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

    // ------------------------------------------------------------------------------------------------------------
    // Gravação por família
    // ------------------------------------------------------------------------------------------------------------

    public function testFamilyOfTwoRowsGetsTheSameAreasAndTheCanonicalLabelWithTheOriginalTextInAreaRaw(): void
    {
        $head = $this->createJournal("Revista Alfa {$this->uniq}", 'Texto original da área');
        $member = $this->createJournal("Revista Alfa Online {$this->uniq}", null, null, $head);
        $this->insertEvidence($head->getId(), 'wos', 'W1', 'Education & Educational Research');
        $this->insertEvidence($member->getId(), 'scopus', 'S1', '3304', '0.5000');
        $this->insertEvidence($member->getId(), 'scopus', 'S1', '3312', '0.5000');

        $result = $this->classifier->classifyFamily($head->getId(), 'run-1');

        $this->assertNotNull($result);
        $this->assertSame($head->getId(), $result['family']);
        $this->assertSame([$head->getId(), $member->getId()], $result['rows']);
        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertNull($result['fallback']);
        $this->assertFalse($result['dry_run']);

        // ASJC 3312 divide em sociologia e ciência política (0,125 cada): ficam abaixo de 0,25, sem secundárias
        $expected = [['slug' => 'educacao', 'primary' => 1, 'score' => '0.7500', 'confidence' => 'high', 'method' => 'auto', 'run_id' => 'run-1']];
        $this->assertSame($expected, $this->areasOf($head->getId()));
        $this->assertSame($expected, $this->areasOf($member->getId()));

        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => 'Texto original da área'], $this->columnsOf($head->getId()));
        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => null], $this->columnsOf($member->getId()));
    }

    public function testPrimaryAndSecondaryAreasAreWrittenInEveryRowOfTheFamily(): void
    {
        $head = $this->createJournal("Revista Beta {$this->uniq}");
        $member = $this->createJournal("Revista Beta Online {$this->uniq}", null, null, $head);
        $this->insertEvidence($head->getId(), 'wos', 'W1', 'Anthropology', '0.5000');
        $this->insertEvidence($head->getId(), 'wos', 'W1', 'Sociology', '0.5000');

        $this->classifier->classifyFamily($head->getId(), 'run-2');

        $expected = [
            ['slug' => 'antropologia-arqueologia', 'primary' => 1, 'score' => '0.5000', 'confidence' => 'high', 'method' => 'auto', 'run_id' => 'run-2'],
            ['slug' => 'sociologia', 'primary' => 0, 'score' => '0.5000', 'confidence' => 'high', 'method' => 'auto', 'run_id' => 'run-2'],
        ];
        $this->assertSame($expected, $this->areasOf($head->getId()));
        $this->assertSame($expected, $this->areasOf($member->getId()));
        $this->assertSame('ANTROPOLOGIA / ARQUEOLOGIA / CIÊNCIAS HUMANAS', $this->columnsOf($member->getId())['area']);
    }

    public function testFamilyWithoutEvidenceButLinkedToPubmedGetsHealthSciencesWithLowConfidence(): void
    {
        $journal = $this->createJournal("Revista Pubmed {$this->uniq}", 'Original PubMed');
        $this->linkPubmed($journal);

        $result = $this->classifier->classifyFamily($journal->getId(), 'run-3');

        $this->assertSame('ciencias-da-saude', $result['primary']['slug']);
        $this->assertSame('pubmed', $result['fallback']);
        $this->assertSame('low', $result['confidence']);
        $this->assertSame(
            [['slug' => 'ciencias-da-saude', 'primary' => 1, 'score' => '0.0000', 'confidence' => 'low', 'method' => 'auto', 'run_id' => 'run-3']],
            $this->areasOf($journal->getId())
        );
        $this->assertSame(['area' => 'CIÊNCIAS DA SAÚDE', 'area_raw' => 'Original PubMed'], $this->columnsOf($journal->getId()));
    }

    public function testPubmedLinkOnAMemberRowCountsForTheWholeFamily(): void
    {
        $head = $this->createJournal("Revista Pubmed Membro {$this->uniq}");
        $member = $this->createJournal("Revista Pubmed Membro Online {$this->uniq}", null, null, $head);
        $this->linkPubmed($member);

        $this->classifier->classifyFamily($head->getId(), 'run-3b');

        $this->assertSame(['ciencias-da-saude'], array_column($this->areasOf($head->getId()), 'slug'));
        $this->assertSame(['ciencias-da-saude'], array_column($this->areasOf($member->getId()), 'slug'));
    }

    public function testMappedEvidenceBeatsThePubmedFallback(): void
    {
        $journal = $this->createJournal("Revista Pubmed Com Evidência {$this->uniq}");
        $this->linkPubmed($journal);
        $this->insertEvidence($journal->getId(), 'wos', 'W1', 'Law');

        $result = $this->classifier->classifyFamily($journal->getId(), 'run-3c');

        $this->assertSame('direito', $result['primary']['slug']);
        $this->assertNull($result['fallback']);
    }

    public function testFamilyWithoutEvidenceNorPubmedHasNoAreasAndAreaGoesBackToAreaRaw(): void
    {
        // Resto de uma classificação anterior: rótulo canônico em `area`, texto original em `area_raw`, linha automática
        $journal = $this->createJournal("Revista Sem Evidência {$this->uniq}", self::LABEL_EDUCACAO, 'Social Sciences');
        $this->insertAutoArea($journal->getId(), 404);

        $result = $this->classifier->classifyFamily($journal->getId(), 'run-4');

        $this->assertNull($result['primary']);
        $this->assertSame('none', $result['confidence']);
        $this->assertSame([], $this->areasOf($journal->getId()));
        $this->assertSame(['area' => 'Social Sciences', 'area_raw' => 'Social Sciences'], $this->columnsOf($journal->getId()));
    }

    public function testUnclassifiedRowWithoutOriginalTextEndsWithEmptyArea(): void
    {
        $journal = $this->createJournal("Revista Vazia {$this->uniq}", self::LABEL_EDUCACAO, null);

        $this->classifier->classifyFamily($journal->getId(), 'run-4b');

        $this->assertSame(['area' => null, 'area_raw' => null], $this->columnsOf($journal->getId()));
    }

    public function testAreaRawNeverReceivesACanonicalLabelAndKeepsTheOriginalText(): void
    {
        // `area` já traz o rótulo canônico de outra área: o original em `area_raw` fica como está
        $journal = $this->createJournal("Revista Canônica {$this->uniq}", 'DIREITO / CIÊNCIAS SOCIAIS APLICADAS', 'Texto antigo');
        $this->insertEvidence($journal->getId(), 'wos', 'W1', 'Education & Educational Research');

        $this->classifier->classifyFamily($journal->getId(), 'run-5');

        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => 'Texto antigo'], $this->columnsOf($journal->getId()));
    }

    public function testNewNonCanonicalTextInAreaIsCopiedToAreaRawBeforeBeingReplaced(): void
    {
        // Linha importada depois da migration: `area` tem texto novo e `area_raw` está vazio ou tem outro valor
        $fresh = $this->createJournal("Revista Nova {$this->uniq}", 'Social Sciences', null);
        $edited = $this->createJournal("Revista Editada {$this->uniq}", 'Texto novo digitado', 'Texto importado');
        $this->insertEvidence($fresh->getId(), 'wos', 'W1', 'Education & Educational Research');
        $this->insertEvidence($edited->getId(), 'wos', 'W2', 'Education & Educational Research');

        $this->classifier->classifyFamily($fresh->getId(), 'run-5b');
        $this->classifier->classifyFamily($edited->getId(), 'run-5b');

        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => 'Social Sciences'], $this->columnsOf($fresh->getId()));
        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => 'Texto novo digitado'], $this->columnsOf($edited->getId()));
    }

    public function testRunningTwiceGivesTheSameResultAndDoesNotDuplicate(): void
    {
        $head = $this->createJournal("Revista Gama {$this->uniq}", 'Texto original');
        $member = $this->createJournal("Revista Gama Online {$this->uniq}", null, null, $head);
        $this->insertEvidence($head->getId(), 'wos', 'W1', 'Education & Educational Research');
        $this->insertEvidence($member->getId(), 'wos', 'W1', 'Education & Educational Research');
        $this->insertEvidence($member->getId(), 'latindex', 'L1', 'Educación');

        $this->classifier->classifyFamily($head->getId(), 'run-a');
        $firstAreas = [$this->areasOf($head->getId()), $this->areasOf($member->getId())];
        $firstColumns = [$this->columnsOf($head->getId()), $this->columnsOf($member->getId())];

        $this->classifier->classifyFamily($head->getId(), 'run-a');
        $this->classifier->classifyFamily($member->getId(), 'run-b');

        $secondAreas = [$this->areasOf($head->getId()), $this->areasOf($member->getId())];
        $this->assertCount(1, $secondAreas[0]);
        $this->assertCount(1, $secondAreas[1]);
        $this->assertSame('run-b', $secondAreas[0][0]['run_id']);
        // só o run_id muda de uma execução para a outra
        foreach ([0, 1] as $position) {
            $this->assertSame(
                array_map(static fn (array $row): array => array_diff_key($row, ['run_id' => 1]), $firstAreas[$position]),
                array_map(static fn (array $row): array => array_diff_key($row, ['run_id' => 1]), $secondAreas[$position])
            );
        }
        $this->assertSame($firstColumns, [$this->columnsOf($head->getId()), $this->columnsOf($member->getId())]);
        $this->assertSame('Texto original', $this->columnsOf($head->getId())['area_raw']);
    }

    public function testClassifyingByAMemberRowClassifiesTheWholeFamily(): void
    {
        $head = $this->createJournal("Revista Delta {$this->uniq}");
        $member = $this->createJournal("Revista Delta Online {$this->uniq}", null, null, $head);
        $this->insertEvidence($head->getId(), 'wos', 'W1', 'Law');

        $result = $this->classifier->classifyFamily($member->getId(), 'run-6');

        $this->assertSame($head->getId(), $result['family']);
        $this->assertSame(['direito'], array_column($this->areasOf($head->getId()), 'slug'));
        $this->assertSame(['direito'], array_column($this->areasOf($member->getId()), 'slug'));
    }

    public function testUnknownJournalReturnsNull(): void
    {
        $this->assertNull($this->classifier->classifyFamily(2147483000, 'run-7'));
    }

    public function testRunIdLongerThanFortyCharactersIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->classifier->classifyAll(str_repeat('x', 41), null, true);
    }

    public function testOpenAlexWorksAndAiConfidenceAreReadFromThePayloadJson(): void
    {
        $journal = $this->createJournal("Revista Payload {$this->uniq}");
        $id = $journal->getId();
        $this->insertEvidence($id, 'openalex', 'S1', '3304', '1.0000', '{"works": 1000, "country": "BR"}');
        $this->insertEvidence($id, 'ai', 'issn:11111111', 'educacao', '1.0000', '{"confidence": 0.9, "role": "primary", "model": "claude-sonnet"}');
        $this->insertEvidence($id, 'ai', 'issn:11111111', 'psicologia', '0.5000', '{"confidence": 0.9, "role": "secondary"}');

        $result = $this->classifier->classifyFamily($id, 'run-8', true);

        // openalex w = 1,0 (1.000 works); ai 0,81 (principal) e 0,405 (secundária): soma 2,215
        $this->assertEqualsWithDelta(2.215, $result['total_weight'], 0.0005);
        $this->assertEqualsWithDelta(1.0, $result['sources']['openalex'], 0.0005);
        $this->assertEqualsWithDelta(1.215, $result['sources']['ai'], 0.0005);
        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertEqualsWithDelta(1.81 / 2.215, $result['primary']['share'], 0.0005);
        $this->assertSame([], $result['secondary']);
        $this->assertSame('high', $result['confidence']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Curadoria manual
    // ------------------------------------------------------------------------------------------------------------

    public function testLockedCanonicalIsSkippedAndNothingIsWritten(): void
    {
        $head = $this->createJournal("Revista Travada {$this->uniq}", 'Área digitada pelo admin', null, null, true);
        $member = $this->createJournal("Revista Travada Online {$this->uniq}", 'Outro texto', null, $head);
        $this->insertManualArea($head->getId(), 405);
        $this->insertEvidence($head->getId(), 'wos', 'W1', 'Education & Educational Research');
        $this->insertEvidence($member->getId(), 'wos', 'W1', 'Education & Educational Research');
        $before = [$this->areasOf($head->getId()), $this->areasOf($member->getId()), $this->columnsOf($head->getId()), $this->columnsOf($member->getId())];

        $this->assertNull($this->classifier->classifyFamily($head->getId(), 'run-9'));
        $this->assertNull($this->classifier->classifyFamily($member->getId(), 'run-9'));
        $this->classifier->classifyAll('run-9');

        $this->assertSame(
            $before,
            [$this->areasOf($head->getId()), $this->areasOf($member->getId()), $this->columnsOf($head->getId()), $this->columnsOf($member->getId())]
        );
        $this->assertSame(
            [['slug' => 'filosofia', 'primary' => 1, 'score' => '1.0000', 'confidence' => 'manual', 'method' => 'manual', 'run_id' => null]],
            $this->areasOf($head->getId())
        );
        $this->assertSame([], $this->areasOf($member->getId()));
    }

    public function testManualAreaOnARowOfAnUnlockedFamilyWinsOverTheAutomaticOne(): void
    {
        $head = $this->createJournal("Revista Mista {$this->uniq}");
        $member = $this->createJournal("Revista Mista Online {$this->uniq}", null, null, $head);
        $this->insertManualArea($member->getId(), 404);
        $this->insertEvidence($head->getId(), 'wos', 'W1', 'Education & Educational Research');

        $this->classifier->classifyFamily($head->getId(), 'run-10');

        $this->assertSame(['educacao'], array_column($this->areasOf($head->getId()), 'slug'));
        $this->assertSame(
            [['slug' => 'educacao', 'primary' => 1, 'score' => '1.0000', 'confidence' => 'manual', 'method' => 'manual', 'run_id' => null]],
            $this->areasOf($member->getId())
        );
    }

    // ------------------------------------------------------------------------------------------------------------
    // classifyAll
    // ------------------------------------------------------------------------------------------------------------

    public function testClassifyAllSummaryCountsFamiliesAndTheRealRunMatchesTheDryRun(): void
    {
        $baseline = $this->classifier->classifyAll('run-base', null, true);

        $researcher = $this->createResearcher();

        // F1: high, visível
        $f1 = $this->createJournal("Família Um {$this->uniq}", 'Texto F1');
        $f1m = $this->createJournal("Família Um Online {$this->uniq}", null, null, $f1);
        $this->insertEvidence($f1->getId(), 'wos', 'W1', 'Education & Educational Research');
        $this->createArticle($researcher, $f1m);
        // F2: medium (só legacy), não visível
        $f2 = $this->createJournal("Família Dois {$this->uniq}", 'EDUCAÇÃO', 'EDUCAÇÃO');
        $this->insertEvidence($f2->getId(), 'legacy', 'area', 'EDUCAÇÃO');
        // F3: fallback PubMed (nível 1, low), visível
        $f3 = $this->createJournal("Família Três {$this->uniq}");
        $this->linkPubmed($f3);
        $this->createArticle($researcher, $f3);
        // F4: sem evidência, visível
        $f4 = $this->createJournal("Família Quatro {$this->uniq}", 'Social Sciences', 'Social Sciences');
        $this->createArticle($researcher, $f4);
        // F5: travada com área manual, visível
        $f5 = $this->createJournal("Família Cinco {$this->uniq}", 'Digitado', null, null, true);
        $this->insertManualArea($f5->getId(), 408);
        $this->insertEvidence($f5->getId(), 'wos', 'W5', 'Law');
        $this->createArticle($researcher, $f5);
        // F6: grande área por evidência (nível 1, medium), não visível
        $f6 = $this->createJournal("Família Seis {$this->uniq}");
        $this->insertEvidence($f6->getId(), 'scielo_area', 'C1', 'Human Sciences');

        $dry = $this->classifier->classifyAll('run-dry', null, true);

        $this->assertSame(6, $dry['families'] - $baseline['families'], 'seis famílias novas (F1 tem duas linhas)');
        $this->assertSame(4, $dry['classified'] - $baseline['classified']);
        $this->assertSame(1, $dry['high'] - $baseline['high']);
        $this->assertSame(2, $dry['medium'] - $baseline['medium']);
        $this->assertSame(1, $dry['low'] - $baseline['low']);
        $this->assertSame(2, $dry['level1'] - $baseline['level1']);
        $this->assertSame(1, $dry['no_evidence'] - $baseline['no_evidence']);
        $this->assertSame(1, $dry['locked'] - $baseline['locked']);
        $this->assertSame(4, $dry['visible_total'] - $baseline['visible_total']);
        $this->assertSame(3, $dry['visible_classified'] - $baseline['visible_classified']);

        $columnsBefore = $this->columnsOf($f1->getId());
        $this->assertSame(['area' => 'Texto F1', 'area_raw' => null], $columnsBefore);

        $progress = [];
        $real = $this->classifier->classifyAll('run-real', static function (int $done, int $total) use (&$progress): void {
            $progress[] = [$done, $total];
        });

        $this->assertSame($dry, $real, 'a execução real tem os mesmos números da simulação');
        $this->assertNotSame([], $progress);
        $this->assertSame($real['families'], $progress[array_key_last($progress)][1]);
        $this->assertSame($real['families'], $progress[array_key_last($progress)][0]);

        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => 'Texto F1'], $this->columnsOf($f1->getId()));
        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => null], $this->columnsOf($f1m->getId()));
        $this->assertSame(['educacao'], array_column($this->areasOf($f1m->getId()), 'slug'));
        $this->assertSame('medium', $this->areasOf($f2->getId())[0]['confidence']);
        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => 'EDUCAÇÃO'], $this->columnsOf($f2->getId()));
        $this->assertSame('ciencias-da-saude', $this->areasOf($f3->getId())[0]['slug']);
        $this->assertSame([], $this->areasOf($f4->getId()));
        $this->assertSame(['area' => 'Social Sciences', 'area_raw' => 'Social Sciences'], $this->columnsOf($f4->getId()));
        $this->assertSame('manual', $this->areasOf($f5->getId())[0]['method']);
        $this->assertSame(['area' => 'Digitado', 'area_raw' => null], $this->columnsOf($f5->getId()));
        $this->assertSame(['ciencias-humanas'], array_column($this->areasOf($f6->getId()), 'slug'));
        $this->assertSame(['area' => 'CIÊNCIAS HUMANAS', 'area_raw' => null], $this->columnsOf($f6->getId()));

        // De novo: mesmo resultado e nada mais para alterar nas colunas
        $again = $this->classifier->classifyAll('run-again');
        $this->assertSame(0, $again['rows_updated']);
        foreach (['families', 'classified', 'high', 'medium', 'low', 'level1', 'no_evidence', 'locked', 'visible_total', 'visible_classified', 'areas_written'] as $key) {
            $this->assertSame($real[$key], $again[$key], $key);
        }
        $this->assertSame(1, $this->conn->fetchOne('SELECT COUNT(*) FROM journal_areas WHERE journal_id = ?', [$f1->getId()]) + 0);
    }

    public function testClassifyAllHandlesSeveralBatchesAndChunksOfRowsAcrossFamilyBoundaries(): void
    {
        // 1.100 famílias de 3 linhas: o primeiro lote (1.000 famílias, 3.000 linhas) é lido em dois pedaços de linhas e o
        // limite do pedaço cai no meio de uma família; a evidência está só nas linhas-membro
        $families = 1100;
        $labels = [['Law', 'direito'], ['Sociology', 'sociologia'], ['Anthropology', 'antropologia-arqueologia']];

        for ($offset = 0; $offset < $families; $offset += 500) {
            $count = min(500, $families - $offset);
            $this->conn->executeStatement(
                'INSERT INTO qualis_journals (title) VALUES ' . implode(',', array_fill(0, $count, '(?)')),
                array_map(fn (int $i): string => sprintf('Lote %s cabeça %05d', $this->uniq, $offset + $i), range(0, $count - 1))
            );
        }
        $heads = array_map('intval', $this->conn->fetchFirstColumn(
            'SELECT id FROM qualis_journals WHERE title LIKE ? ORDER BY id',
            ["Lote {$this->uniq} cabeça %"]
        ));
        $this->assertCount($families, $heads);

        $members = [];
        $params = [];
        foreach ($heads as $index => $headId) {
            foreach ([1, 2] as $n) {
                $members[] = '(?, ?, ?)';
                array_push($params, sprintf('Lote %s membro %05d-%d', $this->uniq, $index, $n), $headId, 'issn');
            }
        }
        foreach (array_chunk($members, 500, true) as $chunk) {
            $this->conn->executeStatement(
                'INSERT INTO qualis_journals (title, canonical_journal_id, canonical_method) VALUES ' . implode(',', $chunk),
                array_slice($params, array_key_first($chunk) * 3, count($chunk) * 3)
            );
        }
        $memberIds = $this->conn->fetchAllAssociative(
            'SELECT id, canonical_journal_id FROM qualis_journals WHERE title LIKE ? ORDER BY id',
            ["Lote {$this->uniq} membro %"]
        );
        $this->assertCount($families * 2, $memberIds);

        $headIndex = array_flip($heads);
        $expectedSlug = [];
        foreach ($memberIds as $position => $row) {
            $head = (int) $row['canonical_journal_id'];
            $index = $headIndex[$head];
            [$label, $slug] = $labels[$index % 3];
            $expectedSlug[$head] = $slug;
            // só o primeiro membro de cada família traz a evidência; o outro fica sem
            if ($position % 2 === 0) {
                $this->insertEvidence((int) $row['id'], 'wos', 'W' . $index, $label);
            }
        }

        $summary = $this->classifier->classifyAll('run-lotes');
        $this->assertGreaterThanOrEqual($families, $summary['classified']);

        // a segunda execução refaz as linhas automáticas de todos os pedaços: sem duplicar e com o run id novo
        $this->classifier->classifyAll('run-lotes-2');

        $wrong = [];
        $rows = $this->conn->fetchAllAssociative(
            "SELECT q.id, COALESCE(q.canonical_journal_id, q.id) AS family, q.area, c.slug, ja.is_primary, ja.run_id
             FROM qualis_journals q
             LEFT JOIN journal_areas ja ON ja.journal_id = q.id
             LEFT JOIN capes_areas c ON c.id = ja.area_id
             WHERE q.title LIKE ?",
            ["Lote {$this->uniq} %"]
        );
        $this->assertCount($families * 3, $rows, 'cada linha tem exatamente uma área (a principal)');
        foreach ($rows as $row) {
            if ($row['slug'] !== $expectedSlug[(int) $row['family']] || (int) $row['is_primary'] !== 1 || $row['run_id'] !== 'run-lotes-2' || $row['area'] === null) {
                $wrong[] = $row['id'];
            }
        }
        $this->assertSame([], $wrong);
    }

    public function testDryRunWritesNothing(): void
    {
        $journal = $this->createJournal("Revista Simulada {$this->uniq}", 'Texto original');
        $other = $this->createJournal("Revista Pubmed Simulada {$this->uniq}");
        $this->linkPubmed($other);
        $this->insertEvidence($journal->getId(), 'wos', 'W1', 'Education & Educational Research');
        $this->insertAutoArea($other->getId(), 404);
        $before = $this->snapshot();

        $summary = $this->classifier->classifyAll('run-dry', null, true);
        $family = $this->classifier->classifyFamily($journal->getId(), 'run-dry', true);

        $this->assertGreaterThanOrEqual(2, $summary['classified']);
        $this->assertGreaterThanOrEqual(2, $summary['rows_updated']);
        $this->assertTrue($family['dry_run']);
        $this->assertSame('educacao', $family['primary']['slug']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->areasOf($journal->getId()));
        $this->assertSame(['area' => 'Texto original', 'area_raw' => null], $this->columnsOf($journal->getId()));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Comando
    // ------------------------------------------------------------------------------------------------------------

    public function testCommandJournalDryRunShowsTheDecisionAndWritesNothing(): void
    {
        $journal = $this->createJournal("Revista Comando {$this->uniq}", 'Texto original');
        $this->insertEvidence($journal->getId(), 'wos', 'W1', 'Education & Educational Research');

        $tester = $this->commandTester();
        $status = $tester->execute(['--journal' => (string) $journal->getId(), '--dry-run' => true, '--run-id' => 'cmd-dry']);
        $output = $tester->getDisplay();

        $this->assertSame(0, $status);
        $this->assertStringContainsString(self::LABEL_EDUCACAO, $output);
        $this->assertStringContainsString('principal', $output);
        $this->assertStringContainsString('cmd-dry', $output);
        $this->assertStringContainsString('--dry-run', $output);
        $this->assertSame([], $this->areasOf($journal->getId()));
        $this->assertSame('Texto original', $this->columnsOf($journal->getId())['area']);
    }

    public function testCommandJournalWritesTheFamily(): void
    {
        $head = $this->createJournal("Revista Comando Real {$this->uniq}", 'Texto original');
        $member = $this->createJournal("Revista Comando Real Online {$this->uniq}", null, null, $head);
        $this->insertEvidence($head->getId(), 'wos', 'W1', 'Law');

        $tester = $this->commandTester();
        $status = $tester->execute(['--journal' => (string) $member->getId(), '--run-id' => 'cmd-real']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('classificada', $tester->getDisplay());
        $this->assertSame(['direito'], array_column($this->areasOf($head->getId()), 'slug'));
        $this->assertSame('cmd-real', $this->areasOf($member->getId())[0]['run_id']);
    }

    public function testCommandJournalReportsALockedFamily(): void
    {
        $head = $this->createJournal("Revista Comando Travada {$this->uniq}", 'Texto', null, null, true);
        $this->insertEvidence($head->getId(), 'wos', 'W1', 'Law');

        $tester = $this->commandTester();
        $status = $tester->execute(['--journal' => (string) $head->getId()]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('areas_locked=1', $tester->getDisplay());
        $this->assertSame([], $this->areasOf($head->getId()));
    }

    public function testCommandJournalWithoutEvidenceExplainsThatThereIsNoArea(): void
    {
        $journal = $this->createJournal("Revista Comando Vazia {$this->uniq}");

        $tester = $this->commandTester();
        $status = $tester->execute(['--journal' => (string) $journal->getId(), '--dry-run' => true]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Sem evidência mapeada', $tester->getDisplay());
    }

    public function testCommandRejectsInvalidOptions(): void
    {
        $tester = $this->commandTester();

        $this->assertSame(1, $tester->execute(['--journal' => 'abc']));
        $this->assertStringContainsString('--journal', $tester->getDisplay());

        $this->assertSame(1, $tester->execute(['--journal' => '2147483000']));
        $this->assertStringContainsString('não existe', $tester->getDisplay());

        $this->assertSame(1, $tester->execute(['--run-id' => str_repeat('x', 41)]));
        $this->assertStringContainsString('--run-id', $tester->getDisplay());
    }

    public function testCommandAllFamiliesDryRunPrintsTheSummaryAndWritesNothing(): void
    {
        $journal = $this->createJournal("Revista Comando Todas {$this->uniq}", 'Texto original');
        $this->insertEvidence($journal->getId(), 'wos', 'W1', 'Education & Educational Research');
        $before = $this->snapshot();

        $tester = $this->commandTester();
        $status = $tester->execute(['--dry-run' => true]);
        $output = $tester->getDisplay();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Famílias do cadastro', $output);
        $this->assertStringContainsString('que seriam gravadas', $output);
        $this->assertStringContainsString('Modo simulação', $output);
        $this->assertStringContainsString('Pico de memória', $output);
        $this->assertSame($before, $this->snapshot());
    }

    // ------------------------------------------------------------------------------------------------------------
    // Auxiliares
    // ------------------------------------------------------------------------------------------------------------

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:journals:classify'));
    }

    private function createJournal(string $title, ?string $area = null, ?string $areaRaw = null, ?QualisJournal $canonical = null, bool $locked = false): QualisJournal
    {
        $journal = (new QualisJournal())->setTitle($title)->setArea($area)->setAreaRaw($areaRaw)->setAreasLocked($locked);
        if ($canonical !== null) {
            $journal->setCanonical($canonical)->setCanonicalMethod('issn');
        }
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    private function insertEvidence(int $journalId, string $source, string $recordKey, string $label, string $weight = '1.0000', ?string $payload = null): void
    {
        $this->conn->executeStatement(
            'INSERT INTO journal_area_evidence (journal_id, source, record_key, label, weight, payload, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$journalId, $source, $recordKey, $label, $weight, $payload]
        );
    }

    private function insertAutoArea(int $journalId, int $areaId): void
    {
        $this->conn->executeStatement(
            "INSERT INTO journal_areas (journal_id, area_id, is_primary, score, confidence, method, run_id, updated_at) VALUES (?, ?, 1, 0.5000, 'low', 'auto', 'antiga', NOW())",
            [$journalId, $areaId]
        );
    }

    private function insertManualArea(int $journalId, int $areaId): void
    {
        $this->conn->executeStatement(
            "INSERT INTO journal_areas (journal_id, area_id, is_primary, score, confidence, method, run_id, updated_at) VALUES (?, ?, ?, 1.0000, 'manual', 'manual', NULL, NOW())",
            [$journalId, $areaId, 1]
        );
    }

    private function linkPubmed(QualisJournal $journal): void
    {
        $base = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => 'pubmed']);
        if ($base === null) {
            $base = (new AcademicDatabase())->setName('PubMed')->setAcronym('pubmed');
            $this->em->persist($base);
        }
        $journal->addAcademicDatabase($base);
        $this->em->flush();
    }

    private function createResearcher(): Researcher
    {
        $researcher = (new Researcher())
            ->setIdLattes('98' . str_pad((string) random_int(0, 99999999999999), 14, '0', STR_PAD_LEFT))
            ->setFullName('Pesquisador Classificador ' . $this->uniq);
        $this->em->persist($researcher);
        $this->em->flush();

        return $researcher;
    }

    private function createArticle(Researcher $researcher, QualisJournal $journal): void
    {
        $item = (new ProductionItem())
            ->setResearcher($researcher)
            ->setItemType(ProductionItem::TYPE_ARTIGO)
            ->setTitle('Artigo de teste ' . $this->uniq . ' ' . random_int(1, 999999))
            ->setYear(2024)
            ->setQualisJournal($journal);
        $this->em->persist($item);
        $this->em->flush();
    }

    /**
     * @return list<array{slug: string, primary: int, score: string, confidence: string, method: string, run_id: ?string}>
     */
    private function areasOf(int $journalId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT c.slug, ja.is_primary, ja.score, ja.confidence, ja.method, ja.run_id
             FROM journal_areas ja JOIN capes_areas c ON c.id = ja.area_id
             WHERE ja.journal_id = ? ORDER BY ja.is_primary DESC, c.slug',
            [$journalId]
        );

        return array_map(static fn (array $row): array => [
            'slug' => (string) $row['slug'],
            'primary' => (int) $row['is_primary'],
            'score' => (string) $row['score'],
            'confidence' => (string) $row['confidence'],
            'method' => (string) $row['method'],
            'run_id' => $row['run_id'] === null ? null : (string) $row['run_id'],
        ], $rows);
    }

    /**
     * @return array{area: ?string, area_raw: ?string}
     */
    private function columnsOf(int $journalId): array
    {
        $row = $this->conn->fetchAssociative('SELECT area, area_raw FROM qualis_journals WHERE id = ?', [$journalId]);
        $this->assertIsArray($row);

        return ['area' => $row['area'], 'area_raw' => $row['area_raw']];
    }

    /**
     * Retrato do banco de testes inteiro: linhas de journal_areas e o conteúdo de area/area_raw.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'areas' => $this->conn->fetchOne('SELECT COUNT(*) FROM journal_areas'),
            'areas_checksum' => $this->conn->fetchOne("SELECT COALESCE(SUM(CRC32(CONCAT(journal_id, ':', area_id, ':', is_primary, ':', method, ':', COALESCE(run_id, '')))), 0) FROM journal_areas"),
            'columns' => $this->conn->fetchAssociative(
                "SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(COALESCE(area, ''))), 0) AS a, COALESCE(SUM(CRC32(COALESCE(area_raw, ''))), 0) AS r FROM qualis_journals"
            ),
        ];
    }
}
