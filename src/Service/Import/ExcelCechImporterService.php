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
 *
 * Só complementa os docentes (departamento, ORCID, anos de entrada e saída). O Qualis de um artigo vem sempre de
 * `qualis_journals` (indexação de periódicos, `app:index:journals`): a planilha de produção, que trazia na coluna N os
 * textos "Qualis" e "sem qualis", gravava esse lixo em `production_items.qualis` e não é mais lida.
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
}
