<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Service\Journal\AreaCrosswalk;
use App\Service\Journal\CapesTaxonomy;
use App\Service\Journal\JournalAreaScorer;
use PHPUnit\Framework\TestCase;

/**
 * Pontuação das áreas (A6.4 e A6.6 de docs/evolutions/periodicos-por-base.md). Unitário, sem banco nem kernel: as
 * evidências são arrays literais e os valores esperados foram calculados à mão com as fórmulas do documento.
 *
 * Os casos (a) a (i) usam os crosswalks reais do projeto, que é o que dá sentido aos rótulos do enunciado ("Education &
 * Educational Research", ASJC 3304). Os testes das regras de decisão (passos 3 a 5, rótulo nulo, fonte sem rótulo
 * mapeado) usam um crosswalk de fixture escrito numa pasta temporária, para não depender do conteúdo dos crosswalks.
 */
class JournalAreaScorerTest extends TestCase
{
    private const DELTA = 0.0005;

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------------------
    // Casos do enunciado (a) a (i), com os crosswalks reais
    // ------------------------------------------------------------------------------------------------------------

    public function testOnlyWosEducationGivesEducacaoWithShareOneAndHighConfidence(): void
    {
        $result = $this->scorer()->score(self::record('wos', 'W1', ['Education & Educational Research']));

        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertEqualsWithDelta(1.0, $result['primary']['share'], self::DELTA);
        $this->assertSame([], $result['secondary']);
        $this->assertSame('high', $result['confidence']);
        $this->assertSame(['educacao' => 1.0], $result['shares']);
        $this->assertEqualsWithDelta(1.0, $result['total_weight'], self::DELTA);
        $this->assertSame(['wos' => 1.0], $result['sources']);
    }

    public function testWosPlusScopusRecordKeepsLowShareAreasOutOfTheSecondaries(): void
    {
        $result = $this->scorer()->score([
            ...self::record('wos', 'W1', ['Education & Educational Research']),
            ...self::record('scopus', 'S1', ['3304', '3312']),
        ]);

        // WoS: educacao 1 (w 1). Scopus: 3304 -> educacao 0,5; 3312 -> sociologia 0,25 e ciencia-politica 0,25 (w 1).
        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertEqualsWithDelta(0.75, $result['primary']['share'], self::DELTA);
        $this->assertEqualsWithDelta(0.125, $result['shares']['sociologia'], self::DELTA);
        $this->assertEqualsWithDelta(0.125, $result['shares']['ciencia-politica-relacoes-internacionais'], self::DELTA);
        $this->assertSame([], $result['secondary']);
        $this->assertSame('high', $result['confidence']);
        $this->assertEqualsWithDelta(2.0, $result['total_weight'], self::DELTA);
    }

    public function testThreeWayTieOverThreeBigAreasIsInterdisciplinaryAndTiesGoByAlphabeticalSlug(): void
    {
        // 3304 educacao, 2746 medicina-ii, 1203 linguistica-literatura: 1/3 cada, três grandes áreas.
        $result = $this->scorer()->score(self::record('scopus', 'S1', ['3304', '2746', '1203']));

        $this->assertSame('interdisciplinar', $result['primary']['slug']);
        $this->assertSame('medium', $result['confidence']);
        $this->assertSame(['educacao', 'linguistica-literatura'], array_column($result['secondary'], 'slug'));
        $this->assertEqualsWithDelta(0.3333, $result['secondary'][0]['share'], self::DELTA);
        $this->assertEqualsWithDelta(0.3333, $result['secondary'][1]['share'], self::DELTA);
        foreach (['educacao', 'medicina-ii', 'linguistica-literatura'] as $slug) {
            $this->assertEqualsWithDelta(0.3333, $result['shares'][$slug], self::DELTA);
        }
        // Nenhuma fonte citou "interdisciplinar": o bucket da principal é 0.
        $this->assertSame(0.0, $result['primary']['share']);
    }

    public function testOnlyOpenAlexWithFiveWorksIsEducacaoWithLowWeightAndLowConfidence(): void
    {
        $result = $this->scorer()->score(self::openAlex('S1', ['3304' => 0.9], 5));

        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertEqualsWithDelta(1.0, $result['primary']['share'], self::DELTA);
        // w = log10(5) / 3
        $this->assertEqualsWithDelta(0.23, $result['total_weight'], 0.005);
        $this->assertSame('low', $result['confidence']);
    }

    public function testOpenAlexWithFiveWorksPlusLatindexIsHighConfidence(): void
    {
        $result = $this->scorer()->score([
            ...self::openAlex('S1', ['3304' => 0.9], 5),
            ...self::record('latindex', 'L1', ['Educación']),
        ]);

        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertEqualsWithDelta(1.03, $result['total_weight'], 0.005);
        $this->assertSame('high', $result['confidence']);
        $this->assertEqualsWithDelta(0.2330, $result['sources']['openalex'], self::DELTA);
        $this->assertEqualsWithDelta(0.8, $result['sources']['latindex'], self::DELTA);
    }

    public function testOnlyTitleRuleIsAlwaysLow(): void
    {
        $result = $this->scorer()->score(self::record('title_rule', 'title', ['educacao']));

        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertSame('low', $result['confidence']);
        $this->assertEqualsWithDelta(0.5, $result['total_weight'], self::DELTA);
    }

    public function testOnlyAiPrimaryWithConfidenceNineTenthsIsMedium(): void
    {
        $result = $this->scorer()->score([self::ai('direito', 'primary', 0.9)]);

        $this->assertSame('direito', $result['primary']['slug']);
        $this->assertSame('medium', $result['confidence']);
        $this->assertEqualsWithDelta(0.81, $result['total_weight'], self::DELTA);
        $this->assertSame([], $result['secondary']);
    }

    public function testLegacySociologyPlusWosAnthropologySplitsTheShareAndIsHigh(): void
    {
        $result = $this->scorer()->score([
            ...self::record('legacy', 'area', ['SOCIOLOGIA']),
            ...self::record('wos', 'W1', ['Anthropology']),
        ]);

        // legacy w 0,8 em sociologia; WoS w 1 em antropologia-arqueologia; total 1,8
        $this->assertSame('antropologia-arqueologia', $result['primary']['slug']);
        $this->assertEqualsWithDelta(0.556, $result['primary']['share'], self::DELTA);
        $this->assertSame('sociologia', $result['secondary'][0]['slug']);
        $this->assertEqualsWithDelta(0.444, $result['secondary'][0]['share'], self::DELTA);
        $this->assertCount(1, $result['secondary']);
        $this->assertSame('high', $result['confidence']);
        $this->assertEqualsWithDelta(1.8, $result['total_weight'], self::DELTA);
    }

    public function testOnlyScieloAreaHumanSciencesIsTheGrandeAreaWithMediumConfidence(): void
    {
        $result = $this->scorer()->score(self::record('scielo_area', 'C1', ['Human Sciences']));

        $this->assertSame('ciencias-humanas', $result['primary']['slug']);
        $this->assertSame(1, (new CapesTaxonomy())->levelOf($result['primary']['slug']));
        $this->assertSame('medium', $result['confidence']);
        $this->assertEqualsWithDelta(0.5, $result['total_weight'], self::DELTA);
    }

    public function testOpenAlexPairInformationSystemsAndLibraryScienceGoesToCommunicationInformation(): void
    {
        $result = $this->scorer()->score(self::openAlex('S1', ['1710' => 0.6, '3309' => 0.4], 1000));

        $this->assertSame('comunicacao-informacao-museologia', $result['primary']['slug']);
        $this->assertEqualsWithDelta(1.0, $result['primary']['share'], self::DELTA);
        $this->assertSame(['comunicacao-informacao-museologia' => 1.0], $result['shares']);
        // Fonte única fora da lista de fontes fortes: nunca high.
        $this->assertSame('medium', $result['confidence']);
        $this->assertEqualsWithDelta(1.0, $result['total_weight'], self::DELTA);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Regra do par 1710 + 3309 (A6.6)
    // ------------------------------------------------------------------------------------------------------------

    public function testInformationSystemsAloneStaysComputacao(): void
    {
        $result = $this->scorer()->score(self::openAlex('S1', ['1710' => 1.0], 1000));

        $this->assertSame('computacao', $result['primary']['slug']);
    }

    public function testPairRuleLooksAtTheWholeFamilyAcrossScopusAndOpenAlex(): void
    {
        $result = $this->scorer()->score([
            ...self::record('scopus', 'S1', ['3309']),
            ...self::openAlex('O1', ['1710' => 1.0], 1000),
        ]);

        $this->assertSame(['comunicacao-informacao-museologia' => 1.0], $result['shares']);
    }

    public function testPairRuleAlsoApplies1710InsideTheSameScopusRecord(): void
    {
        $result = $this->scorer()->score(self::record('scopus', 'S1', ['1710', '3309']));

        $this->assertSame(['comunicacao-informacao-museologia' => 1.0], $result['shares']);
    }

    public function testPairRuleIgnoresAnOpenAlexLabelBelowTheShareCut(): void
    {
        // 3309 com share 0,05 não passa do corte: não há par e o 1710 segue o crosswalk.
        $result = $this->scorer()->score(self::openAlex('O1', ['1710' => 0.9, '3309' => 0.05], 1000));

        $this->assertSame(['computacao' => 1.0], $result['shares']);
    }

    public function testPairRuleDoesNotCountAnOpenAlexSourceWithWeightZero(): void
    {
        // OpenAlex com 1 work pesa 0 e não contribui; a presença do 3309 dele não aciona a regra.
        $result = $this->scorer()->score([
            ...self::openAlex('O1', ['3309' => 1.0], 1),
            ...self::record('scopus', 'S1', ['1710']),
        ]);

        $this->assertSame(['computacao' => 1.0], $result['shares']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // OpenAlex: peso por works, corte e renormalização
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider openAlexWeightProvider
     */
    public function testOpenAlexWeightGrowsWithTheLogOfWorks(int $works, float $expected): void
    {
        $result = $this->scorer()->score(self::openAlex('S1', ['3304' => 1.0], $works));

        $this->assertEqualsWithDelta($expected, $result['total_weight'], self::DELTA);
    }

    /**
     * @return array<string, array{int, float}>
     */
    public static function openAlexWeightProvider(): array
    {
        return [
            '10 works' => [10, 0.3333],
            '100 works' => [100, 0.6667],
            '1.000 works' => [1000, 1.0],
            '50.000 works (limitado a 1)' => [50000, 1.0],
        ];
    }

    public function testOpenAlexWithOneWorkWeighsZeroAndContributesNothing(): void
    {
        $result = $this->scorer()->score(self::openAlex('S1', ['3304' => 1.0], 1));

        $this->assertNull($result['primary']);
        $this->assertSame('none', $result['confidence']);
        $this->assertSame([], $result['sources']);
    }

    public function testOpenAlexLabelsBelowTenPercentAreDroppedAndTheRestIsRenormalized(): void
    {
        $result = $this->scorer()->score(self::openAlex('S1', ['3304' => 0.7, '3312' => 0.2, '1203' => 0.05], 1000));

        // 1203 (0,05) cai fora; os mapeados somam 0,9: educacao 0,7/0,9; 3312 divide 0,2 em dois.
        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertEqualsWithDelta(0.7778, $result['shares']['educacao'], self::DELTA);
        $this->assertEqualsWithDelta(0.1111, $result['shares']['sociologia'], self::DELTA);
        $this->assertEqualsWithDelta(0.1111, $result['shares']['ciencia-politica-relacoes-internacionais'], self::DELTA);
        $this->assertArrayNotHasKey('linguistica-literatura', $result['shares']);
    }

    public function testTwoOpenAlexRecordsAreOneSourceWithTheLargestWorks(): void
    {
        $result = $this->scorer()->score([
            ...self::openAlex('O1', ['3304' => 1.0], 10),
            ...self::openAlex('O2', ['3304' => 1.0], 1000),
        ]);

        $this->assertEqualsWithDelta(1.0, $result['total_weight'], self::DELTA);
        $this->assertSame(['openalex' => 1.0], $result['sources']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Entrada: deduplicação, formatos e fontes
    // ------------------------------------------------------------------------------------------------------------

    public function testEvidenceRepeatedForEveryRowOfTheFamilyCountsOnce(): void
    {
        $one = $this->scorer()->score(self::record('wos', 'W1', ['Education & Educational Research', 'Sociology']));
        $repeated = $this->scorer()->score([
            ...self::record('wos', 'W1', ['Education & Educational Research', 'Sociology']),
            ...self::record('wos', 'W1', ['Education & Educational Research', 'Sociology']),
            ...self::record('wos', 'W1', ['Education & Educational Research', 'Sociology']),
        ]);

        $this->assertSame($one, $repeated);
        $this->assertEqualsWithDelta(0.5, $one['shares']['educacao'], self::DELTA);
    }

    public function testSameLabelInDifferentRecordsOfTheSameSourceCountsTwiceButTheSourceWeighsOnce(): void
    {
        $result = $this->scorer()->score([
            ...self::record('wos', 'W1', ['Sociology']),
            ...self::record('wos', 'W2', ['Sociology']),
            ...self::record('wos', 'W3', ['Anthropology']),
        ]);

        $this->assertEqualsWithDelta(0.6667, $result['shares']['sociologia'], self::DELTA);
        $this->assertEqualsWithDelta(0.3333, $result['shares']['antropologia-arqueologia'], self::DELTA);
        $this->assertSame(['wos' => 1.0], $result['sources']);
        $this->assertEqualsWithDelta(1.0, $result['total_weight'], self::DELTA);
    }

    public function testResultDoesNotDependOnTheOrderOfTheEvidence(): void
    {
        $evidence = [
            ...self::record('wos', 'W1', ['Education & Educational Research', 'Sociology']),
            ...self::record('scopus', 'S1', ['3304', '3312', '1203']),
            ...self::record('latindex', 'L1', ['Educación']),
            ...self::openAlex('O1', ['3304' => 0.8, '3312' => 0.2], 50),
            ...self::record('legacy', 'area', ['SOCIOLOGIA']),
        ];
        $expected = $this->scorer()->score($evidence);

        for ($seed = 1; $seed <= 5; ++$seed) {
            mt_srand($seed);
            $shuffled = $evidence;
            shuffle($shuffled);
            $this->assertSame($expected, $this->scorer()->score($shuffled));
        }

        // Sem isto, os testes seguintes repetem os mesmos números "aleatórios" a cada execução.
        mt_srand();
    }

    public function testAcceptsDatabaseShapedInput(): void
    {
        // O banco devolve weight como texto ('0.5000'), payload pode ser nulo ou faltar e o rótulo pode vir como inteiro.
        $result = $this->scorer()->score([
            ['source' => 'scopus', 'record_key' => 'S1', 'label' => 3304, 'weight' => '0.5000', 'payload' => null],
            ['source' => 'scopus', 'record_key' => 'S1', 'label' => '3312', 'weight' => '0.5000'],
        ]);

        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertEqualsWithDelta(0.5, $result['primary']['share'], self::DELTA);
    }

    public function testUnknownSourcesAndBlankLabelsAreIgnored(): void
    {
        $result = $this->scorer()->score([
            self::row('pubmed', 'P1', 'Qualquer coisa'),
            self::row('desconhecida', 'X1', 'Sociology'),
            self::row('wos', 'W1', '   '),
        ]);

        $this->assertNull($result['primary']);
        $this->assertSame('none', $result['confidence']);
    }

    public function testNoEvidenceGivesNoResult(): void
    {
        $this->assertSame(
            ['primary' => null, 'secondary' => [], 'confidence' => 'none', 'shares' => [], 'total_weight' => 0.0, 'sources' => []],
            $this->scorer()->score([])
        );
    }

    public function testUnmappedLabelsOfAKnownSourceYieldNoResult(): void
    {
        $result = $this->scorer()->score(self::record('wos', 'W1', ['Rótulo que não existe no crosswalk']));

        $this->assertNull($result['primary']);
        $this->assertSame([], $result['sources']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Fonte `legacy`: nome da área em texto, sem acento e sem caixa
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider legacyLabelProvider
     */
    public function testLegacyMatchesAreaNamesIgnoringAccentsAndCase(string $label, string $slug): void
    {
        $result = $this->scorer()->score(self::record('legacy', 'area', [$label]));

        $this->assertSame($slug, $result['primary']['slug']);
        $this->assertSame(['legacy' => 0.8], $result['sources']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function legacyLabelProvider(): array
    {
        return [
            'maiúsculas com acento' => ['SAÚDE COLETIVA', 'saude-coletiva'],
            'maiúsculas sem acento' => ['SAUDE COLETIVA', 'saude-coletiva'],
            'minúsculas' => ['saúde coletiva', 'saude-coletiva'],
            'espaço duplicado' => ['Saúde  Coletiva', 'saude-coletiva'],
            'numeral romano' => ['ENGENHARIAS III', 'engenharias-iii'],
            'barra no nome' => ['ZOOTECNIA / RECURSOS PESQUEIROS', 'zootecnia-recursos-pesqueiros'],
            'grande área' => ['CIÊNCIAS HUMANAS', 'ciencias-humanas'],
            'educação física' => ['EDUCAÇÃO FÍSICA, FISIOTERAPIA, FONOAUDIOLOGIA E TERAPIA OCUPACIONAL', 'educacao-fisica'],
        ];
    }

    public function testLegacyLabelThatIsNotACapesNameIsIgnored(): void
    {
        $result = $this->scorer()->score(self::record('legacy', 'area', ['Social Sciences']));

        $this->assertNull($result['primary']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Fonte `ai`
    // ------------------------------------------------------------------------------------------------------------

    public function testAiPrimaryWithTwoSecondariesSplitsHalfAndAQuarterEach(): void
    {
        $result = $this->scorer()->score([
            self::ai('educacao', 'primary', 0.9),
            self::ai('psicologia', 'secondary', 0.9),
            self::ai('sociologia', 'secondary', 0.9),
        ]);

        // pesos 0,81 / 0,405 / 0,405 -> 0,50 / 0,25 / 0,25: as secundárias chegam exatamente ao limiar de 0,25
        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertEqualsWithDelta(0.5, $result['primary']['share'], self::DELTA);
        $this->assertSame(['psicologia', 'sociologia'], array_column($result['secondary'], 'slug'));
        $this->assertEqualsWithDelta(1.62, $result['total_weight'], self::DELTA);
        $this->assertSame('medium', $result['confidence']);
        $this->assertEqualsWithDelta(1.62, $result['sources']['ai'], self::DELTA);
    }

    public function testAiWithConfidenceBelowEightTenthsIsLow(): void
    {
        $result = $this->scorer()->score([self::ai('direito', 'primary', 0.7)]);

        $this->assertSame('direito', $result['primary']['slug']);
        $this->assertSame('low', $result['confidence']);
    }

    public function testAiPlusAnotherSourceFollowsTheGeneralRules(): void
    {
        $result = $this->scorer()->score([
            self::ai('direito', 'primary', 0.9),
            ...self::record('wos', 'W1', ['Law']),
        ]);

        $this->assertSame('direito', $result['primary']['slug']);
        $this->assertSame('high', $result['confidence']);
        $this->assertEqualsWithDelta(1.81, $result['total_weight'], self::DELTA);
    }

    public function testAiRowWithoutValidSlugOrConfidenceIsIgnored(): void
    {
        $result = $this->scorer()->score([
            self::ai('nao-existe', 'primary', 0.9),
            ['source' => 'ai', 'record_key' => 'k', 'label' => 'direito', 'weight' => 1.0, 'payload' => ['role' => 'primary']],
            ['source' => 'ai', 'record_key' => 'k', 'label' => 'educacao', 'weight' => 1.0, 'payload' => ['confidence' => 0, 'role' => 'primary']],
        ]);

        $this->assertNull($result['primary']);
    }

    public function testAiWithoutRoleUsesTheRowWeightToTellPrimaryFromSecondary(): void
    {
        $result = $this->scorer()->score([
            ['source' => 'ai', 'record_key' => 'k', 'label' => 'educacao', 'weight' => 1.0, 'payload' => ['confidence' => 0.8]],
            ['source' => 'ai', 'record_key' => 'k', 'label' => 'psicologia', 'weight' => 0.5, 'payload' => ['confidence' => 0.8]],
        ]);

        // 0,72 primária e 0,36 secundária
        $this->assertEqualsWithDelta(0.6667, $result['shares']['educacao'], self::DELTA);
        $this->assertEqualsWithDelta(0.3333, $result['shares']['psicologia'], self::DELTA);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Confiança (passo 6)
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @dataProvider confidenceProvider
     *
     * @param list<array<string, mixed>> $evidence
     */
    public function testConfidenceRules(array $evidence, string $slug, string $confidence, float $totalWeight): void
    {
        $result = $this->scorer()->score($evidence);

        $this->assertSame($slug, $result['primary']['slug']);
        $this->assertSame($confidence, $result['confidence']);
        $this->assertEqualsWithDelta($totalWeight, $result['total_weight'], self::DELTA);
    }

    /**
     * @return array<string, array{list<array<string, mixed>>, string, string, float}>
     */
    public static function confidenceProvider(): array
    {
        return [
            'Scopus sozinho é fonte forte: high' => [self::record('scopus', 'S1', ['3304']), 'educacao', 'high', 1.0],
            'SciELO WoS sozinho é fonte forte: high' => [self::record('scielo_wos', 'C1', ['EDUCATION & EDUCATIONAL RESEARCH']), 'educacao', 'high', 1.0],
            'legacy sozinho pesa 0,8: medium' => [self::record('legacy', 'area', ['EDUCAÇÃO']), 'educacao', 'medium', 0.8],
            'DOAJ sozinho pesa 0,8: medium' => [self::record('doaj', 'D1', ['Education']), 'educacao', 'medium', 0.8],
            'SciELO descritor sozinho pesa 0,9: medium' => [self::record('scielo_desc', 'C1', ['EDUCAÇÃO']), 'educacao', 'medium', 0.9],
            'DOAJ e Latindex concordando somam 1,6: high' => [
                [...self::record('doaj', 'D1', ['Education']), ...self::record('latindex', 'L1', ['Educación'])],
                'educacao',
                'high',
                1.6,
            ],
            'título e SciELO área no limite exato (share 0,5 e soma 1,0) com duas fontes: high' => [
                [...self::record('title_rule', 'title', ['educacao']), ...self::record('scielo_area', 'C1', ['Human Sciences'])],
                'educacao',
                'high',
                1.0,
            ],
            'título com WoS: a regra "só title_rule" não vale' => [
                [...self::record('title_rule', 'title', ['educacao']), ...self::record('wos', 'W1', ['Education & Educational Research'])],
                'educacao',
                'high',
                1.5,
            ],
        ];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Regras de decisão (passos 2 a 5), com crosswalk de fixture
    // ------------------------------------------------------------------------------------------------------------

    public function testNullMappedLabelIsIgnoredAndItsWeightLeavesTheDenominator(): void
    {
        $result = $this->fixtureScorer(['sem-area' => null, 'b' => 'educacao'])
            ->score(self::weighted('wos', ['sem-area' => 0.5, 'b' => 0.5]));

        $this->assertSame(['educacao' => 1.0], $result['shares']);
        $this->assertEqualsWithDelta(1.0, $result['total_weight'], self::DELTA);
    }

    public function testSourceWithoutMappedLabelDoesNotEnterTheSums(): void
    {
        $result = $this->fixtureScorer(['sem-area' => null], ['x' => 'educacao'])->score([
            ...self::weighted('wos', ['sem-area' => 1.0]),
            ...self::weighted('doaj', ['x' => 1.0]),
        ]);

        $this->assertSame(['doaj' => 0.8], $result['sources']);
        $this->assertEqualsWithDelta(0.8, $result['total_weight'], self::DELTA);
        $this->assertSame(['educacao' => 1.0], $result['shares']);
    }

    public function testOneLabelCanSplitBetweenTwoSlugs(): void
    {
        $result = $this->fixtureScorer(['dividido' => ['educacao', 'sociologia'], 'sozinho' => 'psicologia'])
            ->score(self::weighted('wos', ['dividido' => 0.6, 'sozinho' => 0.4]));

        $this->assertEqualsWithDelta(0.3, $result['shares']['educacao'], self::DELTA);
        $this->assertEqualsWithDelta(0.3, $result['shares']['sociologia'], self::DELTA);
        $this->assertEqualsWithDelta(0.4, $result['shares']['psicologia'], self::DELTA);
        $this->assertSame('psicologia', $result['primary']['slug']);
    }

    public function testAreaAboveThirtyFourPercentBeatsTheGrandeAreaEvenWhenTheGrandeAreaIsLarger(): void
    {
        // educacao 0,35 (nível 2) contra ciencias-humanas 0,65 (nível 1): vale o passo 2
        $result = $this->fixtureScorer(['a' => 'ciencias-humanas', 'b' => 'educacao'])
            ->score(self::weighted('wos', ['a' => 0.65, 'b' => 0.35]));

        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertEqualsWithDelta(0.35, $result['primary']['share'], self::DELTA);
        $this->assertSame('medium', $result['confidence']);
        // ciencias-humanas (0,65) tem a principal como filha entre os buckets: não entra como secundária
        $this->assertSame([], $result['secondary']);
    }

    public function testThresholdsAbsorbFloatingPointError(): void
    {
        // Com estes pesos o share de educacao sai 0,33999999999999997 em ponto flutuante: o limiar de 0,34 é inclusivo e o
        // erro de arredondamento não pode custar a principal (sem tolerância viraria espalhamento e interdisciplinar)
        $result = $this->fixtureScorer(['a' => 'educacao', 'b' => 'educacao', 'c' => 'direito', 'd' => 'psicologia', 'e' => 'historia'])
            ->score(self::weighted('wos', ['a' => 0.03, 'b' => 0.31, 'c' => 0.20, 'd' => 0.20, 'e' => 0.26]));

        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertEqualsWithDelta(0.34, $result['primary']['share'], self::DELTA);
        $this->assertSame('medium', $result['confidence']);
    }

    public function testGrandeAreaWithAChildAboveTwentyPercentMakesTheChildThePrimary(): void
    {
        // nenhuma área de nível 2 chega a 0,34; ciencias-humanas tem 0,40 e educacao 0,25 (>= 0,20): principal = educacao
        $result = $this->fixtureScorer([
            'a' => 'ciencias-humanas',
            'b' => 'educacao',
            'c' => 'psicologia',
            'd' => 'sociologia',
        ])->score(self::weighted('wos', ['a' => 0.40, 'b' => 0.25, 'c' => 0.20, 'd' => 0.15]));

        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertEqualsWithDelta(0.25, $result['primary']['share'], self::DELTA);
        // A confiança olha o share do bucket da principal: 0,25 < 0,34 é sempre low.
        $this->assertSame('low', $result['confidence']);
        // ciencias-humanas tem filhos presentes e psicologia tem 0,20 (< 0,25): nenhuma secundária
        $this->assertSame([], $result['secondary']);
    }

    public function testGrandeAreaWithoutAChildAboveTwentyPercentStaysAsThePrimary(): void
    {
        $result = $this->fixtureScorer([
            'a' => 'ciencias-humanas',
            'b' => 'educacao',
            'c' => 'psicologia',
            'e' => 'direito',
        ])->score(self::weighted('wos', ['a' => 0.50, 'b' => 0.15, 'c' => 0.15, 'e' => 0.20]));

        $this->assertSame('ciencias-humanas', $result['primary']['slug']);
        $this->assertEqualsWithDelta(0.5, $result['primary']['share'], self::DELTA);
        $this->assertSame([], $result['secondary']);
    }

    public function testSpreadInsideOneGrandeAreaKeepsTheLargestBucketWithLowConfidence(): void
    {
        $result = $this->fixtureScorer(['b' => 'educacao', 'c' => 'psicologia', 'd' => 'sociologia', 'g' => 'historia'])
            ->score(self::weighted('wos', ['b' => 0.30, 'c' => 0.30, 'd' => 0.20, 'g' => 0.20]));

        // nenhum >= 0,34 e tudo em Ciências Humanas: maior bucket (empate educacao/psicologia: alfabética), low
        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertSame('low', $result['confidence']);
        $this->assertSame(['psicologia'], array_column($result['secondary'], 'slug'));
    }

    public function testSpreadBelowFifteenPercentEverywhereIsNotInterdisciplinary(): void
    {
        // oito buckets de 0,125: nenhum chega a 0,15, então não há "duas grandes áreas": maior bucket, low
        $labels = ['educacao', 'psicologia', 'direito', 'quimica', 'enfermagem', 'artes', 'zootecnia-recursos-pesqueiros', 'filosofia'];
        $map = [];
        $weights = [];
        foreach ($labels as $position => $slug) {
            $map['r' . $position] = $slug;
            $weights['r' . $position] = 0.125;
        }

        $result = $this->fixtureScorer($map)->score(self::weighted('wos', $weights));

        $this->assertSame('artes', $result['primary']['slug']);
        $this->assertSame('low', $result['confidence']);
        $this->assertSame([], $result['secondary']);
    }

    public function testSpreadOverSeveralGrandesAreasIsInterdisciplinaryWithMediumConfidence(): void
    {
        $result = $this->fixtureScorer(['b' => 'educacao', 'e' => 'direito', 'f' => 'quimica', 'g' => 'enfermagem'])
            ->score(self::weighted('wos', ['b' => 0.30, 'e' => 0.30, 'f' => 0.20, 'g' => 0.20]));

        $this->assertSame('interdisciplinar', $result['primary']['slug']);
        $this->assertSame('medium', $result['confidence']);
        $this->assertSame(['direito', 'educacao'], array_column($result['secondary'], 'slug'));
    }

    public function testSpreadOnlyFromTitleRulesIsInterdisciplinaryButStaysLow(): void
    {
        // Três slugs de regras de título (1/3 cada): espalhamento, mas "só title_rule" é sempre low
        $result = $this->scorer()->score(self::record('title_rule', 'title', ['educacao', 'direito', 'quimica']));

        $this->assertSame('interdisciplinar', $result['primary']['slug']);
        $this->assertSame('low', $result['confidence']);
    }

    public function testInterdisciplinaryByTheSourceItselfWins(): void
    {
        $result = $this->scorer()->score(self::record('wos', 'W1', ['Multidisciplinary Sciences']));

        $this->assertSame('interdisciplinar', $result['primary']['slug']);
        $this->assertEqualsWithDelta(1.0, $result['primary']['share'], self::DELTA);
        $this->assertSame('high', $result['confidence']);
    }

    public function testSecondariesAreLimitedToTwoAndTiesGoByAlphabeticalOrder(): void
    {
        // quatro áreas de Ciências Humanas com 0,25: principal = a primeira em ordem alfabética (espalhamento numa só
        // grande área, low); sobram três candidatas a secundária e entram duas, também pela ordem alfabética
        $result = $this->fixtureScorer(['a' => 'educacao', 'b' => 'psicologia', 'c' => 'sociologia', 'd' => 'historia'])
            ->score(self::weighted('wos', ['a' => 0.25, 'b' => 0.25, 'c' => 0.25, 'd' => 0.25]));

        $this->assertSame('educacao', $result['primary']['slug']);
        $this->assertSame('low', $result['confidence']);
        $this->assertSame(['historia', 'psicologia'], array_column($result['secondary'], 'slug'));
    }

    public function testGrandeAreaSecondaryRequiresThatNoChildOfItIsPresent(): void
    {
        $fixture = $this->fixtureScorer(['a' => 'educacao', 'b' => 'ciencias-da-saude', 'c' => 'ciencias-humanas', 'd' => 'medicina-i']);

        // ciencias-da-saude (0,30) sem nenhum filho entre os buckets: entra como secundária; ciencias-humanas (0,20) não chega a 0,25
        $without = $fixture->score(self::weighted('wos', ['a' => 0.50, 'b' => 0.30, 'c' => 0.20]));
        $this->assertSame('educacao', $without['primary']['slug']);
        $this->assertSame(['ciencias-da-saude'], array_column($without['secondary'], 'slug'));

        // com medicina-i presente (0,05), ciencias-da-saude (0,30) perde o lugar
        $with = $fixture->score(self::weighted('wos', ['a' => 0.50, 'b' => 0.30, 'c' => 0.15, 'd' => 0.05]));
        $this->assertSame('educacao', $with['primary']['slug']);
        $this->assertSame([], $with['secondary']);

        // e o pai da principal nunca é secundária, mesmo com 0,30
        $parent = $fixture->score(self::weighted('wos', ['a' => 0.70, 'c' => 0.30]));
        $this->assertSame('educacao', $parent['primary']['slug']);
        $this->assertSame([], $parent['secondary']);
    }

    public function testLevelTwoSecondariesComeBeforeAGrandeAreaWithAHigherShare(): void
    {
        // educacao 0,30, ciencias-da-saude 0,30 (nível 1, sem filhos), sociologia 0,25, direito 0,15: espalhamento em
        // três grandes áreas -> interdisciplinar; a grande área só entraria depois das áreas de nível 2
        $result = $this->fixtureScorer(['a' => 'educacao', 'b' => 'ciencias-da-saude', 'c' => 'sociologia', 'd' => 'direito'])
            ->score(self::weighted('wos', ['a' => 0.30, 'b' => 0.30, 'c' => 0.25, 'd' => 0.15]));

        $this->assertSame('interdisciplinar', $result['primary']['slug']);
        $this->assertSame('medium', $result['confidence']);
        $this->assertSame(['educacao', 'sociologia'], array_column($result['secondary'], 'slug'));
    }

    public function testSharesComeOutInDescendingOrderWithAlphabeticalTies(): void
    {
        $result = $this->scorer()->score(self::record('scopus', 'S1', ['3304', '2746', '1203']));

        $this->assertSame(['educacao', 'linguistica-literatura', 'medicina-ii'], array_keys($result['shares']));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Auxiliares
    // ------------------------------------------------------------------------------------------------------------

    private function scorer(): JournalAreaScorer
    {
        return new JournalAreaScorer(new AreaCrosswalk(), new CapesTaxonomy());
    }

    /**
     * Scorer com crosswalks de fixture (fontes `wos` e `doaj`), numa pasta temporária.
     *
     * @param array<string, string|list<string>|null> $wos
     * @param array<string, string|list<string>|null> $doaj
     */
    private function fixtureScorer(array $wos, array $doaj = []): JournalAreaScorer
    {
        $directory = sys_get_temp_dir() . '/area-scorer-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700, true));
        $this->temporaryDirectories[] = $directory;

        foreach (['wos' => $wos, 'doaj' => $doaj] as $source => $map) {
            file_put_contents(
                $directory . '/crosswalk_' . $source . '.json',
                json_encode(['_meta' => ['source' => $source], 'map' => (object) $map], JSON_THROW_ON_ERROR)
            );
        }

        $taxonomy = new CapesTaxonomy();

        return new JournalAreaScorer(new AreaCrosswalk($directory, $taxonomy), $taxonomy);
    }

    /**
     * Uma linha de evidência.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function row(string $source, string $recordKey, string $label, float $weight = 1.0, array $payload = []): array
    {
        return ['source' => $source, 'record_key' => $recordKey, 'label' => $label, 'weight' => $weight, 'payload' => $payload];
    }

    /**
     * Um registro de fonte de rótulo simples: peso 1/n em cada rótulo (como na carga).
     *
     * @param list<string> $labels
     *
     * @return list<array<string, mixed>>
     */
    private static function record(string $source, string $recordKey, array $labels): array
    {
        $rows = [];
        foreach ($labels as $label) {
            $rows[] = self::row($source, $recordKey, $label, 1 / count($labels));
        }

        return $rows;
    }

    /**
     * Um registro com os pesos dados (a soma não precisa ser 1).
     *
     * @param array<string, float> $weights rótulo => peso
     *
     * @return list<array<string, mixed>>
     */
    private static function weighted(string $source, array $weights): array
    {
        $rows = [];
        foreach ($weights as $label => $weight) {
            $rows[] = self::row($source, 'R1', (string) $label, $weight);
        }

        return $rows;
    }

    /**
     * Um registro do OpenAlex: `share` por código ASJC e `payload.works` em toda linha.
     *
     * @param array<string, float> $shares código => share
     *
     * @return list<array<string, mixed>>
     */
    private static function openAlex(string $recordKey, array $shares, int $works): array
    {
        $rows = [];
        foreach ($shares as $code => $share) {
            $rows[] = self::row('openalex', $recordKey, (string) $code, $share, ['works' => $works]);
        }

        return $rows;
    }

    /**
     * Uma linha de evidência de IA, como a importação grava.
     *
     * @return array<string, mixed>
     */
    private static function ai(string $slug, string $role, float $confidence): array
    {
        return [
            'source' => 'ai',
            'record_key' => 'issn:00000000',
            'label' => $slug,
            'weight' => $role === 'primary' ? 1.0 : 0.5,
            'payload' => ['confidence' => $confidence, 'role' => $role],
        ];
    }
}
