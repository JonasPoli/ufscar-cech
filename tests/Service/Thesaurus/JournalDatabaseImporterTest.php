<?php

declare(strict_types=1);

namespace App\Tests\Service\Thesaurus;

use App\Entity\AcademicDatabase;
use App\Entity\JournalVariation;
use App\Entity\QualisJournal;
use App\Service\Thesaurus\JournalDatabaseImporterService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Importação de listas de bases (Scopus, WoS...) pelo JournalDatabaseImporterService: a trava de variantes, o tratamento de
 * nomes ambíguos, a escolha da linha de menor id para um ISSN repetido, a replicação dos vínculos na família e o destino do
 * texto de área (`area_raw` e rótulo canônico).
 *
 * Os dados usam sufixo único e saem no tearDown (cech_test é compartilhado com outros testes e agentes); cada teste cria a
 * sua própria base de indexação, para os vínculos não se misturarem.
 */
class JournalDatabaseImporterTest extends KernelTestCase
{
    private const LABEL_EDUCACAO = 'EDUCAÇÃO / CIÊNCIAS HUMANAS';
    private const LABEL_PSICOLOGIA = 'PSICOLOGIA / CIÊNCIAS HUMANAS';

    private EntityManagerInterface $em;
    private Connection $conn;
    private JournalDatabaseImporterService $importer;
    private string $uniq;
    private string $acronym;
    private int $databaseId;

    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->importer = $container->get(JournalDatabaseImporterService::class);
        $this->uniq = 'qz' . bin2hex(random_bytes(4));

        $database = new AcademicDatabase();
        $database->setName("Base teste {$this->uniq}");
        $database->setAcronym("{$this->uniq}b");
        $this->em->persist($database);
        $this->em->flush();
        $this->acronym = (string)$database->getAcronym();
        $this->databaseId = (int)$database->getId();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            // Variantes, vínculos e áreas saem em cascata; as famílias se desfazem por SET NULL
            $this->conn->executeStatement('DELETE FROM qualis_journals WHERE title LIKE ?', ['%' . $this->uniq . '%']);
            $this->conn->executeStatement('DELETE FROM academic_database WHERE acronym = ?', [$this->acronym]);
        }
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        if (isset($this->em)) {
            $this->em->clear();
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------------------
    // Trava de variantes
    // ------------------------------------------------------------------------------------------------------------

    public function testNewVariantsGoThroughTheGuardAndAreWrittenOnTheCanonicalOfTheFamily(): void
    {
        $head = $this->journal("Revista Cabeça {$this->uniq}", $this->uniqueIssn());
        $memberIssn = $this->uniqueIssn();
        $this->journal("Revista Membro {$this->uniq}", $memberIssn, $head);
        $owner = $this->journal("Revista Dona {$this->uniq}", $this->uniqueIssn());
        $this->variant($owner, "Nome do Outro {$this->uniq}");

        $result = $this->import([
            ["Revista Membro {$this->uniq}", $memberIssn, "Abrev Nova {$this->uniq}"],
            // Pertence a outra família: conflito, continua com o dono
            ["Revista Membro {$this->uniq}", $memberIssn, "NOME DO OUTRO {$this->uniq}"],
            // Código, não nome: a trava recusa
            ["Revista Membro {$this->uniq}", $memberIssn, '<html><body>Fatal error: Stack trace</body></html>'],
            // Título de uma linha da própria família: já conhecido, não é conflito
            ["Revista Membro {$this->uniq}", $memberIssn, "revista cabeça {$this->uniq}"],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['updated']);
        $this->assertSame(1, $result['variationsAdded']);
        $this->assertSame(3, $result['variationsSkipped']);
        $this->assertSame(1, $result['variationConflicts'], 'Só o nome da outra família é conflito');

        // A variante nova fica na canônica, não na linha que casou
        $variant = $this->conn->fetchAssociative('SELECT journal_id, variation_type, status FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Abrev Nova {$this->uniq}")]);
        $this->assertIsArray($variant);
        $this->assertSame($head->getId(), (int)$variant['journal_id']);
        $this->assertSame('alternative', $variant['variation_type']);
        $this->assertSame(1, (int)$variant['status']);

        $this->assertSame($owner->getId(), (int)$this->conn->fetchOne('SELECT journal_id FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Nome do Outro {$this->uniq}")]));
        $this->assertSame(0, (int)$this->conn->fetchOne("SELECT COUNT(*) FROM journal_name_variants WHERE variation_name LIKE '%<html>%'"), 'Nenhum código virou variante');
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Revista Cabeça {$this->uniq}")]), 'O título da família não vira variante');
    }

    public function testManyNewVariantsAreSavedInBatchesAndAreNotRepeatedByAnotherImport(): void
    {
        // Mais que o lote de gravação (200): a trava confere cada nome contra o que ainda não foi gravado
        $rows = [];
        for ($i = 1; $i <= 450; $i++) {
            $rows[] = [sprintf('Revista Lote %03d %s', $i, $this->uniq), $this->issnFor($i), sprintf('Abreviatura Lote %03d %s', $i, $this->uniq)];
        }
        $path = $this->csv($rows);

        $first = $this->importer->import($path, $this->acronym);
        $this->assertTrue($first['success']);
        $this->assertSame(450, $first['inserted']);
        $this->assertSame(450, $first['variationsAdded']);
        $this->assertSame(0, $first['variationsSkipped']);
        $this->assertSame(450, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM journal_name_variants WHERE normalized_name LIKE ?', ['ABREVIATURA LOTE % ' . strtoupper($this->uniq)]));

        // Outra importação do mesmo arquivo: nada de novo, e os nomes já conhecidos não chegam à trava
        $second = $this->importer->import($path, $this->acronym);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(450, $second['updated']);
        $this->assertSame(0, $second['variationsAdded']);
        $this->assertSame(450, $second['variationsSkipped']);
        $this->assertSame(0, $second['variationConflicts']);
        $this->assertSame(450, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM journal_name_variants WHERE normalized_name LIKE ?', ['ABREVIATURA LOTE % ' . strtoupper($this->uniq)]));
        $this->assertSame(450, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM qualis_journals WHERE title LIKE ?', ['Revista Lote % ' . $this->uniq]));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Ambiguidade e ISSN repetido
    // ------------------------------------------------------------------------------------------------------------

    public function testARowWhoseNameBelongsToMoreThanOneFamilyIsIgnoredWithItsVariants(): void
    {
        $this->journal("Revista Gêmea {$this->uniq}", $this->uniqueIssn());
        $this->journal("Revista Gêmea {$this->uniq}", $this->uniqueIssn());

        $result = $this->import([
            // Sem ISSN conhecido, o nome casa com as duas famílias: não há como saber qual é o periódico
            ["Revista Gêmea {$this->uniq}", '', "Apelido da Gêmea {$this->uniq}"],
            // O mesmo nome sem os parênteses também é ambíguo
            ["Revista Gêmea {$this->uniq} (Online)", '', ''],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['ambiguous']);
        $this->assertSame(0, $result['inserted'], 'Não cria um terceiro periódico com o mesmo nome');
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['linksCreated']);
        $this->assertSame(0, $result['variationsAdded'], 'Variante de linha ambígua não entra');
        $this->assertSame(2, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM qualis_journals WHERE normalized_title = ?', [self::norm("Revista Gêmea {$this->uniq}")]));
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM qualis_journal_academic_database WHERE academic_database_id = ?', [$this->databaseId]));
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Apelido da Gêmea {$this->uniq}")]));
    }

    public function testAnIssnWithAnUnambiguousTitleStillMatchesWhenTheNameIsAmbiguous(): void
    {
        $issn = $this->uniqueIssn();
        $first = $this->journal("Revista Gêmea Dois {$this->uniq}", $issn);
        $this->journal("Revista Gêmea Dois {$this->uniq}", $this->uniqueIssn());

        // O ISSN identifica o periódico mesmo com o nome repetido em duas famílias
        $result = $this->import([["Revista Gêmea Dois {$this->uniq}", $issn, "Apelido Dois {$this->uniq}"]]);

        $this->assertSame(0, $result['ambiguous']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame([$first->getId()], $this->linkedJournals());
        $this->assertSame($first->getId(), (int)$this->conn->fetchOne('SELECT journal_id FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Apelido Dois {$this->uniq}")]));
    }

    public function testAnIssnSharedByTwoRowsBelongsToTheLowestId(): void
    {
        $issn = $this->uniqueIssn();
        $first = new QualisJournal();
        $first->setTitle("Revista Primeira {$this->uniq}");
        $first->setIssnImp($issn);
        $second = new QualisJournal();
        $second->setTitle("Revista Segunda {$this->uniq}");
        $second->setIssnE($issn);
        $this->em->persist($first);
        $this->em->flush();
        $this->em->persist($second);
        $this->em->flush();

        $result = $this->import([["Outro Título {$this->uniq}", $issn, '']]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame([$first->getId()], $this->linkedJournals(), 'Como no resolver de periódicos, vale a linha de menor id');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Vínculos por família
    // ------------------------------------------------------------------------------------------------------------

    public function testTheBaseLinkIsReplicatedToTheWholeFamilyAfterTheImport(): void
    {
        $head = $this->journal("Revista Família {$this->uniq}", $this->uniqueIssn());
        $memberIssn = $this->uniqueIssn();
        $member = $this->journal("Revista Família Online {$this->uniq}", $memberIssn, $head);
        $sibling = $this->journal("Revista Família Impressa {$this->uniq}", $this->uniqueIssn(), $head);
        $alien = $this->journal("Revista Alheia {$this->uniq}", $this->uniqueIssn());

        // A lista só traz a linha membro, pelo ISSN dela
        $result = $this->import([["Revista Família Online {$this->uniq}", $memberIssn, '']]);

        $this->assertSame(1, $result['linksCreated']);
        // Pelo menos as duas linhas desta família (a replicação vale para todas as famílias do banco compartilhado)
        $this->assertGreaterThanOrEqual(2, $result['baseLinksReplicated'], 'A canônica e a outra linha da família recebem o vínculo');
        $linked = $this->linkedJournals();
        $this->assertSame([$head->getId(), $member->getId(), $sibling->getId()], $linked);
        $this->assertNotContains($alien->getId(), $linked, 'Outra família não recebe nada');

        // Importar de novo não cria nada
        $again = $this->import([["Revista Família Online {$this->uniq}", $memberIssn, '']]);
        $this->assertSame(0, $again['linksCreated']);
        $this->assertSame($linked, $this->linkedJournals());
    }

    // ------------------------------------------------------------------------------------------------------------
    // Texto de área
    // ------------------------------------------------------------------------------------------------------------

    public function testAreaTextGoesToAreaRawAndTheCanonicalLabelWhenTheThesaurusRecognizesIt(): void
    {
        $emptyIssn = $this->uniqueIssn();
        $labelledIssn = $this->uniqueIssn();
        $this->journal("Revista Sem Área {$this->uniq}", $emptyIssn);
        $labelled = $this->journal("Revista Com Rótulo {$this->uniq}", $labelledIssn);
        $this->conn->executeStatement('UPDATE qualis_journals SET area = ? WHERE id = ?', [self::LABEL_EDUCACAO, $labelled->getId()]);
        $newRecognizedIssn = $this->uniqueIssn();
        $newFreeIssn = $this->uniqueIssn();

        $result = $this->import([
            ["Revista Nova Reconhecida {$this->uniq}", $newRecognizedIssn, '', 'Educação'],
            ["Revista Nova Livre {$this->uniq}", $newFreeIssn, '', "Categoria sem mapa {$this->uniq}"],
            ["Revista Sem Área {$this->uniq}", $emptyIssn, '', 'Psicologia'],
            ["Revista Com Rótulo {$this->uniq}", $labelledIssn, '', 'Psicologia'],
        ]);

        $this->assertSame(2, $result['inserted']);
        $this->assertSame(2, $result['updated']);

        // Novo, reconhecido: rótulo canônico em `area` e o texto bruto em `area_raw`
        $this->assertSame([self::LABEL_EDUCACAO, 'Educação'], $this->areaColumns("Revista Nova Reconhecida {$this->uniq}"));
        // Novo, sem categoria no tesauro: o próprio texto nas duas
        $this->assertSame(["Categoria sem mapa {$this->uniq}", "Categoria sem mapa {$this->uniq}"], $this->areaColumns("Revista Nova Livre {$this->uniq}"));
        // Existente e vazio: preenchido pela mesma regra
        $this->assertSame([self::LABEL_PSICOLOGIA, 'Psicologia'], $this->areaColumns("Revista Sem Área {$this->uniq}"));
        // Existente com `area` preenchida: não é sobrescrita, mas o texto bruto que faltava entra em `area_raw`
        $this->assertSame([self::LABEL_EDUCACAO, 'Psicologia'], $this->areaColumns("Revista Com Rótulo {$this->uniq}"));

        // Importar de novo com outro texto não muda o que já está preenchido
        $this->import([["Revista Sem Área {$this->uniq}", $emptyIssn, '', 'Direito']]);
        $this->assertSame([self::LABEL_PSICOLOGIA, 'Psicologia'], $this->areaColumns("Revista Sem Área {$this->uniq}"));
    }

    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param list<array{0: string, 1: string, 2?: string, 3?: string}> $rows título, ISSN, abreviação e área
     *
     * @return array<string, mixed>
     */
    private function import(array $rows): array
    {
        $result = $this->importer->import($this->csv($rows), $this->acronym);
        $this->assertTrue($result['success'], implode(' ', $result['errors']));

        return $result;
    }

    /**
     * @param list<array{0: string, 1: string, 2?: string, 3?: string}> $rows
     */
    private function csv(array $rows): string
    {
        $path = sys_get_temp_dir() . '/journal_list_' . $this->uniq . '_' . count($this->tempFiles) . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, ['Source Title', 'ISSN', 'Abbreviation', 'Area'], ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, [$row[0], $row[1], $row[2] ?? '', $row[3] ?? ''], ',', '"', '\\');
        }
        fclose($handle);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function journal(string $title, ?string $issn = null, ?QualisJournal $canonical = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        if ($issn !== null) {
            $journal->setIssnImp($issn);
        }
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
        $variation->setNormalizedName(self::norm($name));
        $variation->setVariationType('alternative');
        $variation->setStatus(true);
        $this->em->persist($variation);
        $this->em->flush();
    }

    /**
     * @return list<int> ids dos periódicos ligados à base deste teste, em ordem
     */
    private function linkedJournals(): array
    {
        return array_map('intval', $this->conn->fetchFirstColumn(
            'SELECT qualis_journal_id FROM qualis_journal_academic_database WHERE academic_database_id = ? ORDER BY qualis_journal_id',
            [$this->databaseId]
        ));
    }

    /**
     * @return array{0: ?string, 1: ?string} area e area_raw do periódico
     */
    private function areaColumns(string $title): array
    {
        $row = $this->conn->fetchAssociative('SELECT area, area_raw FROM qualis_journals WHERE title = ?', [$title]);
        $this->assertIsArray($row, "Periódico «{$title}» não existe");

        return [$row['area'], $row['area_raw']];
    }

    private static function norm(string $name): string
    {
        return StringNormalizer::normalizeString($name, true);
    }

    /**
     * ISSN (NNNN-NNNN) que nenhuma linha do banco de teste tem em nenhuma das quatro colunas.
     */
    private function uniqueIssn(): string
    {
        do {
            $issn = sprintf('%04d-%04d', random_int(1000, 9999), random_int(1000, 9999));
        } while ($this->issnTaken($issn));

        return $issn;
    }

    /**
     * ISSN determinístico por posição (os 450 do teste de lote), também livre no banco.
     */
    private function issnFor(int $i): string
    {
        static $base = null;
        $base ??= random_int(1000, 8999);

        $issn = sprintf('%04d-%04d', $base + intdiv($i, 100), 1000 + $i);

        return $this->issnTaken($issn) ? sprintf('%04d-%04d', random_int(1000, 9999), random_int(1000, 9999)) : $issn;
    }

    private function issnTaken(string $issn): bool
    {
        $norm = str_replace('-', '', $issn);

        return (int)$this->conn->fetchOne(
            'SELECT COUNT(*) FROM qualis_journals WHERE normalized_issn = ? OR normalized_issn_e = ? OR normalized_issn_l = ? OR normalized_issn_imp = ?',
            [$norm, $norm, $norm, $norm]
        ) > 0;
    }
}
