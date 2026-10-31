<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Entity\AcademicDatabase;
use App\Entity\QualisJournal;
use App\Service\Journal\Issn;
use App\Service\Journal\ScieloIssnEnricher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ScieloIssnEnricherTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ScieloIssnEnricher $enricher;
    private AcademicDatabase $scielo;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->enricher = $container->get(ScieloIssnEnricher::class);

        // Cada teste roda numa transação revertida no tearDown: nada fica gravado no banco de teste
        $this->em->getConnection()->beginTransaction();
        $this->scielo = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => 'scielo'])
            ?? $this->createScieloDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
            $this->em->clear();
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testFillsIssnsAndDisplayTitleOfScieloRowWithoutIssn(): void
    {
        $id = $this->createJournal('REVISTA BRASILEIRA DE TESTE.')->getId();
        $file = $this->fixture([
            '0103-863X' => self::record('Revista Brasileira de Teste', [
                ['issn' => '0103-863X', 'type' => 'PRINT'],
                ['issn' => '1982-4327', 'type' => 'ONLIN'],
            ]),
        ]);

        $result = $this->enricher->enrich($file, false);

        $this->assertSame(['matched' => 1, 'updated' => 1, 'unmatched' => [], 'ambiguous' => []], $result);

        $journal = $this->reload($id);
        $this->assertSame('0103-863X', $journal->getIssn());
        $this->assertSame('0103-863X', $journal->getIssnImp());
        $this->assertSame('1982-4327', $journal->getIssnE());
        $this->assertSame('0103863x', $journal->getNormalizedIssn());
        $this->assertSame('0103863x', $journal->getNormalizedIssnImp());
        $this->assertSame('19824327', $journal->getNormalizedIssnE());
        $this->assertSame('Revista Brasileira de Teste', $journal->getDisplayTitle());
        $this->assertSame('scielo', $journal->getDisplayTitleSource());
        $this->assertSame('REVISTA BRASILEIRA DE TESTE.', $journal->getTitle());
    }

    public function testUntypedIssnIsStoredAsGeneralWithoutPresumingPrint(): void
    {
        $id = $this->createJournal('RAE Eletrônica Teste')->getId();
        $file = $this->fixture([
            '1676-5648' => self::record('RAE eletrônica teste', [['issn' => '1676-5648', 'type' => null]]),
        ]);

        $this->enricher->enrich($file, false);

        $journal = $this->reload($id);
        $this->assertSame('1676-5648', $journal->getIssn());
        $this->assertSame('16765648', $journal->getNormalizedIssn());
        $this->assertNull($journal->getIssnImp());
        $this->assertNull($journal->getNormalizedIssnImp());
        $this->assertNull($journal->getIssnE());
        $this->assertNull($journal->getNormalizedIssnE());
    }

    public function testOnlineOnlyIssnDoesNotFillPrint(): void
    {
        $id = $this->createJournal('Revista Só Eletrônica Teste')->getId();
        $file = $this->fixture([
            '1982-4327' => self::record('Revista Só Eletrônica Teste', [['issn' => '1982-4327', 'type' => 'ONLIN']]),
        ]);

        $this->enricher->enrich($file, false);

        $journal = $this->reload($id);
        $this->assertSame('1982-4327', $journal->getIssnE());
        $this->assertSame('1982-4327', $journal->getIssn());
        $this->assertNull($journal->getIssnImp());
        $this->assertNull($journal->getNormalizedIssnImp());
    }

    public function testRowWithValidIssnIsNotTouched(): void
    {
        $id = $this->createJournal('Revista Com ISSN Teste', '1806-9053')->getId();
        $file = $this->fixture([
            '0102-6909' => self::record('Revista Com ISSN Teste', [['issn' => '0102-6909', 'type' => 'PRINT']]),
        ]);

        $result = $this->enricher->enrich($file, false);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(0, $result['updated']);
        $journal = $this->reload($id);
        $this->assertSame('1806-9053', $journal->getIssn());
        $this->assertSame('1806-9053', $journal->getIssnImp());
        $this->assertNull($journal->getIssnE());
        $this->assertNull($journal->getDisplayTitleSource());
    }

    public function testOnlyRowsLinkedToScieloAreChanged(): void
    {
        $id = $this->createJournal('Revista Sem Base Teste', null, null, null, false)->getId();
        $file = $this->fixture([
            '0102-6909' => self::record('Revista Sem Base Teste', [['issn' => '0102-6909', 'type' => 'PRINT']]),
        ]);

        $result = $this->enricher->enrich($file, false);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(0, $result['updated']);
        $this->assertNull($this->reload($id)->getIssn());
    }

    public function testReportsUnmatchedAndAmbiguousRows(): void
    {
        $ambiguousId = $this->createJournal('Revista Duplicada Teste')->getId();
        $unmatchedId = $this->createJournal('Revista Inexistente Teste')->getId();
        $file = $this->fixture([
            '0102-6909' => self::record('Revista Duplicada Teste', [['issn' => '0102-6909', 'type' => 'PRINT']]),
            '1806-9053' => self::record('REVISTA DUPLICADA TESTE!', [['issn' => '1806-9053', 'type' => 'PRINT']]),
        ]);

        $result = $this->enricher->enrich($file, false);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame([['id' => $unmatchedId, 'title' => 'Revista Inexistente Teste']], $result['unmatched']);
        $this->assertCount(1, $result['ambiguous']);
        $this->assertSame($ambiguousId, $result['ambiguous'][0]['id']);
        $codes = $result['ambiguous'][0]['codes'];
        sort($codes);
        $this->assertSame(['0102-6909', '1806-9053'], $codes);
        $this->assertNull($this->reload($ambiguousId)->getIssn());
        $this->assertNull($this->reload($unmatchedId)->getIssn());
    }

    public function testDisplayTitleRespectsDisplayTitleSource(): void
    {
        $manualId = $this->createJournal('Titulo Scielo Oficial', null, 'manual', 'Nome manual')->getId();
        $titleId = $this->createJournal('Titulo Scielo Oficial', null, 'title', 'Titulo Scielo Oficial')->getId();
        $file = $this->fixture([
            '0102-6909' => self::record('Título SciELO Oficial', [['issn' => '0102-6909', 'type' => 'PRINT']]),
        ]);

        $result = $this->enricher->enrich($file, false);

        $this->assertSame(2, $result['matched']);
        $this->assertSame(2, $result['updated']);

        $manual = $this->reload($manualId);
        $this->assertSame('0102-6909', $manual->getIssnImp());
        $this->assertSame('Nome manual', $manual->getDisplayTitle());
        $this->assertSame('manual', $manual->getDisplayTitleSource());

        $fromTitle = $this->reload($titleId);
        $this->assertSame('0102-6909', $fromTitle->getIssnImp());
        $this->assertSame('Título SciELO Oficial', $fromTitle->getDisplayTitle());
        $this->assertSame('scielo', $fromTitle->getDisplayTitleSource());
    }

    public function testDryRunCountsWithoutWriting(): void
    {
        $id = $this->createJournal('Revista Simulada Teste')->getId();
        $file = $this->fixture([
            '0102-6909' => self::record('Revista Simulada Teste', [['issn' => '0102-6909', 'type' => 'PRINT']]),
        ]);

        $result = $this->enricher->enrich($file, true);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(1, $result['updated']);
        $journal = $this->reload($id);
        $this->assertNull($journal->getIssn());
        $this->assertNull($journal->getDisplayTitleSource());
    }

    public function testWritesInBatchesOfOneHundred(): void
    {
        $expected = [];
        $records = [];
        for ($n = 1; $n <= 105; $n++) {
            $title = sprintf('Revista Lote Teste %03d', $n);
            $code = self::issnWithCheckDigit(1000000 + $n);
            $this->assertTrue(Issn::isValid(strtolower(str_replace('-', '', $code))));
            $expected[$this->createJournal($title)->getId()] = $code;
            $records[$code] = self::record($title, [['issn' => $code, 'type' => 'PRINT']]);
        }
        $file = $this->fixture($records);

        $result = $this->enricher->enrich($file, false);

        $this->assertSame(105, $result['matched']);
        $this->assertSame(105, $result['updated']);
        $this->assertSame([], $result['unmatched']);
        foreach ($expected as $id => $code) {
            $this->assertSame($code, $this->reload($id)->getIssnImp(), sprintf('periódico %d', $id));
        }
    }

    private function createScieloDatabase(): AcademicDatabase
    {
        $database = new AcademicDatabase();
        $database->setName('SciELO (teste)');
        $database->setAcronym('scielo');
        $this->em->persist($database);
        $this->em->flush();

        return $database;
    }

    private function createJournal(
        string $title,
        ?string $issn = null,
        ?string $displayTitleSource = null,
        ?string $displayTitle = null,
        bool $linkedToScielo = true
    ): QualisJournal {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        if ($issn !== null) {
            $journal->setIssn($issn);
        }
        $journal->setDisplayTitle($displayTitle);
        $journal->setDisplayTitleSource($displayTitleSource);
        if ($linkedToScielo) {
            $journal->addAcademicDatabase($this->scielo);
        }
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    /** Relê a linha do banco (limpa o EntityManager para não ler estado em memória). */
    private function reload(int $id): QualisJournal
    {
        $this->em->clear();
        $journal = $this->em->find(QualisJournal::class, $id);
        $this->assertInstanceOf(QualisJournal::class, $journal);

        return $journal;
    }

    /** @param array<string, array<string, mixed>> $records */
    private function fixture(array $records): string
    {
        $path = tempnam(sys_get_temp_dir(), 'scielo-enrich-');
        $this->assertIsString($path);
        $this->files[] = $path;
        file_put_contents($path, json_encode($records, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @param list<array{issn: string, type: ?string}> $issns
     * @return array<string, mixed>
     */
    private static function record(string $title, array $issns): array
    {
        return [
            'title' => $title,
            'short' => null,
            'issns' => $issns,
            'area' => [],
            'wos' => [],
            'desc' => [],
            'status' => 'C',
        ];
    }

    /** Monta um ISSN na forma 0000-0000 a partir de sete dígitos, calculando o dígito verificador. */
    private static function issnWithCheckDigit(int $sevenDigits): string
    {
        $digits = str_pad((string) $sevenDigits, 7, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $digits[$i] * (8 - $i);
        }
        $check = (11 - ($sum % 11)) % 11;

        return substr($digits, 0, 4) . '-' . substr($digits, 4, 3) . ($check === 10 ? 'X' : (string) $check);
    }
}
