<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Entity\AcademicDatabase;
use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Service\Journal\DisplayTitle;
use App\Service\Journal\Issn;
use App\Service\Journal\JournalEvidenceArtifactBuilder;
use App\Service\Journal\JournalFamilyBuilder;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Famílias de periódicos e nome exibido (docs/evolutions/periodicos-por-base.md, A4).
 *
 * Os testes de `plan()` são puros (sem banco) e cobrem a regra com os casos medidos nos dados reais. Os de `build()` rodam
 * em `cech_test` dentro de uma transação revertida no tearDown: nada fica gravado e as outras linhas do banco de teste
 * só entram nas contagens globais, nunca nas afirmações sobre as linhas criadas aqui.
 */
class JournalFamilyBuilderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private JournalFamilyBuilder $builder;
    /** @var list<string> */
    private array $files = [];
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->builder = $container->get(JournalFamilyBuilder::class);

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
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ($this->dirs as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------------------
    // plan(): regra pura, com os casos medidos nos dados reais
    // ------------------------------------------------------------------------------------------------------------

    public function testRowsWithTheSameIssnAreOneFamilyAndTheNameComesFromTheExternalSource(): void
    {
        // Linha 23639: ISSN certo, título errado (os 19 artigos são da RBCS). Outra linha traz os dois ISSNs da RBCS.
        $plan = $this->plan(
            [
                23639 => self::row('REVISTA BRASILEIRA DE HISTÓRIA & CIÊNCIAS SOCIAIS', ['0102-6909']),
                24000 => self::row('Revista Brasileira de Ciências Sociais', ['0102-6909', '1806-9053']),
            ],
            [
                self::record('scopus', ['0102-6909', '1806-9053'], 'Revista Brasileira de Ciencias Sociais'),
                self::record('scielo', ['0102-6909', '1806-9053'], 'Revista Brasileira de Ciências Sociais'),
            ],
            [23639 => self::keys(19), 24000 => self::keys(3, 'b')]
        );

        $this->assertSame(23639, $plan['heads'][23639]);
        $this->assertSame(23639, $plan['heads'][24000]);
        $this->assertSame([null, null], $plan['assign'][23639]);
        $this->assertSame([23639, 'issn'], $plan['assign'][24000]);

        $expected = ['title' => 'Revista Brasileira de Ciências Sociais', 'source' => 'scielo'];
        $this->assertSame($expected, $plan['titles'][23639]);
        $this->assertSame($expected, $plan['titles'][24000]);
        $this->assertSame(1, $plan['report']['families']);
        $this->assertSame(1, $plan['report']['multi']);
        $this->assertSame(2, $plan['report']['largest']);
    }

    public function testRowWithWrongTitleGetsTheNameOfItsIssnInTheFirstSourceThatHasIt(): void
    {
        // "ILHA - REVISTA DE ANTROPOLOGIA" tem os ISSNs da Revista de Antropologia (USP)
        $plan = $this->plan(
            [23600 => self::row('ILHA - REVISTA DE ANTROPOLOGIA', ['0034-7701', '1678-9857'])],
            [
                self::record('wos', ['0034-7701', '1678-9857'], 'REVISTA DE ANTROPOLOGIA-USP'),
                self::record('doaj', ['0034-7701', '1678-9857'], 'Revista de Antropologia'),
            ]
        );

        $this->assertSame(['title' => 'Revista de Antropologia', 'source' => 'doaj'], $plan['titles'][23600]);
    }

    public function testNameSourcesFollowTheDocumentOrderEvenWhenTheArtifactListsThemInAnotherOrder(): void
    {
        $issn = self::issn(9000011);
        $plan = $this->plan(
            [1 => self::row('LINHA', [$issn])],
            [
                self::record('openalex', [$issn], 'Do OpenAlex'),
                self::record('scopus', [$issn], 'Do Scopus'),
                self::record('latindex', [$issn], 'Do Latindex'),
            ]
        );

        $this->assertSame(['title' => 'Do Latindex', 'source' => 'latindex'], $plan['titles'][1]);
    }

    public function testParenthesesAreNeverRemovedSoSameNameRowsWithoutIssnStaySeparate(): void
    {
        $plan = $this->plan([
            1 => self::row('Estudos de Psicologia (Campinas)'),
            2 => self::row('Estudos de Psicologia (Natal)'),
        ]);

        $this->assertSame([null, null], $plan['assign'][1]);
        $this->assertSame([null, null], $plan['assign'][2]);
        $this->assertNotSame($plan['heads'][1], $plan['heads'][2]);
        $this->assertSame(2, $plan['report']['families']);
        $this->assertSame(0, $plan['report']['multi']);
        $this->assertSame(0, $plan['report']['joined_by_title']);
        $this->assertSame([], $plan['report']['ambiguous_titles']);
    }

    public function testRowWithoutIssnJoinsOnlyTheRowWithTheSameExactTitleIncludingParentheses(): void
    {
        $campinas = self::issn(9000101);
        $natal = self::issn(9000102);

        $plan = $this->plan([
            1 => self::row('ESTUDOS DE PSICOLOGIA (CAMPINAS)', [$campinas]),
            2 => self::row('ESTUDOS DE PSICOLOGIA (NATAL)', [$natal]),
            3 => self::row('Estudos de Psicologia (Campinas)'),
            4 => self::row('Estudos de Psicologia (Natal)'),
        ]);

        $this->assertSame(1, $plan['heads'][3]);
        $this->assertSame(2, $plan['heads'][4]);
        $this->assertSame([1, 'title'], $plan['assign'][3]);
        $this->assertSame([2, 'title'], $plan['assign'][4]);
        $this->assertSame(2, $plan['report']['joined_by_title']);
        $this->assertSame(2, $plan['report']['families']);
    }

    public function testIssueBriefVariantsDoNotFormAFamily(): void
    {
        // O dossiê juntava "Issue brief (…)" removendo os parênteses e criava uma família falsa
        $plan = $this->plan([
            1 => self::row('Issue brief (A)'),
            2 => self::row('Issue brief (B)'),
            3 => self::row('Issue brief (C)'),
            4 => self::row('Issue brief', [self::issn(9000103)]),
        ]);

        $this->assertSame(4, $plan['report']['families']);
        $this->assertSame(0, $plan['report']['multi']);
        $this->assertSame(1, $plan['report']['largest']);
        $this->assertSame(0, $plan['report']['joined_by_title']);
        foreach ([1, 2, 3, 4] as $id) {
            $this->assertSame($id, $plan['heads'][$id]);
        }
    }

    public function testRowWithoutIssnWhoseTitlePointsToTwoIssnGroupsStaysIsolatedAndIsReported(): void
    {
        $a = self::issn(9000111);
        $b = self::issn(9000112);

        $plan = $this->plan([
            1 => self::row('Cadernos de Pesquisa', [$a]),
            2 => self::row('CADERNOS DE PESQUISA', [$b]),
            3 => self::row('Cadernos de Pesquisa'),
            4 => self::row('Cadernos de Pesquisa.'),
            5 => self::row('Um título que ninguém mais tem'),
        ]);

        $this->assertSame([null, null], $plan['assign'][3]);
        $this->assertSame([null, null], $plan['assign'][4]);
        $this->assertSame(3, $plan['heads'][3]);
        $this->assertSame(4, $plan['heads'][4]);
        $this->assertSame(0, $plan['report']['joined_by_title']);
        $this->assertSame(5, $plan['report']['families']);

        $ambiguous = $plan['report']['ambiguous_titles'];
        $this->assertCount(1, $ambiguous);
        $this->assertSame('CADERNOS DE PESQUISA', $ambiguous[0]['title']);
        $this->assertSame([3, 4], $ambiguous[0]['ids']);
        $this->assertSame(
            [['id' => 1, 'title' => 'Cadernos de Pesquisa'], ['id' => 2, 'title' => 'CADERNOS DE PESQUISA']],
            $ambiguous[0]['families']
        );
    }

    public function testArtifactRecordTitlePointingToTwoGroupsIsAlsoAmbiguous(): void
    {
        $c = self::issn(9000121);
        $d = self::issn(9000122);

        $plan = $this->plan(
            [
                6 => self::row('Revista Alfa'),
                7 => self::row('Alfa Impressa', [$c]),
                8 => self::row('Alfa de Outro País', [$d]),
            ],
            [
                self::record('doaj', [$c], 'Revista Alfa'),
                self::record('scopus', [$d], 'Revista Alfa'),
            ]
        );

        $this->assertSame([null, null], $plan['assign'][6]);
        $this->assertCount(1, $plan['report']['ambiguous_titles']);
        $this->assertSame([6], $plan['report']['ambiguous_titles'][0]['ids']);
        $this->assertSame(0, $plan['report']['joined_by_title']);
    }

    public function testArtifactGroupsWithoutAnyCatalogRowDoNotCountForTheTitleMatch(): void
    {
        $e = self::issn(9000131);
        $f = self::issn(9000132);
        $g = self::issn(9000133);

        $plan = $this->plan(
            [
                9 => self::row('Revista Beta'),
                10 => self::row('Beta Impressa', [$g]),
            ],
            [
                self::record('doaj', [$e, $f], 'Revista Beta'),   // nenhum ISSN no cadastro: não aponta para linha alguma
                self::record('scopus', [$g], 'Revista Beta'),
            ]
        );

        $this->assertSame([10, 'title'], $plan['assign'][9]);
        $this->assertSame([], $plan['report']['ambiguous_titles']);
    }

    public function testRowWithoutIssnJoinsTheGroupOfAnArtifactRecordWithTheSameTitle(): void
    {
        // Paidéia: a linha do SciELO não tem ISSN e o título dela só bate com o do registro do artefato
        $print = self::issn(9000141);
        $online = self::issn(9000142);

        $plan = $this->plan(
            [
                11322 => self::row('PAIDÉIA (USP. RIBEIRAO PRETO. IMPRESSO)', [$print]),
                65044 => self::row('Paidéia (Ribeirão Preto)'),
            ],
            [self::record('scielo', [$print, $online], 'Paidéia (Ribeirão Preto)')],
            [11322 => self::keys(40)]
        );

        $this->assertSame([null, null], $plan['assign'][11322]);
        $this->assertSame([11322, 'title'], $plan['assign'][65044]);
        $this->assertSame(['title' => 'Paidéia (Ribeirão Preto)', 'source' => 'scielo'], $plan['titles'][11322]);
        $this->assertSame(['title' => 'Paidéia (Ribeirão Preto)', 'source' => 'scielo'], $plan['titles'][65044]);
        $this->assertSame(1, $plan['report']['joined_by_title']);
    }

    public function testArtifactRecordsWithMoreThanThreeIssnsDoNotUniteNorLendTheirTitle(): void
    {
        [$a, $b, $c, $d] = [self::issn(9000151), self::issn(9000152), self::issn(9000153), self::issn(9000154)];

        $plan = $this->plan(
            [
                1 => self::row('Linha A', [$a]),
                2 => self::row('Linha B', [$b]),
                3 => self::row('Linha C', [$c]),
                4 => self::row('Linha D', [$d]),
            ],
            [
                self::record('doaj', [$a, $b, $c], 'Nome certo'),               // 3 ISSNs: une 1, 2 e 3
                self::record('scielo', [$a, $b, $c, $d], 'Nome do pacote'),      // 4 ISSNs: ignorado, mesmo sendo a fonte mais forte
            ]
        );

        $this->assertSame($plan['heads'][1], $plan['heads'][2]);
        $this->assertSame($plan['heads'][1], $plan['heads'][3]);
        $this->assertSame(4, $plan['heads'][4]);
        $this->assertSame(2, $plan['report']['families']);
        $this->assertSame(['title' => 'Nome certo', 'source' => 'doaj'], $plan['titles'][1]);
        $this->assertSame(['title' => 'Linha D', 'source' => 'title'], $plan['titles'][4]);

        $artifact = $plan['report']['artifact'];
        $this->assertSame(2, $artifact['records']);
        $this->assertSame(1, $artifact['used']);
        $this->assertSame(1, $artifact['skipped_many_issns']);
    }

    public function testArtifactRecordWithTwoOrThreeIssnsBridgesRowsThatHaveOnlyOneOfThem(): void
    {
        // Impresso numa linha, online na outra: só o par declarado pela fonte une as duas
        [$print, $online] = [self::issn(9000161), self::issn(9000162)];

        $without = $this->plan([1 => self::row('Revista Par', [$print]), 2 => self::row('Revista Par (online)', [$online])]);
        $this->assertSame(2, $without['report']['families']);

        $with = $this->plan(
            [1 => self::row('Revista Par', [$print]), 2 => self::row('Revista Par (online)', [$online])],
            [self::record('openalex', [$print, $online], 'Revista Par')]
        );
        $this->assertSame(1, $with['report']['families']);
        $this->assertSame([1, 'issn'], [$with['heads'][2], $with['assign'][2][1]]);
    }

    public function testRowsWithoutIssnAndWithoutArtifactStillGroupByTheCatalogIssns(): void
    {
        [$a, $b, $c, $d] = [self::issn(9000171), self::issn(9000172), self::issn(9000173), self::issn(9000174)];

        $plan = $this->plan([
            1 => self::row('Um', [$a, $b]),
            2 => self::row('Dois', [$b, $c]),
            3 => self::row('Três', [$d]),
        ]);

        $this->assertSame(1, $plan['heads'][2]);
        $this->assertSame(3, $plan['heads'][3]);
        $this->assertSame(2, $plan['report']['families']);
        $this->assertSame(1, $plan['report']['multi']);
    }

    public function testCanonicalIsTheRowWithMostArticlesThenMostIssnsThenLowestId(): void
    {
        [$a, $b, $c] = [self::issn(9000181), self::issn(9000182), self::issn(9000183)];
        $rows = [
            10 => self::row('Dez', [$a]),
            11 => self::row('Onze', [$a, $b]),
            12 => self::row('Doze', [$a]),
        ];

        $this->assertSame(11, $this->plan($rows)['heads'][10], 'mais ISSNs');
        $this->assertSame(12, $this->plan($rows, [], [12 => self::keys(1)])['heads'][10], 'mais artigos vence mais ISSNs');
        $this->assertSame(10, $this->plan([
            10 => self::row('Dez', [$c]),
            11 => self::row('Onze', [$c]),
        ])['heads'][11], 'empate: menor id');
    }

    public function testManualRowsAreNeverChangedAndTheFamilyUsesTheManualCanonical(): void
    {
        [$x, $y] = [self::issn(9000191), self::issn(9000192)];

        $plan = $this->plan(
            [
                1 => self::row('Cabeça manual', [$x], ['method' => 'manual', 'displayTitle' => 'Nome manual', 'displayTitleSource' => 'manual']),
                2 => self::row('Mais artigos', [$x]),
                3 => self::row('Membro manual sem ISSN', [], ['method' => 'manual', 'canonical' => 1]),
                4 => self::row('Membro manual com outro ISSN', [$y], ['method' => 'manual', 'canonical' => 1]),
                5 => self::row('Mais artigos'),
            ],
            [],
            [2 => self::keys(5)]
        );

        // As manuais não aparecem em assign; as demais seguem a canônica manual, mesmo a linha 2 tendo mais artigos
        $this->assertSame([2, 5], array_keys($plan['assign']));
        $this->assertSame([1, 'issn'], $plan['assign'][2]);
        $this->assertSame([1, 'title'], $plan['assign'][5]);
        $this->assertSame([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1], $plan['heads']);
        $this->assertSame(1, $plan['report']['families']);
        $this->assertSame(5, $plan['report']['largest']);
        $this->assertSame(3, $plan['report']['manual_rows']);
        $this->assertSame(0, $plan['report']['manual_conflicts']);

        // Nome exibido: a linha com source manual não recebe nada; as outras recebem o da família
        $this->assertSame([2, 3, 4, 5], array_keys($plan['titles']));
    }

    public function testManualFamilyIsUnitedEvenWhenNoSourceLinksItsIssns(): void
    {
        [$x, $y] = [self::issn(9000201), self::issn(9000202)];

        $plan = $this->plan([
            1 => self::row('Cabeça', [$x], ['method' => 'manual']),
            2 => self::row('Membro', [$y], ['method' => 'manual', 'canonical' => 1]),
            3 => self::row('Livre com o ISSN do membro', [$y]),
        ]);

        $this->assertSame([1, 'issn'], $plan['assign'][3]);
        $this->assertSame(1, $plan['report']['families']);
    }

    public function testGroupWithTwoManualCanonicalsKeepsBothAndReportsTheConflict(): void
    {
        [$x, $y] = [self::issn(9000211), self::issn(9000212)];

        $plan = $this->plan(
            [
                1 => self::row('Manual um', [$x], ['method' => 'manual']),
                2 => self::row('Manual dois', [$y], ['method' => 'manual']),
                3 => self::row('Livre', [$x]),
            ],
            [self::record('doaj', [$x, $y], 'Nome')],
            [2 => self::keys(4)]
        );

        $this->assertSame(1, $plan['report']['manual_conflicts']);
        $this->assertArrayNotHasKey(1, $plan['assign']);
        $this->assertArrayNotHasKey(2, $plan['assign']);
        $this->assertSame([2, 'issn'], $plan['assign'][3], 'segue a melhor canônica manual (mais artigos)');
        $this->assertSame(1, $plan['heads'][1]);
        $this->assertSame(2, $plan['heads'][2]);
    }

    public function testRowsThatAreHeadsOfManualMembersAreProtectedEvenWhenNotMarkedManual(): void
    {
        $x = self::issn(9000221);

        // A linha 2 é membro manual sem ISSN da linha 1; a linha 3 tem o ISSN da 1 e mais artigos
        $plan = $this->plan(
            [
                1 => self::row('Cabeça sem marca', [$x]),
                2 => self::row('Membro manual', [], ['method' => 'manual', 'canonical' => 1]),
                3 => self::row('Livre com mais artigos', [$x]),
            ],
            [],
            [3 => self::keys(5)]
        );

        $this->assertArrayNotHasKey(1, $plan['assign']);
        $this->assertArrayNotHasKey(2, $plan['assign']);
        $this->assertSame([1, 'issn'], $plan['assign'][3]);
        $this->assertSame([1 => 1, 2 => 1, 3 => 1], $plan['heads']);
    }

    public function testExistingNonManualPointersAreRecomputedFromScratch(): void
    {
        [$a, $b] = [self::issn(9000231), self::issn(9000232)];

        $plan = $this->plan([
            1 => self::row('Um', [$a], ['canonical' => 2, 'method' => 'issn']),   // ponteiro velho: virou isolada
            2 => self::row('Dois', [$b], ['canonical' => 1, 'method' => 'title']),
        ]);

        $this->assertSame([null, null], $plan['assign'][1]);
        $this->assertSame([null, null], $plan['assign'][2]);
    }

    public function testLattesSpellingsAreSummedAcrossTheRowsOfTheFamily(): void
    {
        $x = self::issn(9000241);

        $plan = $this->plan(
            [
                1 => self::row('LINHA UM', [$x]),
                2 => self::row('LINHA DOIS', [$x]),
                3 => self::row('LINHA SEM LATTES'),
                4 => self::row('LINHA COM UMA GRAFIA SÓ'),
            ],
            [],
            [],
            [1 => ['Nome do Lattes' => 1], 2 => ['Nome do Lattes' => 1], 4 => ['Grafia única' => 1]]
        );

        $this->assertSame(['title' => 'Nome do Lattes', 'source' => 'lattes'], $plan['titles'][1]);
        $this->assertSame(['title' => 'Nome do Lattes', 'source' => 'lattes'], $plan['titles'][2]);
        $this->assertSame(['title' => 'Linha Sem Lattes', 'source' => 'title'], $plan['titles'][3]);
        $this->assertSame(['title' => 'Linha com Uma Grafia Só', 'source' => 'title'], $plan['titles'][4]);
    }

    public function testDisplayTitleKeepsMixedCaseRowTitleAndCutsAtFiveHundredCharacters(): void
    {
        $long = str_repeat('Palavra ', 80);
        $plan = $this->plan([
            1 => self::row('Journal of Education for Teaching'),
            2 => self::row($long, [], ['normalizedTitle' => 'LONGO']),
        ]);

        $this->assertSame('Journal of Education for Teaching', $plan['titles'][1]['title']);
        $this->assertSame(500, mb_strlen($plan['titles'][2]['title']));
    }

    public function testArticlesByBaseCountDistinctArticlesAndItemsByRowAndByFamily(): void
    {
        [$x, $y] = [self::issn(9000251), self::issn(9000252)];

        $plan = $this->plan(
            [
                1 => self::row('Linha ligada à base', [$x]),
                2 => self::row('Linha sem ligação', [$y]),
                3 => self::row('Linha de outra família'),
            ],
            [self::record('doaj', [$x, $y], 'Nome')],
            [
                1 => ['DOI:d1', 'DOI:d2'],
                2 => ['DOI:d2', 'DOI:d3'],   // d2 repetido: coautor com a mesma revista vinculada a outra linha
                3 => ['DOI:z1', 'DOI:z1'],
            ],
            [],
            [1 => ['scielo'], 3 => ['scielo', 'wos']]
        );

        $byDb = $plan['report']['by_db'];
        $this->assertSame(['scopus', 'pubmed', 'scielo', 'wos', 'latindex'], array_keys($byDb));
        $this->assertSame(['row' => 2 + 1, 'family' => 3 + 1, 'row_items' => 2 + 2, 'family_items' => 4 + 2], $byDb['scielo']);
        $this->assertSame(['row' => 1, 'family' => 1, 'row_items' => 2, 'family_items' => 2], $byDb['wos']);
        $this->assertSame(['row' => 0, 'family' => 0, 'row_items' => 0, 'family_items' => 0], $byDb['scopus']);
    }

    public function testPlanIsIdempotentWhenFedWithItsOwnResult(): void
    {
        [$a, $b, $c, $d] = [self::issn(9000271), self::issn(9000272), self::issn(9000273), self::issn(9000274)];
        $rows = [
            1 => self::row('Um', [$a]),
            2 => self::row('Dois', [$b]),
            3 => self::row('Um'),
            4 => self::row('Manual', [$c], ['method' => 'manual', 'displayTitle' => 'Nome manual', 'displayTitleSource' => 'manual']),
            5 => self::row('Livre', [$c]),
            6 => self::row('Sem família', [$d]),
        ];
        $records = [self::record('doaj', [$a, $b], 'Nome Um e Dois')];
        $articles = [2 => self::keys(3)];

        $first = $this->plan($rows, $records, $articles);
        foreach ($first['assign'] as $id => [$canonical, $method]) {
            $rows[$id]['canonical'] = $canonical;
            $rows[$id]['method'] = $method;
        }
        foreach ($first['titles'] as $id => $chosen) {
            $rows[$id]['displayTitle'] = $chosen['title'];
            $rows[$id]['displayTitleSource'] = $chosen['source'];
        }
        $second = $this->plan($rows, $records, $articles);

        $this->assertSame($first['assign'], $second['assign']);
        $this->assertSame($first['heads'], $second['heads']);
        $this->assertSame($first['titles'], $second['titles']);
        $this->assertSame([2, 'issn'], $second['assign'][1]);
        $this->assertSame([2, 'title'], $second['assign'][3]);
        $this->assertArrayNotHasKey(4, $second['assign'], 'a linha manual continua fora do que o algoritmo altera');
    }

    public function testPlanIsDeterministicWhateverTheOrderOfTheRows(): void
    {
        [$a, $b, $c] = [self::issn(9000261), self::issn(9000262), self::issn(9000263)];
        $rows = [
            3 => self::row('Três', [$c]),
            1 => self::row('Um', [$a, $b]),
            2 => self::row('Dois', [$b]),
            4 => self::row('Um'),
        ];
        $records = [self::record('doaj', [$a, $c], 'Nome')];

        $first = $this->plan($rows, $records);
        krsort($rows);
        $second = $this->plan($rows, $records);

        $this->assertSame($first['heads'], $second['heads']);
        $this->assertSame($first['assign'], $second['assign']);
        $this->assertSame($first['titles'], $second['titles']);
        $this->assertSame($first['report'], $second['report']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Linhas em conflito: uma linha do cadastro não funde revistas diferentes
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider titleScoreProvider
     */
    public function testTitleScoreBetweenTwoTitles(string $a, string $b, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, JournalFamilyBuilder::titleScore($a, $b), 0.001);
        $this->assertEqualsWithDelta($expected, JournalFamilyBuilder::titleScore($b, $a), 0.001, 'simétrica');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: float}>
     */
    public static function titleScoreProvider(): array
    {
        return [
            'chave compacta igual (conectivos e pontuação não contam)' => ['Educação & Sociedade', 'EDUCAÇÃO E SOCIEDADE', 1.0],
            'só muda a caixa e o acento' => ['Revista Brasileira de Ciencias Sociais', 'Revista Brasileira de Ciências Sociais', 1.0],
            'uma contida na outra, 2 palavras ou mais' => ['Revista Estudos Feministas', 'Estudos Feministas', JournalFamilyBuilder::CONTAINMENT_SCORE],
            'contida no meio do título' => ['Materials Science', 'Materials Science-Poland', JournalFamilyBuilder::CONTAINMENT_SCORE],
            'o qualificador entre parênteses não conta' => ['Paidéia (Ribeirão Preto)', 'Paideia', 1.0],
            'nem quando é uma palavra a mais do cadastro' => ['PERSPECTIVA (FLORIANÓPOLIS)', 'Perspectiva', 1.0],
            'qualificador sem parênteses vira palavra (contido, 2 palavras ou mais)' => ['Educação e Pesquisa', 'Educação e Pesquisa Impresso', JournalFamilyBuilder::CONTAINMENT_SCORE],
            'uma palavra igual' => ['Perspectiva', 'PERSPECTIVA', 1.0],
            'uma palavra contra duas não basta' => ['Educação', 'Educação em Revista', 0.5],
            'similaridade de palavras sem contenção' => [
                'Journal of Applied Research in Mental Health Nursing',
                'Journal of Applied Research in Mental Health Care',
                0.75,
            ],
            'revistas diferentes' => ['Revista Eletrônica Acervo Saúde', 'Water Resources and Industry', 0.0],
            'título vazio' => ['', 'Perspectiva', 0.0],
        ];
    }

    public function testTitlesAreCompatibleFromTheThresholdUp(): void
    {
        $this->assertTrue(JournalFamilyBuilder::titlesCompatible('Educação & Sociedade', 'Educação e Sociedade'));
        $this->assertTrue(JournalFamilyBuilder::titlesCompatible('Materials Science', 'Materials Science-Poland'));
        $this->assertTrue(JournalFamilyBuilder::titlesCompatible('Journal of Applied Research in Mental Health Nursing', 'Journal of Applied Research in Mental Health Care'));
        $this->assertFalse(JournalFamilyBuilder::titlesCompatible('Educação', 'Educação em Revista'));
        $this->assertFalse(JournalFamilyBuilder::titlesCompatible('Scandia Journal of Medieval Norse Studies', 'Scandia'));
        // O limiar é um parâmetro: a contenção (0,9) e a similaridade 0,75 deixam de bastar acima deles
        $this->assertFalse(JournalFamilyBuilder::titlesCompatible('Materials Science', 'Materials Science-Poland', 0.95));
        $this->assertFalse(JournalFamilyBuilder::titlesCompatible('Journal of Applied Research in Mental Health Nursing', 'Journal of Applied Research in Mental Health Care', 0.8));
    }

    public function testRowsThatBridgeDifferentJournalsDoNotFuseFamily12965(): void
    {
        // Rev. Bras. de Pesquisa em Educação em Ciências, Acervo Saúde e Water Resources and Industry: as linhas 23598
        // ("Home") e 23615 ("Multidisciplinar") têm ISSNs de revistas diferentes e antes juntavam as três
        $plan = $this->plan(
            [
                12965 => self::row('REVISTA BRASILEIRA DE PESQUISA EM EDUCAÇÃO EM CIÊNCIAS', ['1806-5104']),
                12966 => self::row('REVISTA BRASILEIRA DE PESQUISA EM EDUCAÇÃO EM CIÊNCIAS', ['1984-2686']),
                13864 => self::row('REVISTA ELETRÔNICA ACERVO SAÚDE', ['2178-2091']),
                23598 => self::row('Home', ['2178-2091', '2212-3717']),
                23615 => self::row('Multidisciplinar', ['2178-2091', '1806-5104']),
                60436 => self::row('Water Resources and Industry', ['2212-3717']),
            ],
            [
                self::record('openalex', ['1806-5104', '1984-2686'], 'Revista Brasileira de Pesquisa em Educação em Ciências'),
                self::record('doaj', ['1984-2686'], 'Revista Brasileira de Pesquisa em Educação em Ciências'),
                self::record('openalex', ['2178-2091'], 'Revista Eletrônica Acervo Saúde'),
                self::record('openalex', ['2212-3717'], 'Water Resources and Industry'),
                self::record('scopus', ['2212-3717'], 'Water Resources and Industry'),
                self::record('wos', ['2212-3717'], 'WATER RESOURCES AND INDUSTRY'),
            ],
            [12965 => self::keys(6), 12966 => self::keys(5, 'b'), 13864 => self::keys(1, 'c')]
        );

        $this->assertSame($plan['heads'][12965], $plan['heads'][12966], 'impresso e online da mesma revista seguem juntos');
        $this->assertSame(12965, $plan['heads'][12966]);
        $this->assertSame(13864, $plan['heads'][13864]);
        $this->assertSame(60436, $plan['heads'][60436]);
        $this->assertSame(23598, $plan['heads'][23598]);
        $this->assertSame(23615, $plan['heads'][23615]);
        $this->assertSame([null, null], $plan['assign'][23598]);
        $this->assertSame([null, null], $plan['assign'][23615]);
        $this->assertSame(5, $plan['report']['families']);
        $this->assertSame(1, $plan['report']['multi']);
        $this->assertSame(2, $plan['report']['largest']);

        $conflicts = $plan['report']['conflict_rows'];
        $this->assertSame([23598, 23615], array_column($conflicts, 'id'));
        $this->assertSame(['title', 'title'], array_column($conflicts, 'reason'));
        $multidisciplinar = $conflicts[1];
        $this->assertSame('Multidisciplinar', $multidisciplinar['title']);
        $this->assertSame(['2178-2091', '1806-5104'], $multidisciplinar['issns']);
        $this->assertNull($multidisciplinar['destination']);
        $this->assertSame(0, $multidisciplinar['articles']);
        $this->assertLessThan(JournalFamilyBuilder::TITLE_SIMILARITY_THRESHOLD, $multidisciplinar['similarity']);
        $this->assertSame(
            [
                ['issns' => ['1806-5104'], 'title' => 'Revista Brasileira de Pesquisa em Educação em Ciências', 'countries' => [], 'family' => 12965],
                ['issns' => ['2178-2091'], 'title' => 'Revista Eletrônica Acervo Saúde', 'countries' => [], 'family' => 13864],
            ],
            $multidisciplinar['groups']
        );
    }

    public function testRowThatBridgesDifferentJournalsDoesNotFuseFamily10119(): void
    {
        // Materials Research e a revista de Sorocaba: a linha 23561 ("Arace") tem um ISSN de cada uma
        $plan = $this->plan(
            [
                10119 => self::row('MATERIALS RESEARCH (SÃO CARLOS. IMPRESSO)', ['1516-1439', '1980-5373']),
                13224 => self::row('REVISTA DA FACULDADE DE CIENCIAS MEDICAS DE SOROCABA', ['1984-4840']),
                23561 => self::row('Arace', ['1984-4840', '1980-5373']),
                65034 => self::row('Materials Research'),
                82641 => self::row('MATERIALS RESEARCH-IBERO-AMERICAN JOURNAL OF MATERIALS'),
            ],
            [
                self::record('scielo', ['1516-1439', '1980-5373'], 'Materials Research'),
                self::record('wos', ['1516-1439', '1980-5373'], 'MATERIALS RESEARCH-IBERO-AMERICAN JOURNAL OF MATERIALS'),
                self::record('openalex', ['1517-8242', '1984-4840'], 'Revista da Faculdade de Ciências Médicas de Sorocaba'),
            ],
            [10119 => self::keys(8)]
        );

        $this->assertSame(10119, $plan['heads'][65034]);
        $this->assertSame(10119, $plan['heads'][82641]);
        $this->assertSame(13224, $plan['heads'][13224], 'a revista de Sorocaba fica fora');
        $this->assertSame(23561, $plan['heads'][23561]);
        $this->assertSame([10119, 'title'], $plan['assign'][65034]);
        $this->assertSame([10119, 'title'], $plan['assign'][82641]);
        $this->assertSame([23561], array_column($plan['report']['conflict_rows'], 'id'));
        $this->assertSame(3, $plan['report']['families']);
        $this->assertSame(3, $plan['report']['largest']);
    }

    public function testRowsWithRightIssnAndWrongTitleStayInTheirJournalFamily(): void
    {
        // 23639 (ISSN da RBCS, título errado) e 23600 (ISSNs da Revista de Antropologia): cada ISSN cai num grupo só
        $plan = $this->plan(
            [
                23639 => self::row('REVISTA BRASILEIRA DE HISTÓRIA & CIÊNCIAS SOCIAIS', ['0102-6909']),
                24000 => self::row('Revista Brasileira de Ciências Sociais', ['0102-6909', '1806-9053']),
                23600 => self::row('ILHA - REVISTA DE ANTROPOLOGIA', ['0034-7701', '1678-9857']),
                24001 => self::row('Revista de Antropologia', ['0034-7701']),
            ],
            [
                self::record('scielo', ['0102-6909', '1806-9053'], 'Revista Brasileira de Ciências Sociais'),
                self::record('scopus', ['0102-6909', '1806-9053'], 'Revista Brasileira de Ciencias Sociais'),
                self::record('scielo', ['0034-7701', '1678-9857'], 'Revista de Antropologia'),
                self::record('doaj', ['0034-7701', '1678-9857'], 'Revista de Antropologia'),
            ],
            [23639 => self::keys(19), 23600 => self::keys(28, 'b')]
        );

        $this->assertSame([], $plan['report']['conflict_rows']);
        $this->assertSame(23639, $plan['heads'][24000]);
        $this->assertSame(23600, $plan['heads'][24001]);
        $this->assertNotSame($plan['heads'][23639], $plan['heads'][23600]);
        $this->assertSame(['title' => 'Revista Brasileira de Ciências Sociais', 'source' => 'scielo'], $plan['titles'][23639]);
        $this->assertSame(['title' => 'Revista de Antropologia', 'source' => 'scielo'], $plan['titles'][23600]);
        $this->assertSame(2, $plan['report']['families']);
    }

    public function testPaideiaRowsStayTogetherWithoutAnyConflict(): void
    {
        $plan = $this->plan(
            [
                11322 => self::row('PAIDÉIA (USP. RIBEIRAO PRETO. IMPRESSO)', ['0103-863X']),
                65044 => self::row('Paidéia (Ribeirão Preto)'),
            ],
            [
                self::record('scielo', ['0103-863X', '1982-4327'], 'Paidéia (Ribeirão Preto)'),
                self::record('scopus', ['0103-863X', '1982-4327'], 'Paideia'),
            ],
            [11322 => self::keys(40)]
        );

        $this->assertSame([11322, 'title'], [$plan['heads'][65044], $plan['assign'][65044][1]]);
        $this->assertSame([], $plan['report']['conflict_rows']);
        $this->assertSame(1, $plan['report']['families']);
    }

    public function testPrintOnlinePairListedTogetherOnlyByTheCatalogRowUnitesWhenTheSourceTitlesAreTheSame(): void
    {
        [$print, $online] = [self::issn(9000301), self::issn(9000302)];

        $plan = $this->plan(
            [
                1 => self::row('Revista Par', [$print, $online]),
                2 => self::row('Revista Par (impresso)', [$print]),
                3 => self::row('Revista Par (online)', [$online]),
            ],
            [
                self::record('scopus', [$print], 'Revista Par'),     // nenhuma fonte lista os dois ISSNs juntos
                self::record('doaj', [$online], 'REVISTA PAR'),
            ]
        );

        $this->assertSame(1, $plan['report']['families']);
        $this->assertSame([1, 1, 1], [$plan['heads'][1], $plan['heads'][2], $plan['heads'][3]]);
        $this->assertSame([], $plan['report']['conflict_rows']);
        // O nome vem das duas fontes, que agora são um grupo só
        $this->assertSame(['title' => 'Revista Par', 'source' => 'doaj'], $plan['titles'][1]);
    }

    public function testPairWhoseSourceTitlesOnlyDifferByAQualifierIsCompatible(): void
    {
        [$print, $online] = [self::issn(9000311), self::issn(9000312)];

        $plan = $this->plan(
            [
                1 => self::row('EDUCAÇÃO E PESQUISA', [$print, $online]),
                2 => self::row('Educação e Pesquisa (impressa)', [$print]),
                3 => self::row('Educação e Pesquisa (online)', [$online]),
            ],
            [
                self::record('scopus', [$print], 'Educação e Pesquisa'),
                self::record('doaj', [$online], 'Educação e Pesquisa (Online)'),
            ]
        );

        $this->assertSame(1, $plan['report']['families']);
        $this->assertSame([], $plan['report']['conflict_rows']);
    }

    public function testConflictRowEntersOnlyTheGroupWhoseTitleIsMostSimilarToItsOwn(): void
    {
        [$a, $b, $c] = [self::issn(9000321), self::issn(9000322), self::issn(9000323)];

        $plan = $this->plan(
            [
                1 => self::row('REVISTA DE EDUCAÇÃO ESPECIAL', [$a]),
                2 => self::row('Journal of Zoology', [$b]),
                3 => self::row('Revista de Educação Especial (Impresso)', [$a, $b]),   // título parecido com o do grupo A
                4 => self::row('Linha sem parecido algum', [$a, $c]),
            ],
            [
                self::record('doaj', [$a], 'Revista de Educação Especial'),
                self::record('doaj', [$b], 'Journal of Zoology'),
                self::record('doaj', [$c], 'Cadernos de Geografia Humana'),
            ],
            [1 => self::keys(2), 3 => self::keys(1, 'b')]
        );

        // A linha 3 vai para o grupo A (família da linha 1); o grupo B continua sozinho
        $this->assertSame([1, 'issn'], $plan['assign'][3]);
        $this->assertSame(1, $plan['heads'][3]);
        $this->assertSame(2, $plan['heads'][2]);
        // A linha 4 não se parece com grupo algum: fica isolada
        $this->assertSame([null, null], $plan['assign'][4]);
        $this->assertSame(4, $plan['heads'][4]);
        $this->assertSame(3, $plan['report']['families']);

        $conflicts = array_column($plan['report']['conflict_rows'], null, 'id');
        $this->assertSame([3, 4], array_keys($conflicts));
        $this->assertSame(['id' => 1, 'title' => 'REVISTA DE EDUCAÇÃO ESPECIAL'], $conflicts[3]['destination']);
        $this->assertSame(1.0, $conflicts[3]['similarity']);
        $this->assertSame(1, $conflicts[3]['articles']);
        $this->assertSame(
            [
                ['issns' => [Issn::format(Issn::extractAll($a)[0])], 'title' => 'Revista de Educação Especial', 'countries' => [], 'family' => 1],
                ['issns' => [Issn::format(Issn::extractAll($b)[0])], 'title' => 'Journal of Zoology', 'countries' => [], 'family' => 2],
            ],
            $conflicts[3]['groups']
        );
        $this->assertNull($conflicts[4]['destination']);
        $this->assertLessThan(JournalFamilyBuilder::TITLE_SIMILARITY_THRESHOLD, $conflicts[4]['similarity']);
    }

    public function testConflictRowIsListedByNumberOfArticles(): void
    {
        [$a, $b] = [self::issn(9000331), self::issn(9000332)];
        $rows = [
            1 => self::row('Linha A', [$a]),
            2 => self::row('Linha B', [$b]),
        ];
        foreach ([10, 11, 12] as $id) {
            $rows[$id] = self::row('Conflito ' . $id, [$a, $b]);
        }

        $plan = $this->plan(
            $rows,
            [self::record('doaj', [$a], 'Nome Alfa Primeiro'), self::record('doaj', [$b], 'Nome Beta Segundo')],
            [10 => self::keys(1), 11 => self::keys(5, 'b'), 12 => self::keys(5, 'c')]
        );

        $this->assertSame([11, 12, 10], array_column($plan['report']['conflict_rows'], 'id'), 'mais artigos primeiro, empate pelo id');
        $this->assertSame([5, 5, 1], array_column($plan['report']['conflict_rows'], 'articles'));
    }

    public function testNoUnionPassesThroughAConflictRowEvenWhenAnotherRowSharesAnIssn(): void
    {
        // O ISSN x não está em nenhuma fonte. A linha 11 o traz junto com o ISSN do grupo A; a linha 12 o traz junto
        // com o do grupo B. Pela linha 11, x acompanha o grupo A; a linha 12 passaria a ligar A a B e é conflito
        [$a, $b, $x] = [self::issn(9000341), self::issn(9000342), self::issn(9000343)];

        $plan = $this->plan(
            [
                1 => self::row('Revista Alfa Primeiro', [$a]),
                2 => self::row('Revista Beta Segundo', [$b]),
                11 => self::row('Linha 11', [$a, $x]),
                12 => self::row('Linha 12', [$x, $b]),
                13 => self::row('Linha 13', [$x]),
            ],
            [self::record('doaj', [$a], 'Revista Alfa Primeiro'), self::record('doaj', [$b], 'Revista Beta Segundo')]
        );

        $this->assertSame([12], array_column($plan['report']['conflict_rows'], 'id'));
        $this->assertSame($plan['heads'][1], $plan['heads'][11]);
        $this->assertSame($plan['heads'][1], $plan['heads'][13], 'quem tem só o ISSN x acompanha o grupo A');
        $this->assertNotSame($plan['heads'][1], $plan['heads'][2]);
        $this->assertSame(12, $plan['heads'][12]);
    }

    public function testRowsAreTreatedInIdOrderSoTheLowerIdKeepsTheSharedIssn(): void
    {
        [$a, $b, $x] = [self::issn(9000351), self::issn(9000352), self::issn(9000353)];
        $records = [self::record('doaj', [$a], 'Revista Alfa Primeiro'), self::record('doaj', [$b], 'Revista Beta Segundo')];

        // Com ids invertidos quem fica em conflito é a outra linha
        $plan = $this->plan(
            [
                1 => self::row('Revista Alfa Primeiro', [$a]),
                2 => self::row('Revista Beta Segundo', [$b]),
                11 => self::row('Linha 11', [$x, $b]),
                12 => self::row('Linha 12', [$a, $x]),
            ],
            $records
        );

        $this->assertSame([12], array_column($plan['report']['conflict_rows'], 'id'));
        $this->assertSame($plan['heads'][2], $plan['heads'][11]);
    }

    public function testIssnAbsentFromTheArtifactOfAConflictRowDoesNotPropagate(): void
    {
        [$a, $b, $x] = [self::issn(9000361), self::issn(9000362), self::issn(9000363)];

        $plan = $this->plan(
            [
                1 => self::row('Revista Alfa Primeiro', [$a]),
                2 => self::row('Revista Beta Segundo', [$b]),
                11 => self::row('Linha em conflito', [$a, $b, $x]),
                12 => self::row('Outra linha com x', [$x]),
            ],
            [self::record('doaj', [$a], 'Revista Alfa Primeiro'), self::record('doaj', [$b], 'Revista Beta Segundo')]
        );

        $this->assertSame([11], array_column($plan['report']['conflict_rows'], 'id'));
        $this->assertSame(12, $plan['heads'][12], 'x não chega a nenhum grupo por uma linha em conflito');
        $this->assertSame(4, $plan['report']['families']);
    }

    public function testRowWithAllIssnsAbsentFromTheArtifactStillUnitesThemAsBefore(): void
    {
        [$x, $y, $z] = [self::issn(9000371), self::issn(9000372), self::issn(9000373)];

        $plan = $this->plan(
            [1 => self::row('Um', [$x, $y]), 2 => self::row('Dois', [$y, $z]), 3 => self::row('Três', [$z])],
            [self::record('doaj', [self::issn(9000379)], 'Outra revista qualquer')]
        );

        $this->assertSame(1, $plan['report']['families']);
        $this->assertSame([], $plan['report']['conflict_rows']);
    }

    public function testRowNeedsAllItsGroupsToBeCompatibleToUniteThem(): void
    {
        [$a, $b, $c] = [self::issn(9000381), self::issn(9000382), self::issn(9000383)];

        $plan = $this->plan(
            [
                1 => self::row('Revista Alfa Primeiro', [$a]),
                2 => self::row('Revista Alfa Primeiro (online)', [$b]),
                3 => self::row('Cadernos de Geografia Humana', [$c]),
                4 => self::row('Linha com três ISSNs', [$a, $b, $c]),   // A e B são compatíveis, C não
            ],
            [
                self::record('doaj', [$a], 'Revista Alfa Primeiro'),
                self::record('doaj', [$b], 'Revista Alfa Primeiro'),
                self::record('doaj', [$c], 'Cadernos de Geografia Humana'),
            ]
        );

        $this->assertSame([4], array_column($plan['report']['conflict_rows'], 'id'));
        $this->assertNotSame($plan['heads'][1], $plan['heads'][2], 'nenhuma união passa pela linha em conflito');
        $this->assertNotSame($plan['heads'][1], $plan['heads'][3]);
    }

    public function testManualRowsBridgeGroupsWithoutCheckingTitles(): void
    {
        [$a, $b] = [self::issn(9000391), self::issn(9000392)];

        $plan = $this->plan(
            [
                1 => self::row('Revista Alfa Primeiro', [$a]),
                2 => self::row('Revista Beta Segundo', [$b]),
                3 => self::row('Cabeça manual', [$a, $b], ['method' => 'manual']),
            ],
            [self::record('doaj', [$a], 'Revista Alfa Primeiro'), self::record('doaj', [$b], 'Revista Beta Segundo')]
        );

        $this->assertSame([], $plan['report']['conflict_rows'], 'decisão humana não é conflito');
        $this->assertSame(1, $plan['report']['families']);
        $this->assertSame([3, 'issn'], $plan['assign'][1]);
    }

    public function testThresholdDecidesWhetherContainedTitlesAreTheSameJournal(): void
    {
        [$a, $b] = [self::issn(9000401), self::issn(9000402)];
        $rows = [
            1 => self::row('Materials Science-Poland', [$a]),
            2 => self::row('Materials Science', [$b]),
            3 => self::row('MATERIALS SCIENCE', [$a, $b]),
        ];
        $records = [self::record('doaj', [$a], 'Materials Science-Poland'), self::record('doaj', [$b], 'Materials Science')];

        $default = $this->plan($rows, $records);
        $this->assertSame([], $default['report']['conflict_rows']);
        $this->assertSame(1, $default['report']['families']);
        $this->assertSame(0.7, $default['report']['similarity_threshold']);

        // Acima de 0,95 a contenção não basta: a linha 3 vira conflito e entra no grupo do título igual ao dela (linha 2)
        $strict = $this->builder->plan($rows, [], [], [], static fn (): array => $records, null, 0, 0.95);
        $this->assertSame([3], array_column($strict['report']['conflict_rows'], 'id'));
        $this->assertSame(0.95, $strict['report']['similarity_threshold']);
        $this->assertSame(2, $strict['report']['families']);
        $this->assertSame([1, 2, 2], [$strict['heads'][1], $strict['heads'][2], $strict['heads'][3]]);
        $this->assertSame(['id' => 2, 'title' => 'Materials Science'], $strict['report']['conflict_rows'][0]['destination']);
    }

    public function testConflictRowIsNeverTheCanonicalButItsArticlesCountInTheDestinationFamily(): void
    {
        [$a, $b] = [self::issn(9000411), self::issn(9000412)];

        $plan = $this->plan(
            [
                1 => self::row('Perspectiva', [$a]),
                2 => self::row('Educação (Santa Maria. Online)', [$b]),
                3 => self::row('PERSPECTIVA (FLORIANÓPOLIS)', [$b, $a]),
            ],
            [self::record('doaj', [$a], 'Perspectiva'), self::record('doaj', [$b], 'Educação (Santa Maria. Online)')],
            [3 => self::keys(8)],
            [],
            [1 => ['scielo']]
        );

        // A linha em conflito tem mais artigos, mas não vira canônica: os ISSNs dela não são confiáveis. Os artigos dela
        // contam na família de destino
        $this->assertSame([1, 1, 2], [$plan['heads'][1], $plan['heads'][3], $plan['heads'][2]]);
        $this->assertSame([1, 'issn'], $plan['assign'][3]);
        $this->assertSame(8, $plan['report']['by_db']['scielo']['family_items']);
        $this->assertSame(0, $plan['report']['by_db']['scielo']['row_items']);
    }

    public function testPlanWithConflictsIsIdempotentAndDeterministic(): void
    {
        [$a, $b] = [self::issn(9000421), self::issn(9000422)];
        $rows = [
            1 => self::row('Revista Alfa Primeiro', [$a]),
            2 => self::row('Revista Beta Segundo', [$b]),
            3 => self::row('Linha em conflito', [$a, $b]),
            4 => self::row('Revista Alfa Primeiro'),
        ];
        $records = [self::record('doaj', [$a], 'Revista Alfa Primeiro'), self::record('doaj', [$b], 'Revista Beta Segundo')];

        $first = $this->plan($rows, $records);
        foreach ($first['assign'] as $id => [$canonical, $method]) {
            $rows[$id]['canonical'] = $canonical;
            $rows[$id]['method'] = $method;
        }
        krsort($rows);
        $second = $this->plan($rows, $records);

        $this->assertSame($first['assign'], $second['assign']);
        $this->assertSame($first['heads'], $second['heads']);
        $this->assertSame($first['report']['conflict_rows'], $second['report']['conflict_rows']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Países que se excluem e registros de 3 ISSNs contraditos por outras fontes
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider countryCodeProvider
     */
    public function testCountryCodeIsNormalizedForEachSource(string $source, string $raw, ?string $expected): void
    {
        $this->assertSame($expected, JournalFamilyBuilder::countryCode($source, $raw));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function countryCodeProvider(): array
    {
        return [
            'DOAJ: nome em inglês' => ['doaj', 'Brazil', 'BR'],
            'DOAJ: nome oficial ISO' => ['doaj', 'Korea, Republic of', 'KR'],
            'DOAJ: acento e apóstrofo' => ['doaj', "Côte d'Ivoire", 'CI'],
            'DOAJ: Türkiye' => ['doaj', 'Türkiye', 'TR'],
            'DOAJ: Bolívia' => ['doaj', 'Bolivia, Plurinational State of', 'BO'],
            'Latindex: nome em espanhol' => ['latindex', 'Brasil', 'BR'],
            'Latindex: acento' => ['latindex', 'Perú', 'PE'],
            'Latindex: México' => ['latindex', 'México', 'MX'],
            'Latindex: nome de duas palavras' => ['latindex', 'República Dominicana', 'DO'],
            'Latindex: não é país' => ['latindex', 'Iberoamericanistas-Europa', null],
            'Latindex: organismo internacional' => ['latindex', 'Organismos Internacionales', null],
            'OpenAlex: código ISO' => ['openalex', 'BR', 'BR'],
            'OpenAlex: ISO em minúsculas' => ['openalex', 'gb', 'GB'],
            'OpenAlex: MARC do Brasil' => ['openalex', 'BL', 'BR'],
            'OpenAlex: MARC da Indonésia' => ['openalex', 'IO', 'ID'],
            'OpenAlex: MARC da Espanha' => ['openalex', 'SP', 'ES'],
            'OpenAlex: MARC dos Países Baixos' => ['openalex', 'NE', 'NL'],
            'OpenAlex: MARC do Reino Unido' => ['openalex', 'XXK', 'GB'],
            'OpenAlex: MARC da Inglaterra' => ['openalex', 'ENK', 'GB'],
            'OpenAlex: MARC de estado americano' => ['openalex', 'CAU', 'US'],
            'OpenAlex: MARC de província canadense' => ['openalex', 'ONC', 'CA'],
            'OpenAlex: MARC de estado australiano' => ['openalex', 'VRA', 'AU'],
            'OpenAlex: ISO e MARC com países diferentes' => ['openalex', 'SA', null],
            'OpenAlex: ISO e MARC com países diferentes (Eslovênia ou Singapura)' => ['openalex', 'SI', null],
            'OpenAlex: código que não é país' => ['openalex', 'ZZ', null],
            'OpenAlex: texto que não é código' => ['openalex', 'Brasil', null],
            'fonte sem país' => ['scopus', 'Brazil', null],
            'vazio' => ['doaj', '   ', null],
        ];
    }

    public function testPerspectivaBrazilAndPeruStaySeparateBecauseTheirCountriesAreDisjoint(): void
    {
        // A linha 11538 (Latindex) tem o ISSN da Perspectiva brasileira e os da peruana; os dois grupos têm o mesmo título
        $plan = $this->plan(
            [
                4150 => self::row('EDUCAÇÃO (UFSM)', ['1984-6444']),
                11538 => self::row('PERSPECTIVA', ['2175-795X', '1996-5389', '1996-5257']),
                23622 => self::row('PERSPECTIVA (FLORIANÓPOLIS)', ['1984-6444', '0102-5473']),
                70001 => self::row('Perspectiva (Lima)', ['1996-5389']),
            ],
            [
                self::record('doaj', ['0102-5473', '2175-795X'], 'Perspectiva', 'Brazil'),
                self::record('openalex', ['0102-5473', '2175-795X'], 'Perspectiva', 'BR'),
                self::record('doaj', ['1996-5257', '1996-5389'], 'Perspectiva', 'Peru'),
                self::record('latindex', ['1996-5257', '1996-5389'], 'Perspectiva', 'Perú'),
                self::record('openalex', ['1996-5257', '1996-5389'], 'REVISTA PERSPECTIVA', 'PE'),
                self::record('doaj', ['1984-6444'], 'Educação (Santa Maria. Online)', 'Brazil'),
            ],
            [11538 => self::keys(22), 23622 => self::keys(8, 'b')]
        );

        $this->assertNotSame($plan['heads'][23622], $plan['heads'][70001], 'Perspectiva do Brasil e do Peru ficam em famílias diferentes');
        $this->assertNotSame($plan['heads'][11538], $plan['heads'][70001]);
        $this->assertNotSame($plan['heads'][11538], $plan['heads'][23622]);
        $this->assertSame(4, $plan['report']['families']);
        $this->assertSame(1, $plan['report']['largest']);

        $conflicts = array_column($plan['report']['conflict_rows'], null, 'id');
        $this->assertSame([11538, 23622], array_keys($conflicts), 'ordenadas por artigos');

        // 11538: títulos iguais, países BR e PE disjuntos; o título não decide o grupo e a linha fica isolada
        $this->assertSame('country', $conflicts[11538]['reason']);
        $this->assertTrue($conflicts[11538]['tie']);
        $this->assertNull($conflicts[11538]['destination']);
        $this->assertSame(22, $conflicts[11538]['articles']);
        $this->assertSame([['BR'], ['PE']], array_column($conflicts[11538]['groups'], 'countries'));
        $this->assertSame(11538, $plan['heads'][11538]);

        // 23622: a Educação (UFSM) não se parece com a Perspectiva; entra no grupo brasileiro, por título parecido
        $this->assertSame('title', $conflicts[23622]['reason']);
        $this->assertFalse($conflicts[23622]['tie']);
        $this->assertSame(23622, $conflicts[23622]['destination']['id']);
    }

    public function testPrintOnlinePairWithTheSameCountryStaysUnitedWhateverTheFormatOfTheCountry(): void
    {
        [$print, $online] = [self::issn(9000501), self::issn(9000502)];
        $rows = [
            1 => self::row('Revista Par', [$print, $online]),
            2 => self::row('Revista Par (impresso)', [$print]),
            3 => self::row('Revista Par (online)', [$online]),
        ];

        // Nome em inglês (DOAJ) e nome em espanhol (Latindex): os dois são o Brasil
        $names = $this->plan($rows, [
            self::record('doaj', [$print], 'Revista Par', 'Brazil'),
            self::record('latindex', [$online], 'Revista Par', 'Brasil'),
        ]);
        $this->assertSame(1, $names['report']['families']);
        $this->assertSame([], $names['report']['conflict_rows']);

        // Código MARC do OpenAlex ("BL") e nome do DOAJ: também o Brasil
        $marc = $this->plan($rows, [
            self::record('doaj', [$print], 'Revista Par', 'Brazil'),
            self::record('openalex', [$online], 'Revista Par', 'BL'),
        ]);
        $this->assertSame(1, $marc['report']['families']);
        $this->assertSame([], $marc['report']['conflict_rows']);
    }

    public function testGroupWithoutAKnownCountryDoesNotTriggerTheCountryRule(): void
    {
        [$print, $online] = [self::issn(9000511), self::issn(9000512)];
        $rows = [
            1 => self::row('Revista Par', [$print, $online]),
            2 => self::row('Revista Par (impresso)', [$print]),
            3 => self::row('Revista Par (online)', [$online]),
        ];

        // Scopus e WoS não trazem país, e "Organismos Internacionales" não é país: nenhum dos dois lados é "conhecido" contra o outro
        $plan = $this->plan($rows, [
            self::record('doaj', [$print], 'Revista Par', 'Brazil'),
            self::record('scopus', [$online], 'Revista Par'),
        ]);
        $this->assertSame(1, $plan['report']['families']);
        $this->assertSame([], $plan['report']['conflict_rows']);

        $unknown = $this->plan($rows, [
            self::record('doaj', [$print], 'Revista Par', 'Brazil'),
            self::record('latindex', [$online], 'Revista Par', 'Organismos Internacionales'),
        ]);
        $this->assertSame(1, $unknown['report']['families']);
        $this->assertSame([], $unknown['report']['conflict_rows']);
    }

    public function testRowIsInConflictWhenAnyTwoOfItsGroupsHaveDisjointKnownCountries(): void
    {
        [$a, $b, $c] = [self::issn(9000521), self::issn(9000522), self::issn(9000523)];

        $plan = $this->plan(
            [
                1 => self::row('Revista Tripla', [$a, $b, $c]),
                2 => self::row('Revista Tripla (A)', [$a]),
                3 => self::row('Revista Tripla (B)', [$b]),
                4 => self::row('Revista Tripla (C)', [$c]),
            ],
            [
                self::record('doaj', [$a], 'Revista Tripla', 'Brazil'),
                self::record('scopus', [$b], 'Revista Tripla'),             // sem país: compatível com os dois
                self::record('doaj', [$c], 'Revista Tripla', 'Peru'),       // mas o Peru exclui o Brasil
            ]
        );

        $this->assertSame([1], array_column($plan['report']['conflict_rows'], 'id'));
        $this->assertSame('country', $plan['report']['conflict_rows'][0]['reason']);
        $this->assertNotSame($plan['heads'][2], $plan['heads'][4]);
        $this->assertNotSame($plan['heads'][2], $plan['heads'][3], 'nenhuma união passa pela linha em conflito');
    }

    public function testCountryConflictStillGoesToTheGroupWhoseTitleIsStrictlyMoreSimilar(): void
    {
        [$a, $b] = [self::issn(9000531), self::issn(9000532)];

        $plan = $this->plan(
            [
                1 => self::row('Revista Alfa Beta', [$a]),
                2 => self::row('Alfa Beta', [$b]),
                3 => self::row('REVISTA ALFA BETA', [$a, $b]),
            ],
            [
                self::record('doaj', [$a], 'Revista Alfa Beta', 'Brazil'),
                self::record('doaj', [$b], 'Alfa Beta', 'Peru'),             // título contido no outro: compatível, mas países disjuntos
            ]
        );

        $conflict = $plan['report']['conflict_rows'][0];
        $this->assertSame(3, $conflict['id']);
        $this->assertSame('country', $conflict['reason']);
        $this->assertFalse($conflict['tie']);
        $this->assertSame(1, $conflict['destination']['id']);
        $this->assertSame([1, 'issn'], $plan['assign'][3]);
        $this->assertNotSame($plan['heads'][1], $plan['heads'][2]);
    }

    public function testCountriesOfASourceRecordThatIsSkippedAreNotUsed(): void
    {
        [$a, $b, $c] = [self::issn(9000541), self::issn(9000542), self::issn(9000543)];

        // O registro de 4 ISSNs é ignorado; o país "Peru" dele não pode opor o grupo de $a ao de $b
        $plan = $this->plan(
            [
                1 => self::row('Revista Par', [$a, $b]),
                2 => self::row('Revista Par (impresso)', [$a]),
                3 => self::row('Revista Par (online)', [$b]),
            ],
            [
                self::record('doaj', [$a], 'Revista Par', 'Brazil'),
                self::record('doaj', [$b], 'Revista Par', 'Brazil'),
                self::record('latindex', [$a, $c, self::issn(9000544), self::issn(9000545)], 'Revista Par', 'Perú'),
            ]
        );

        $this->assertSame(1, $plan['report']['families']);
        $this->assertSame([], $plan['report']['conflict_rows']);
    }

    public function testRecordWithThreeIssnsThatTwoOtherSourcesContradictDoesNotUniteIssnsNorLendItsTitle(): void
    {
        [$a, $b, $c] = [self::issn(9000601), self::issn(9000602), self::issn(9000603)];

        $plan = $this->plan(
            [1 => self::row('Linha A', [$a]), 2 => self::row('Linha B', [$b]), 3 => self::row('Linha C', [$c])],
            [
                self::record('scielo', [$a, $b, $c], 'Nome do registro contradito'),
                self::record('scopus', [$a, $b], 'Revista Dupla'),    // uma fonte diz: a e b são uma revista
                self::record('doaj', [$c], 'Outra Revista'),          // outra fonte diz: c é outra
            ]
        );

        $this->assertSame($plan['heads'][1], $plan['heads'][2], 'o registro de Scopus segue valendo');
        $this->assertNotSame($plan['heads'][1], $plan['heads'][3]);
        $this->assertSame(2, $plan['report']['families']);
        $this->assertSame(1, $plan['report']['artifact']['skipped_contradicted']);
        $this->assertSame(2, $plan['report']['artifact']['used']);
        // O nome vem das outras fontes, não do SciELO contradito (que viria primeiro)
        $this->assertSame(['title' => 'Revista Dupla', 'source' => 'scopus'], $plan['titles'][1]);
        $this->assertSame(['title' => 'Outra Revista', 'source' => 'doaj'], $plan['titles'][3]);
    }

    public function testRecordWithThreeIssnsIsKeptWhenOnlyOneOtherSourceDisagrees(): void
    {
        [$a, $b, $c] = [self::issn(9000611), self::issn(9000612), self::issn(9000613)];

        $plan = $this->plan(
            [1 => self::row('Linha A', [$a]), 2 => self::row('Linha B', [$b]), 3 => self::row('Linha C', [$c])],
            [
                self::record('openalex', [$a, $b, $c], 'Revista Tripla'),
                self::record('scopus', [$a, $b], 'Revista Tripla'),       // só uma fonte discorda: não basta
            ]
        );

        $this->assertSame(1, $plan['report']['families']);
        $this->assertSame(0, $plan['report']['artifact']['skipped_contradicted']);
    }

    public function testRecordWithThreeIssnsIsKeptWhenTheDisagreeingRecordsComeFromTheSameSource(): void
    {
        [$a, $b, $c] = [self::issn(9000621), self::issn(9000622), self::issn(9000623)];

        $plan = $this->plan(
            [1 => self::row('Linha A', [$a]), 2 => self::row('Linha B', [$b]), 3 => self::row('Linha C', [$c])],
            [
                self::record('openalex', [$a, $b, $c], 'Revista Tripla'),
                self::record('scopus', [$a, $b], 'Revista Tripla'),
                self::record('scopus', [$c], 'Revista Tripla'),           // a mesma fonte não conta duas vezes
            ]
        );

        $this->assertSame(1, $plan['report']['families']);
        $this->assertSame(0, $plan['report']['artifact']['skipped_contradicted']);
    }

    public function testRecordWithThreeIssnsIsKeptWhenOtherSourcesListAllThreeToo(): void
    {
        [$a, $b, $c] = [self::issn(9000631), self::issn(9000632), self::issn(9000633)];

        $plan = $this->plan(
            [1 => self::row('Linha A', [$a]), 2 => self::row('Linha B', [$b]), 3 => self::row('Linha C', [$c])],
            [
                self::record('openalex', [$a, $b, $c], 'Revista Tripla'),
                self::record('scopus', [$a, $b, $c], 'Revista Tripla'),
                self::record('doaj', [$a, $b, $c], 'Revista Tripla'),
            ]
        );

        $this->assertSame(1, $plan['report']['families']);
        $this->assertSame(0, $plan['report']['artifact']['skipped_contradicted']);
    }

    public function testRecordWithTwoIssnsIsNeverSkippedByContradiction(): void
    {
        [$a, $b] = [self::issn(9000641), self::issn(9000642)];

        $plan = $this->plan(
            [1 => self::row('Linha A', [$a]), 2 => self::row('Linha B', [$b])],
            [
                self::record('openalex', [$a, $b], 'Revista Dupla'),
                self::record('scopus', [$a], 'Revista Dupla'),
                self::record('doaj', [$b], 'Revista Dupla'),
            ]
        );

        $this->assertSame(1, $plan['report']['families']);
        $this->assertSame(0, $plan['report']['artifact']['skipped_contradicted']);
    }

    public function testAnyRecordCountsAsEvidenceEvenOneWithMoreThanThreeIssns(): void
    {
        [$a, $b, $c] = [self::issn(9000651), self::issn(9000652), self::issn(9000653)];

        $plan = $this->plan(
            [1 => self::row('Linha A', [$a]), 2 => self::row('Linha B', [$b]), 3 => self::row('Linha C', [$c])],
            [
                self::record('latindex', [$a, $b, $c], 'Revista Tripla'),
                self::record('scopus', [$a, $b], 'Revista Tripla'),
                self::record('wos', [$c, self::issn(9000654), self::issn(9000655), self::issn(9000656)], 'Pacote de revistas'),
            ]
        );

        $this->assertSame(1, $plan['report']['artifact']['skipped_contradicted']);
        $this->assertSame(1, $plan['report']['artifact']['skipped_many_issns']);
        $this->assertNotSame($plan['heads'][1], $plan['heads'][3]);
    }

    public function testIconosAndForoStaySeparateAfterTheLatindexRecordWithThreeIssnsIsSkipped(): void
    {
        // Família #5995: o registro do Latindex junta Íconos (1390-1249, 1390-8065) e Foro (1390-2466)
        $plan = $this->plan(
            [
                5995 => self::row('ÍCONOS (QUITO)', ['1390-1249', '1390-8065', '1390-2466']),
                5996 => self::row('ÍCONOS. REVISTA DE CIENCIAS SOCIALES DA FLACSO', ['1390-8065']),
                38053 => self::row('Foro: Revista de Derecho', ['1390-2466', '2631-2484']),
                81340 => self::row('ICONOS'),
            ],
            [
                self::record('doaj', ['1390-1249', '1390-8065'], 'Íconos', 'Ecuador'),
                self::record('doaj', ['1390-2466', '2631-2484'], 'Foro, Revista de Derecho', 'Ecuador'),
                self::record('latindex', ['1390-1249', '1390-8065', '1390-2466'], 'Íconos', 'Ecuador'),
                self::record('latindex', ['1390-2466', '2631-2484'], 'FORO. Revista de Derecho', 'Ecuador'),
                self::record('openalex', ['1390-1249', '1390-8065', '2224-6983'], 'Íconos - Revista de Ciencias Sociales', 'EC'),
                self::record('openalex', ['1390-2466', '2631-2484'], 'FORO Revista de Derecho', 'EC'),
                self::record('scopus', ['1390-1249', '1390-8065'], 'Iconos'),
                self::record('scopus', ['1390-2466', '2631-2484'], 'Foro: Revista de Derecho'),
                self::record('wos', ['1390-1249', '1390-8065'], 'ICONOS'),
            ]
        );

        $this->assertSame(1, $plan['report']['artifact']['skipped_contradicted'], 'só o registro do Latindex é contradito');
        $this->assertSame($plan['heads'][5996], $plan['heads'][5995]);
        $this->assertSame($plan['heads'][5996], $plan['heads'][81340]);
        $this->assertNotSame($plan['heads'][5996], $plan['heads'][38053], 'Foro fica fora da família da Íconos');
        $this->assertSame(2, $plan['report']['families']);
        $this->assertSame(3, $plan['report']['largest']);

        // A linha 5995 tem ISSNs das duas revistas: conflito por título, e vai para a Íconos
        $this->assertSame([5995], array_column($plan['report']['conflict_rows'], 'id'));
        $this->assertSame('title', $plan['report']['conflict_rows'][0]['reason']);
        $this->assertSame(5996, $plan['report']['conflict_rows'][0]['destination']['id']);
        $this->assertSame([5996, 'issn'], [$plan['heads'][5995], $plan['assign'][5995][1]]);
        $this->assertSame(['title' => 'Íconos', 'source' => 'doaj'], $plan['titles'][5996]);
        $this->assertSame(['title' => 'Foro, Revista de Derecho', 'source' => 'doaj'], $plan['titles'][38053]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // build(): leitura do cadastro, gravação, idempotência
    // ------------------------------------------------------------------------------------------------------------

    public function testBuildGroupsByIssnAndByTitleAndWritesTheDisplayTitle(): void
    {
        $t = $this->token();
        [$x, $y] = [self::issn(9100001), self::issn(9100002)];

        // B nasce antes (id menor), mas A tem 2 artigos e vira a canônica; C não tem ISSN e tem o título de A
        $b = $this->createJournal("Revista B $t", $y);
        $a = $this->createJournal("Revista A $t", $x);
        $c = $this->createJournal("Revista A $t");
        $this->addArticle($a, $this->createResearcher(), "10.9999/{$t}-1");
        $this->addArticle($a, $this->createResearcher(), "10.9999/{$t}-2");

        $artifact = $this->artifact([self::record('scielo', [$x, $y], 'Nome Bonito')]);
        $report = $this->builder->build($artifact, false);

        $rows = $this->snapshot([$a, $b, $c]);
        $this->assertNull($rows[$a->getId()]['canonical_journal_id']);
        $this->assertNull($rows[$a->getId()]['canonical_method']);
        $this->assertSame($a->getId(), (int) $rows[$b->getId()]['canonical_journal_id']);
        $this->assertSame('issn', $rows[$b->getId()]['canonical_method']);
        $this->assertSame($a->getId(), (int) $rows[$c->getId()]['canonical_journal_id']);
        $this->assertSame('title', $rows[$c->getId()]['canonical_method']);
        foreach ($rows as $row) {
            $this->assertSame('Nome Bonito', $row['display_title']);
            $this->assertSame('scielo', $row['display_title_source']);
        }

        $this->assertTrue($report['artifact']['found']);
        $this->assertSame(1, $report['artifact']['records']);
        $this->assertGreaterThanOrEqual(1, $report['multi']);
        $this->assertGreaterThanOrEqual(1, $report['joined_by_title']);
        $this->assertGreaterThanOrEqual(2, $report['updated']['canonical']);
        $this->assertGreaterThanOrEqual(3, $report['updated']['display_title']);
        $this->assertFalse($report['dry_run']);
    }

    public function testRunningTwiceChangesNothing(): void
    {
        $t = $this->token();
        [$x, $y] = [self::issn(9100011), self::issn(9100012)];
        $a = $this->createJournal("Revista A $t", $x);
        $b = $this->createJournal("Revista B $t", $y);
        $c = $this->createJournal("Revista A $t");
        $artifact = $this->artifact([self::record('doaj', [$x, $y], 'Nome Bonito')]);

        $first = $this->builder->build($artifact, false);
        $before = $this->snapshot([$a, $b, $c]);
        $second = $this->builder->build($artifact, false);
        $after = $this->snapshot([$a, $b, $c]);

        $this->assertSame($before, $after);
        // Só grava o que mudou: a segunda rodada não regrava as linhas já certas (o banco de teste é compartilhado,
        // então a comparação é relativa à primeira rodada e não exige zero absoluto)
        $this->assertGreaterThanOrEqual(2, $first['updated']['canonical']);
        $this->assertLessThan($first['updated']['canonical'], $second['updated']['canonical']);
        $this->assertLessThan($first['updated']['display_title'], $second['updated']['display_title']);
    }

    public function testDryRunReportsTheChangesWithoutWritingThem(): void
    {
        $t = $this->token();
        [$x, $y] = [self::issn(9100021), self::issn(9100022)];
        $a = $this->createJournal("Revista A $t", $x);
        $b = $this->createJournal("Revista B $t", $y);
        $artifact = $this->artifact([self::record('doaj', [$x, $y], 'Nome Bonito')]);

        $before = $this->snapshot([$a, $b]);
        $report = $this->builder->build($artifact, true);

        $this->assertSame($before, $this->snapshot([$a, $b]));
        $this->assertTrue($report['dry_run']);
        $this->assertGreaterThanOrEqual(1, $report['updated']['canonical']);
        $this->assertGreaterThanOrEqual(2, $report['updated']['display_title']);
    }

    public function testManualRowsAreNotAlteredByBuild(): void
    {
        $t = $this->token();
        [$x, $y] = [self::issn(9100031), self::issn(9100032)];

        $manual = $this->createJournal("Cabeça manual $t", $x);
        $manual->setCanonicalMethod('manual');
        $manual->setDisplayTitle('Nome manual');
        $manual->setDisplayTitleSource('manual');
        $member = $this->createJournal("Membro manual $t", $y);
        $member->setCanonical($manual);
        $member->setCanonicalMethod('manual');
        $free = $this->createJournal("Livre $t", $x);
        $this->addArticle($free, $this->createResearcher(), "10.9999/{$t}-1");   // teria mais artigos, mas a canônica é a manual
        $this->em->flush();

        $this->builder->build($this->artifact([self::record('doaj', [$x, $y], 'Nome da fonte')]), false);

        $rows = $this->snapshot([$manual, $member, $free]);
        $this->assertNull($rows[$manual->getId()]['canonical_journal_id']);
        $this->assertSame('manual', $rows[$manual->getId()]['canonical_method']);
        $this->assertSame('Nome manual', $rows[$manual->getId()]['display_title']);
        $this->assertSame('manual', $rows[$manual->getId()]['display_title_source']);

        $this->assertSame($manual->getId(), (int) $rows[$member->getId()]['canonical_journal_id']);
        $this->assertSame('manual', $rows[$member->getId()]['canonical_method']);
        $this->assertSame('Nome da fonte', $rows[$member->getId()]['display_title']);

        $this->assertSame($manual->getId(), (int) $rows[$free->getId()]['canonical_journal_id']);
        $this->assertSame('issn', $rows[$free->getId()]['canonical_method']);
        $this->assertSame('Nome da fonte', $rows[$free->getId()]['display_title']);
        $this->assertSame('doaj', $rows[$free->getId()]['display_title_source']);
    }

    public function testBuildReadsTwoIssnsStuckInTheSameField(): void
    {
        $t = $this->token();
        [$x, $y] = [self::issn(9100041), self::issn(9100042)];

        // "1806-9053, 0102-6909" no mesmo campo: a linha une a que tem só um e a que tem só o outro
        $glued = $this->createJournal("Revista Colada $t", "$y, $x");
        $printOnly = $this->createJournal("Revista Colada Impressa $t", $x);
        $onlineOnly = $this->createJournal("Revista Colada Online $t", null, $y);

        $this->builder->build('/caminho/que/nao/existe.jsonl.gz', false);

        $rows = $this->snapshot([$glued, $printOnly, $onlineOnly]);
        $heads = array_map(
            static fn (array $row, int $id): int => $row['canonical_journal_id'] !== null ? (int) $row['canonical_journal_id'] : $id,
            $rows,
            array_keys($rows)
        );
        $this->assertCount(1, array_unique($heads));
    }

    public function testBuildWithoutTheArtifactFileRunsOnTheCatalogIssnsAndWarnsInTheReport(): void
    {
        $t = $this->token();
        $x = self::issn(9100051);
        $a = $this->createJournal("Revista Sem Artefato A $t", $x);
        $b = $this->createJournal("Revista Sem Artefato B $t", null, null, $x);

        $report = $this->builder->build('/caminho/que/nao/existe.jsonl.gz', false);

        $this->assertFalse($report['artifact']['found']);
        $this->assertSame(0, $report['artifact']['records']);
        $rows = $this->snapshot([$a, $b]);
        $this->assertNull($rows[$a->getId()]['canonical_journal_id']);
        $this->assertSame($a->getId(), (int) $rows[$b->getId()]['canonical_journal_id']);
    }

    public function testBuildUsesTheLattesSpellingOfTheArticlesWhenThereIsNoSource(): void
    {
        $t = $this->token();
        $researcher = $this->createResearcher();

        $twice = $this->createJournal('LINHA SEM FONTE ' . strtoupper($t), self::issn(9100061));
        $this->addArticle($twice, $researcher, "10.9999/{$t}-a", "Revista do Lattes $t");
        $this->addArticle($twice, $researcher, "10.9999/{$t}-b", "Revista do Lattes $t");
        $once = $this->createJournal('LINHA COM UM ARTIGO ' . strtoupper($t), self::issn(9100062));
        $this->addArticle($once, $researcher, "10.9999/{$t}-c", "Outra Grafia $t");

        $this->builder->build('/caminho/que/nao/existe.jsonl.gz', false);

        $rows = $this->snapshot([$twice, $once]);
        $this->assertSame("Revista do Lattes $t", $rows[$twice->getId()]['display_title']);
        $this->assertSame('lattes', $rows[$twice->getId()]['display_title_source']);
        // Uma só ocorrência não basta: cai no título da linha (em maiúsculas, então em caixa de título)
        $this->assertSame('title', $rows[$once->getId()]['display_title_source']);
        $this->assertSame(DisplayTitle::titleCase('LINHA COM UM ARTIGO ' . strtoupper($t)), $rows[$once->getId()]['display_title']);
        $this->assertStringStartsWith('Linha com Um Artigo ', (string) $rows[$once->getId()]['display_title']);
    }

    public function testBuildExtendsBaseLinksToTheWholeFamily(): void
    {
        $t = $this->token();
        [$x, $y] = [self::issn(9100081), self::issn(9100082)];
        $pubmed = $this->database('pubmed');
        $scopus = $this->database('scopus');

        $first = $this->createJournal("Revista Primeira $t", $x);
        $first->addAcademicDatabase($pubmed);
        $second = $this->createJournal("Revista Segunda $t", $y);
        $second->addAcademicDatabase($scopus);
        $alone = $this->createJournal("Revista Sozinha $t", self::issn(9100083));
        $this->em->flush();
        $artifact = $this->artifact([self::record('doaj', [$x, $y], "Revista Primeira $t")]);

        $report = $this->builder->build($artifact, false);
        $links = fn (QualisJournal $j): array => $this->conn->fetchFirstColumn(
            'SELECT d.acronym FROM qualis_journal_academic_database l JOIN academic_database d ON d.id = l.academic_database_id WHERE l.qualis_journal_id = ? ORDER BY d.acronym',
            [$j->getId()]
        );

        $this->assertSame(['pubmed', 'scopus'], $links($first));
        $this->assertSame(['pubmed', 'scopus'], $links($second));
        $this->assertSame([], $links($alone), 'Linha de outra família não recebe vínculo.');
        $this->assertGreaterThanOrEqual(2, $report['base_links_added']);

        $this->assertSame(0, $this->builder->build($artifact, false)['base_links_added'], 'Rodar de novo não cria nada.');
    }

    public function testDryRunDoesNotExtendBaseLinks(): void
    {
        $t = $this->token();
        [$x, $y] = [self::issn(9100091), self::issn(9100092)];
        $first = $this->createJournal("Revista Seca $t", $x);
        $first->addAcademicDatabase($this->database('pubmed'));
        $second = $this->createJournal("Revista Molhada $t", $y);
        $this->em->flush();
        $artifact = $this->artifact([self::record('doaj', [$x, $y], "Revista Seca $t")]);

        $this->assertSame(0, $this->builder->build($artifact, true)['base_links_added']);
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM qualis_journal_academic_database WHERE qualis_journal_id = ?', [$second->getId()]));
    }

    public function testBuildReportsArticlesByBaseForRowsAndFamilies(): void
    {
        $t = $this->token();
        [$x, $y] = [self::issn(9100071), self::issn(9100072)];
        $base = $this->database('pubmed');
        $missing = '/caminho/que/nao/existe.jsonl.gz';
        $baseline = $this->builder->build($missing, true)['by_db']['pubmed'];

        $linked = $this->createJournal("Revista Ligada $t", $x);
        $linked->addAcademicDatabase($base);
        $this->em->flush();
        $other = $this->createJournal("Revista Irmã $t", $y);
        $researcher = $this->createResearcher();
        $this->addArticle($linked, $researcher, "10.9999/{$t}-1");
        $this->addArticle($linked, $researcher, "10.9999/{$t}-2");
        $this->addArticle($other, $researcher, "10.9999/{$t}-2");   // o mesmo artigo, vinculado à linha irmã
        $this->addArticle($other, $researcher, "10.9999/{$t}-3");
        $artifact = $this->artifact([self::record('doaj', [$x, $y], 'Nome')]);

        $byDb = $this->builder->build($artifact, true)['by_db'];

        $this->assertSame(['scopus', 'pubmed', 'scielo', 'wos', 'latindex'], array_keys($byDb));
        $this->assertSame(2, $byDb['pubmed']['row'] - $baseline['row']);
        $this->assertSame(3, $byDb['pubmed']['family'] - $baseline['family']);
        $this->assertSame(2, $byDb['pubmed']['row_items'] - $baseline['row_items']);
        $this->assertSame(4, $byDb['pubmed']['family_items'] - $baseline['family_items']);
    }

    public function testBuildWithTheArtifactBuiltByTheRealBuilderFromTheFixtureSources(): void
    {
        // Artefato de verdade: T06 lê tests/fixtures/journal_sources e grava o .jsonl.gz que o reader devolve aqui
        $fixtures = dirname(__DIR__, 2) . '/fixtures/journal_sources';
        $dir = sys_get_temp_dir() . '/families_fixture_' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $this->dirs[] = $dir;
        $artifact = $dir . '/journal_evidence.jsonl.gz';
        $this->files[] = $artifact;
        (new JournalEvidenceArtifactBuilder())->build($fixtures, $fixtures, $artifact, null);

        // Paidéia: impresso com 3 artigos, online noutra linha e a linha do SciELO sem ISSN (só o título bate)
        $print = $this->createJournal('PAIDÉIA (USP. RIBEIRAO PRETO. IMPRESSO)', null, null, '0103-863X');
        $byTitle = $this->createJournal('Paidéia (Ribeirão Preto)');
        $online = $this->createJournal('PAIDÉIA (ONLINE)', null, '1982-4327');
        $researcher = $this->createResearcher();
        foreach ([1, 2, 3] as $n) {
            $this->addArticle($print, $researcher, "10.9999/paideia-{$n}");
        }
        // Educação e Pesquisa: os dois ISSNs do par vêm de uma linha cada
        $educationPrint = $this->createJournal('EDUCAÇÃO E PESQUISA', '1517-9702');
        $educationOnline = $this->createJournal('EDUCAÇÃO E PESQUISA (ONLINE)', null, '1678-4634');

        $report = $this->builder->build($artifact, false);

        $rows = $this->snapshot([$print, $byTitle, $online, $educationPrint, $educationOnline]);
        $this->assertNull($rows[$print->getId()]['canonical_journal_id']);
        $this->assertSame([$print->getId(), 'title'], [(int) $rows[$byTitle->getId()]['canonical_journal_id'], $rows[$byTitle->getId()]['canonical_method']]);
        $this->assertSame([$print->getId(), 'issn'], [(int) $rows[$online->getId()]['canonical_journal_id'], $rows[$online->getId()]['canonical_method']]);
        $this->assertNull($rows[$educationPrint->getId()]['canonical_journal_id']);
        $this->assertSame([$educationPrint->getId(), 'issn'], [(int) $rows[$educationOnline->getId()]['canonical_journal_id'], $rows[$educationOnline->getId()]['canonical_method']]);

        // O SciELO vem antes do OpenAlex (que também traz "Educação e Pesquisa") e o nome não é o título das linhas
        foreach ([$print, $byTitle, $online] as $journal) {
            $this->assertSame('Paidéia (Ribeirão Preto)', $rows[$journal->getId()]['display_title']);
            $this->assertSame('scielo', $rows[$journal->getId()]['display_title_source']);
        }
        foreach ([$educationPrint, $educationOnline] as $journal) {
            $this->assertSame('Educação e Pesquisa', $rows[$journal->getId()]['display_title']);
            $this->assertSame('scielo', $rows[$journal->getId()]['display_title_source']);
        }

        $this->assertTrue($report['artifact']['found']);
        $this->assertGreaterThan(10, $report['artifact']['records']);
        $this->assertSame(0, $report['artifact']['skipped_many_issns']);
    }

    public function testBuildReportsAndKeepsApartTheRowsInConflict(): void
    {
        $t = $this->token();
        [$x, $y] = [self::issn(9100091), self::issn(9100092)];
        $first = $this->createJournal("Revista Alfa $t", $x);
        $second = $this->createJournal("Revista Beta $t", $y);
        $conflict = $this->createJournal("Linha em conflito $t", $x, $y);
        $this->addArticle($conflict, $this->createResearcher(), "10.9999/{$t}-1");
        $artifact = $this->artifact([
            self::record('doaj', [$x], "Nome Alfa Original $t"),
            self::record('doaj', [$y], "Nome Beta Distinto $t"),
        ]);

        $report = $this->builder->build($artifact, false);

        $rows = $this->snapshot([$first, $second, $conflict]);
        foreach ($rows as $row) {
            $this->assertNull($row['canonical_journal_id']);
            $this->assertNull($row['canonical_method']);
        }
        $this->assertSame("Nome Alfa Original $t", $rows[$first->getId()]['display_title']);
        $this->assertSame("Nome Beta Distinto $t", $rows[$second->getId()]['display_title']);

        $byId = array_column($report['conflict_rows'], null, 'id');
        $this->assertArrayHasKey($conflict->getId(), $byId);
        $entry = $byId[$conflict->getId()];
        $this->assertSame("Linha em conflito $t", $entry['title']);
        $this->assertSame(1, $entry['articles']);
        $this->assertNull($entry['destination']);
        $this->assertCount(2, $entry['groups']);
        $this->assertSame([Issn::format(Issn::extractAll($x)[0]), Issn::format(Issn::extractAll($y)[0])], $entry['issns']);
    }

    public function testBuildKeepsApartTheGroupsWhoseCountriesAreDisjointAndLeavesTheTiedRowIsolated(): void
    {
        $t = $this->token();
        [$x, $y] = [self::issn(9100111), self::issn(9100112)];
        $brazil = $this->createJournal("Revista Perspectiva $t", $x);
        $peru = $this->createJournal("Revista Perspectiva $t (Lima)", $y);
        $merged = $this->createJournal("REVISTA PERSPECTIVA $t", $x, $y);
        $artifact = $this->artifact([
            self::record('doaj', [$x], "Revista Perspectiva $t", 'Brazil'),
            self::record('doaj', [$y], "Revista Perspectiva $t", 'Peru'),
        ]);

        $report = $this->builder->build($artifact, false);

        $rows = $this->snapshot([$brazil, $peru, $merged]);
        foreach ($rows as $row) {
            $this->assertNull($row['canonical_journal_id'], 'três famílias de uma linha');
            $this->assertNull($row['canonical_method']);
        }

        $entry = array_column($report['conflict_rows'], null, 'id')[$merged->getId()];
        $this->assertSame('country', $entry['reason']);
        $this->assertTrue($entry['tie']);
        $this->assertNull($entry['destination']);
        $this->assertSame([['BR'], ['PE']], array_column($entry['groups'], 'countries'));
    }

    public function testBuildWithoutConflictsReportsAnEmptyList(): void
    {
        $report = $this->builder->build('/caminho/que/nao/existe.jsonl.gz', true);

        $this->assertSame([], $report['conflict_rows']);
        $this->assertSame(JournalFamilyBuilder::TITLE_SIMILARITY_THRESHOLD, $report['similarity_threshold']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Comando
    // ------------------------------------------------------------------------------------------------------------

    public function testCommandDryRunPrintsTheReportAndWritesNothing(): void
    {
        $t = $this->token();
        [$x, $y] = [self::issn(9100081), self::issn(9100082)];
        $a = $this->createJournal("Revista Comando A $t", $x);
        $b = $this->createJournal("Revista Comando B $t", $y);
        $artifact = $this->artifact([self::record('doaj', [$x, $y], 'Nome do Comando')]);

        $before = $this->snapshot([$a, $b]);
        $tester = $this->commandTester();
        $exit = $tester->execute(['--dry-run' => true, '--artifact' => $artifact]);
        $display = $tester->getDisplay();

        $this->assertSame(0, $exit, $display);
        $this->assertStringContainsString('Famílias com mais de 1 linha', $display);
        $this->assertStringContainsString('Artigos por base, por linha e por família', $display);
        $this->assertStringContainsString('Modo simulação', $display);
        $this->assertStringNotContainsString('Artefato de evidências não encontrado', $display);
        $this->assertSame($before, $this->snapshot([$a, $b]));
    }

    public function testCommandListsTheRowsInConflictByNumberOfArticles(): void
    {
        $t = $this->token();
        [$x, $y] = [self::issn(9100101), self::issn(9100102)];
        $this->createJournal("Revista Alfa $t", $x);
        $this->createJournal("Revista Beta $t", $y);
        $conflict = $this->createJournal("Linha em conflito $t", $x, $y);
        $this->addArticle($conflict, $this->createResearcher(), "10.9999/{$t}-1");
        $artifact = $this->artifact([
            self::record('doaj', [$x], "Nome Alfa Original $t"),
            self::record('doaj', [$y], "Nome Beta Distinto $t"),
        ]);

        $tester = $this->commandTester();
        $exit = $tester->execute(['--dry-run' => true, '--artifact' => $artifact]);
        $display = $tester->getDisplay();

        $this->assertSame(0, $exit, $display);
        $this->assertStringContainsString('Linhas em conflito (por número de artigos)', $display);
        $this->assertStringContainsString("Linha em conflito $t", $display);
        $this->assertStringContainsString("Nome Alfa Original $t", $display);
        $this->assertStringContainsString('isolada', $display);
        $this->assertStringContainsString('Limiar de semelhança entre títulos', $display);
    }

    public function testCommandShowsTheReasonCountriesAndTieOfEachConflictRow(): void
    {
        $t = $this->token();
        [$x, $y] = [self::issn(9100121), self::issn(9100122)];
        $this->createJournal("Revista Perspectiva $t", $x);
        $this->createJournal("Revista Perspectiva $t (Lima)", $y);
        $this->createJournal("REVISTA PERSPECTIVA $t", $x, $y);
        $artifact = $this->artifact([
            self::record('doaj', [$x], "Revista Perspectiva $t", 'Brazil'),
            self::record('doaj', [$y], "Revista Perspectiva $t", 'Peru'),
        ]);

        $tester = $this->commandTester();
        $tester->setInputs([]);
        $exit = $tester->execute(['--dry-run' => true, '--artifact' => $artifact], ['decorated' => false]);
        $display = $tester->getDisplay();

        $this->assertSame(0, $exit, $display);
        $this->assertStringContainsString('Linhas em conflito (por número de artigos)', $display);
        $this->assertStringContainsString('país', $display);
        $this->assertStringContainsString('[BR]', $display);
        $this->assertStringContainsString('[PE]', $display);
        $this->assertStringContainsString('isolada (empate', $display);
        $this->assertStringContainsString('ignorados por contradição entre fontes', $display);
    }

    public function testCommandWarnsWhenTheArtifactIsMissingAndStillRuns(): void
    {
        $tester = $this->commandTester();
        $exit = $tester->execute(['--dry-run' => true, '--artifact' => '/caminho/que/nao/existe.jsonl.gz']);
        $display = $tester->getDisplay();

        $this->assertSame(0, $exit, $display);
        $this->assertStringContainsString('Artefato de evidências não encontrado', $display);
        $this->assertStringContainsString('só com os ISSNs do cadastro', $display);
    }

    public function testCommandFailsWithAMessageWhenTheArtifactIsNotAValidGzip(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'families-');
        $this->files[] = $path;
        file_put_contents($path, "isto não é gzip\n");

        $tester = $this->commandTester();
        $exit = $tester->execute(['--dry-run' => true, '--artifact' => $path]);

        $this->assertNotSame(0, $exit);
        $this->assertNotSame('', trim($tester->getDisplay()));
    }

    // ------------------------------------------------------------------------------------------------------------
    // auxiliares
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param list<array<string, mixed>> $records
     * @param array<int, list<string>> $articles
     * @param array<int, array<array-key, int>> $lattes
     * @param array<int, list<string>> $bases
     *
     * @return array<string, mixed>
     */
    private function plan(array $rows, array $records = [], array $articles = [], array $lattes = [], array $bases = []): array
    {
        return $this->builder->plan($rows, $articles, $lattes, $bases, static fn (): array => $records);
    }

    /**
     * @param list<string> $issns ISSNs com ou sem hífen
     * @param array<string, mixed> $extra substitui qualquer campo da linha
     *
     * @return array<string, mixed>
     */
    private static function row(string $title, array $issns = [], array $extra = []): array
    {
        return $extra + [
            'title' => $title,
            'normalizedTitle' => StringNormalizer::normalizeString($title, true) ?: null,
            'issns' => Issn::extractAll(implode(' ', $issns)),
            'canonical' => null,
            'method' => null,
            'displayTitle' => null,
            'displayTitleSource' => null,
        ];
    }

    /**
     * @param list<string> $issns
     * @param string|null $country país como a fonte o grava: nome em inglês (doaj), em espanhol (latindex) ou código (openalex)
     *
     * @return array<string, mixed>
     */
    private static function record(string $source, array $issns, string $title, ?string $country = null): array
    {
        return [
            'src' => $source,
            'rec' => $issns[0] ?? md5($title),
            'issns' => array_map(static fn (string $issn): string => strtolower(str_replace('-', '', $issn)), $issns),
            'title' => $title,
            'labels' => [],
            'extra' => $country !== null ? ['country' => $country] : [],
        ];
    }

    /**
     * Chaves de artigo distintas (uma por artigo).
     *
     * @return list<string>
     */
    private static function keys(int $count, string $prefix = 'a'): array
    {
        return array_map(static fn (int $n): string => sprintf('DOI:%s-%d', $prefix, $n), range(1, $count));
    }

    /** ISSN válido (0000-0000, com dígito verificador) a partir de sete dígitos; o prefixo 9 não existe no mundo real. */
    private static function issn(int $sevenDigits): string
    {
        $digits = str_pad((string) $sevenDigits, 7, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 7; ++$i) {
            $sum += (int) $digits[$i] * (8 - $i);
        }
        $check = (11 - ($sum % 11)) % 11;

        return substr($digits, 0, 4) . '-' . substr($digits, 4, 3) . ($check === 10 ? 'X' : (string) $check);
    }

    private function token(): string
    {
        return 'Zq' . bin2hex(random_bytes(5));
    }

    /**
     * Grava um artefato .jsonl.gz de fixture: primeira linha `_meta`, depois um registro por linha.
     *
     * @param list<array<string, mixed>> $records
     */
    private function artifact(array $records): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'families-');
        $this->files[] = $path;

        $lines = [json_encode(['_meta' => ['built_at' => '2026-10-30T00:00:00+00:00', 'sources' => ['doaj' => ['file' => 'fixture', 'records' => count($records)]]]], JSON_THROW_ON_ERROR)];
        foreach ($records as $record) {
            $lines[] = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        file_put_contents($path, gzencode(implode("\n", $lines) . "\n"));

        return $path;
    }

    private function createJournal(string $title, ?string $issn = null, ?string $issnE = null, ?string $issnImp = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        if ($issn !== null) {
            $journal->setIssn($issn);
        }
        if ($issnE !== null) {
            $journal->setIssnE($issnE);
        }
        if ($issnImp !== null) {
            $journal->setIssnImp($issnImp);
        }
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    private function createResearcher(): Researcher
    {
        $researcher = new Researcher();
        $researcher->setIdLattes('9977' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT));
        $researcher->setFullName('Docente Teste Famílias');
        $researcher->setSlug('docente-teste-familias-' . bin2hex(random_bytes(4)));
        $this->em->persist($researcher);
        $this->em->flush();

        return $researcher;
    }

    private function addArticle(QualisJournal $journal, Researcher $researcher, ?string $doi, ?string $journalName = null): ProductionItem
    {
        $article = new ProductionItem();
        $article->setItemType(ProductionItem::TYPE_ARTIGO);
        $article->setTitle('Artigo de teste ' . bin2hex(random_bytes(4)));
        $article->setYear(2024);
        $article->setDoi($doi);
        $article->setJournalName($journalName);
        $article->setQualisJournal($journal);
        $researcher->addProduction($article);
        $this->em->persist($article);
        $this->em->flush();

        return $article;
    }

    private function database(string $acronym): AcademicDatabase
    {
        $database = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => $acronym]);
        if ($database === null) {
            $database = new AcademicDatabase();
            $database->setName($acronym . ' (teste)');
            $database->setAcronym($acronym);
            $this->em->persist($database);
            $this->em->flush();
        }

        return $database;
    }

    /**
     * Lê as linhas direto do banco (sem passar pelo EntityManager), indexadas pelo id.
     *
     * @param list<QualisJournal> $journals
     *
     * @return array<int, array<string, mixed>>
     */
    private function snapshot(array $journals): array
    {
        $ids = array_map(static fn (QualisJournal $journal): int => (int) $journal->getId(), $journals);
        $rows = [];
        foreach ($this->conn->fetchAllAssociative(
            'SELECT id, canonical_journal_id, canonical_method, display_title, display_title_source
             FROM qualis_journals WHERE id IN (' . implode(',', $ids) . ') ORDER BY id'
        ) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:journals:families'));
    }
}
