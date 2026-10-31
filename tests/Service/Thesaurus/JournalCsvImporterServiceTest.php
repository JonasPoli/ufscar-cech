<?php

declare(strict_types=1);

namespace App\Tests\Service\Thesaurus;

use App\Repository\JournalAreaRepository;
use App\Service\Thesaurus\JournalAreaLabeler;
use App\Service\Thesaurus\JournalCsvImporterService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Importação de periódicos por CSV (upsert): importar duas vezes não duplica, só os campos vazios são preenchidos, a
 * família travada nunca é tocada, linha inválida vira aviso e um erro desfaz o arquivo inteiro.
 *
 * Os dados usam sufixo único e saem no tearDown (cech_test é compartilhado com outros testes e agentes). Os ISSNs também
 * são únicos por execução: a importação procura o periódico por eles, e o banco de teste tem linhas com ISSNs fixos.
 */
class JournalCsvImporterServiceTest extends KernelTestCase
{
    private const LABEL_EDUCACAO = 'EDUCAÇÃO / CIÊNCIAS HUMANAS';

    private EntityManagerInterface $em;
    private Connection $conn;
    private JournalCsvImporterService $importer;
    private string $uniq;

    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->importer = $container->get(JournalCsvImporterService::class);
        $this->uniq = 'qz' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            if ($this->conn->isTransactionActive()) {
                $this->conn->rollBack();
            }
            // Áreas, evidências e variantes saem em cascata; as famílias se desfazem por SET NULL
            $this->conn->executeStatement('DELETE FROM qualis_journals WHERE title LIKE ?', ['%' . $this->uniq . '%']);
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
    // Upsert
    // ------------------------------------------------------------------------------------------------------------

    public function testTheSameFileImportedTwiceDoesNotDuplicateAnything(): void
    {
        $issn = $this->uniqueIssn();
        $path = $this->csv([
            ["Revista Com ISSN {$this->uniq}", $issn, 'b1', 'Educação', ''],
            ["Revista Sem ISSN {$this->uniq}", '', 'a2', '', ''],
        ]);

        $first = $this->importer->import($path);
        $this->assertSame(2, $first['created']);
        $this->assertSame(0, $first['ignored']);
        $this->assertSame(2, $this->countRows());

        $second = $this->importer->import($path);
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['completed']);
        $this->assertSame(2, $second['unchanged']);
        $this->assertSame(2, $second['ignored'], 'Já cadastrados e completos: nada a fazer');
        $this->assertSame(2, $this->countRows(), 'A segunda importação não duplicou');

        // Duas linhas do mesmo arquivo para o mesmo periódico também não duplicam (a segunda acha a primeira)
        $third = $this->importer->import($this->csv([
            ["Revista Repetida {$this->uniq}", '', 'b3', '', ''],
            ["Revista Repetida {$this->uniq}", '', '', '', ''],
        ]));
        $this->assertSame(1, $third['created']);
        $this->assertSame(1, $third['unchanged']);
        $this->assertSame(1, $this->countRows("Revista Repetida {$this->uniq}"));
    }

    public function testOnlyEmptyFieldsAreFilledAndNothingIsOverwritten(): void
    {
        $withQualis = $this->insertJournal("Revista Com Qualis {$this->uniq}", $this->uniqueIssn(), 'B2');
        $withoutQualis = $this->insertJournal("Revista Sem Qualis {$this->uniq}", $this->uniqueIssn(), null);

        $result = $this->importer->import($this->csv([
            // Já tem Qualis: o do arquivo não vale; a área, que estava vazia, entra
            ["Outro Título {$this->uniq}", $withQualis['issn'], 'a1', 'Educação', ''],
            // Sem Qualis: preenche
            ["Outro Título Dois {$this->uniq}", $withoutQualis['issn'], 'a3', '', ''],
        ]));

        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['completed']);

        $row = $this->row($withQualis['id']);
        $this->assertSame('B2', $row['qualis']);
        $this->assertSame("Revista Com Qualis {$this->uniq}", $row['title'], 'O título nunca muda');
        $this->assertSame(self::LABEL_EDUCACAO, $row['area']);
        $this->assertSame('Educação', $row['area_raw']);
        $this->assertSame('A3', $this->row($withoutQualis['id'])['qualis']);

        // De novo, com outros valores: tudo já está preenchido, nada muda
        $again = $this->importer->import($this->csv([
            ["Outro Título {$this->uniq}", $withQualis['issn'], 'c', 'Direito', ''],
            ["Outro Título Dois {$this->uniq}", $withoutQualis['issn'], 'b4', '', ''],
        ]));
        $this->assertSame(0, $again['completed']);
        $this->assertSame(2, $again['unchanged']);
        $this->assertSame('B2', $this->row($withQualis['id'])['qualis']);
        $this->assertSame('A3', $this->row($withoutQualis['id'])['qualis']);
        $this->assertSame(self::LABEL_EDUCACAO, $this->row($withQualis['id'])['area']);
    }

    public function testTheJournalIsFoundByAnyOfTheFourIssnColumnsAndByEachIssnOfTheField(): void
    {
        $onlyElectronic = $this->uniqueIssn();
        $other = $this->uniqueIssn();
        $id = $this->insertJournal("Revista Eletrônica {$this->uniq}", null, null);
        $this->conn->executeStatement(
            'UPDATE qualis_journals SET issn_e = ?, normalized_issn_e = ? WHERE id = ?',
            [$onlyElectronic, str_replace('-', '', $onlyElectronic), $id['id']]
        );

        $result = $this->importer->import($this->csv([
            // O ISSN está só na coluna do eletrônico
            ["Qualquer Coisa {$this->uniq}", $onlyElectronic, 'a4', '', ''],
            // Dois ISSNs no mesmo campo: o segundo é o que o cadastro tem
            ["Outra Coisa {$this->uniq}", "{$other}, {$onlyElectronic}", '', '', ''],
        ]));

        $this->assertSame(0, $result['created'], 'Os dois ISSNs achados são o mesmo periódico');
        $this->assertSame(1, $result['completed']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame('A4', $this->row($id['id'])['qualis']);
        $this->assertSame(0, $this->countRows("Qualquer Coisa {$this->uniq}"));
    }

    public function testALegacyRowWithTheIssnOnlyInTheTextColumnIsFoundInsteadOfDuplicated(): void
    {
        // Linha antiga: o ISSN está em `issn`, sem nenhuma coluna normalizada. O arquivo exportado traz esse texto
        $issn = $this->uniqueIssn();
        $id = $this->insertJournal("Revista Antiga {$this->uniq}", null, null);
        $this->conn->executeStatement('UPDATE qualis_journals SET issn = ?, issn_imp = ? WHERE id = ?', [$issn, $issn, $id['id']]);

        $result = $this->importer->import($this->csv([["Revista Antiga {$this->uniq}", $issn, 'b3', '', '']]));

        $this->assertSame(0, $result['created'], 'Achada pelo texto do ISSN, não duplicada');
        $this->assertSame(1, $result['completed']);
        $this->assertSame('B3', $this->row($id['id'])['qualis']);
        $this->assertSame(1, $this->countRows("Revista Antiga {$this->uniq}"));
    }

    public function testACanonicalLabelInTheAreaColumnNeverGoesToAreaRaw(): void
    {
        // O que a exportação traz em `area` das linhas classificadas é o rótulo canônico, não o dado bruto
        $classified = $this->insertJournal("Revista Classificada {$this->uniq}", $this->uniqueIssn(), null);
        $this->conn->executeStatement('UPDATE qualis_journals SET area = ? WHERE id = ?', [self::LABEL_EDUCACAO, $classified['id']]);
        $unclassified = $this->insertJournal("Revista Não Classificada {$this->uniq}", $this->uniqueIssn(), null);

        $result = $this->importer->import($this->csv([
            ["Revista Classificada {$this->uniq}", $classified['issn'], '', self::LABEL_EDUCACAO, ''],
            ["Revista Não Classificada {$this->uniq}", $unclassified['issn'], '', self::LABEL_EDUCACAO, ''],
        ]));

        $this->assertSame(1, $result['unchanged'], 'A linha que já tem o rótulo não muda');
        $this->assertSame(1, $result['completed']);
        $this->assertSame([self::LABEL_EDUCACAO, null], [$this->row($classified['id'])['area'], $this->row($classified['id'])['area_raw']]);
        $this->assertSame([self::LABEL_EDUCACAO, null], [$this->row($unclassified['id'])['area'], $this->row($unclassified['id'])['area_raw']], 'O rótulo entra em area, mas area_raw só guarda o texto original');
    }

    public function testARowWithAnIssnTheCatalogDoesNotKnowIsNewEvenIfTheTitleExists(): void
    {
        $this->insertJournal("Revista Existente {$this->uniq}", $this->uniqueIssn(), 'B1');

        $result = $this->importer->import($this->csv([["Revista Existente {$this->uniq}", $this->uniqueIssn(), 'a1', '', '']]));

        $this->assertSame(1, $result['created'], 'Com ISSN, o título não decide: é outra linha do cadastro (outro ISSN da mesma revista)');
        $this->assertSame(2, $this->countRows("Revista Existente {$this->uniq}"));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Família travada e título ambíguo
    // ------------------------------------------------------------------------------------------------------------

    public function testRowsOfALockedFamilyAreNeverTouched(): void
    {
        $head = $this->insertJournal("Revista Travada {$this->uniq}", $this->uniqueIssn(), null);
        $member = $this->insertJournal("Revista Travada Online {$this->uniq}", $this->uniqueIssn(), null, $head['id']);
        $this->conn->executeStatement('UPDATE qualis_journals SET areas_locked = 1 WHERE id = ?', [$head['id']]);

        $result = $this->importer->import($this->csv([
            ["Revista Travada {$this->uniq}", $head['issn'], 'a1', 'Educação', ''],
            // A linha membro não tem a trava, mas a família tem
            ["Revista Travada Online {$this->uniq}", $member['issn'], 'a2', 'Educação', 'Direito'],
            // Sem ISSN, pelo título
            ["Revista Travada {$this->uniq}", '', 'a3', '', ''],
        ]));

        $this->assertSame(3, $result['locked']);
        $this->assertSame(3, $result['ignored']);
        $this->assertSame(0, $result['created'] + $result['completed']);
        foreach ([$head['id'], $member['id']] as $id) {
            $row = $this->row($id);
            $this->assertNull($row['qualis'], 'A curadoria vence: nada é preenchido');
            $this->assertNull($row['area']);
        }
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM journal_areas WHERE journal_id IN (?, ?)', [$head['id'], $member['id']]));
    }

    public function testATitleOfMoreThanOneFamilyWithoutIssnIsAmbiguousAndIgnored(): void
    {
        $this->insertJournal("Revista Gêmea {$this->uniq}", $this->uniqueIssn(), null);
        $this->insertJournal("Revista Gêmea {$this->uniq}", $this->uniqueIssn(), null);

        $result = $this->importer->import($this->csv([["Revista Gêmea {$this->uniq}", '', 'a1', '', '']]));

        $this->assertSame(1, $result['ambiguous']);
        $this->assertSame(1, $result['ignored']);
        $this->assertSame(0, $result['created'] + $result['completed']);
        $this->assertSame(2, $this->countRows("Revista Gêmea {$this->uniq}"));
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('mais de uma família', $result['warnings'][0]);
    }

    public function testTheTitleOfTwoRowsOfTheSameFamilyIsNotAmbiguous(): void
    {
        $head = $this->insertJournal("Revista Dupla {$this->uniq}", $this->uniqueIssn(), null);
        $this->insertJournal("Revista Dupla {$this->uniq}", $this->uniqueIssn(), null, $head['id']);

        $result = $this->importer->import($this->csv([["Revista Dupla {$this->uniq}", '', 'b2', '', '']]));

        $this->assertSame(0, $result['ambiguous']);
        $this->assertSame(1, $result['completed']);
        $this->assertSame('B2', $this->row($head['id'])['qualis'], 'Vale a linha de menor id da família');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Linhas inválidas e transação
    // ------------------------------------------------------------------------------------------------------------

    public function testInvalidRowsBecomeWarningsAndDoNotStopTheOthers(): void
    {
        $result = $this->importer->import($this->csv([
            ["Revista Válida Um {$this->uniq}", '', 'a1', '', ''],
            [str_repeat('T', 501 - strlen($this->uniq)) . $this->uniq, '', '', '', ''],
            ["Revista Qualis Longo {$this->uniq}", '', 'ABCDEFGHIJK', '', ''],
            ["Revista ISSN Longo {$this->uniq}", str_repeat('1', 51), '', '', ''],
            ['', $this->uniqueIssn(), 'b1', '', ''],
            ["Revista Válida Dois {$this->uniq}", '', 'b2', '', ''],
        ]));

        $this->assertSame(6, $result['read']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(4, $result['invalid']);
        $this->assertSame(2, $result['processed']);
        $this->assertCount(4, $result['warnings']);
        $this->assertStringContainsString('Linha 3', $result['warnings'][0]);
        $this->assertStringContainsString('título com 501 caracteres', $result['warnings'][0]);
        $this->assertStringContainsString('Linha 4', $result['warnings'][1]);
        $this->assertStringContainsString('Qualis', $result['warnings'][1]);
        $this->assertStringContainsString('ISSN', $result['warnings'][2]);
        $this->assertStringContainsString('sem título', $result['warnings'][3]);

        $this->assertSame(1, $this->countRows("Revista Válida Um {$this->uniq}"));
        $this->assertSame(1, $this->countRows("Revista Válida Dois {$this->uniq}"));
        $this->assertSame(0, $this->countRows("Revista Qualis Longo {$this->uniq}"));
        $this->assertSame(0, $this->countRows("Revista ISSN Longo {$this->uniq}"));
    }

    public function testAnUnexpectedFailureRollsTheWholeFileBack(): void
    {
        $areaRepo = $this->createMock(JournalAreaRepository::class);
        $areaRepo->method('resolveSlugs')->willReturn(['slugs' => ['educacao'], 'unknown' => []]);
        $areaRepo->method('replaceManual')->willThrowException(new \RuntimeException('falha simulada'));
        $importer = new JournalCsvImporterService($this->em, $areaRepo, static::getContainer()->get(JournalAreaLabeler::class));

        $path = $this->csv([
            ["Revista Antes Do Erro {$this->uniq}", '', 'a1', '', ''],
            ["Revista Do Erro {$this->uniq}", '', 'a1', '', 'Educação'],
            ["Revista Depois Do Erro {$this->uniq}", '', 'a1', '', ''],
        ]);

        try {
            $importer->import($path);
            $this->fail('A falha da segunda linha deveria subir');
        } catch (\RuntimeException $e) {
            $this->assertSame('falha simulada', $e->getMessage());
        }

        $this->assertFalse($this->conn->isTransactionActive(), 'A transação não pode ficar aberta');
        $this->assertSame(0, $this->countRows(), 'Nem a linha anterior ao erro fica gravada');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Áreas
    // ------------------------------------------------------------------------------------------------------------

    public function testAreaTextGoesToAreaRawAndTheCanonicalLabelWithoutLockingTheFamily(): void
    {
        $result = $this->importer->import($this->csv([
            ["Revista Reconhecida {$this->uniq}", '', '', 'Educação', ''],
            ["Revista Livre {$this->uniq}", '', '', "Categoria sem mapa {$this->uniq}", ''],
        ]));
        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['withAreas']);

        $recognized = $this->rowByTitle("Revista Reconhecida {$this->uniq}");
        $this->assertSame(self::LABEL_EDUCACAO, $recognized['area']);
        $this->assertSame('Educação', $recognized['area_raw']);
        $this->assertSame(0, (int)$recognized['areas_locked'], 'O texto de area não trava: a classificação automática continua valendo');
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM journal_areas WHERE journal_id = ?', [$recognized['id']]));

        $free = $this->rowByTitle("Revista Livre {$this->uniq}");
        $this->assertSame("Categoria sem mapa {$this->uniq}", $free['area']);
        $this->assertSame("Categoria sem mapa {$this->uniq}", $free['area_raw']);
    }

    public function testAreasCapesIsAppliedOnlyToAFamilyWithoutAreas(): void
    {
        $plain = $this->insertJournal("Revista Sem Áreas {$this->uniq}", $this->uniqueIssn(), null);
        $classified = $this->insertJournal("Revista Já Classificada {$this->uniq}", $this->uniqueIssn(), null);
        $this->conn->insert('journal_areas', [
            'journal_id' => $classified['id'],
            'area_id' => static::getContainer()->get(\App\Service\Journal\CapesTaxonomy::class)->bySlug('psicologia')['id'],
            'is_primary' => 1,
            'score' => '0.9000',
            'confidence' => 'high',
            'method' => 'auto',
            'run_id' => 'teste',
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $result = $this->importer->import($this->csv([
            ["Revista Nova {$this->uniq}", '', '', '', 'Direito'],
            ["Revista Sem Áreas {$this->uniq}", $plain['issn'], '', '', 'Educação;Sociologia'],
            ["Revista Já Classificada {$this->uniq}", $classified['issn'], '', '', 'Educação'],
        ]));

        $this->assertSame(2, $result['withAreas'], 'A nova e a que não tinha áreas');
        $this->assertSame(['educacao', 'sociologia'], $this->areaSlugs($plain['id']));
        $this->assertSame(1, (int)$this->row($plain['id'])['areas_locked']);
        $this->assertSame(['psicologia'], $this->areaSlugs($classified['id']), 'O que já está classificado não é sobrescrito');
        $this->assertSame(0, (int)$this->row($classified['id'])['areas_locked']);
        $this->assertSame(['direito'], $this->areaSlugs($this->rowByTitle("Revista Nova {$this->uniq}")['id']));
    }

    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows título, ISSN, Qualis, área e areas_capes
     */
    private function csv(array $rows): string
    {
        $path = sys_get_temp_dir() . '/journal_csv_' . $this->uniq . '_' . count($this->tempFiles) . '.csv';
        $handle = fopen($path, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['title', 'issn', 'qualis', 'area', 'areas_capes'], ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';', '"', '\\');
        }
        fclose($handle);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array{id: int, issn: ?string}
     */
    private function insertJournal(string $title, ?string $issn, ?string $qualis, ?int $canonicalId = null): array
    {
        $this->conn->insert('qualis_journals', [
            'title' => $title,
            'normalized_title' => StringNormalizer::normalizeString($title, true),
            'issn' => $issn,
            'normalized_issn' => $issn !== null ? str_replace('-', '', $issn) : null,
            'qualis' => $qualis,
            'canonical_journal_id' => $canonicalId,
            'areas_locked' => 0,
        ]);

        return ['id' => (int)$this->conn->lastInsertId(), 'issn' => $issn];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM qualis_journals WHERE id = ?', [$id]);
        $this->assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowByTitle(string $title): array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM qualis_journals WHERE title = ?', [$title]);
        $this->assertIsArray($row, "Periódico «{$title}» não foi criado");

        return $row;
    }

    private function countRows(?string $title = null): int
    {
        return $title === null
            ? (int)$this->conn->fetchOne('SELECT COUNT(*) FROM qualis_journals WHERE title LIKE ?', ['%' . $this->uniq . '%'])
            : (int)$this->conn->fetchOne('SELECT COUNT(*) FROM qualis_journals WHERE title = ?', [$title]);
    }

    /**
     * @return list<string>
     */
    private function areaSlugs(int $journalId): array
    {
        return array_map('strval', $this->conn->fetchFirstColumn(
            'SELECT a.slug FROM journal_areas ja JOIN capes_areas a ON a.id = ja.area_id WHERE ja.journal_id = ? ORDER BY ja.is_primary DESC, a.slug',
            [$journalId]
        ));
    }

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
}
