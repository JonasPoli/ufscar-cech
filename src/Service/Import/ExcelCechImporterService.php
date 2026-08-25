<?php

namespace App\Service\Import;

use App\Entity\Researcher;
use App\Entity\ProductionItem;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelCechImporterService
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    /**
     * Imports faculty metadata from "Info docentes do CECH.xlsx"
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
            'LE' => 'Departamento de Letras',
            'FIL' => 'Departamento de Filosofia',
            'CS' => 'Departamento de Ciências Sociais',
            'DPsi' => 'Departamento de Psicologia',
            'DEC' => 'Departamento de Educação e Comunicação',
            'DME' => 'Departamento de Metodologia de Ensino',
            'DTE' => 'Departamento de Teoria e Prática da Educação',
            'DMTE' => 'Departamento de Metodologia e Teoria da Educação',
            'DEF' => 'Departamento de Educação Física',
            'DAD' => 'Departamento de Administração',
            'DART' => 'Departamento de Artes',
            'DMUS' => 'Departamento de Música',
            'DCI' => 'Departamento de Ciência da Informação',
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
