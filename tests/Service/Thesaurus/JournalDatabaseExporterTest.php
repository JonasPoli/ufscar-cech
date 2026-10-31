<?php

declare(strict_types=1);

namespace App\Tests\Service\Thesaurus;

use App\Entity\AcademicDatabase;
use App\Entity\JournalVariation;
use App\Entity\QualisJournal;
use App\Service\Thesaurus\JournalDatabaseExporterService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportações do catálogo de periódicos por família (docs/evolutions/periodicos-por-base.md): o catálogo ganha o nome de
 * exibição e a linha canônica de cada linha, e o tesauro traz uma entrada por revista, com o nome de exibição da canônica
 * como cabeçalho e todos os nomes da família como variantes.
 *
 * Os dados usam sufixo único e saem no tearDown (cech_test é compartilhado com outros testes e agentes).
 */
class JournalDatabaseExporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private JournalDatabaseExporterService $exporter;
    private string $uniq;

    /** @var string[] */
    private array $databaseAcronyms = [];

    private QualisJournal $head;
    private QualisJournal $member;
    private QualisJournal $alien;
    private string $issnHead;
    private string $issnMember;
    private string $issnAlien;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->exporter = $container->get(JournalDatabaseExporterService::class);
        $this->uniq = 'qz' . bin2hex(random_bytes(4));

        // Uma família de duas linhas (impresso e online), com nome de exibição, e uma revista sem família nem nome de exibição
        $this->issnHead = $this->uniqueIssn();
        $this->issnMember = $this->uniqueIssn();
        $this->issnAlien = $this->uniqueIssn();
        $this->head = $this->journal("Revista Cabeça {$this->uniq}", $this->issnHead);
        $this->member = $this->journal("Revista Cabeça Online {$this->uniq}", $this->issnMember, $this->head);
        $this->alien = $this->journal("Revista Alheia {$this->uniq}", $this->issnAlien);
        $this->conn->executeStatement(
            "UPDATE qualis_journals SET display_title = ?, display_title_source = 'manual' WHERE id IN (?, ?)",
            ["Nome Bonito {$this->uniq}", $this->head->getId(), $this->member->getId()]
        );
        $this->variant($this->head, "Apelido Cabeça {$this->uniq}");
        $this->variant($this->member, "Apelido Online {$this->uniq}");
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            // Variantes e vínculos saem em cascata; a família se desfaz por SET NULL
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

    // ------------------------------------------------------------------------------------------------------------
    // Catálogo
    // ------------------------------------------------------------------------------------------------------------

    public function testCatalogCsvAndJsonCarryTheDisplayTitleAndTheCanonicalRow(): void
    {
        $csv = Reader::createFromString((string)$this->exporter->export(null, null, 'csv')['content']);
        $csv->setHeaderOffset(0);
        $this->assertSame(['display_title', 'canonical_journal_id'], array_slice($csv->getHeader(), -2));
        $this->assertSame(['ID', 'Título do Periódico'], array_slice($csv->getHeader(), 0, 2), 'As colunas que já existiam continuam onde estavam');

        $rows = [];
        foreach ($csv->getRecords() as $record) {
            if (str_contains((string)$record['Título do Periódico'], $this->uniq)) {
                $rows[(int)$record['ID']] = $record;
            }
        }
        $this->assertSame("Nome Bonito {$this->uniq}", $rows[$this->head->getId()]['display_title']);
        $this->assertSame('', $rows[$this->head->getId()]['canonical_journal_id'], 'A canônica não tem canônica');
        $this->assertSame("Nome Bonito {$this->uniq}", $rows[$this->member->getId()]['display_title']);
        $this->assertSame((string)$this->head->getId(), $rows[$this->member->getId()]['canonical_journal_id']);
        $this->assertSame('', $rows[$this->alien->getId()]['display_title']);

        $json = json_decode((string)$this->exporter->export(null, null, 'json')['content'], true, 512, JSON_THROW_ON_ERROR);
        $byId = array_column($json, null, 'id');
        $this->assertSame(['display_title', 'canonical_journal_id'], array_slice(array_keys($byId[$this->member->getId()]), -2));
        $this->assertSame($this->head->getId(), $byId[$this->member->getId()]['canonical_journal_id']);
        $this->assertNull($byId[$this->head->getId()]['canonical_journal_id']);
        $this->assertSame("Nome Bonito {$this->uniq}", $byId[$this->head->getId()]['display_title']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Tesauro por família
    // ------------------------------------------------------------------------------------------------------------

    public function testFamilyRecordsHaveOneEntryPerRevistaWithTheDisplayNameAsHeader(): void
    {
        $records = array_values(array_filter(
            $this->exporter->familyThesaurusRecords(),
            fn (array $record): bool => str_contains($record['header'], $this->uniq)
        ));
        $byHeader = array_column($records, null, 'header');

        $this->assertCount(2, $records, 'Uma entrada para a família de duas linhas e outra para a revista sozinha');
        $this->assertArrayNotHasKey("Revista Cabeça Online {$this->uniq}", $byHeader, 'A linha membro não é uma entrada à parte');
        $this->assertArrayNotHasKey("Revista Cabeça {$this->uniq}", $byHeader, 'O cabeçalho é o nome de exibição, não o título da canônica');

        $family = $byHeader["Nome Bonito {$this->uniq}"];
        $this->assertSame("Nome Bonito {$this->uniq}", $family['preferred_name']);
        $this->assertSame($family['variants'], $family['variations']);
        $this->assertEqualsCanonicalizing(
            ["Revista Cabeça {$this->uniq}", "Revista Cabeça Online {$this->uniq}", "Apelido Cabeça {$this->uniq}", "Apelido Online {$this->uniq}"],
            $family['variants'],
            'Os títulos das duas linhas e as variantes de todas elas'
        );

        // Sem nome de exibição, o cabeçalho é o título
        $this->assertSame([], $byHeader["Revista Alheia {$this->uniq}"]['variants']);
    }

    public function testStreamedThesaurusByTitleIsGroupedByFamilyAndKeepsTheTheFormat(): void
    {
        $the = $this->stream('title', 'the');
        $lines = preg_split('/\r?\n/', trim($the));

        $this->assertSame(1, substr_count($the, "**#Nome Bonito {$this->uniq}\r\n"), 'Um cabeçalho para a família toda');
        $this->assertStringNotContainsString("**#Revista Cabeça Online {$this->uniq}", $the);
        $this->assertStringContainsString("**#Revista Alheia {$this->uniq}\r\n", $the);

        // Variantes em minúsculas, que é o formato do .the, logo abaixo do cabeçalho da família
        $start = array_search("**#Nome Bonito {$this->uniq}", $lines, true);
        $this->assertIsInt($start);
        $block = [];
        for ($i = $start + 1; $i < count($lines) && !str_starts_with($lines[$i], '**#'); $i++) {
            $block[] = $lines[$i];
        }
        foreach (["revista cabeça {$this->uniq}", "revista cabeça online {$this->uniq}", "apelido cabeça {$this->uniq}", "apelido online {$this->uniq}", $this->issnHead, str_replace('-', '', $this->issnHead), $this->issnMember, str_replace('-', '', $this->issnMember)] as $variant) {
            $this->assertContains("100 1 ^{$variant}$", $block, "Variante «{$variant}» da família");
        }

        // Em CSV, uma linha por variante, sob o mesmo cabeçalho
        $csv = Reader::createFromString($this->stream('title', 'csv'));
        $csv->setHeaderOffset(0);
        $variants = [];
        foreach ($csv->getRecords() as $record) {
            if ($record['preferred_name'] === "Nome Bonito {$this->uniq}") {
                $variants[] = $record['variant_name'];
            }
        }
        $this->assertContains("Apelido Online {$this->uniq}", $variants);
        $this->assertContains("Revista Cabeça Online {$this->uniq}", $variants);
    }

    public function testStreamedThesaurusByIssnHasOneEntryPerFamilyUnderTheIssnOfTheCanonical(): void
    {
        $json = json_decode($this->stream('issn', 'json'), true, 512, JSON_THROW_ON_ERROR);
        $byHeader = array_column($json, null, 'header');

        // A família inteira sob o ISSN da canônica; o ISSN da linha membro não é entrada à parte
        $this->assertArrayHasKey($this->issnHead, $byHeader);
        $this->assertArrayNotHasKey($this->issnMember, $byHeader);
        $variants = $byHeader[$this->issnHead]['variants'];
        foreach (["Nome Bonito {$this->uniq}", "Revista Cabeça {$this->uniq}", "Revista Cabeça Online {$this->uniq}", "Apelido Online {$this->uniq}", $this->issnMember, str_replace('-', '', $this->issnMember)] as $expected) {
            $this->assertContains($expected, $variants);
        }
        $this->assertArrayHasKey($this->issnAlien, $byHeader, 'A revista sozinha continua com o seu ISSN');
    }

    public function testStreamedThesaurusByBaseTakesTheWholeFamilyWhenOneRowIsLinked(): void
    {
        $database = new AcademicDatabase();
        $database->setName("Base teste {$this->uniq}");
        $database->setAcronym("{$this->uniq}b");
        $this->em->persist($database);
        $this->em->flush();
        $this->databaseAcronyms[] = "{$this->uniq}b";
        // Só a canônica está ligada à base; a família toda conta
        $this->conn->insert('qualis_journal_academic_database', ['qualis_journal_id' => $this->head->getId(), 'academic_database_id' => $database->getId()]);

        $json = json_decode($this->stream('base', 'json', (int)$database->getId()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(1, $json);
        $this->assertSame("Base teste {$this->uniq}", $json[0]['header']);
        foreach (["Revista Cabeça {$this->uniq}", "Revista Cabeça Online {$this->uniq}", "Apelido Online {$this->uniq}", $this->issnMember] as $expected) {
            $this->assertContains($expected, $json[0]['variants']);
        }
        $this->assertNotContains("Revista Alheia {$this->uniq}", $json[0]['variants'], 'Outra revista não está na base');

        // Pela chave de título, com o filtro da base: só a família ligada
        $byTitle = json_decode($this->stream('title', 'json', (int)$database->getId()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(["Nome Bonito {$this->uniq}"], array_column($byTitle, 'header'));
    }

    // ------------------------------------------------------------------------------------------------------------

    /**
     * ISSN (NNNN-NNNN) que nenhuma linha do banco de teste tem em nenhuma das quatro colunas.
     */
    private function uniqueIssn(): string
    {
        do {
            $issn = sprintf('%04d-%04d', random_int(1000, 9999), random_int(1000, 9999));
            $norm = str_replace('-', '', $issn);
            $taken = (int)$this->conn->fetchOne(
                'SELECT COUNT(*) FROM qualis_journals WHERE normalized_issn = ? OR normalized_issn_e = ? OR normalized_issn_l = ? OR normalized_issn_imp = ?',
                [$norm, $norm, $norm, $norm]
            );
        } while ($taken > 0);

        return $issn;
    }

    private function stream(string $key, string $format, ?int $databaseId = null): string
    {
        /** @var StreamedResponse $response */
        $response = $this->exporter->streamThesaurusExport($databaseId, $key, $format, true);
        ob_start();
        $response->sendContent();

        return (string)ob_get_clean();
    }

    private function journal(string $title, string $issn, ?QualisJournal $canonical = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        $journal->setIssnImp($issn);
        $journal->setCanonical($canonical);
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    private function variant(QualisJournal $journal, string $name): void
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
}
