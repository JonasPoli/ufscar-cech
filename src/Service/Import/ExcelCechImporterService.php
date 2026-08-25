<?php

namespace App\Service\Import;

use App\Entity\Researcher;
use App\Entity\ProductionItem;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Serviço de importação e enriquecimento a partir da planilha oficial de docentes do CECH.
 *
 * Utiliza o PhpSpreadsheet para ler as planilhas institucionais (.xlsx),
 * mapeando códigos de departamento (ex: 'LE', 'FIL', 'CS', 'DPsi'), nomes completos,
 * e-mails institucionais e datas de admissão, cruzando os dados com a base Lattes pelo ID de 16 dígitos.
 */
class ExcelCechImporterService
{
    /**
     * @param EntityManagerInterface $em Gerenciador de entidades do Doctrine
     */
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    /**
     * Importa metadados docentes a partir de uma planilha Excel institucional (.xlsx).
     *
     * @param string $filePath Caminho para o arquivo Excel
     * @return int Total de registros de docentes processados/atualizados
     * @throws \InvalidArgumentException Se o arquivo não existir
     */
    public function importFacultyInfo(string $filePath): int
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $rows = $sheet->toArray(null, true, true, true);
        if (empty($rows)) return 0;

        $header = array_shift($rows);
        $count = 0;

        $departmentNames = [
            'AC' => 'Departamento de Artes e Comunicação',
            'DAC' => 'Departamento de Artes e Comunicação',
            'CA' => 'Departamento de Ciências Ambientais',
            'DCA' => 'Departamento de Ciências Ambientais',
            'DCAm' => 'Departamento de Ciências Ambientais',
            'CI' => 'Departamento de Ciência da Informação',
            'DCI' => 'Departamento de Ciência da Informação',
            'CS' => 'Departamento de Ciências Sociais',
            'DCSo' => 'Departamento de Ciências Sociais',
            'DCS' => 'Departamento de Ciências Sociais',
            'ED' => 'Departamento de Educação',
            'DEd' => 'Departamento de Educação',
            'DEC' => 'Departamento de Educação e Comunicação',
            'FI' => 'Departamento de Filosofia',
            'FIL' => 'Departamento de Filosofia',
            'DFil' => 'Departamento de Filosofia',
            'IFD' => 'Departamento de Metodologia de Ensino / Formação Docente',
            'DME' => 'Departamento de Metodologia de Ensino',
            'DMTE' => 'Departamento de Metodologia e Teoria da Educação',
            'DTE' => 'Departamento de Teoria e Prática da Educação',
            'LE' => 'Departamento de Letras',
            'DL' => 'Departamento de Letras',
            'PS' => 'Departamento de Psicologia',
            'DPsi' => 'Departamento de Psicologia',
            'SO' => 'Departamento de Sociologia',
            'DSo' => 'Departamento de Sociologia',
            'TPP' => 'Departamento de Teoria e Prática Pedagógica',
            'DTPP' => 'Departamento de Teoria e Prática Pedagógica',
            'DEF' => 'Departamento de Educação Física',
            'DAD' => 'Departamento de Administração',
            'DART' => 'Departamento de Artes',
            'DMUS' => 'Departamento de Música',
            'GEO' => 'Departamento de Geografia',
            'HIS' => 'Departamento de História',
        ];

        foreach ($rows as $row) {
            $idLattes = trim((string)($row['A'] ?? ''));
            if ($idLattes === '' || !preg_match('/^\d{16}$/', $idLattes)) continue;

            $departmentCode = trim((string)($row['B'] ?? ''));
            $fullName = trim((string)($row['C'] ?? ''));
            $orcid = trim((string)($row['G'] ?? ''));
            $admissionYear = (int)($row['Y'] ?? 0);
            $leaveYear = (int)($row['Z'] ?? 0);

            $researcher = $this->em->getRepository(Researcher::class)->findOneBy(['idLattes' => $idLattes]);
            if (!$researcher) {
                $researcher = new Researcher();
                $researcher->setIdLattes($idLattes);
                if ($fullName) {
                    $researcher->setFullName($fullName);
                    $researcher->setSlug(StringNormalizer::slugify($fullName));
                }
                $this->em->persist($researcher);
            }

            if ($departmentCode !== '') {
                $researcher->setDepartmentCode($departmentCode);
                $researcher->setDepartment($departmentNames[$departmentCode] ?? "Departamento ({$departmentCode})");
            }

            if ($orcid !== '' && !$researcher->getOrcid()) {
                $researcher->setOrcid(preg_replace('#^https?://orcid\.org/#i', '', $orcid));
            }

            if ($admissionYear > 0) $researcher->setAdmissionYear($admissionYear);
            if ($leaveYear > 0) $researcher->setLeaveYear($leaveYear);

            $count++;
            if ($count % 50 === 0) {
                $this->em->flush();
            }
        }

        $this->em->flush();
        return $count;
    }

    /**
     * Imports Qualis and production metadata from "Producao cientifica-tecnologica-cultura.xlsx"
     */
    public function importProductionQualis(string $filePath): int
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $count = 0;
        $conn = $this->em->getConnection();

        $rowIterator = $sheet->getRowIterator();
        $isFirst = true;

        foreach ($rowIterator as $row) {
            if ($isFirst) {
                $isFirst = false;
                continue;
            }

            $cellIterator = $row->getCellIterator('A', 'S');
            $cellIterator->setIterateOnlyExistingCells(false);
            $cells = [];
            foreach ($cellIterator as $cell) {
                $cells[] = $cell->getValue();
            }

            $idLattes = trim((string)($cells[6] ?? ''));
            $qualis = trim((string)($cells[13] ?? ''));
            $doi = trim((string)($cells[18] ?? ''));

            if ($idLattes === '' || $qualis === '') continue;

            if ($doi !== '') {
                $cleanDoi = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $doi);
                $conn->executeStatement(
                    'UPDATE production_items p 
                     JOIN researchers r ON p.researcher_id = r.id 
                     SET p.qualis = ? 
                     WHERE r.id_lattes = ? AND p.doi LIKE ? AND (p.qualis IS NULL OR p.qualis = \'\')',
                    [$qualis, $idLattes, '%' . $cleanDoi . '%']
                );
                $count++;
            }
        }

        return $count;
    }
}
