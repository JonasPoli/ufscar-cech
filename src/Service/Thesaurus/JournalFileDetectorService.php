<?php

namespace App\Service\Thesaurus;

use App\Entity\AcademicDatabase;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;

class JournalFileDetectorService
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    /**
     * Detects which AcademicDatabase matches the given file structure.
     *
     * @param string $filePath Absolute path to the file (.csv, .xlsx, .txt)
     * @return array{database: ?AcademicDatabase, acronym: ?string, detectedName: ?string, confidence: float, headers: array<string>}
     */
    public function detect(string $filePath): array
    {
        @ini_set('memory_limit', '1024M');

        if (!file_exists($filePath)) {
            return [
                'database' => null,
                'acronym' => null,
                'detectedName' => null,
                'confidence' => 0.0,
                'headers' => [],
            ];
        }

        // If directory, inspect first valid file
        if (is_dir($filePath)) {
            $subFiles = glob($filePath . '/*.{json,csv,xlsx,xls,txt}', GLOB_BRACE);
            if (!empty($subFiles)) {
                $filePath = $subFiles[0];
            } else {
                $allSub = glob($filePath . '/*');
                if (!empty($allSub) && is_file($allSub[0])) {
                    $filePath = $allSub[0];
                }
            }
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $headers = [];
        $sheetNames = [];

        if ($ext === 'xlsx' || $ext === 'xls') {
            try {
                $reader = IOFactory::createReaderForFile($filePath);
                $reader->setReadDataOnly(true);
                $reader->setReadEmptyCells(false);

                if (method_exists($reader, 'listWorksheetNames')) {
                    $sheetNames = $reader->listWorksheetNames($filePath);
                }

                // Load only the first sheet to check headers
                if (!empty($sheetNames)) {
                    $targetSheet = $sheetNames[0];
                    foreach ($sheetNames as $sn) {
                        if (str_contains(strtolower($sn), 'sources') || str_contains(strtolower($sn), 'journal') || str_contains(strtolower($sn), 'titles')) {
                            $targetSheet = $sn;
                            break;
                        }
                    }
                    $reader->setLoadSheetsOnly([$targetSheet]);
                }
                
                $spreadsheet = $reader->load($filePath);
                $sheet = $spreadsheet->getActiveSheet();
                $highestColumn = $sheet->getHighestDataColumn();
                $range = 'A1:' . $highestColumn . '1';
                $headerRow = $sheet->rangeToArray($range, null, true, true, false)[0] ?? [];
                $headers = array_filter(array_map('strval', $headerRow), fn($h) => trim($h) !== '');
            } catch (\Throwable $e) {
                $headers = [];
            }
        } elseif ($ext === 'csv') {
            try {
                $csv = Reader::createFromPath($filePath, 'r');
                $firstLine = '';
                $fp = @fopen($filePath, 'r');
                if ($fp) {
                    $firstLine = fgets($fp) ?: '';
                    fclose($fp);
                }
                if (str_contains($firstLine, ';') && substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
                    $csv->setDelimiter(';');
                } elseif (str_contains($firstLine, "\t")) {
                    $csv->setDelimiter("\t");
                }
                $csv->setHeaderOffset(0);
                $headers = $csv->getHeader();
            } catch (\Throwable $e) {
                // Try fallback reading first line
                $fp = fopen($filePath, 'r');
                if ($fp) {
                    $line = fgets($fp);
                    fclose($fp);
                    if ($line) {
                        $delim = (str_contains($line, ';') && substr_count($line, ';') > substr_count($line, ',')) ? ';' : ',';
                        $headers = str_getcsv($line, $delim);
                    }
                }
            }
        } elseif ($ext === 'txt') {
            // Read sample text lines
            $fp = @fopen($filePath, 'r');
            if ($fp) {
                $sample = '';
                for ($i = 0; $i < 40 && !feof($fp); $i++) {
                    $sample .= fgets($fp);
                }
                fclose($fp);

                if (str_contains($sample, 'JournalTitle:') || str_contains($sample, 'MedAbbr:') || str_contains($sample, 'NlmId:')) {
                    $headers = ['jrid', 'journaltitle', 'medabbr', 'isoabbr', 'issnprint', 'issnonline', 'nlmid'];
                }
            }
        } elseif ($ext === 'json') {
            // Read sample JSON
            $content = @file_get_contents($filePath);
            if ($content) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $items = $data['results'] ?? ($data['items'] ?? ($data['data'] ?? (isset($data[0]) ? $data : [$data])));
                    if (isset($items[0]) && is_array($items[0])) {
                        $headers = array_keys($items[0]);
                    }
                }
            }
        }

        $headersNorm = array_map(fn($h) => strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', (string)$h))), $headers);
        $sheetNamesNorm = array_map(fn($s) => strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', (string)$s))), $sheetNames);

        // 1. Specific checks based on known signatures
        
        // Scopus signatures
        if (
            in_array('sourcerecordid', $headersNorm, true) ||
            in_array('asjcclassificationcodes', $headersNorm, true) ||
            in_array('titlesdiscontinuedbyscopus', $headersNorm, true) ||
            array_filter($sheetNamesNorm, fn($s) => str_contains($s, 'scopussources'))
        ) {
            $db = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => 'scopus']);
            return [
                'database' => $db,
                'acronym' => 'scopus',
                'detectedName' => 'Scopus (Elsevier)',
                'confidence' => 0.99,
                'headers' => $headers,
            ];
        }

        // Web of Science signatures (including JCR)
        if (
            in_array('webofsciencecategories', $headersNorm, true) ||
            in_array('clarivate', $headersNorm, true) ||
            (in_array('scie', $headersNorm, true) && in_array('ssci', $headersNorm, true)) ||
            (in_array('journaltitle', $headersNorm, true) && in_array('eissn', $headersNorm, true) && in_array('publishername', $headersNorm, true))
        ) {
            $db = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => 'wos']);
            return [
                'database' => $db,
                'acronym' => 'wos',
                'detectedName' => 'Web of Science (Clarivate)',
                'confidence' => 0.95,
                'headers' => $headers,
            ];
        }

        // DOAJ signatures
        if (
            in_array('doajseal', $headersNorm, true) ||
            in_array('journalissnprintversion', $headersNorm, true) ||
            in_array('journalurlindoaj', $headersNorm, true) ||
            in_array('journalissnprintprint', $headersNorm, true) ||
            in_array('journallicense', $headersNorm, true)
        ) {
            $db = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => 'doaj']);
            return [
                'database' => $db,
                'acronym' => 'doaj',
                'detectedName' => 'DOAJ',
                'confidence' => 0.95,
                'headers' => $headers,
            ];
        }

        // PubMed signatures
        if (
            in_array('pmid', $headersNorm, true) ||
            in_array('jrid', $headersNorm, true) ||
            in_array('medabbr', $headersNorm, true) ||
            in_array('journaltitle', $headersNorm, true) && in_array('nlmid', $headersNorm, true)
        ) {
            $db = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => 'pubmed']);
            return [
                'database' => $db,
                'acronym' => 'pubmed',
                'detectedName' => 'PubMed',
                'confidence' => 0.95,
                'headers' => $headers,
            ];
        }

        // SciELO signatures
        if (
            in_array('scielonetwork', $headersNorm, true) ||
            in_array('scielojournal', $headersNorm, true) ||
            ((in_array('lastvolume', $headersNorm, true) || in_array('lastnumber', $headersNorm, true)) && in_array('isactive', $headersNorm, true))
        ) {
            $db = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => 'scielo']);
            return [
                'database' => $db,
                'acronym' => 'scielo',
                'detectedName' => 'SciELO',
                'confidence' => 0.95,
                'headers' => $headers,
            ];
        }

        // Latindex signatures (Indice_indice.csv, folio_u, tit_propio, issn_e, issn_l, issn_imp)
        if (
            in_array('foliou', $headersNorm, true) ||
            in_array('idcatalogo', $headersNorm, true) ||
            (in_array('titpropio', $headersNorm, true) && in_array('issne', $headersNorm, true)) ||
            (in_array('issne', $headersNorm, true) && in_array('issnl', $headersNorm, true) && in_array('issnimp', $headersNorm, true))
        ) {
            $db = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => 'latindex']);
            return [
                'database' => $db,
                'acronym' => 'latindex',
                'detectedName' => 'Latindex (Catálogo 2.0)',
                'confidence' => 0.98,
                'headers' => $headers,
            ];
        }

        // OpenAlex signatures
        if (
            in_array('issnl', $headersNorm, true) &&
            in_array('displayname', $headersNorm, true)
        ) {
            $db = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => 'openalex']);
            return [
                'database' => $db,
                'acronym' => 'openalex',
                'detectedName' => 'OpenAlex',
                'confidence' => 0.90,
                'headers' => $headers,
            ];
        }

        // Fallback: Check signature columns from DB
        $allDatabases = $this->em->getRepository(AcademicDatabase::class)->findAll();
        foreach ($allDatabases as $ad) {
            $sigCols = $ad->getSignatureColumns();
            if (!empty($sigCols)) {
                $matches = 0;
                foreach ($sigCols as $sc) {
                    $normSc = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', (string)$sc)));
                    if (in_array($normSc, $headersNorm, true)) {
                        $matches++;
                    }
                }
                if ($matches > 0 && $matches >= count($sigCols)) {
                    return [
                        'database' => $ad,
                        'acronym' => $ad->getAcronym(),
                        'detectedName' => $ad->getName(),
                        'confidence' => 0.80,
                        'headers' => $headers,
                    ];
                }
            }
        }

        return [
            'database' => null,
            'acronym' => null,
            'detectedName' => null,
            'confidence' => 0.0,
            'headers' => $headers,
        ];
    }
}
