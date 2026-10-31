<?php

namespace App\Controller\admin;

use App\Entity\Researcher;
use App\Repository\ResearcherRepository;
use App\Service\Export\CurriculumExporterService;
use App\Service\Import\CurriculumPostImportService;
use App\Service\Import\LattesHtmlParserService;
use App\Service\Import\LattesXmlParserService;
use App\Service\Import\RepositoryImportService;
use App\Service\PageCacheService;
use App\Service\Thesaurus\AuthorResolverService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/curriculum')]
class AdminCurriculumController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
        private readonly ResearcherRepository $researcherRepo,
        private readonly CurriculumExporterService $exporterService,
        private readonly LattesXmlParserService $parserService,
        private readonly LattesHtmlParserService $htmlParserService,
        private readonly CurriculumPostImportService $postImport,
        private readonly AuthorResolverService $authorResolver,
        private readonly PageCacheService $pageCache
    ) {}

    #[Route('/', name: 'app_admin_curriculum_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $dept = $request->query->get('dept');
        $search = trim((string) $request->query->get('q', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;

        $researchers = $this->researcherRepo->searchResearchersWithCounts($search !== '' ? $search : null, $dept, $page, $limit);
        $totalResearchers = $this->researcherRepo->countSearchResearchers($search !== '' ? $search : null, $dept);
        $totalPages = (int) ceil($totalResearchers / $limit);
        $departments = $this->researcherRepo->findAllDistinctDepartments();

        return $this->render('admin/curriculum/index.html.twig', [
            'researchers' => $researchers,
            'departments' => $departments,
            'selectedDept' => $dept,
            'search' => $search,
            'currentPage' => $page,
            'totalPages' => max(1, $totalPages),
            'totalResearchers' => $totalResearchers,
            'limit' => $limit,
        ]);
    }

    #[Route('/new', name: 'app_admin_curriculum_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        \App\Service\Crawler\LattesPhotoCrawlerService $photoService,
        \App\Service\Security\SyncTokenProvider $syncToken
    ): Response {
        if (\function_exists('ini_set')) {
            @\ini_set('memory_limit', '512M');
        }
        if (\function_exists('set_time_limit')) {
            @\set_time_limit(300);
        }

        if ($request->isMethod('POST')) {
            $rawLattes = trim((string)$request->request->get('lattes_id'));
            $rawDept = trim((string)$request->request->get('department'));
            // Caixa desmarcada não é enviada: com o padrão `true`, a foto oficial era buscada mesmo assim e trocava a enviada à mão
            $fetchPhoto = $request->request->has('fetch_photo');
            $forceOlder = $request->request->has('force_older');
            /** @var UploadedFile|null $xmlFile */
            $xmlFile = $request->files->get('xml_file');

            // Extract 16-digit ID
            preg_match('/\b(\d{16})\b/', $rawLattes, $matches);
            $idLattes = $matches[1] ?? preg_replace('/\D/', '', $rawLattes);

            if (strlen($idLattes) !== 16) {
                $this->addFlash('error', 'O ID Lattes informado deve conter exatamente 16 dígitos numéricos (ou ser uma URL válida).');
                return $this->render('admin/curriculum/new.html.twig', ['syncToken' => $syncToken->getToken()]);
            }

            $department = $this->parseDepartment($rawDept);

            $researcher = null;
            $skippedOlder = false;
            $importFailed = false;

            // 1. Process XML if uploaded or if exists locally
            $xmlPaths = [];
            if ($xmlFile instanceof UploadedFile) {
                if ($xmlFile->isValid()) {
                    $xmlPaths[] = $xmlFile->getRealPath();
                } else {
                    $importFailed = true;
                    $this->addFlash('error', 'O servidor não recebeu o arquivo XML (' . $xmlFile->getErrorMessage() . '). Nada foi importado dele.');
                }
            } else {
                // Check if XML exists in docs/banco/CECH/
                foreach (["lattes{$idLattes}.xml", "{$idLattes}.xml"] as $name) {
                    $path = $this->getParameter('kernel.project_dir') . "/docs/banco/CECH/{$name}";
                    if (file_exists($path)) {
                        $xmlPaths[] = $path;
                    }
                }
            }

            foreach ($xmlPaths as $xmlPath) {
                try {
                    // O ID da tela vale para o XML sem NUMERO-IDENTIFICADOR; o do próprio XML, quando existe, tem preferência
                    if (!$forceOlder && $this->parserService->isOlderThanStored($xmlPath, $idLattes)) {
                        $skippedOlder = true;
                        continue;
                    }
                    $researcher = $this->parserService->parseAndSave($xmlPath, $idLattes);
                    break;
                } catch (\Throwable $e) {
                    $importFailed = true;
                    $this->recoverEntityManager();
                    $this->addFlash('error', 'Erro ao processar arquivo XML: ' . $e->getMessage());
                }
            }

            // 2. Process HTML if pasted directly
            $htmlContent = trim((string)$request->request->get('html_content', ''));
            if (!$researcher && $htmlContent !== '') {
                try {
                    $existing = $this->researcherRepo->findOneBy(['idLattes' => $idLattes]);
                    $researcher = $this->htmlParserService->parseHtmlAndSave($htmlContent, $existing, $idLattes);
                } catch (\Throwable $e) {
                    $importFailed = true;
                    $this->recoverEntityManager();
                    $this->addFlash('error', 'Erro ao processar HTML colado: ' . $e->getMessage());
                }
            }
            $imported = $researcher !== null;
            $report = $this->parserService->getLastReport() ?? $this->htmlParserService->getLastReport();

            // If no XML was found or parsed, get or create researcher entity
            if (!$researcher) {
                $researcher = $this->researcherRepo->findOneBy(['idLattes' => $idLattes]);
                if (!$researcher) {
                    $researcher = new \App\Entity\Researcher();
                    $researcher->setIdLattes($idLattes);
                    $researcher->setFullName("Pesquisador Lattes {$idLattes}");
                    // Sem slug, o docente novo fica com '' e a página pública dele não tem endereço
                    $researcher->setSlug(StringNormalizer::slugify($researcher->getFullName()));
                    $this->em->persist($researcher);
                }
            }

            if ($department !== null) {
                $researcher->setDepartmentCode($department[0]);
                $researcher->setDepartment($department[1]);
            }

            $this->em->flush();

            // 3. Fetch official photo if requested
            $photoSuccess = false;
            if ($fetchPhoto) {
                $url = $photoService->crawlPhoto($researcher);
                if ($url) {
                    $photoSuccess = true;
                }
            }

            // Cache de páginas e teses do Repositório; falha aqui não desfaz a importação, vira aviso
            $post = $this->postImport->run([$researcher]);

            $photoMessage = $photoSuccess ? ' Foto oficial do CNPq vinculada com sucesso.' : '';
            if ($imported) {
                $msg = $report['summaryMessage'] ?? "Docente \"{$researcher->getFullName()}\" cadastrado/atualizado com sucesso!";
                $this->addFlash('success', $msg . $photoMessage . ' ' . CurriculumPostImportService::HEAVY_STEPS_NOTICE);
            } elseif ($skippedOlder) {
                $stored = $researcher->getLastLattesUpdate();
                $this->addFlash('warning', sprintf(
                    'O XML tem data de atualização anterior à do currículo que já está no banco%s, e importá-lo trocaria dados novos por antigos: nada foi importado. Marque "Importar mesmo com data antiga" para importar assim mesmo.',
                    $stored ? ' (' . $stored->format('d/m/Y') . ')' : ''
                ));
                if ($photoSuccess || $department !== null) {
                    $this->addFlash('success', trim(($department !== null ? 'Departamento atualizado.' : '') . $photoMessage));
                }
            } elseif (!$importFailed) {
                $this->addFlash('success', "Docente \"{$researcher->getFullName()}\" cadastrado/atualizado com sucesso!" . $photoMessage);
            }
            foreach ($post['warnings'] as $warning) {
                $this->addFlash('warning', $warning);
            }

            return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $researcher->getId()]);
        }

        return $this->render('admin/curriculum/new.html.twig', ['syncToken' => $syncToken->getToken()]);
    }

    #[Route('/sync-all-photos', name: 'app_admin_curriculum_sync_all_photos', methods: ['POST'])]
    public function syncAllPhotos(\App\Service\Crawler\LattesPhotoCrawlerService $photoService): Response
    {
        $researchers = $this->researcherRepo->findBy(['photoUrl' => null]);
        $count = 0;
        foreach ($researchers as $r) {
            if ($photoService->crawlPhoto($r)) {
                $count++;
            }
        }
        $this->addFlash('success', "Sincronização concluída: {$count} novas fotos obtidas e vinculadas!");
        return $this->redirectToRoute('app_admin_curriculum_index');
    }

    #[Route('/import', name: 'app_admin_curriculum_import', methods: ['GET', 'POST'])]
    public function import(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            // Cada XML grande leva alguns segundos: o lote não pode parar no limite padrão de execução
            if (\function_exists('ini_set')) {
                @\ini_set('memory_limit', '512M');
            }
            if (\function_exists('set_time_limit')) {
                @\set_time_limit(300);
            }

            /** @var UploadedFile[]|UploadedFile|null $files */
            $files = $request->files->get('xml_files');
            $department = $this->parseDepartment(trim((string)$request->request->get('department')));
            $forceOlder = $request->request->has('force_older');

            /** @var Researcher[] $imported */
            $imported = [];
            $skipped = [];
            $errors = [];

            if ($files) {
                if (!is_array($files)) {
                    $files = [$files];
                }

                foreach ($files as $file) {
                    if (!$file instanceof UploadedFile) {
                        continue;
                    }

                    $name = $file->getClientOriginalName();
                    if (!$file->isValid()) {
                        $errors[] = sprintf('%s: o servidor não recebeu o arquivo (%s)', $name, $file->getErrorMessage());
                        continue;
                    }

                    // O arquivo enviado se chama /tmp/phpXXXXXX: o ID, quando o XML não o traz, vem de 16 dígitos do nome original
                    $idLattes = $this->lattesIdFrom($name);

                    try {
                        if (!$forceOlder && $this->parserService->isOlderThanStored($file->getRealPath(), $idLattes)) {
                            $skipped[] = $name;
                            continue;
                        }

                        $researcher = $this->parserService->parseAndSave($file->getRealPath(), $idLattes);
                        $imported[] = $researcher;

                        if ($department !== null) {
                            $researcher->setDepartmentCode($department[0]);
                            $researcher->setDepartment($department[1]);
                            $this->em->flush();
                        }
                    } catch (\Throwable $e) {
                        // Erro de banco fecha o EntityManager: sem reabrir, todos os arquivos seguintes falhariam também
                        $this->recoverEntityManager();
                        $errors[] = sprintf('%s: %s', $name, $e->getMessage());
                    }
                }
            }

            if ($imported !== []) {
                $this->addFlash('success', sprintf('%d currículo(s) importado(s) com sucesso! %s', count($imported), CurriculumPostImportService::HEAVY_STEPS_NOTICE));
            }
            if ($skipped !== []) {
                $this->addFlash('warning', sprintf(
                    '%d arquivo(s) pulado(s): o XML tem data anterior à do currículo que já está no banco (%s). Marque "Importar mesmo com data antiga" para importar assim mesmo.',
                    count($skipped),
                    implode(', ', array_slice($skipped, 0, 5))
                ));
            }
            if (!empty($errors)) {
                $this->addFlash('error', 'Erros: ' . implode(' | ', array_slice($errors, 0, 5)));
            }
            if ($imported === [] && $skipped === [] && $errors === []) {
                $this->addFlash('warning', 'Nenhum arquivo XML foi enviado.');
            }

            // Cache de páginas e teses do Repositório; falha aqui não desfaz a importação, vira aviso
            if ($imported !== []) {
                foreach ($this->postImport->run($imported)['warnings'] as $warning) {
                    $this->addFlash('warning', $warning);
                }
            }

            return $this->redirectToRoute('app_admin_curriculum_index');
        }

        return $this->render('admin/curriculum/import.html.twig');
    }

    #[Route('/export/{format}', name: 'app_admin_curriculum_export', methods: ['GET'])]
    public function export(string $format, Request $request): Response
    {
        $dept = $request->query->get('dept');
        $criteria = [];
        if ($dept) {
            $criteria['departmentCode'] = $dept;
        }

        $researchers = $this->researcherRepo->findBy($criteria, ['fullName' => 'ASC']);

        return match (strtolower($format)) {
            'json' => $this->exporterService->exportJson($researchers),
            'csv' => $this->exporterService->exportCsv($researchers),
            'xml' => $this->exporterService->exportXml($researchers),
            default => throw $this->createNotFoundException('Formato de exportação não suportado.'),
        };
    }

    #[Route('/{id}', name: 'app_admin_curriculum_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $researcher = $this->researcherRepo->findWithAllDetails($id);
        if (!$researcher) {
            throw $this->createNotFoundException('Pesquisador não encontrado.');
        }

        return $this->render('admin/curriculum/show.html.twig', [
            'researcher' => $researcher,
        ]);
    }

    #[Route('/{id}/pdf', name: 'app_admin_curriculum_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportPdf(Researcher $researcher): Response
    {
        return $this->exporterService->exportPdf($researcher);
    }

    #[Route('/{id}/photo', name: 'app_admin_curriculum_photo_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadPhoto(Request $request, Researcher $researcher, \App\Service\Crawler\LattesPhotoCrawlerService $photoService): Response
    {
        /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $photoFile */
        $photoFile = $request->files->get('photoFile');
        if ($photoFile && $photoFile->isValid()) {
            try {
                $photoService->assignUploadedPhoto($researcher, $photoFile);
                $this->addFlash('success', "Foto do pesquisador atualizada com sucesso!");
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        } else {
            $this->addFlash('error', "Arquivo de foto inválido.");
        }

        return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $researcher->getId()]);
    }

    #[Route('/{id}/crawl-photo', name: 'app_admin_curriculum_photo_crawl', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function crawlPhoto(Researcher $researcher, \App\Service\Crawler\LattesPhotoCrawlerService $photoService): Response
    {
        $url = $photoService->crawlPhoto($researcher);
        if ($url) {
            $this->addFlash('success', "Foto obtida com sucesso da plataforma Lattes/CNPq!");
        } else {
            $this->addFlash('warning', "Não foi possível recuperar a foto automaticamente do CNPq (proteção contra robôs). Recomendamos o envio manual pelo formulário.");
        }

        return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $researcher->getId()]);
    }

    #[Route('/{id}/import-repository', name: 'app_admin_curriculum_import_repository', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function importRepository(Request $request, Researcher $researcher, RepositoryImportService $repositoryImportService): Response
    {
        if (!$this->isCsrfTokenValid('import_repository' . $researcher->getId(), (string)$request->request->get('_token'))) {
            $this->addFlash('error', 'Token de segurança inválido. Recarregue a página e tente novamente.');
            return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $researcher->getId()]);
        }

        $csvPath = $this->getParameter('kernel.project_dir') . '/' . CurriculumPostImportService::REPOSITORY_CSV_PATH;
        if (!is_readable($csvPath)) {
            $this->addFlash('error', sprintf('Arquivo do Repositório Institucional não encontrado no servidor (%s).', CurriculumPostImportService::REPOSITORY_CSV_PATH));
            return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $researcher->getId()]);
        }

        // A leitura do CSV completo (~100 MB) pode ultrapassar o limite padrão de execução.
        // Em produção (RunCloud) set_time_limit() está em disable_functions.
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        try {
            $stats = $repositoryImportService->import(csvFilePath: $csvPath, onlyResearcher: $researcher);
            $found = $stats['enrichedOrientations'] + $stats['newOrientationsCreated'] + $stats['skippedOrientations'];

            if ($found === 0) {
                $this->addFlash('warning', 'Nenhuma tese ou dissertação deste docente foi encontrada no Repositório Institucional da UFSCar.');
            } else {
                $this->addFlash('success', sprintf(
                    'Repositório UFSCar: %d trabalho(s) encontrado(s) — %d orientação(ões) enriquecida(s), %d nova(s) cadastrada(s) e %d já vinculada(s).',
                    $found,
                    $stats['enrichedOrientations'],
                    $stats['newOrientationsCreated'],
                    $stats['skippedOrientations']
                ));
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erro ao importar do Repositório Institucional: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $researcher->getId()]);
    }

    #[Route('/api/quick-photo', name: 'app_admin_curriculum_quick_photo', methods: ['POST'])]
    public function quickPhoto(Request $request, \App\Service\Crawler\LattesPhotoCrawlerService $photoService): Response
    {
        $data = json_decode($request->getContent(), true) ?: $request->request->all();
        $idLattes = trim((string)($data['idLattes'] ?? ''));
        $base64 = (string)($data['photoData'] ?? '');

        if (!$idLattes || !$base64) {
            return $this->json(['success' => false, 'message' => 'Parâmetros inválidos.'], 400);
        }

        $researcher = $this->researcherRepo->findOneBy(['idLattes' => $idLattes]);
        if (!$researcher) {
            return $this->json(['success' => false, 'message' => 'Pesquisador não encontrado.'], 404);
        }

        if (str_contains($base64, ',')) {
            $base64 = explode(',', $base64)[1];
        }

        $binary = base64_decode($base64, true);
        $photoUrl = $binary !== false ? $photoService->storeBinaryPhoto($researcher, $binary) : null;

        if ($photoUrl === null) {
            return $this->json(['success' => false, 'message' => 'Imagem corrompida, vazia ou em formato não suportado.'], 400);
        }

        return $this->json([
            'success' => true,
            'message' => 'Foto salva com sucesso!',
            'photoUrl' => $photoUrl,
            'researcher' => $researcher->getFullName()
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_curriculum_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Researcher $researcher): Response
    {
        if ($this->isCsrfTokenValid('delete' . $researcher->getId(), (string)$request->request->get('_token'))) {
            $name = $researcher->getFullName();
            $this->em->remove($researcher);
            $this->em->flush();
            // O índice de docentes fica 30 dias em cache e as páginas públicas, outros 30: os dois ainda citam o docente
            // excluído. Com o id velho no índice, a próxima importação falha na chave estrangeira de
            // production_authors.matched_researcher_id.
            $this->authorResolver->clearCache();
            $this->pageCache->clearCache();
            $this->addFlash('success', "Currículo de \"{$name}\" removido com sucesso.");
        }

        return $this->redirectToRoute('app_admin_curriculum_index');
    }

    /**
     * ID Lattes de exatamente 16 dígitos dentro de um texto (nome de arquivo, URL), ou null se não houver.
     */
    private function lattesIdFrom(string $text): ?string
    {
        return preg_match('/(?<!\d)\d{16}(?!\d)/', $text, $match) === 1 ? $match[0] : null;
    }

    /**
     * "CODIGO|Nome" do formulário em [código, nome]; só o código serve também de nome. Vazio devolve null: o departamento
     * do docente não é mexido.
     *
     * @return array{0: string, 1: string}|null
     */
    private function parseDepartment(string $raw): ?array
    {
        $parts = explode('|', trim($raw), 2);
        $code = trim($parts[0]);
        if ($code === '') {
            return null;
        }
        $name = trim($parts[1] ?? '');

        return [$code, $name !== '' ? $name : $code];
    }

    /**
     * Um erro de banco fecha o EntityManager; sem reabrir, a tela (ou o arquivo seguinte do lote) falha também.
     */
    private function recoverEntityManager(): void
    {
        if ($this->em->isOpen()) {
            $this->em->clear();
        } else {
            $this->registry->resetManager();
        }
    }
}
