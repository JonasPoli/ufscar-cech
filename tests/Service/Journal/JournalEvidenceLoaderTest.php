<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Entity\AcademicDatabase;
use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Service\Journal\AreaCrosswalk;
use App\Service\Journal\CapesTaxonomy;
use App\Service\Journal\Issn;
use App\Service\Journal\JournalAreaScorer;
use App\Service\Journal\JournalAreaThesaurus;
use App\Service\Journal\JournalEvidenceArtifactBuilder;
use App\Service\Journal\JournalEvidenceLoader;
use App\Service\Thesaurus\JournalResolverService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Cada teste roda numa transação revertida no tearDown: nada fica gravado em `cech_test`. Os periódicos de teste têm
 * ISSNs 99xxxxxx (inexistentes) e títulos com sufixo único; as asserções olham só para os ids criados aqui, porque o
 * banco de testes é compartilhado e pode ter outras linhas.
 */
class JournalEvidenceLoaderTest extends KernelTestCase
{
    private const BUILT_AT = '2026-10-30T20:15:00+00:00';
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    private EntityManagerInterface $em;
    private Connection $conn;
    private JournalEvidenceLoader $loader;
    private string $uniq;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->loader = $container->get(JournalEvidenceLoader::class);
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
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------------------
    // Carga do artefato
    // ------------------------------------------------------------------------------------------------------------

    public function testLoadsEvidenceByIssnAndTitleWithWeightsAndFirstRowPayload(): void
    {
        [$a, $b, $c, $unknown] = [self::n8(1), self::n8(2), self::n8(3), self::n8(99)];
        $u = $this->uniq;
        $j1 = $this->createJournal("Revista Alfa $u", Issn::format($a));
        $j2 = $this->createJournal("Revista Alfa Online $u", null, Issn::format($b));
        $j3 = $this->createJournal("Revista Gama $u");
        $j4 = $this->createJournal("Revista Delta $u", Issn::format($c));

        $artifact = $this->writeArtifact([
            self::record('wos', 'W1', [$a, $b], 'Revista Alfa Wos', ['Education & Educational Research', 'Sociology', 'History'], ['indexes' => ['SSCI']]),
            self::record('scopus', 'S1', [$a], 'Revista Alfa Scopus', ['3304', '3312'], ['publisher' => 'Editora X', 'active' => true, 'type' => 'Journal']),
            self::record('openalex', 'S100', [$b], 'Revista Alfa OA', [
                ['code' => '3304', 'share' => 0.6],
                ['code' => '3312', 'share' => 0.3],
                ['code' => '1203', 'share' => 0.05],
            ], ['works' => 123, 'country' => 'BR', 'publisher' => null, 'in_doaj' => true, 'type' => 'journal', 'alt_titles' => ['Alfa']]),
            self::record('scielo', 'SC1', [$a, $b], 'Revista Alfa Scielo', ['Human Sciences'], [
                'wos' => ['EDUCATION & EDUCATIONAL RESEARCH', 'SOCIOLOGY'],
                'desc' => ['EDUCAÇÃO'],
                'status' => 'C',
                'issn_types' => [$a => 'PRINT', $b => 'ONLIN'],
            ]),
            self::record('pubmed', 'P1', [$a], 'Revista Alfa Pubmed', [], ['medabbr' => 'Alfa']),
            self::record('doaj', 'D1', [$c], 'Revista Delta Doaj', ['Law'], ['country' => 'BR']),
            // O ISSN casa com J4: o título (igual ao de J3) não é usado como alternativa
            self::record('doaj', 'D2', [$c], 'REVISTA GAMA ' . strtoupper($u), ['Education'], []),
            // Sem ISSN: casa por título com a linha sem ISSN (J3), ignorando caixa
            self::record('latindex', 'L1', [], 'REVISTA GAMA ' . strtoupper($u), ['Derecho', 'Historia'], ['country' => 'Brasil']),
            self::record('wos', 'W2', [$unknown], "Revista Que Nao Existe $u", ['Law'], []),
        ]);

        $result = $this->loader->load($artifact);

        $this->assertSame(9, $result['records']);
        $this->assertSame(22, $result['evidence_rows']);
        $this->assertSame(4, $result['journals_hit']);
        $this->assertSame(1, $result['records_unmatched']);
        $this->assertSame(0, $result['records_skipped']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['legacy_rows']);
        $this->assertSame(0, $result['title_rule_rows']);
        $this->assertSame(
            ['doaj' => 2, 'latindex' => 2, 'openalex' => 2, 'scielo_area' => 2, 'scielo_desc' => 2, 'scielo_wos' => 4, 'scopus' => 2, 'wos' => 6],
            $result['by_source']
        );
        $this->assertSame(
            ['doaj' => 2, 'latindex' => 1, 'openalex' => 1, 'pubmed' => 1, 'scielo' => 1, 'scopus' => 1, 'wos' => 2],
            $result['records_by_source']
        );
        $this->assertSame('2026-10-30', $result['batch']);
        $this->assertFalse($result['dry_run']);

        $rows = $this->evidence([$j1, $j2, $j3, $j4]);
        $this->assertCount(22, $rows);
        $this->assertSame(9, $this->countFor($rows, $j1));
        $this->assertSame(9, $this->countFor($rows, $j2));
        $this->assertSame(2, $this->countFor($rows, $j3));
        $this->assertSame(2, $this->countFor($rows, $j4));
        foreach ($rows as $row) {
            $this->assertSame('2026-10-30', $row['batch']);
        }

        // J3 só recebe o registro sem ISSN (L1) e J4 recebe os dois registros do DOAJ com o ISSN dele
        $this->assertSame(['L1'], array_values(array_unique(array_column($this->rowsOf($rows, $j3), 'record_key'))));
        $this->assertSame(['D1', 'D2'], array_values(array_unique(array_column($this->rowsOf($rows, $j4), 'record_key'))));

        // Pesos: 1/n somando exatamente 1,0000 por (linha, fonte, registro)
        $this->assertSame(['0.3334', '0.3333', '0.3333'], $this->weightsOf($rows, $j1, 'wos', 'W1'));
        $this->assertSame(['0.5000', '0.5000'], $this->weightsOf($rows, $j1, 'scopus', 'S1'));
        $this->assertSame(['1.0000'], $this->weightsOf($rows, $j1, 'scielo_area', 'SC1'));
        $this->assertSame(['0.5000', '0.5000'], $this->weightsOf($rows, $j1, 'scielo_wos', 'SC1'));
        $this->assertSame(['1.0000'], $this->weightsOf($rows, $j1, 'scielo_desc', 'SC1'));
        $sums = $this->conn->fetchAllAssociative(
            "SELECT journal_id, source, record_key, SUM(weight) AS total FROM journal_area_evidence
             WHERE journal_id IN (?) AND source <> 'openalex' GROUP BY journal_id, source, record_key",
            [[$j1, $j2, $j3, $j4]],
            [ArrayParameterType::INTEGER]
        );
        $this->assertNotEmpty($sums);
        foreach ($sums as $sum) {
            $this->assertSame('1.0000', $sum['total'], sprintf('%s/%s/%s', $sum['journal_id'], $sum['source'], $sum['record_key']));
        }

        // OpenAlex: label = código, weight = share, sem renormalizar e sem o rótulo com share < 0,10
        $openalex = $this->rowsOf($rows, $j2, 'openalex');
        $this->assertSame(['3304', '3312'], array_column($openalex, 'label'));
        $this->assertSame(['0.6000', '0.3000'], array_column($openalex, 'weight'));

        // source_title e payload só na primeira linha do par (registro, linha) ...
        $wos = $this->rowsOf($rows, $j1, 'wos');
        $this->assertSame('Revista Alfa Wos', $wos[0]['source_title']);
        // O JSON do MySQL reordena as chaves do objeto: compara sem considerar a ordem
        $this->assertEquals(['indexes' => ['SSCI'], 'issns' => [$a, $b]], json_decode((string) $wos[0]['payload'], true));
        $this->assertNull($wos[1]['source_title']);
        $this->assertNull($wos[1]['payload']);
        $this->assertNull($wos[2]['source_title']);
        $this->assertNull($wos[2]['payload']);

        // ... exceto o OpenAlex, em que toda linha traz ao menos {"works": N}; o payload completo não tem alt_titles
        $this->assertSame('Revista Alfa OA', $openalex[0]['source_title']);
        $this->assertEquals(
            ['works' => 123, 'country' => 'BR', 'publisher' => null, 'in_doaj' => true, 'type' => 'journal', 'issns' => [$b]],
            json_decode((string) $openalex[0]['payload'], true)
        );
        $this->assertNull($openalex[1]['source_title']);
        $this->assertSame(['works' => 123], json_decode((string) $openalex[1]['payload'], true));

        // SciELO: o par (registro, linha) é um só, mesmo desdobrado em três fontes; só a primeira linha leva o payload
        $scielo = array_values(array_filter($this->rowsOf($rows, $j1), static fn (array $row): bool => $row['record_key'] === 'SC1'));
        $this->assertSame(['scielo_area', 'scielo_wos', 'scielo_wos', 'scielo_desc'], array_column($scielo, 'source'));
        $this->assertSame('Revista Alfa Scielo', $scielo[0]['source_title']);
        $payload = json_decode((string) $scielo[0]['payload'], true);
        $this->assertSame(['EDUCAÇÃO'], $payload['desc']);
        $this->assertSame([$a => 'PRINT', $b => 'ONLIN'], $payload['issn_types']);
        $this->assertSame([$a, $b], $payload['issns']);
        foreach (array_slice($scielo, 1) as $row) {
            $this->assertNull($row['source_title']);
            $this->assertNull($row['payload']);
        }

        // Exatamente uma linha com payload por par (registro, linha), salvo o OpenAlex
        $withPayload = $this->conn->fetchAllAssociative(
            "SELECT journal_id, record_key, COUNT(*) AS n FROM journal_area_evidence
             WHERE journal_id IN (?) AND source <> 'openalex' AND payload IS NOT NULL GROUP BY journal_id, record_key",
            [[$j1, $j2, $j3, $j4]],
            [ArrayParameterType::INTEGER]
        );
        $this->assertNotEmpty($withPayload);
        foreach ($withPayload as $pair) {
            $this->assertSame('1', (string) $pair['n'], sprintf('%s/%s', $pair['journal_id'], $pair['record_key']));
        }
    }

    public function testReloadDoesNotDuplicateAndReplacesOnlyDeclaredSources(): void
    {
        $a = self::n8(11);
        $j1 = $this->createJournal("Revista Recarga $this->uniq", Issn::format($a));
        $this->insertEvidence($j1, 'ai', 'IA', 'educacao');
        $this->insertEvidence($j1, 'legacy', 'area', 'SOCIOLOGIA');
        $this->insertEvidence($j1, 'title_rule', 'title', 'educacao');
        $this->insertEvidence($j1, 'doaj', 'OLD-DOAJ', 'Law');
        $this->insertEvidence($j1, 'scopus', 'OLD-SCOPUS', '1203');

        // O artefato declara só o Scopus (o DOAJ foi pulado na construção, então não aparece em _meta.sources)
        $artifact = $this->writeArtifact([
            self::record('scopus', 'S1', [$a], 'Revista Recarga', ['3304', '3312'], []),
        ]);

        $first = $this->loader->load($artifact);
        $this->assertSame(1, $first['deleted']);
        $this->assertSame(2, $first['evidence_rows']);

        $bySource = $this->countBySource($j1);
        $this->assertSame(['ai' => 1, 'doaj' => 1, 'legacy' => 1, 'scopus' => 2, 'title_rule' => 1], $bySource);
        $this->assertSame(
            ['3304', '3312'],
            array_column($this->rowsOf($this->evidence([$j1]), $j1, 'scopus'), 'label'),
            'a evidência antiga do Scopus (OLD-SCOPUS) foi substituída'
        );
        $this->assertSame(
            ['OLD-DOAJ'],
            array_column($this->rowsOf($this->evidence([$j1]), $j1, 'doaj'), 'record_key'),
            'o DOAJ não está no artefato: fica como estava'
        );

        $second = $this->loader->load($artifact);
        $this->assertSame(2, $second['deleted']);
        $this->assertSame(2, $second['evidence_rows']);
        $this->assertSame($bySource, $this->countBySource($j1));
    }

    public function testRecordsOfSourcesNotDeclaredInMetaAreSkipped(): void
    {
        $a = self::n8(21);
        $j1 = $this->createJournal("Revista Declarada $this->uniq", Issn::format($a));

        $artifact = $this->writeArtifact(
            [
                self::record('scopus', 'S1', [$a], 'Revista Declarada', ['3304'], []),
                self::record('wos', 'W1', [$a], 'Revista Declarada', ['Education & Educational Research'], []),
            ],
            ['scopus' => ['file' => 'mini.xlsx', 'records' => 1]]
        );

        $result = $this->loader->load($artifact);

        $this->assertSame(1, $result['records']);
        $this->assertSame(1, $result['records_skipped']);
        $this->assertSame(['scopus' => 1], $this->countBySource($j1));
    }

    public function testArtifactWithoutDeclaredSourcesIsRejectedBeforeAnyChange(): void
    {
        $a = self::n8(22);
        $j1 = $this->createJournal("Revista Sem Fontes $this->uniq", Issn::format($a));
        $this->insertEvidence($j1, 'scopus', 'OLD', '1203');

        $artifact = $this->writeArtifact(
            [self::record('scopus', 'S1', [$a], 'Revista Sem Fontes', ['3304'], [])],
            [],
        );

        try {
            $this->loader->load($artifact);
            $this->fail('Era esperada uma RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('_meta.sources', $e->getMessage());
        }

        $this->assertSame(['scopus' => 1], $this->countBySource($j1), 'nada foi apagado nem gravado');
    }

    public function testMissingArtifactFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('não encontrado');

        $this->loader->load(sys_get_temp_dir() . '/nao-existe-' . $this->uniq . '.jsonl.gz');
    }

    public function testDryRunReportsTheNumbersButWritesNothing(): void
    {
        $a = self::n8(31);
        $j1 = $this->createJournal("Revista Simulada $this->uniq", Issn::format($a));
        $this->insertEvidence($j1, 'scopus', 'OLD', '1203');
        $artifact = $this->writeArtifact([self::record('scopus', 'S1', [$a], 'Revista Simulada', ['3304', '3312'], [])]);

        $result = $this->loader->load($artifact, false, false, false, null, true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(2, $result['evidence_rows']);
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(['1203'], array_column($this->rowsOf($this->evidence([$j1]), $j1, 'scopus'), 'label'), 'a evidência antiga continua lá');
    }

    public function testProgressCallbackReceivesMessages(): void
    {
        $a = self::n8(32);
        $this->createJournal("Revista Andamento $this->uniq", Issn::format($a));
        $artifact = $this->writeArtifact([self::record('scopus', 'S1', [$a], 'Revista Andamento', ['3304'], [])]);

        $messages = [];
        $this->loader->load($artifact, false, false, false, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('Registros lidos: 1', implode("\n", $messages));
    }

    public function testRealArtifactFormatBuiltFromFixtures(): void
    {
        $out = $this->tempPath('.jsonl.gz');
        $fixtures = dirname(__DIR__, 2) . '/fixtures/journal_sources';
        static::getContainer()->get(JournalEvidenceArtifactBuilder::class)->build($fixtures, $fixtures, $out, null);

        // Paidéia (SciELO), ABACUS (WoS) e "Advances in intelligent systems and computing" (OpenAlex)
        $paideia = $this->createJournal("Paidéia Fixture $this->uniq", '0103-863X', '1982-4327');
        $abacus = $this->createJournal("Abacus Fixture $this->uniq", '0001-3072');
        $advances = $this->createJournal("Advances Fixture $this->uniq", '2194-5357');

        $result = $this->loader->load($out);

        $this->assertSame(17, $result['records']);
        $rows = $this->evidence([$paideia, $abacus, $advances]);

        $this->assertSame(
            ['scielo_area' => 'Human Sciences', 'scielo_wos' => 'PSYCHOLOGY, MULTIDISCIPLINARY', 'scielo_desc' => 'PSICOLOGIA'],
            array_column($this->rowsOf($rows, $paideia), 'label', 'source')
        );
        $this->assertSame(['Business, Finance'], array_column($this->rowsOf($rows, $abacus), 'label'));
        $openalex = $this->rowsOf($rows, $advances);
        $this->assertSame(['1702', '1710'], array_column($openalex, 'label'));
        $this->assertSame(['0.7273', '0.1818'], array_column($openalex, 'weight'));
        $this->assertSame(['works' => 73533], json_decode((string) $openalex[1]['payload'], true));
        $this->assertArrayNotHasKey('alt_titles', json_decode((string) $openalex[0]['payload'], true));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Legado
    // ------------------------------------------------------------------------------------------------------------

    public function testLegacyCreatesEvidenceForCapesAreaNamesOnly(): void
    {
        $u = $this->uniq;
        $canonical = (new CapesTaxonomy())->areaLabel('saude-coletiva');

        $sociologia = $this->createJournal("Legado Sociologia $u", null, null, 'SOCIOLOGIA');
        $social = $this->createJournal("Legado Social Sciences $u", null, null, 'Social Sciences');
        $wosCategories = $this->createJournal("Legado WoS $u", null, null, 'Education & Educational Research|Sociology');
        $classified = $this->createJournal("Legado Classificado $u", null, null, $canonical, 'SAÚDE COLETIVA');
        $feedback = $this->createJournal("Legado Realimentação $u", null, null, 'CIÊNCIAS HUMANAS');
        $latindex = $this->createJournal("Legado Latindex $u", null, null, 'Sociología');
        $accentless = $this->createJournal("Legado Sem Acento $u", null, null, 'SAUDE COLETIVA');
        $titleCase = $this->createJournal("Legado Caixa $u", null, null, 'Educação');
        $empty = $this->createJournal("Legado Vazio $u");
        $ids = [$sociologia, $social, $wosCategories, $classified, $feedback, $latindex, $accentless, $titleCase, $empty];

        $areasBefore = $this->areasOf($ids);

        $rows = $this->loader->loadLegacy();
        $this->assertGreaterThanOrEqual(4, $rows);

        $evidence = $this->evidence($ids);
        $this->assertSame(
            [$sociologia => 'SOCIOLOGIA', $classified => 'SAÚDE COLETIVA', $accentless => 'SAUDE COLETIVA', $titleCase => 'Educação'],
            $this->labelsByJournal($evidence)
        );
        foreach ($evidence as $row) {
            $this->assertSame('legacy', $row['source']);
            $this->assertSame('area', $row['record_key']);
            $this->assertSame('1.0000', $row['weight']);
            $this->assertNull($row['source_title']);
            $this->assertNull($row['payload']);
            $this->assertSame(date('Y-m-d'), $row['batch']);
        }
        $this->assertSame($areasBefore, $this->areasOf($ids), 'a carga não altera area');

        // Recarregável: não duplica e não toca nas outras fontes
        $this->insertEvidence($sociologia, 'ai', 'IA', 'sociologia');
        $this->assertSame($rows, $this->loader->loadLegacy());
        $this->assertCount(5, $this->evidence($ids));
        $this->assertSame(['ai' => 1, 'legacy' => 1], $this->countBySource($sociologia));

        // --dry-run: conta e desfaz
        $this->conn->executeStatement("DELETE FROM journal_area_evidence WHERE source = 'legacy'");
        $this->assertSame($rows, $this->loader->loadLegacy(true));
        $this->assertSame(['ai' => 1], $this->countBySource($sociologia));
    }

    public function testLoadWithLegacyOptionAddsLegacyRowsToTheResult(): void
    {
        $a = self::n8(41);
        $journal = $this->createJournal("Revista Com Legado $this->uniq", Issn::format($a), null, 'SOCIOLOGIA');
        $artifact = $this->writeArtifact([self::record('scopus', 'S1', [$a], 'Revista Com Legado', ['3312'], [])]);

        $result = $this->loader->load($artifact, false, true);

        $this->assertGreaterThanOrEqual(1, $result['legacy_rows']);
        $this->assertSame(1, $result['evidence_rows']);
        $this->assertSame(['legacy' => 1, 'scopus' => 1], $this->countBySource($journal));
        $this->assertSame($result['legacy_rows'], $result['by_source']['legacy']);
        $this->assertSame('SOCIOLOGIA', $this->rowsOf($this->evidence([$journal]), $journal, 'legacy')[0]['label']);
    }

    public function testLegacyAcceptsWhatTheTrustedThesaurusKnowsLikeAManualSynonym(): void
    {
        $u = $this->uniq;
        $taxonomy = new CapesTaxonomy();
        $synonym = "Ciência Sinônima $u";
        // Correção manual do admin: o texto livre vale a área Sociologia
        $this->conn->executeStatement(
            "INSERT INTO capes_area_variants (area_id, vocabulary, variation_name, normalized_name, origin, status, occurrences, created_at, updated_at) VALUES (?, 'livre', ?, ?, 'manual', 'active', 0, NOW(), NOW())",
            [$taxonomy->bySlug('sociologia')['id'], $synonym, StringNormalizer::normalizeString($synonym, true)]
        );
        $thesaurus = static::getContainer()->get(JournalAreaThesaurus::class);
        $thesaurus->reset();

        $exact = $this->createJournal("Legado Sinônimo $u", null, null, $synonym);
        $upper = $this->createJournal("Legado Sinônimo Caixa Alta $u", null, null, mb_strtoupper($synonym, 'UTF-8'));
        $folded = $this->createJournal("Legado Sinônimo Sem Acento $u", null, null, strtolower("Ciencia Sinonima $u"));
        $social = $this->createJournal("Legado Social Sciences Sinônimo $u", null, null, 'Social Sciences');
        $latindex = $this->createJournal("Legado Latindex Sinônimo $u", null, null, 'Psicología');
        $english = $this->createJournal("Legado Inglês Sinônimo $u", null, null, 'Mathematics');
        $ids = [$exact, $upper, $folded, $social, $latindex, $english];
        $areasBefore = $this->areasOf($ids);

        $this->loader->loadLegacy();

        $this->assertSame(
            [$exact => $synonym, $upper => mb_strtoupper($synonym, 'UTF-8')],
            $this->labelsByJournal($this->evidence($ids)),
            'Entram a grafia exata e a caixa alta do sinônimo; a variante sem acento, o lixo do importador, o rótulo do Latindex e o inglês, não'
        );
        foreach ($this->evidence($ids) as $row) {
            $this->assertSame('legacy', $row['source']);
            $this->assertSame('area', $row['record_key']);
            $this->assertSame('1.0000', $row['weight']);
        }
        $this->assertSame($areasBefore, $this->areasOf($ids), 'a carga não altera area');

        // O classificador entende o rótulo: o legacy do sinônimo dá a área da correção
        $scorer = new JournalAreaScorer(new AreaCrosswalk(null, $taxonomy), $taxonomy, $thesaurus);
        $score = $scorer->score([['source' => 'legacy', 'record_key' => 'area', 'label' => $synonym, 'weight' => 1]]);
        $this->assertSame('sociologia', $score['primary']['slug'] ?? null);
        // Sem o serviço do tesauro o escorer só conhece o nome de uma área (comportamento antigo)
        $this->assertNull((new JournalAreaScorer(new AreaCrosswalk(null, $taxonomy), $taxonomy))->score([['source' => 'legacy', 'record_key' => 'area', 'label' => $synonym, 'weight' => 1]])['primary']);

        // Excluída a correção, o sinônimo deixa de valer na próxima carga
        $this->conn->executeStatement("DELETE FROM capes_area_variants WHERE vocabulary = 'livre' AND variation_name = ?", [$synonym]);
        $thesaurus->reset();
        $this->loader->loadLegacy();
        $this->assertSame([], $this->labelsByJournal($this->evidence($ids)));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Regras de título
    // ------------------------------------------------------------------------------------------------------------

    public function testTitleRulesAreAppliedPerFamilyOnTheCanonicalRow(): void
    {
        $u = $this->uniq;
        $crosswalk = new AreaCrosswalk();

        $canonical = $this->createJournal("Revista de Educação Matemática $u");
        $member = $this->createJournal("Periódico de Psicologia $u");
        $standalone = $this->createJournal("Sociologia Aplicada $u");
        $none = $this->createJournal("Boletim Sem Regra $u");
        foreach ([$canonical, $member] as $id) {
            $this->conn->executeStatement('UPDATE qualis_journals SET display_title = ? WHERE id = ?', ["Revista de Educação Matemática $u", $id]);
        }
        $this->conn->executeStatement('UPDATE qualis_journals SET canonical_journal_id = ?, canonical_method = ? WHERE id = ?', [$canonical, 'issn', $member]);

        $expected = array_values(array_unique([
            ...$crosswalk->matchTitle(JournalResolverService::normalizeString("Revista de Educação Matemática $u")),
            ...$crosswalk->matchTitle(JournalResolverService::normalizeString("Periódico de Psicologia $u")),
        ]));
        $this->assertContains('ensino', $expected);
        $this->assertContains('educacao', $expected);
        $this->assertContains('matematica-estatistica', $expected);
        $this->assertContains('psicologia', $expected);

        $rows = $this->loader->loadTitleRules();
        $this->assertGreaterThanOrEqual(count($expected) + 1, $rows);

        $evidence = $this->evidence([$canonical, $member, $standalone, $none]);
        $this->assertSame([], $this->rowsOf($evidence, $member), 'a linha membro não recebe: a evidência fica na canônica');
        $this->assertSame([], $this->rowsOf($evidence, $none));

        $family = $this->rowsOf($evidence, $canonical);
        $this->assertSame($expected, array_column($family, 'label'));
        foreach ($family as $row) {
            $this->assertSame('title_rule', $row['source']);
            $this->assertSame('title', $row['record_key']);
            $this->assertSame(date('Y-m-d'), $row['batch']);
            $this->assertNull($row['payload']);
        }
        $this->assertEqualsWithDelta(1 / count($expected), (float) $family[0]['weight'], 0.0001);
        $this->assertSame('1.0000', $this->conn->fetchOne(
            "SELECT SUM(weight) FROM journal_area_evidence WHERE journal_id = ? AND source = 'title_rule'",
            [$canonical]
        ));
        $this->assertSame("Revista de Educação Matemática $u", $family[0]['source_title']);
        $this->assertNull($family[1]['source_title']);

        $alone = $this->rowsOf($evidence, $standalone);
        $this->assertContains('sociologia', array_column($alone, 'label'));

        // Recarregável, sem duplicar, e sem mexer nas outras fontes
        $this->insertEvidence($canonical, 'legacy', 'area', 'EDUCAÇÃO');
        $this->assertSame($rows, $this->loader->loadTitleRules());
        $this->assertSame(['legacy' => 1, 'title_rule' => count($expected)], $this->countBySource($canonical));
        $this->assertSame($rows, $this->loader->loadTitleRules(true), '--dry-run devolve a mesma contagem');
        $this->assertSame(['legacy' => 1, 'title_rule' => count($expected)], $this->countBySource($canonical));
    }

    public function testLoadWithTitleRulesOptionAddsRowsToTheResult(): void
    {
        $a = self::n8(51);
        $journal = $this->createJournal("Revista de Direito $this->uniq", Issn::format($a));
        $artifact = $this->writeArtifact([self::record('doaj', 'D1', [$a], 'Revista de Direito', ['Law'], [])]);

        $result = $this->loader->load($artifact, false, false, true);

        $this->assertGreaterThanOrEqual(1, $result['title_rule_rows']);
        $this->assertSame(['direito'], array_column($this->rowsOf($this->evidence([$journal]), $journal, 'title_rule'), 'label'));
        $this->assertSame($result['title_rule_rows'], $result['by_source']['title_rule']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Linhas faltantes (A5.5)
    // ------------------------------------------------------------------------------------------------------------

    public function testCreateMissingRowsForArticlesLinksBasesAndLoadsTheirEvidence(): void
    {
        [$x, $x2, $z, $z2, $w, $k, $q] = [self::n8(61), self::n8(62), self::n8(63), self::n8(64), self::n8(65), self::n8(66), self::n8(67)];
        $u = $this->uniq;

        $scielo = $this->findOrCreateBase('scielo', 'SciELO');
        $latindex = $this->findOrCreateBase('latindex', 'Latindex');
        $pubmed = $this->findOrCreateBase('pubmed', 'PubMed');
        $this->findOrCreateBase('scopus', 'Scopus');
        $this->findOrCreateBase('doaj', 'DOAJ');

        $existing = $this->createJournal("Revista Existente $u", Issn::format($k));
        $researcher = $this->createResearcher();
        foreach ([$x, $z, $w, $k] as $issn) {
            $this->createArticle($researcher, $issn);
        }

        $artifact = $this->writeArtifact([
            self::record('doaj', 'D1', [$x, $x2], "Revista Doaj Fixture $u", ['Education'], ['country' => 'BR']),
            self::record('latindex', 'L1', [$x], "Revista Latindex Fixture $u", ['Educación'], []),
            self::record('openalex', 'S9', [$x, $x2], "OA Title $u", [['code' => '3304', 'share' => 0.9]], ['works' => 10]),
            // Só o ISSN-irmão (x2): vincula a base mesmo assim
            self::record('scopus', 'SX', [$x2], "Scopus Title $u", ['3304'], []),
            self::record('scielo', 'SC9', [$z, $z2], "Revista Scielo Fixture $u", ['Human Sciences'], [
                'wos' => [],
                'desc' => ['EDUCAÇÃO'],
                'issn_types' => [$z => 'ONLIN', $z2 => 'PRINT'],
            ]),
            self::record('pubmed', 'P9', [$z], "PubMed Title $u", [], []),
            self::record('wos', 'WK', [$k], "Revista Existente $u", ['Law'], []),
            self::record('wos', 'WQ', [$q], "Sem Artigo $u", ['Law'], []),
        ]);

        $result = $this->loader->load($artifact, true);

        $this->assertSame(2, $result['created']);
        $this->assertGreaterThanOrEqual(3, $result['missing_issns'], 'x, z e w; a linha existente (k) não conta');
        $this->assertGreaterThanOrEqual(1, $result['missing_without_record'], 'w não consta em nenhuma fonte');
        $this->assertCount(2, $result['created_rows']);

        $r1 = $this->journalRowByIssn(Issn::format($x));
        $this->assertNotNull($r1);
        $this->assertSame("Revista Doaj Fixture $u", $r1['title']);
        $this->assertSame("Revista Doaj Fixture $u", $r1['display_title']);
        $this->assertSame('doaj', $r1['display_title_source']);
        $this->assertSame(Issn::format($x), $r1['issn_imp']);
        $this->assertSame(Issn::format($x2), $r1['issn_e']);
        $this->assertSame(Issn::format($x), $r1['issn']);
        $this->assertSame([$x, $x2, $x], [$r1['normalized_issn_imp'], $r1['normalized_issn_e'], $r1['normalized_issn']]);
        $this->assertNull($r1['issn_l']);
        $this->assertNull($r1['qualis']);
        $this->assertNull($r1['area']);
        $this->assertNull($r1['canonical_journal_id']);
        $this->assertSame(strtoupper("REVISTA DOAJ FIXTURE $u"), $r1['normalized_title']);
        // Latindex e Scopus listam o ISSN (o Scopus só o irmão); o DOAJ e o OpenAlex não são bases da página
        $this->assertSame(['latindex', 'scopus'], $this->basesOf((int) $r1['id']));
        $this->assertSame(1, $this->countBySource((int) $r1['id'])['latindex']);
        $this->assertSame(
            ['doaj' => 1, 'latindex' => 1, 'openalex' => 1, 'scopus' => 1],
            $this->countBySource((int) $r1['id']),
            'a linha criada também recebe as evidências'
        );

        $r2 = $this->journalRowByIssn(Issn::format($z));
        $this->assertNotNull($r2);
        $this->assertSame("Revista Scielo Fixture $u", $r2['title']);
        $this->assertSame('scielo', $r2['display_title_source']);
        $this->assertSame(Issn::format($z2), $r2['issn_imp'], 'tipo PRINT do SciELO, não a posição');
        $this->assertSame(Issn::format($z), $r2['issn_e']);
        $this->assertSame(Issn::format($z2), $r2['issn']);
        $this->assertSame(['pubmed', 'scielo'], $this->basesOf((int) $r2['id']));
        $this->assertSame(['scielo_area' => 1, 'scielo_desc' => 1], $this->countBySource((int) $r2['id']));

        $this->assertNull($this->journalRowByIssn(Issn::format($w)), 'sem registro nas fontes, nada é criado');
        $this->assertNull($this->journalRowByIssn(Issn::format($q)), 'ISSN sem artigo não gera linha');
        $this->assertSame(['wos' => 1], $this->countBySource($existing), 'a linha existente só recebe evidência');
        $this->assertSame([], $this->basesOf($existing));
        $this->assertSame(
            [['id' => (int) $r1['id'], 'bases' => ['latindex', 'scopus'], 'source' => 'doaj'], ['id' => (int) $r2['id'], 'bases' => ['pubmed', 'scielo'], 'source' => 'scielo']],
            array_map(static fn (array $row): array => ['id' => $row['id'], 'bases' => $row['bases'], 'source' => $row['source']], $result['created_rows'])
        );
        $this->assertSame(4, $result['created_links']);
        $this->assertNotNull($scielo);
        $this->assertNotNull($latindex);
        $this->assertNotNull($pubmed);

        // Idempotente: a segunda carga não cria nada e as evidências ficam iguais
        $second = $this->loader->load($artifact, true);
        $this->assertSame(0, $second['created']);
        $this->assertSame(['doaj' => 1, 'latindex' => 1, 'openalex' => 1, 'scopus' => 1], $this->countBySource((int) $r1['id']));
        $this->assertSame(['scielo_area' => 1, 'scielo_desc' => 1], $this->countBySource((int) $r2['id']));
        $this->assertSame(['latindex', 'scopus'], $this->basesOf((int) $r1['id']));
    }

    public function testCreateMissingForArticlesPublicMethodUsesThirdIssnAndFallbackTitle(): void
    {
        [$p, $q, $r, $s] = [self::n8(71), self::n8(72), self::n8(73), self::n8(74)];
        $u = $this->uniq;
        $this->findOrCreateBase('latindex', 'Latindex');
        $researcher = $this->createResearcher();
        $this->createArticle($researcher, $r);
        $this->createArticle($researcher, $s);

        $records = [
            // 3 ISSNs; o do artigo é o terceiro
            $r => [self::record('doaj', 'D1', [$p, $q, $r], "Revista Tres Issns $u", ['Law'], [])],
            // Registro sem título: a linha usa o título padrão do importador
            $s => [self::record('latindex', 'L1', [$s], '', ['Derecho'], [])],
        ];

        $this->assertSame(2, $this->loader->createMissingForArticles($records));

        $three = $this->journalRowByIssn(Issn::format($p));
        $this->assertNotNull($three);
        $this->assertSame([Issn::format($p), Issn::format($q), Issn::format($r)], [$three['issn_imp'], $three['issn_e'], $three['issn_l']]);
        $this->assertSame($r, $three['normalized_issn_l']);

        $untitled = $this->journalRowByIssn(Issn::format($s));
        $this->assertNotNull($untitled);
        $this->assertSame('Periódico ISSN ' . Issn::format($s), $untitled['title']);
        $this->assertNull($untitled['display_title']);
        $this->assertNull($untitled['display_title_source']);
        $this->assertSame(['latindex'], $this->basesOf((int) $untitled['id']));

        // O que foi criado já existe no cadastro: uma segunda chamada não cria nada
        $this->assertSame(0, $this->loader->createMissingForArticles($records));
    }

    public function testCreateMissingFromDoajOnlyCreatesTheRowWithoutBaseLinks(): void
    {
        $d = self::n8(101);
        $this->findOrCreateBase('latindex', 'Latindex');
        $this->createArticle($this->createResearcher(), $d);

        $artifact = $this->writeArtifact([
            self::record('doaj', 'D1', [$d], "Revista Só Doaj $this->uniq", ['Law'], []),
            self::record('openalex', 'S1', [$d], "OA $this->uniq", [['code' => '3304', 'share' => 0.5]], ['works' => 1]),
        ]);

        $result = $this->loader->load($artifact, true);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['created_links']);
        $row = $this->journalRowByIssn(Issn::format($d));
        $this->assertNotNull($row);
        $this->assertSame([], $this->basesOf((int) $row['id']), 'DOAJ e OpenAlex não são bases da página: criam a linha, não o vínculo');
        $this->assertSame(['doaj' => 1, 'openalex' => 1], $this->countBySource((int) $row['id']));
    }

    public function testCreateMissingSkipsTheLinkWhenTheBaseIsNotRegistered(): void
    {
        $l = self::n8(102);
        // A base é localizada por acrônimo: sem ela (aqui, renomeada dentro da transação) a linha nasce sem vínculo
        $this->findOrCreateBase('latindex', 'Latindex');
        $this->conn->executeStatement("UPDATE academic_database SET acronym = CONCAT('x-', acronym) WHERE acronym = 'latindex'");
        $this->createArticle($this->createResearcher(), $l);

        $artifact = $this->writeArtifact([self::record('latindex', 'L1', [$l], "Revista Só Latindex $this->uniq", ['Derecho'], [])]);

        $result = $this->loader->load($artifact, true);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['created_links']);
        $row = $this->journalRowByIssn(Issn::format($l));
        $this->assertNotNull($row);
        $this->assertSame([], $this->basesOf((int) $row['id']));
        $this->assertSame('latindex', $row['display_title_source']);
    }

    public function testCreateMissingIgnoresArticlesWithoutValidIssnAndNonArticles(): void
    {
        $x = self::n8(81);
        $researcher = $this->createResearcher();
        $this->createArticle($researcher, '12345678'); // dígito verificador inválido
        $newspaper = $this->createArticle($researcher, $x);
        $this->conn->executeStatement('UPDATE production_items SET item_type = ? WHERE id = ?', [ProductionItem::TYPE_TEXTO_JORNAL, $newspaper]);

        $created = $this->loader->createMissingForArticles([$x => [self::record('doaj', 'D1', [$x], "Jornal $this->uniq", ['Law'], [])]]);

        $this->assertSame(0, $created);
        $this->assertNull($this->journalRowByIssn(Issn::format($x)));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Comando
    // ------------------------------------------------------------------------------------------------------------

    public function testCommandDryRunPrintsTablesAndWritesNothing(): void
    {
        $a = self::n8(91);
        $journal = $this->createJournal("Revista Comando $this->uniq", Issn::format($a));
        $artifact = $this->writeArtifact([self::record('scopus', 'S1', [$a], 'Revista Comando', ['3304', '3312'], [])]);

        $tester = $this->commandTester();
        $status = $tester->execute(['artifact' => $artifact, '--dry-run' => true]);

        $this->assertSame(0, $status);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Fonte de evidência', $display);
        $this->assertStringContainsString('scopus', $display);
        $this->assertStringContainsString('Modo simulação', $display);
        $this->assertSame([], $this->evidence([$journal]));
    }

    public function testCommandLoadsAndReportsPerSource(): void
    {
        $a = self::n8(92);
        $journal = $this->createJournal("Revista Comando Carga $this->uniq", Issn::format($a));
        $artifact = $this->writeArtifact([self::record('scopus', 'S1', [$a], 'Revista Comando Carga', ['3304', '3312'], [])]);

        $tester = $this->commandTester();
        $status = $tester->execute(['artifact' => $artifact]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Linhas gravadas', $tester->getDisplay());
        $this->assertSame(['scopus' => 2], $this->countBySource($journal));
    }

    public function testCommandTitleRulesAloneDoesNotReadTheArtifact(): void
    {
        $journal = $this->createJournal("Revista de Direito Comando $this->uniq");

        $tester = $this->commandTester();
        $status = $tester->execute(['artifact' => sys_get_temp_dir() . '/nao-existe-' . $this->uniq . '.jsonl.gz', '--title-rules' => true]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Só as regras de título', $tester->getDisplay());
        $this->assertSame(['title_rule' => 1], $this->countBySource($journal));
    }

    public function testCommandFailsWhenArtifactIsMissing(): void
    {
        $tester = $this->commandTester();
        $status = $tester->execute(['artifact' => sys_get_temp_dir() . '/nao-existe-' . $this->uniq . '.jsonl.gz']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('não encontrado', $tester->getDisplay());
    }

    // ------------------------------------------------------------------------------------------------------------
    // Auxiliares
    // ------------------------------------------------------------------------------------------------------------

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:journals:evidence:load'));
    }

    /**
     * ISSN válido de 8 caracteres na faixa 99xxxxxx, que não existe em dados reais.
     */
    private static function n8(int $n): string
    {
        $base = sprintf('99%05d', $n);
        $sum = 0;
        for ($i = 0; $i < 7; ++$i) {
            $sum += (int) $base[$i] * (8 - $i);
        }
        $digit = (11 - ($sum % 11)) % 11;
        $issn = $base . ($digit === 10 ? 'x' : (string) $digit);
        self::assertTrue(Issn::isValid($issn));

        return $issn;
    }

    /**
     * @param list<string> $issns
     * @param list<mixed>  $labels
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function record(string $src, string $rec, array $issns, string $title, array $labels, array $extra): array
    {
        return ['src' => $src, 'rec' => $rec, 'issns' => $issns, 'title' => $title, 'labels' => $labels, 'extra' => $extra === [] ? new \stdClass() : $extra];
    }

    /**
     * Grava um artefato (.jsonl.gz) com `_meta` calculado dos registros, salvo se `$sources` for informado.
     *
     * @param list<array<string, mixed>>                      $records
     * @param array<string, array<string, int|string>>|null   $sources
     */
    private function writeArtifact(array $records, ?array $sources = null): string
    {
        if ($sources === null) {
            $sources = [];
            foreach ($records as $record) {
                $src = (string) $record['src'];
                $sources[$src] = ['file' => $src . '.csv', 'records' => (int) ($sources[$src]['records'] ?? 0) + 1];
            }
            ksort($sources);
        }

        $path = $this->tempPath('.jsonl.gz');
        $gz = gzopen($path, 'wb9');
        self::assertNotFalse($gz);
        gzwrite($gz, json_encode(['_meta' => ['built_at' => self::BUILT_AT, 'sources' => $sources === [] ? new \stdClass() : $sources]], self::JSON_FLAGS) . "\n");
        foreach ($records as $record) {
            gzwrite($gz, json_encode($record, self::JSON_FLAGS) . "\n");
        }
        gzclose($gz);

        return $path;
    }

    private function tempPath(string $suffix): string
    {
        $base = tempnam(sys_get_temp_dir(), 'evidence-loader-');
        self::assertIsString($base);
        $this->files[] = $base;
        $this->files[] = $base . $suffix;

        return $base . $suffix;
    }

    private function createJournal(string $title, ?string $issnImp = null, ?string $issnE = null, ?string $area = null, ?string $areaRaw = null): int
    {
        $journal = (new QualisJournal())->setTitle($title);
        if ($issnImp !== null) {
            $journal->setIssnImp($issnImp);
        }
        if ($issnE !== null) {
            $journal->setIssnE($issnE);
        }
        $journal->setArea($area)->setAreaRaw($areaRaw);
        $this->em->persist($journal);
        $this->em->flush();

        return (int) $journal->getId();
    }

    private function createResearcher(): Researcher
    {
        $researcher = (new Researcher())
            ->setIdLattes('99' . str_pad((string) random_int(0, 99999999999999), 14, '0', STR_PAD_LEFT))
            ->setFullName('Pesquisador Carga Evidencias ' . $this->uniq);
        $this->em->persist($researcher);
        $this->em->flush();

        return $researcher;
    }

    private function createArticle(Researcher $researcher, string $issn): int
    {
        $item = (new ProductionItem())
            ->setResearcher($researcher)
            ->setItemType(ProductionItem::TYPE_ARTIGO)
            ->setTitle('Artigo de teste ' . $this->uniq)
            ->setYear(2024)
            ->setIssn($issn);
        $this->em->persist($item);
        $this->em->flush();

        return (int) $item->getId();
    }

    private function findOrCreateBase(string $acronym, string $name): AcademicDatabase
    {
        $base = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => $acronym]);
        if ($base === null) {
            $base = (new AcademicDatabase())->setName($name)->setAcronym($acronym);
            $this->em->persist($base);
            $this->em->flush();
        }

        return $base;
    }

    private function insertEvidence(int $journalId, string $source, string $recordKey, string $label): void
    {
        $this->conn->executeStatement(
            'INSERT INTO journal_area_evidence (journal_id, source, record_key, label, weight, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$journalId, $source, $recordKey, $label, '1.0000']
        );
    }

    /**
     * @param list<int> $journalIds
     *
     * @return list<array<string, mixed>>
     */
    private function evidence(array $journalIds): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT id, journal_id, source, record_key, label, weight, source_title, batch, payload
             FROM journal_area_evidence WHERE journal_id IN (?) ORDER BY id',
            [$journalIds],
            [ArrayParameterType::INTEGER]
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOf(array $rows, int $journalId, ?string $source = null): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) $row['journal_id'] === $journalId && ($source === null || $row['source'] === $source)
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function countFor(array $rows, int $journalId): int
    {
        return count($this->rowsOf($rows, $journalId));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private function weightsOf(array $rows, int $journalId, string $source, string $recordKey): array
    {
        return array_column(
            array_filter($this->rowsOf($rows, $journalId, $source), static fn (array $row): bool => $row['record_key'] === $recordKey),
            'weight'
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, string>
     */
    private function labelsByJournal(array $rows): array
    {
        $labels = [];
        foreach ($rows as $row) {
            $labels[(int) $row['journal_id']] = (string) $row['label'];
        }

        return $labels;
    }

    /**
     * @return array<string, int>
     */
    private function countBySource(int $journalId): array
    {
        $counts = [];
        foreach ($this->conn->fetchAllAssociative('SELECT source, COUNT(*) AS n FROM journal_area_evidence WHERE journal_id = ? GROUP BY source ORDER BY source', [$journalId]) as $row) {
            $counts[(string) $row['source']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{area: ?string, area_raw: ?string}>
     */
    private function areasOf(array $ids): array
    {
        $areas = [];
        foreach ($this->conn->fetchAllAssociative('SELECT id, area, area_raw FROM qualis_journals WHERE id IN (?) ORDER BY id', [$ids], [ArrayParameterType::INTEGER]) as $row) {
            $areas[(int) $row['id']] = ['area' => $row['area'], 'area_raw' => $row['area_raw']];
        }

        return $areas;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function journalRowByIssn(string $formattedIssn): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT * FROM qualis_journals WHERE issn_imp = ? OR issn_e = ? OR issn_l = ? ORDER BY id LIMIT 1',
            [$formattedIssn, $formattedIssn, $formattedIssn]
        );

        return $row === false ? null : $row;
    }

    /**
     * @return list<string>
     */
    private function basesOf(int $journalId): array
    {
        $acronyms = $this->conn->fetchFirstColumn(
            'SELECT d.acronym FROM qualis_journal_academic_database l JOIN academic_database d ON d.id = l.academic_database_id WHERE l.qualis_journal_id = ? ORDER BY d.acronym',
            [$journalId]
        );

        return array_map('strval', $acronyms);
    }
}
