<?php

namespace App\Controller\admin;

use App\Entity\JournalArea;
use App\Entity\JournalAreaEvidence;
use App\Entity\QualisJournal;
use App\Entity\JournalVariation;
use App\Repository\JournalAreaEvidenceRepository;
use App\Repository\JournalAreaRepository;
use App\Repository\QualisJournalRepository;
use App\Service\Journal\CapesTaxonomy;
use App\Service\Journal\JournalAreaClassifier;
use App\Service\Journal\JournalVariantConflictException;
use App\Service\Journal\JournalVariantGuard;
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
    /** Evidências de área mostradas no formulário (a lista é só para conferência). */
    private const EVIDENCE_LIMIT = 300;

    /** Rótulos em português da confiança da classificação (`journal_areas.confidence`). */
    private const CONFIDENCE_LABELS = ['high' => 'alta', 'medium' => 'média', 'low' => 'baixa', 'manual' => 'manual'];

    /** Tamanho das colunas `normalized_name`/`variation_name` (a trava corta a chave nesse tamanho). */
    private const MAX_VARIATION_KEY = 500;

    /** Quantas variantes/periódicos novos o importador de tesauro acumula antes de gravar (mantém a trava rápida). */
    private const THESAURUS_FLUSH_EVERY = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QualisJournalRepository $journalRepo,
        private readonly ThesaurusFileService $fileService,
        private readonly EntityMergeService $mergeService,
        private readonly \App\Service\Thesaurus\JournalDatabaseImporterService $databaseImporter,
        private readonly \App\Service\Thesaurus\JournalDatabaseExporterService $databaseExporter,
        private readonly JournalVariantGuard $variantGuard,
        private readonly JournalAreaRepository $areaRepo,
        private readonly JournalAreaEvidenceRepository $evidenceRepo,
        private readonly JournalAreaClassifier $areaClassifier,
        private readonly CapesTaxonomy $taxonomy
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

        // Área principal CAPES de cada periódico da página (uma consulta só)
        $primaryAreas = $this->areaRepo->primaryByJournal(array_map(static fn (QualisJournal $j): int => (int) $j->getId(), $journals));

        return $this->render('admin/journal/index.html.twig', [
            'journals' => $journals,
            'primaryAreas' => $primaryAreas,
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
        $values = $this->formValues($journal, null);
        $variationsText = '';

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('journal_new', (string)$request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido. Tente novamente.');
                return $this->redirectToRoute('app_admin_journal_new');
            }

            $values = $this->readForm($request, $values);
            $variationsText = (string)$request->request->get('variations', '');
            $candidates = $this->parseVariations($variationsText, (string)$request->request->get('new_variation', ''));

            // Valida tudo antes de tocar na entidade: com erro, nada é gravado e o formulário volta com o que foi digitado
            $errors = $this->validateForm($values, $candidates, null, false);

            if ($errors === []) {
                $conn = $this->em->getConnection();
                $conn->beginTransaction();
                try {
                    $this->applyFields($journal, $values, false);
                    $this->syncAcademicDatabases($journal, $values['academic_databases'], $allDatabases);
                    $this->em->persist($journal);
                    $this->em->flush(); // o periódico passa a ter id: a trava de variantes compara famílias

                    $notes = $this->syncVariations($journal, $candidates);
                    $this->em->flush();
                    $notes = array_merge($notes, $this->saveAreasAndDisplayTitle($journal, $values, null));
                    $conn->commit();

                    $this->addFlash('success', "Periódico \"{$journal->getTitle()}\" cadastrado com sucesso.");
                    foreach ($notes as $note) {
                        $this->addFlash('info', $note);
                    }

                    return $this->redirectToRoute('app_admin_journal_index');
                } catch (JournalVariantConflictException $e) {
                    // Só acontece se outro cadastro tomou o nome entre a validação e a gravação: desfaz tudo
                    if ($conn->isTransactionActive()) {
                        $conn->rollBack();
                    }
                    $this->em->clear();
                    $journal = new QualisJournal();
                    $errors[] = $e->getMessage();
                } catch (\Throwable $e) {
                    if ($conn->isTransactionActive()) {
                        $conn->rollBack();
                    }

                    throw $e;
                }
            }

            foreach ($errors as $error) {
                $this->addFlash('danger', $error);
            }
        }

        return $this->renderForm($journal, true, $values, $variationsText, null, $allDatabases);
    }

    #[Route('/{id}/edit', name: 'app_admin_journal_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, QualisJournal $journal): Response
    {
        $allDatabases = $this->em->getRepository(\App\Entity\AcademicDatabase::class)->findBy([], ['name' => 'ASC']);
        $context = $this->areaContext($journal);
        $values = $this->formValues($journal, $context['areaState']);
        $variationsText = implode("\n", array_map(
            static fn (JournalVariation $v): string => $v->getVariationName(),
            $journal->getVariations()->toArray()
        ));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('journal_edit', (string)$request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF inválido. Tente novamente.');
                return $this->redirectToRoute('app_admin_journal_edit', ['id' => $journal->getId()]);
            }

            $values = $this->readForm($request, $values);
            $variationsText = (string)$request->request->get('variations', '');
            $candidates = $this->parseVariations($variationsText, (string)$request->request->get('new_variation', ''));

            // Valida tudo antes de tocar na entidade: com erro (título vazio, variação que já é de outro periódico, área
            // inválida) nada é gravado e o formulário volta com o que foi digitado
            $errors = $this->validateForm($values, $candidates, $journal, $context['isMember']);

            if ($errors === []) {
                $conn = $this->em->getConnection();
                $conn->beginTransaction();
                try {
                    $this->applyFields($journal, $values, $context['familyHasAreas']);
                    $this->syncAcademicDatabases($journal, $values['academic_databases'], $allDatabases);
                    $notes = $this->syncVariations($journal, $candidates);
                    $this->em->flush();
                    $notes = array_merge($notes, $this->saveAreasAndDisplayTitle($journal, $values, $context));
                    $conn->commit();

                    $this->addFlash('success', "Periódico \"{$journal->getTitle()}\" atualizado com sucesso.");
                    foreach ($notes as $note) {
                        $this->addFlash('info', $note);
                    }

                    return $this->redirectToRoute('app_admin_journal_index');
                } catch (JournalVariantConflictException $e) {
                    // Só acontece se outro cadastro tomou o nome entre a validação e a gravação: desfaz tudo
                    if ($conn->isTransactionActive()) {
                        $conn->rollBack();
                    }
                    $errors[] = $e->getMessage();
                } catch (\Throwable $e) {
                    if ($conn->isTransactionActive()) {
                        $conn->rollBack();
                    }

                    throw $e;
                }
            }

            foreach ($errors as $error) {
                $this->addFlash('danger', $error);
            }
        }

        return $this->renderForm($journal, false, $values, $variationsText, $context, $allDatabases);
    }

    /**
     * "Voltar ao automático": apaga a curadoria manual de áreas da família, destrava e roda a classificação automática
     * só dessa família (A6.8).
     */
    #[Route('/{id}/areas/unlock', name: 'app_admin_journal_areas_unlock', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unlockAreas(Request $request, QualisJournal $journal): Response
    {
        $id = (int)$journal->getId();

        if (!$this->isCsrfTokenValid('unlock_areas_' . $id, (string)$request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');
            return $this->redirectToRoute('app_admin_journal_edit', ['id' => $id]);
        }

        // As áreas são da canônica da família; pedir pela linha membro vale pela canônica
        $canonical = $journal->getCanonical() ?? $journal;
        $canonicalId = (int)$canonical->getId();
        $title = $canonical->getTitle();

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $removed = $this->areaRepo->clearManual($canonical);
            $decision = $this->areaClassifier->classifyFamily($canonicalId, 'unlock-' . date('Ymd-His'));
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->addFlash('danger', 'Não foi possível voltar ao automático: ' . $e->getMessage());

            return $this->redirectToRoute('app_admin_journal_edit', ['id' => $id]);
        }

        // O classificador grava por SQL: o que estava carregado ficou desatualizado
        $this->em->clear();

        if ($decision === null || ($decision['primary'] ?? null) === null) {
            $this->addFlash('success', sprintf(
                'Curadoria manual de «%s» removida (%d registros de área). Sem evidência suficiente para classificar: a área voltou ao valor original.',
                $title,
                $removed
            ));
        } else {
            $slug = (string)$decision['primary']['slug'];
            $this->addFlash('success', sprintf(
                'Curadoria manual de «%s» removida (%d registros de área). Classificação automática: %s (confiança %s).',
                $title,
                $removed,
                $this->taxonomy->bySlug($slug)['name'] ?? $slug,
                self::CONFIDENCE_LABELS[$decision['confidence']] ?? $decision['confidence']
            ));
        }

        return $this->redirectToRoute('app_admin_journal_edit', ['id' => $id]);
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
        $areasByJournal = $this->areaRepo->namesByJournal();

        $response = new StreamedResponse(function() use ($conn, $areasByJournal) {
            $handle = fopen('php://output', 'w+');
            fwrite($handle, "\xEF\xBB\xBF");
            // As colunas de áreas CAPES vêm no fim: quem já consome o arquivo continua lendo as quatro primeiras
            fputcsv($handle, ['issn', 'title', 'qualis', 'area', 'area_capes_primary', 'areas_capes'], ';', '"', "\\");

            $stmt = $conn->executeQuery('SELECT id, issn, title, qualis, area FROM qualis_journals ORDER BY title ASC');
            while ($row = $stmt->fetchAssociative()) {
                $capes = $areasByJournal[(int)$row['id']] ?? ['primary' => null, 'all' => []];
                fputcsv($handle, [
                    $row['issn'] ?? '',
                    $row['title'],
                    $row['qualis'] ?? '',
                    $row['area'] ?? '',
                    $capes['primary'] ?? '',
                    implode(';', $capes['all']),
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
            if (\function_exists('set_time_limit')) {
                @\set_time_limit(600);
            }
            $csv = Reader::createFromPath($file->getRealPath(), 'r');
            $csv->setDelimiter($this->detectCsvDelimiter((string)$file->getRealPath()));
            $csv->setHeaderOffset(0);

            $conn = $this->em->getConnection();
            $batch = [];
            $imported = 0;
            $withAreas = 0;
            $unknownAreas = [];

            foreach ($csv->getRecords() as $record) {
                $title = trim($record['title'] ?? $record['TITLE'] ?? $record['titulo'] ?? $record['Título'] ?? '');
                $issn = trim($record['issn'] ?? $record['ISSN'] ?? '');
                $qualis = strtoupper(trim($record['qualis'] ?? $record['QUALIS'] ?? ''));
                $area = trim($record['area'] ?? $record['AREA'] ?? $record['Área'] ?? '');
                $areasCapes = trim($record['areas_capes'] ?? $record['AREAS_CAPES'] ?? '');

                if ($title === '') continue;

                $normIssn = $issn ? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $issn)) : null;

                $row = [
                    'title' => $title,
                    'issn' => $issn ?: null,
                    'normalized_issn' => $normIssn,
                    'qualis' => $qualis ?: null,
                    'area' => $area ?: null,
                ];

                // Coluna opcional `areas_capes` (slugs ou nomes separados por `;`, a primeira é a principal): vira
                // curadoria manual travada. Valores que não são áreas CAPES são ignorados e relatados no fim.
                if ($areasCapes !== '') {
                    $resolved = $this->areaRepo->resolveSlugs($areasCapes);
                    foreach ($resolved['unknown'] as $unknown) {
                        $unknownAreas[$unknown] = true;
                    }
                    if ($resolved['slugs'] !== []) {
                        $this->insertJournalWithAreas($conn, $row, $resolved['slugs']);
                        $imported++;
                        $withAreas++;
                        continue;
                    }
                }

                $batch[] = $row;

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

            $message = "Importação concluída! {$imported} periódicos processados.";
            if ($withAreas > 0) {
                $message .= " Áreas CAPES gravadas em {$withAreas} (curadoria manual, travadas).";
            }
            $this->addFlash('success', $message);
            if ($unknownAreas !== []) {
                $this->addFlash('warning', 'Valores de areas_capes que não são áreas CAPES foram ignorados: ' . implode('; ', array_slice(array_keys($unknownAreas), 0, 10)) . (count($unknownAreas) > 10 ? '…' : '') . '.');
            }
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
            if (\function_exists('set_time_limit')) {
                @\set_time_limit(600);
            }
            $ext = strtolower($file->getClientOriginalExtension());
            $entries = $this->fileService->parseFile($file->getRealPath(), $ext);

            $journalsMap = [];
            foreach ($this->journalRepo->findAll() as $j) {
                $journalsMap[StringNormalizer::normalizeString($j->getTitle(), true)] = $j;
            }

            $addedVars = 0;
            $newJournals = 0;
            $conflicts = [];
            $pending = 0;

            // Uma transação só: o arquivo entra inteiro ou não entra. O flush periódico mantém curta a lista de variantes
            // ainda não gravadas, que a trava de variantes confere a cada nome.
            $conn = $this->em->getConnection();
            $conn->beginTransaction();
            try {
                foreach ($entries as $entry) {
                    $headerName = trim($entry['header'] ?? '');
                    if ($headerName === '') continue;

                    $normHeader = StringNormalizer::normalizeString($headerName, true);
                    $journal = $journalsMap[$normHeader] ?? null;

                    if (!$journal) {
                        // O título novo não pode ser nome (título ou variante) de outro periódico
                        $conflict = $this->variantGuard->findConflict($normHeader, null);
                        if ($conflict !== null) {
                            $conflicts[] = sprintf('«%s» → #%d «%s»', $headerName, $conflict['journalId'], $conflict['title']);
                            continue;
                        }

                        $journal = new QualisJournal();
                        $journal->setTitle(mb_convert_case($headerName, MB_CASE_TITLE, 'UTF-8'));
                        $this->em->persist($journal);
                        $this->em->flush(); // com id e título gravado, a trava compara a família do periódico com a dos nomes
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

                        if (isset($existingVars[$normVar])) continue;
                        $existingVars[$normVar] = true;

                        try {
                            // null = a família já conhecia o nome (variante ou título de uma das linhas): nada a fazer
                            if ($this->variantGuard->addVariant($journal, $varName, 'alternative') !== null) {
                                $addedVars++;
                                $pending++;
                            }
                        } catch (JournalVariantConflictException $e) {
                            $other = $e->getConflict();
                            $conflicts[] = sprintf('«%s» → #%d «%s»', $varName, $other['journalId'], $other['title']);
                        }
                    }

                    if ($pending >= self::THESAURUS_FLUSH_EVERY) {
                        $this->em->flush();
                        $pending = 0;
                    }
                }

                $this->em->flush();
                $conn->commit();
            } catch (\Throwable $e) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }

                throw $e;
            }

            $this->addFlash('success', "Importação do Tesauro concluída! Novos Periódicos: {$newJournals}, Novas Variações: {$addedVars}.");
            if ($conflicts !== []) {
                $this->addFlash('warning', sprintf(
                    '%d nomes ignorados por já pertencerem a outro periódico (nome → periódico dono): %s%s.',
                    count($conflicts),
                    implode('; ', array_slice($conflicts, 0, 5)),
                    count($conflicts) > 5 ? '…' : ''
                ));
            }
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
            $values[] = '(?, ?, ?, ?, ?, ?)';
            $params[] = $row['title'];
            $params[] = mb_substr(StringNormalizer::normalizeString($row['title'], true), 0, 500, 'UTF-8') ?: null;
            $params[] = $row['issn'];
            $params[] = $row['normalized_issn'];
            $params[] = $row['qualis'];
            $params[] = $row['area'];
        }

        $sql = 'INSERT INTO qualis_journals (title, normalized_title, issn, normalized_issn, qualis, area) VALUES ' . implode(', ', $values);
        $conn->executeStatement($sql, $params);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Importação de CSV: delimitador e periódicos com áreas CAPES
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Delimitador do CSV pelo cabeçalho: o mais frequente entre `;`, `,` e tabulação (vírgula se nenhum aparece).
     * A exportação do admin usa `;`, e um arquivo assim precisa voltar pela importação.
     */
    private function detectCsvDelimiter(string $path): string
    {
        $handle = @fopen($path, 'r');
        $line = $handle !== false ? (string)fgets($handle, 65536) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;

        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($counts);
        $best = (string)array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    /**
     * Insere um periódico do CSV que traz `areas_capes` e grava as áreas como curadoria manual travada, na hora
     * (precisa do id da linha nova, por isso fica de fora do INSERT em lote).
     *
     * @param array{title: string, issn: ?string, normalized_issn: ?string, qualis: ?string, area: ?string} $row
     * @param list<string> $slugs a primeira é a principal
     */
    private function insertJournalWithAreas(\Doctrine\DBAL\Connection $conn, array $row, array $slugs): void
    {
        $this->batchInsertJournals($conn, [$row]);
        $journal = $this->em->find(QualisJournal::class, (int)$conn->lastInsertId());
        if ($journal !== null) {
            $this->areaRepo->replaceManual($journal, $slugs, $slugs[0]);
        }
        $this->em->clear();
    }

    // ------------------------------------------------------------------------------------------------------------
    // Formulário de periódico: valores, validação e gravação
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Valores do formulário como o template os usa: textos, ids das bases e slugs das áreas.
     *
     * @param array<string, mixed>|null $areaState estado das áreas da família (ver areaContext()); null num periódico novo
     *
     * @return array{title: string, issn_imp: string, issn_e: string, issn_l: string, qualis: string, area: string, display_title: string, academic_databases: list<string>, areas: list<string>, area_primary: string, areas_locked: bool}
     */
    private function formValues(QualisJournal $journal, ?array $areaState): array
    {
        $areaState ??= self::emptyAreaState();

        return [
            'title' => $journal->getTitle(),
            'issn_imp' => (string)$journal->getIssnImp(),
            'issn_e' => (string)$journal->getIssnE(),
            'issn_l' => (string)$journal->getIssnL(),
            'qualis' => (string)$journal->getQualis(),
            'area' => (string)$journal->getArea(),
            'display_title' => (string)$journal->getDisplayTitle(),
            'academic_databases' => array_values(array_map(
                static fn (\App\Entity\AcademicDatabase $db): string => (string)$db->getId(),
                $journal->getAcademicDatabases()->toArray()
            )),
            'areas' => array_column($areaState['rows'], 'slug'),
            'area_primary' => (string)$areaState['primary_slug'],
            'areas_locked' => (bool)$areaState['locked'],
        ];
    }

    /**
     * Lê o POST por cima dos valores atuais. Os campos que já existiam (título, ISSNs, Qualis, área) valem como vierem,
     * inclusive vazios; os acrescentados depois (nome de exibição, áreas CAPES) só mudam se o cliente os enviar.
     * A caixa "Travar" só trava: destravar é o botão "Voltar ao automático".
     *
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function readForm(Request $request, array $defaults): array
    {
        $input = $request->request;
        $all = $input->all();
        $values = $defaults;

        $values['title'] = trim((string)$input->get('title'));
        foreach (['issn_imp', 'issn_e', 'issn_l', 'qualis', 'area'] as $field) {
            $values[$field] = trim((string)$input->get($field));
        }
        if ($input->has('display_title')) {
            $values['display_title'] = trim((string)$input->get('display_title'));
        }
        $values['academic_databases'] = self::stringList($all['academic_databases'] ?? []);
        if (array_key_exists('areas', $all)) {
            $values['areas'] = self::stringList($all['areas']);
        }
        if ($input->has('area_primary')) {
            $values['area_primary'] = trim((string)$input->get('area_primary'));
        }
        $values['areas_locked'] = $defaults['areas_locked'] || $input->getBoolean('areas_locked');

        return $values;
    }

    /**
     * @return list<string> textos não vazios e sem repetição, na ordem recebida (o que não for texto é ignorado)
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = trim((string)$item);
            if ($item !== '') {
                $list[$item] = $item;
            }
        }

        return array_values($list);
    }

    /**
     * Variações digitadas (uma por linha do textarea, mais a variação avulsa), pela forma do tesauro. A chave tem o
     * mesmo tamanho máximo que a trava de variantes usa.
     *
     * @return array<string, string> forma do tesauro => grafia digitada, na ordem digitada
     */
    private function parseVariations(string $text, string $single = ''): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $text)), static fn (string $line): bool => $line !== '');
        if (trim($single) !== '') {
            $lines[] = trim($single);
        }

        $candidates = [];
        foreach ($lines as $line) {
            $norm = mb_substr(StringNormalizer::normalizeString($line, true), 0, self::MAX_VARIATION_KEY, 'UTF-8');
            if ($norm !== '') {
                $candidates[$norm] ??= $line;
            }
        }

        return $candidates;
    }

    /**
     * Validação sem efeitos: título, variações novas que já pertencem a outro periódico (A5.3) e áreas CAPES.
     *
     * @param array<string, mixed> $values
     * @param array<string, string> $candidates variações digitadas (parseVariations)
     * @param bool $isMember linha membro de família: as áreas são da canônica e o que vier delas é ignorado
     *
     * @return list<string> mensagens de erro (vazia = pode gravar)
     */
    private function validateForm(array $values, array $candidates, ?QualisJournal $journal, bool $isMember): array
    {
        $errors = [];

        if ($values['title'] === '') {
            $errors[] = 'Informe o título do periódico.';
        }

        $own = [];
        if ($journal !== null) {
            foreach ($journal->getVariations() as $variation) {
                $own[$variation->getNormalizedName()] = true;
            }
        }
        foreach ($candidates as $norm => $raw) {
            if (isset($own[$norm])) {
                continue;
            }
            $conflict = $this->variantGuard->findConflict($norm, $journal?->getId());
            if ($conflict !== null) {
                $errors[] = JournalVariantConflictException::message($raw, $conflict);
            }
        }

        if (!$isMember) {
            foreach ($values['areas'] as $slug) {
                if (!$this->taxonomy->isValidSlug($slug)) {
                    $errors[] = sprintf('Área CAPES desconhecida: «%s».', $slug);
                }
            }
            if ($values['area_primary'] !== '' && !$this->taxonomy->isValidSlug($values['area_primary'])) {
                $errors[] = sprintf('Área principal desconhecida: «%s».', $values['area_primary']);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function applyFields(QualisJournal $journal, array $values, bool $familyHasAreas): void
    {
        $journal->setTitle($values['title']);
        $journal->setIssnImp($values['issn_imp'] ?: null);
        $journal->setIssnE($values['issn_e'] ?: null);
        $journal->setIssnL($values['issn_l'] ?: null);
        $journal->setQualis($values['qualis'] ?: null);

        // O texto livre de `area` só vale enquanto a família não tem áreas CAPES: com áreas, o campo é do classificador e
        // da curadoria (bloco CAPES) e o valor enviado é ignorado
        if (!$familyHasAreas) {
            $area = $values['area'] !== '' ? $values['area'] : null;
            if ($area !== $journal->getArea()) {
                $journal->setArea($area);
                // `area_raw` guarda o texto original: acompanha o que foi digitado, exceto um rótulo canônico
                if ($area === null || !$this->taxonomy->isCanonicalLabel($area)) {
                    $journal->setAreaRaw($area);
                }
            }
        }
    }

    /**
     * @param list<string> $selectedIds ids das bases marcadas
     * @param iterable<\App\Entity\AcademicDatabase> $allDatabases
     */
    private function syncAcademicDatabases(QualisJournal $journal, array $selectedIds, iterable $allDatabases): void
    {
        foreach ($allDatabases as $db) {
            if (in_array((string)$db->getId(), $selectedIds, true)) {
                $journal->addAcademicDatabase($db);
            } else {
                $journal->removeAcademicDatabase($db);
            }
        }
    }

    /**
     * Sincroniza as variações do periódico com o textarea: some o que foi apagado e entra o que é novo, pela trava de
     * variantes (`variation_type = 'manual'`). A trava grava na canônica da família; numa linha membro, a variação nova
     * sai do formulário dela e aparece no da canônica, e o aviso devolvido diz isso. Não faz flush.
     *
     * @param array<string, string> $candidates variações digitadas (parseVariations)
     *
     * @return list<string> avisos para o usuário
     *
     * @throws JournalVariantConflictException se o nome passou a pertencer a outro periódico depois da validação
     */
    private function syncVariations(QualisJournal $journal, array $candidates): array
    {
        $own = [];
        foreach ($journal->getVariations() as $variation) {
            $own[$variation->getNormalizedName()] = $variation;
        }

        foreach ($own as $norm => $variation) {
            if (!isset($candidates[$norm])) {
                $journal->removeVariation($variation);
            }
        }

        $notes = [];
        foreach ($candidates as $norm => $raw) {
            if (isset($own[$norm])) {
                continue;
            }
            $variation = $this->variantGuard->addVariant($journal, $raw, 'manual');
            if ($variation !== null && $variation->getJournal() !== $journal) {
                $head = $variation->getJournal();
                $notes[] = sprintf(
                    'A variação «%s» foi gravada no periódico canônico #%d «%s», que agrupa este.',
                    $raw,
                    (int)$head?->getId(),
                    (string)$head?->getTitle()
                );
            }
        }

        return $notes;
    }

    /**
     * Grava, depois do flush das entidades, o que vai por SQL: o nome de exibição (em toda a família) e as áreas CAPES
     * (só na canônica, replicadas pelo repositório).
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $context areaContext(); null num periódico novo
     *
     * @return list<string> avisos para o usuário
     */
    private function saveAreasAndDisplayTitle(QualisJournal $journal, array $values, ?array $context): array
    {
        $notes = [];

        if ($this->applyDisplayTitle($journal, (string)$values['display_title'])) {
            $notes[] = $values['display_title'] === ''
                ? 'Nome de exibição removido: será calculado de novo a partir das fontes.'
                : sprintf('Nome de exibição definido para a família: «%s».', $values['display_title']);
        }

        if ($context !== null && $context['isMember']) {
            return $notes;
        }

        $note = $this->applyAreaSelection($journal, $values, $context['areaState'] ?? self::emptyAreaState());
        if ($note !== null) {
            $notes[] = $note;
        }

        return $notes;
    }

    /**
     * Nome de exibição manual: vale para toda a família (`display_title_source = 'manual'`, que a reconstrução de famílias
     * nunca sobrescreve); em branco, volta ao cálculo automático. Não mexe se o texto é o que já estava.
     */
    private function applyDisplayTitle(QualisJournal $journal, string $posted): bool
    {
        $posted = trim($posted);
        if ($posted === (string)$journal->getDisplayTitle()) {
            return false;
        }

        $value = $posted === '' ? null : mb_substr($posted, 0, 500, 'UTF-8');
        $familyId = $journal->getFamilyId();
        $this->em->getConnection()->executeStatement(
            'UPDATE qualis_journals SET display_title = ?, display_title_source = ? WHERE id = ? OR canonical_journal_id = ?',
            [$value, $value === null ? null : 'manual', $familyId, $familyId]
        );

        return true;
    }

    /**
     * Curadoria manual das áreas CAPES (só na canônica; a réplica nas demais linhas é do repositório).
     *
     * Com ao menos uma área marcada, grava como manual e trava quando a seleção mudou (conjunto ou principal) ou quando
     * a caixa "Travar" foi marcada agora; salvar o formulário sem mexer nas áreas não congela a classificação automática.
     * Sem nenhuma marcada, as áreas ficam como estão (limpar é o botão "Voltar ao automático"), salvo o pedido de
     * travar uma família que ainda não tem área, que só marca `areas_locked`.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $areaState estado atual (areaContext())
     *
     * @return string|null aviso para o usuário quando algo foi gravado
     */
    private function applyAreaSelection(QualisJournal $journal, array $values, array $areaState): ?string
    {
        $selected = $values['areas'];
        $current = array_column($areaState['rows'], 'slug');

        if ($selected !== []) {
            $primary = in_array($values['area_primary'], $selected, true) ? $values['area_primary'] : $selected[0];
            $sameSet = array_diff($selected, $current) === [] && array_diff($current, $selected) === [];
            $changed = !$sameSet || $primary !== $areaState['primary_slug'];

            if ($changed || ($values['areas_locked'] && !$areaState['locked'])) {
                $this->areaRepo->replaceManual($journal, $selected, $primary);

                return sprintf(
                    'Áreas CAPES gravadas (curadoria manual, travadas): %s.',
                    implode(', ', array_map(
                        fn (string $slug): string => ($this->taxonomy->bySlug($slug)['name'] ?? $slug) . ($slug === $primary ? ' (principal)' : ''),
                        array_unique(array_merge([$primary], $selected))
                    ))
                );
            }

            return null;
        }

        if ($values['areas_locked'] && !$areaState['locked'] && $current === []) {
            $this->em->getConnection()->executeStatement('UPDATE qualis_journals SET areas_locked = 1 WHERE id = ?', [$journal->getId()]);

            return 'Classificação de área travada: a classificação automática não vai alterar este periódico.';
        }

        return null;
    }

    /**
     * Situação das áreas da família do periódico, para o formulário: a canônica, as linhas de `journal_areas` dela,
     * a trava e as evidências de toda a família.
     *
     * @return array{
     *     canonical: QualisJournal, isMember: bool, familyHasAreas: bool, areaState: array<string, mixed>,
     *     evidences: list<JournalAreaEvidence>, evidenceTotal: int
     * }
     */
    private function areaContext(QualisJournal $journal): array
    {
        $head = $journal->getCanonical();
        $isMember = $head !== null && $head->getId() !== $journal->getId();
        $canonical = $isMember ? $head : $journal;
        $canonicalId = (int)$canonical->getId();

        $rows = [];
        $primarySlug = null;
        $runId = null;
        $updatedAt = null;
        $hasManual = false;
        foreach ($this->areaRepo->findOrdered($canonicalId) as $journalArea) {
            $area = $journalArea->getArea();
            if ($area === null) {
                continue;
            }

            $isManual = $journalArea->getMethod() !== JournalArea::METHOD_AUTO;
            $rows[] = [
                'slug' => $area->getSlug(),
                'name' => $area->getName(),
                'label' => $this->taxonomy->areaLabel($area->getSlug()),
                'primary' => $journalArea->isPrimary(),
                'confidence' => $journalArea->getConfidence(),
                'method' => $journalArea->getMethod(),
                'score' => $journalArea->getScoreAsFloat(),
            ];
            if ($journalArea->isPrimary() && $primarySlug === null) {
                $primarySlug = $area->getSlug();
            }
            $hasManual = $hasManual || $isManual;
            if (!$isManual && $runId === null) {
                $runId = $journalArea->getRunId();
            }
            if ($updatedAt === null || $journalArea->getUpdatedAt() > $updatedAt) {
                $updatedAt = $journalArea->getUpdatedAt();
            }
        }

        $familyRowIds = $this->areaRepo->familyRowIds($canonicalId);

        return [
            'canonical' => $canonical,
            'isMember' => $isMember,
            'familyHasAreas' => $rows !== [],
            'areaState' => [
                'rows' => $rows,
                'primary_slug' => $primarySlug,
                'locked' => $canonical->isAreasLocked(),
                'has_manual' => $hasManual,
                'run_id' => $runId,
                'updated_at' => $updatedAt,
            ],
            'evidences' => $this->evidenceRepo->findBy(['journal' => $familyRowIds], ['source' => 'ASC', 'id' => 'ASC'], self::EVIDENCE_LIMIT),
            'evidenceTotal' => $this->evidenceRepo->count(['journal' => $familyRowIds]),
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, primary_slug: ?string, locked: bool, has_manual: bool, run_id: ?string, updated_at: ?\DateTimeImmutable}
     */
    private static function emptyAreaState(): array
    {
        return ['rows' => [], 'primary_slug' => null, 'locked' => false, 'has_manual' => false, 'run_id' => null, 'updated_at' => null];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $context areaContext(); null num periódico novo
     * @param iterable<\App\Entity\AcademicDatabase> $databases
     */
    private function renderForm(QualisJournal $journal, bool $isNew, array $values, string $variationsText, ?array $context, iterable $databases): Response
    {
        $isMember = $context['isMember'] ?? false;

        return $this->render('admin/journal/form.html.twig', [
            'journal' => $journal,
            'databases' => $databases,
            'isNew' => $isNew,
            'variationsText' => $variationsText,
            'values' => $values,
            'capesGroups' => $this->taxonomy->grouped(),
            'isMember' => $isMember,
            'canonical' => $isMember ? $context['canonical'] : null,
            'familyHasAreas' => $context['familyHasAreas'] ?? false,
            'areaState' => $context['areaState'] ?? self::emptyAreaState(),
            'evidences' => $context['evidences'] ?? [],
            'evidenceTotal' => $context['evidenceTotal'] ?? 0,
            'evidenceLimit' => self::EVIDENCE_LIMIT,
            'confidenceLabels' => self::CONFIDENCE_LABELS,
        ]);
    }
}
