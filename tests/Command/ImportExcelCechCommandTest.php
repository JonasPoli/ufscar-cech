<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\ProductionItem;
use App\Entity\Researcher;
use App\Service\Import\ExcelCechImporterService;
use Doctrine\DBAL\Connection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:import:excel` só complementa os docentes (departamento, ORCID, anos); o Qualis não vem mais da planilha (gravava os
 * textos "Qualis" e "sem qualis" da coluna N em production_items.qualis) e o comando termina com erro quando uma etapa falha,
 * inclusive quando a planilha não existe.
 *
 * Os dados usam ID Lattes e nome únicos e saem no tearDown (cech_test é compartilhado com outros testes e agentes).
 */
class ImportExcelCechCommandTest extends KernelTestCase
{
    private Connection $conn;
    private string $uniq;

    /** @var string[] */
    private array $tempFiles = [];
    /** @var string[] */
    private array $lattesIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(Connection::class);
        $this->uniq = 'qz' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ($this->lattesIds as $lattes) {
            // Os artigos de teste saem junto com o docente (ON DELETE CASCADE)
            $this->conn->executeStatement('DELETE FROM researchers WHERE id_lattes = ?', [$lattes]);
        }
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testASpreadsheetThatDoesNotExistFailsInsteadOfFakingSuccess(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['--info-file' => sys_get_temp_dir() . '/nao-existe-' . $this->uniq . '.xlsx']);

        $this->assertSame(Command::FAILURE, $status);
        $display = $this->flat($tester->getDisplay());
        $this->assertStringContainsString('não encontrado', $display);
        $this->assertStringNotContainsString('concluído com sucesso', $display);
    }

    public function testAnImportThatThrowsFailsWithTheErrorMessage(): void
    {
        // Um diretório existe, mas não é planilha: o leitor lança a exceção e o comando a traduz em erro
        $tester = $this->tester();

        $status = $tester->execute(['--info-file' => sys_get_temp_dir()]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Erro ao importar dados dos docentes', $this->flat($tester->getDisplay()));
    }

    public function testAFileWithoutAnyFacultyRowFailsToo(): void
    {
        $path = $this->tempFile('xlsx');
        file_put_contents($path, 'isto não é uma planilha');
        $tester = $this->tester();

        $status = $tester->execute(['--info-file' => $path]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Nenhum docente foi importado', $this->flat($tester->getDisplay()));
    }

    public function testAValidSpreadsheetOnlyComplementsTheFacultyAndLeavesTheArticlesQualisAlone(): void
    {
        $lattes = '9977' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        $this->lattesIds[] = $lattes;
        $path = $this->spreadsheet($lattes, "Docente Excel {$this->uniq}", 'DEd');

        // Um artigo do docente, sem Qualis: o comando não pode escrever nada em production_items.qualis
        $researcher = new Researcher();
        $researcher->setIdLattes($lattes);
        $researcher->setFullName("Docente Excel {$this->uniq}");
        $researcher->setSlug('docente-excel-' . $this->uniq);
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $article = new ProductionItem();
        $article->setItemType(ProductionItem::TYPE_ARTIGO);
        $article->setTitle('Artigo do docente do Excel');
        $article->setYear(2024);
        $article->setDoi('10.1234/qz.' . $this->uniq);
        $article->setJournalName('Revista Qualquer');
        $researcher->addProduction($article);
        $em->persist($researcher);
        $em->persist($article);
        $em->flush();
        $articleId = (int)$article->getId();
        $em->clear();

        $tester = $this->tester();
        $status = $tester->execute(['--info-file' => $path]);

        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->assertStringContainsString('1 docentes atualizados', $this->flat($tester->getDisplay()));
        $row = $this->conn->fetchAssociative('SELECT department, department_code, orcid, admission_year FROM researchers WHERE id_lattes = ?', [$lattes]);
        $this->assertSame(['Departamento de Educação', 'DEd', '0000-0002-1825-0097', 2010], [$row['department'], $row['department_code'], $row['orcid'], (int)$row['admission_year']]);
        $this->assertNull($this->conn->fetchOne('SELECT qualis FROM production_items WHERE id = ?', [$articleId]), 'O Qualis vem de qualis_journals, não da planilha');
    }

    public function testTheCommandNoLongerImportsQualisAndSaysItOnlyComplementsDepartments(): void
    {
        $command = (new Application(self::$kernel))->find('app:import:excel');

        $this->assertStringContainsString('Só complementa departamentos', $command->getDescription());
        $this->assertStringContainsString('[Removida, ignorada]', $command->getDefinition()->getOption('prod-file')->getDescription());
        $this->assertFalse(method_exists(ExcelCechImporterService::class, 'importProductionQualis'), 'O passo de Qualis da planilha de produção foi removido');
    }

    public function testTheRemovedProductionFileOptionIsIgnoredWithAWarningAndNeverRead(): void
    {
        $lattes = '9988' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        $this->lattesIds[] = $lattes;
        $info = $this->spreadsheet($lattes, "Docente Excel Dois {$this->uniq}", 'DPsi');
        $tester = $this->tester();

        // A planilha de produção nem existe: antes isso era um aviso e uma etapa; agora a opção só é ignorada
        $status = $tester->execute(['--info-file' => $info, '--prod-file' => sys_get_temp_dir() . '/producao-' . $this->uniq . '.xlsx']);

        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $display = $this->flat($tester->getDisplay());
        $this->assertStringContainsString('A opção --prod-file foi removida e é ignorada', $display);
        $this->assertStringNotContainsString('estratos Qualis', $display);
        $this->assertSame('Departamento de Psicologia', $this->conn->fetchOne('SELECT department FROM researchers WHERE id_lattes = ?', [$lattes]));
    }

    // ------------------------------------------------------------------------------------------------------------

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:import:excel'));
    }

    /**
     * Planilha no formato da de docentes do CECH: cabeçalho e uma linha (A = ID Lattes, B = departamento, C = nome, G = ORCID,
     * Y = ano de admissão, Z = ano de saída).
     */
    private function spreadsheet(string $lattes, string $name, string $department): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'ID Lattes');
        $sheet->setCellValueExplicit('A2', $lattes, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('B2', $department);
        $sheet->setCellValue('C2', $name);
        $sheet->setCellValue('G2', 'https://orcid.org/0000-0002-1825-0097');
        $sheet->setCellValue('Y2', 2010);

        $path = $this->tempFile('xlsx');
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function tempFile(string $extension): string
    {
        $path = sys_get_temp_dir() . '/excel_cech_' . $this->uniq . '_' . count($this->tempFiles) . '.' . $extension;
        $this->tempFiles[] = $path;

        return $path;
    }

    private function flat(string $display): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $display));
    }
}
