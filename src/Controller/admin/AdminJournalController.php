<?php

namespace App\Controller\admin;

use App\Entity\QualisJournal;
use App\Entity\JournalVariation;
use App\Repository\QualisJournalRepository;
use App\Service\Thesaurus\EntityMergeService;
use App\Service\Thesaurus\StringNormalizer;
use App\Service\Thesaurus\ThesaurusFileService;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/journals')]
class AdminJournalController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QualisJournalRepository $journalRepo,
        private readonly ThesaurusFileService $fileService,
        private readonly EntityMergeService $mergeService,
        private readonly \App\Service\Thesaurus\JournalDatabaseImporterService $databaseImporter,
        private readonly \App\Service\Thesaurus\JournalDatabaseExporterService $databaseExporter
    ) {}

    #[Route('/', name: 'app_admin_journal_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = trim((string)$request->query->get('search', ''));
        $qualisFilter = trim((string)$request->query->get('qualis', 'all'));
        $databaseFilter = trim((string)$request->query->get('database', 'all'));
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $qb = $this->em->createQueryBuilder()
            ->select('j')
            ->from(QualisJournal::class, 'j')
            ->leftJoin('j.academicDatabases', 'db')
            ->addSelect('db');

        if ($search !== '') {
            $norm = StringNormalizer::normalizeString($search, true);
            $qb->leftJoin('j.variations', 'v')
               ->andWhere('j.title LIKE :search OR j.issn LIKE :search OR j.issnImp LIKE :search OR j.issnE LIKE :search OR j.issnL LIKE :search OR j.qualis LIKE :search OR v.variationName LIKE :search OR v.normalizedName LIKE :norm')
               ->setParameter('search', '%' . $search . '%')
               ->setParameter('norm', '%' . $norm . '%')
               ->distinct();
        }

        if ($qualisFilter !== 'all' && $qualisFilter !== '') {
            if ($qualisFilter === 'EMPTY') {
                $qb->andWhere('j.qualis IS NULL OR j.qualis = \'\'');
            } else {
                $qb->andWhere('j.qualis = :qf')
                   ->setParameter('qf', $qualisFilter);
            }
        }

        if ($databaseFilter !== 'all' && $databaseFilter !== '') {
            $qb->andWhere('db.acronym = :dbFilter')
               ->setParameter('dbFilter', $databaseFilter);
        }

        $countQb = clone $qb;
        $countQb->select('COUNT(DISTINCT j.id)');
        $totalItems = (int)$countQb->getQuery()->getSingleScalarResult();

        $journals = $qb->orderBy('j.title', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = max(1, (int)ceil($totalItems / $limit));

        // Qualis Summary stats
        $statsRows = $this->em->getConnection()->executeQuery('
            SELECT COALESCE(NULLIF(qualis, ""), "N/A") as grade, COUNT(*) as count 
            FROM qualis_journals 
            GROUP BY grade 
            ORDER BY count DESC
        ')->fetchAllAssociative();

        $allDatabases = $this->em->getRepository(\App\Entity\AcademicDatabase::class)->findBy([], ['name' => 'ASC']);

        return $this->render('admin/journal/index.html.twig', [
            'journals' => $journals,
            'search' => $search,
            'qualisFilter' => $qualisFilter,
            'databaseFilter' => $databaseFilter,
            'databases' => $allDatabases,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'stats' => $statsRows,
        ]);
    }

    #[Route('/new', name: 'app_admin_journal_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $journal = new QualisJournal();
        $allDatabases = $this->em->getRepository(\App\Entity\AcademicDatabase::class)->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            $title = trim((string)$request->request->get('title'));
            $issnImp = trim((string)$request->request->get('issn_imp')) ?: null;
            $issnE = trim((string)$request->request->get('issn_e')) ?: null;
            $issnL = trim((string)$request->request->get('issn_l')) ?: null;
            $qualis = trim((string)$request->request->get('qualis')) ?: null;
            $area = trim((string)$request->request->get('area')) ?: null;

            if ($title !== '') {
                $journal->setTitle($title);
                $journal->setIssnImp($issnImp);
                $journal->setIssnE($issnE);
                $journal->setIssnL($issnL);
                $journal->setQualis($qualis);
                $journal->setArea($area);

                // Sync academic databases
                $selectedDbIds = $request->request->all()['academic_databases'] ?? [];
                foreach ($allDatabases as $db) {
                    if (in_array((string)$db->getId(), $selectedDbIds, true)) {
                        $journal->addAcademicDatabase($db);
                    }
                }

                // Initial variations textarea
                $variationsText = trim((string)$request->request->get('variations', ''));
                if ($variationsText !== '') {
                    $lines = array_filter(array_map('trim', explode("\n", $variationsText)));
                    foreach ($lines as $line) {
                        $norm = StringNormalizer::normalizeString($line, true);
                        if ($norm === '') continue;
                        $var = new JournalVariation();
                        $var->setJournal($journal);
                        $var->setVariationName($line);
                        $var->setNormalizedName($norm);
                        $journal->addVariation($var);
                    }
                }

                $this->em->persist($journal);
                $this->em->flush();

                $this->addFlash('success', "Periódico \"{$title}\" cadastrado com sucesso.");
                return $this->redirectToRoute('app_admin_journal_index');
            }
        }

        return $this->render('admin/journal/form.html.twig', [
            'journal' => $journal,
            'databases' => $allDatabases,
            'isNew' => true,
            'variationsText' => '',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_journal_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, QualisJournal $journal): Response
    {
        $allDatabases = $this->em->getRepository(\App\Entity\AcademicDatabase::class)->findBy([], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            $title = trim((string)$request->request->get('title'));
            $issnImp = trim((string)$request->request->get('issn_imp')) ?: null;
            $issnE = trim((string)$request->request->get('issn_e')) ?: null;
            $issnL = trim((string)$request->request->get('issn_l')) ?: null;
            $qualis = trim((string)$request->request->get('qualis')) ?: null;
            $area = trim((string)$request->request->get('area')) ?: null;

            if ($title !== '') {
                $journal->setTitle($title);
                $journal->setIssnImp($issnImp);
                $journal->setIssnE($issnE);
                $journal->setIssnL($issnL);
                $journal->setQualis($qualis);
                $journal->setArea($area);

                // Sync academic databases
                $selectedDbIds = $request->request->all()['academic_databases'] ?? [];
                foreach ($allDatabases as $db) {
                    if (in_array((string)$db->getId(), $selectedDbIds, true)) {
                        $journal->addAcademicDatabase($db);
                    } else {
                        $journal->removeAcademicDatabase($db);
                    }
                }

                // Sync variations text
                $variationsText = trim((string)$request->request->get('variations', ''));
                $lines = array_filter(array_map('trim', explode("\n", $variationsText)));
                $submitted = [];
                foreach ($lines as $line) {
                    $norm = StringNormalizer::normalizeString($line, true);
                    if ($norm !== '') {
                        $submitted[$norm] = $line;
                    }
                }

                // Remove unsubmitted variations
                foreach ($journal->getVariations() as $existingVar) {
                    if (!isset($submitted[$existingVar->getNormalizedName()])) {
                        $journal->removeVariation($existingVar);
                    } else {
                        unset($submitted[$existingVar->getNormalizedName()]);
                    }
                }

                // Add newly added variations
                foreach ($submitted as $norm => $orig) {
                    $var = new JournalVariation();
                    $var->setJournal($journal);
                    $var->setVariationName($orig);
                    $var->setNormalizedName($norm);
                    $journal->addVariation($var);
                }

                // Single new variation shortcut input
                $newVariation = trim((string)$request->request->get('new_variation', ''));
                if ($newVariation !== '') {
                    $normNew = StringNormalizer::normalizeString($newVariation, true);
                    if ($normNew !== '') {
                        $exists = false;
                        foreach ($journal->getVariations() as $v) {
                            if ($v->getNormalizedName() === $normNew) {
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                            $var = new JournalVariation();
                            $var->setJournal($journal);
                            $var->setVariationName($newVariation);
                            $var->setNormalizedName($normNew);
                            $journal->addVariation($var);
                        }
                    }
                }

                $this->em->flush();

                $this->addFlash('success', "Periódico \"{$journal->getTitle()}\" atualizado com sucesso.");
                return $this->redirectToRoute('app_admin_journal_index');
            }
        }

        $lines = [];
        foreach ($journal->getVariations() as $v) {
            $lines[] = $v->getVariationName();
        }

        return $this->render('admin/journal/form.html.twig', [
            'journal' => $journal,
            'databases' => $allDatabases,
            'isNew' => false,
            'variationsText' => implode("\n", $lines),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_journal_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, QualisJournal $journal): Response
    {
        if ($this->isCsrfTokenValid('delete_journal_' . $journal->getId(), $request->request->get('_token'))) {
            $title = $journal->getTitle();
            $this->em->remove($journal);
            $this->em->flush();
            $this->addFlash('success', "Periódico \"{$title}\" removido com sucesso.");
        } else {
            $this->addFlash('danger', 'Token de segurança inválido.');
        }

        return $this->redirectToRoute('app_admin_journal_index');
    }

    #[Route('/merge', name: 'app_admin_journal_merge', methods: ['POST'])]
    public function merge(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('merge_journals', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_journal_index');
        }

        $masterId = (int)$request->request->get('master_id');
        $sourceIds = $request->request->all()['source_ids'] ?? [];

        if (!$masterId || empty($sourceIds)) {
            $this->addFlash('danger', 'Selecione o periódico principal e ao menos um periódico a ser mesclado.');
            return $this->redirectToRoute('app_admin_journal_index');
        }

        try {
            $master = $this->mergeService->mergeJournals($masterId, $sourceIds);
            $this->addFlash('success', sprintf('Periódicos mesclados com sucesso em "%s". Variações unificadas.', $master->getTitle()));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro na mesclagem: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_journal_index');
    }

    #[Route('/export', name: 'app_admin_journal_export_csv', methods: ['GET'])]
    public function exportCsv(): Response
    {
        $conn = $this->em->getConnection();

        $response = new StreamedResponse(function() use ($conn) {
            $handle = fopen('php://output', 'w+');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['issn', 'title', 'qualis', 'area'], ';', '"', "\\");

            $stmt = $conn->executeQuery('SELECT issn, title, qualis, area FROM qualis_journals ORDER BY title ASC');
            while ($row = $stmt->fetchAssociative()) {
                fputcsv($handle, [
                    $row['issn'] ?? '',
                    $row['title'],
                    $row['qualis'] ?? '',
                    $row['area'] ?? ''
                ], ';', '"', "\\");
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="periodicos_qualis_cech.csv"');

        return $response;
    }

    #[Route('/export-thesaurus', name: 'app_admin_journal_export_thesaurus', methods: ['GET'])]
    public function exportThesaurus(Request $request): Response
    {
        $format = strtolower($request->query->get('format', 'the'));
        $journals = $this->journalRepo->findAll();
        $records = [];
        foreach ($journals as $j) {
            $vars = [];
            foreach ($j->getVariations() as $v) {
                $vars[] = $v->getVariationName();
            }
            $records[] = [
                'header' => $j->getTitle(),
                'preferred_name' => $j->getTitle(),
                'variations' => $vars,
                'variants' => $vars,
            ];
        }

        return $this->fileService->exportThesaurusStream($records, $format, 'tesauro_periodicos');
    }

    #[Route('/import', name: 'app_admin_journal_import_csv', methods: ['POST'])]
    public function importCsv(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_journal_csv', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_journal_index');
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('csv_file');
        if (!$file) {
            $this->addFlash('danger', 'Nenhum arquivo enviado.');
            return $this->redirectToRoute('app_admin_journal_index');
        }

        try {
            @\set_time_limit(600);
            $csv = Reader::createFromPath($file->getRealPath(), 'r');
            $csv->setHeaderOffset(0);
            $delimiter = ';';
            $firstHeader = $csv->getHeader()[0] ?? '';
            if (str_contains($firstHeader, ',')) {
                $delimiter = ',';
                $csv = Reader::createFromPath($file->getRealPath(), 'r');
                $csv->setHeaderOffset(0);
            }

            $conn = $this->em->getConnection();
            $batch = [];
            $imported = 0;

            foreach ($csv->getRecords() as $record) {
                $title = trim($record['title'] ?? $record['TITLE'] ?? $record['titulo'] ?? $record['Título'] ?? '');
                $issn = trim($record['issn'] ?? $record['ISSN'] ?? '');
                $qualis = strtoupper(trim($record['qualis'] ?? $record['QUALIS'] ?? ''));
                $area = trim($record['area'] ?? $record['AREA'] ?? $record['Área'] ?? '');

                if ($title === '') continue;

                $normIssn = $issn ? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $issn)) : null;

                $batch[] = [
                    'title' => $title,
                    'issn' => $issn ?: null,
                    'normalized_issn' => $normIssn,
                    'qualis' => $qualis ?: null,
                    'area' => $area ?: null,
                ];

                if (count($batch) >= 500) {
                    $this->batchInsertJournals($conn, $batch);
                    $imported += count($batch);
                    $batch = [];
                }
            }

            if (count($batch) > 0) {
                $this->batchInsertJournals($conn, $batch);
                $imported += count($batch);
            }

            $this->addFlash('success', "Importação concluída! {$imported} periódicos processados.");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro na importação: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_journal_index');
    }

    #[Route('/import-thesaurus', name: 'app_admin_journal_import_thesaurus', methods: ['POST'])]
    public function importThesaurus(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_journal_thesaurus', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_journal_index');
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('thesaurus_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor envie um arquivo .the ou .csv.');
            return $this->redirectToRoute('app_admin_journal_index');
        }

        try {
            @\set_time_limit(600);
            $ext = strtolower($file->getClientOriginalExtension());
            $entries = $this->fileService->parseFile($file->getRealPath(), $ext);

            $journalsMap = [];
            foreach ($this->journalRepo->findAll() as $j) {
                $journalsMap[StringNormalizer::normalizeString($j->getTitle(), true)] = $j;
            }

            $addedVars = 0;
            $newJournals = 0;

            foreach ($entries as $entry) {
                $headerName = trim($entry['header'] ?? '');
                if ($headerName === '') continue;

                $normHeader = StringNormalizer::normalizeString($headerName, true);
                $journal = $journalsMap[$normHeader] ?? null;

                if (!$journal) {
                    $journal = new QualisJournal();
                    $journal->setTitle(mb_convert_case($headerName, MB_CASE_TITLE, 'UTF-8'));
                    $this->em->persist($journal);
                    $journalsMap[$normHeader] = $journal;
                    $newJournals++;
                }

                $existingVars = [];
                foreach ($journal->getVariations() as $v) {
                    $existingVars[$v->getNormalizedName()] = true;
                }

                foreach ($entry['variations'] as $varName) {
                    $varName = trim($varName);
                    $normVar = StringNormalizer::normalizeString($varName, true);
                    if ($normVar === '' || $normVar === $normHeader) continue;

                    if (!isset($existingVars[$normVar])) {
                        $var = new JournalVariation();
                        $var->setJournal($journal);
                        $var->setVariationName($varName);
                        $var->setNormalizedName($normVar);
                        $journal->addVariation($var);
                        $existingVars[$normVar] = true;
                        $addedVars++;
                    }
                }
            }

            $this->em->flush();
            $this->addFlash('success', "Importação do Tesauro concluída! Novos Periódicos: {$newJournals}, Novas Variações: {$addedVars}.");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro na importação de tesauro: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_journal_index');
    }

    #[Route('/import-database', name: 'app_admin_journal_import_database', methods: ['POST'])]
    public function importDatabase(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('import_journal_database', (string)$request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_admin_journal_index');
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('database_file');
        if (!$file) {
            $this->addFlash('danger', 'Por favor envie um arquivo de lista de periódicos (.csv ou .xlsx).');
            return $this->redirectToRoute('app_admin_journal_index');
        }

        $baseAcronym = trim((string)$request->request->get('base_acronym', ''));
        $targetDb = $baseAcronym !== '' ? $baseAcronym : null;

        try {
            @ini_set('memory_limit', '1024M');
            $clientExt = strtolower($file->getClientOriginalExtension());
            $tempPath = sys_get_temp_dir() . '/import_db_' . uniqid() . '.' . ($clientExt ?: 'csv');
            $file->move(sys_get_temp_dir(), basename($tempPath));

            $result = $this->databaseImporter->import($tempPath, $targetDb);
            @unlink($tempPath);

            if ($result['success']) {
                $this->addFlash('success', sprintf(
                    'Importação da base %s concluída! %d registros lidos (%d novos inseridos, %d atualizados, %d vínculos criados).',
                    $result['databaseName'] ?? $result['database'],
                    $result['totalRead'],
                    $result['inserted'],
                    $result['updated'],
                    $result['linksCreated']
                ));
            } else {
                $err = implode(' ', $result['errors']);
                $this->addFlash('danger', 'Erro na importação: ' . $err);
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erro durante o processamento do arquivo: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_journal_index');
    }

    #[Route('/export-full', name: 'app_admin_journal_export_full', methods: ['GET'])]
    public function exportFull(Request $request): Response
    {
        $format = strtolower((string)$request->query->get('format', 'csv'));
        $qualis = $request->query->get('qualis');
        $database = $request->query->get('database');

        if (!in_array($format, ['csv', 'json'], true)) {
            $format = 'csv';
        }

        $res = $this->databaseExporter->export($qualis, $database, $format);

        $filename = sprintf('catalogo_periodicos_thesaurus_%s.%s', date('Ymd_His'), $format);
        $contentType = $format === 'json' ? 'application/json; charset=utf-8' : 'text/csv; charset=utf-8';

        $response = new Response($res['content']);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    private function batchInsertJournals(\Doctrine\DBAL\Connection $conn, array $batch): void
    {
        $values = [];
        $params = [];
        foreach ($batch as $row) {
            $values[] = '(?, ?, ?, ?, ?)';
            $params[] = $row['title'];
            $params[] = $row['issn'];
            $params[] = $row['normalized_issn'];
            $params[] = $row['qualis'];
            $params[] = $row['area'];
        }

        $sql = 'INSERT INTO qualis_journals (title, issn, normalized_issn, qualis, area) VALUES ' . implode(', ', $values);
        $conn->executeStatement($sql, $params);
    }
}
