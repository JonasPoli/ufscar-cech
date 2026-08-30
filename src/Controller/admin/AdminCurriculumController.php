<?php

namespace App\Controller\admin;

use App\Entity\Researcher;
use App\Repository\ResearcherRepository;
use App\Service\Export\CurriculumExporterService;
use App\Service\Import\LattesHtmlParserService;
use App\Service\Import\LattesXmlParserService;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly ResearcherRepository $researcherRepo,
        private readonly CurriculumExporterService $exporterService,
        private readonly LattesXmlParserService $parserService,
        private readonly LattesHtmlParserService $htmlParserService
    ) {}

    #[Route('/', name: 'app_admin_curriculum_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $dept = $request->query->get('dept');
        $search = $request->query->get('search');

        $researchers = $this->researcherRepo->findResearchersWithCounts($dept);
        $departments = $this->researcherRepo->findAllDistinctDepartments();

        return $this->render('admin/curriculum/index.html.twig', [
            'researchers' => $researchers,
            'departments' => $departments,
            'selectedDept' => $dept,
            'search' => $search,
        ]);
    }

    #[Route('/new', name: 'app_admin_curriculum_new', methods: ['GET', 'POST'])]
    public function new(Request $request, \App\Service\Crawler\LattesPhotoCrawlerService $photoService): Response
    {
        if (\function_exists('ini_set')) {
            @\ini_set('memory_limit', '512M');
        }
        if (\function_exists('set_time_limit')) {
            @\set_time_limit(300);
        }

        if ($request->isMethod('POST')) {
            $rawLattes = trim((string)$request->request->get('lattes_id'));
            $rawDept = trim((string)$request->request->get('department'));
            $fetchPhoto = (bool)$request->request->get('fetch_photo', true);
            /** @var UploadedFile|null $xmlFile */
            $xmlFile = $request->files->get('xml_file');

            // Extract 16-digit ID
            preg_match('/\b(\d{16})\b/', $rawLattes, $matches);
            $idLattes = $matches[1] ?? preg_replace('/\D/', '', $rawLattes);

            if (strlen($idLattes) !== 16) {
                $this->addFlash('error', 'O ID Lattes informado deve conter exatamente 16 dígitos numéricos (ou ser uma URL válida).');
                return $this->render('admin/curriculum/new.html.twig');
            }

            $deptCode = null;
            $deptName = null;
            if ($rawDept) {
                $parts = explode('|', $rawDept, 2);
                $deptCode = $parts[0];
                $deptName = $parts[1] ?? $parts[0];
            }

            $researcher = null;

            // 1. Process XML if uploaded or if exists locally
            if ($xmlFile && $xmlFile->isValid()) {
                try {
                    $researcher = $this->parserService->parseAndSave($xmlFile->getRealPath());
                } catch (\Throwable $e) {
                    $this->addFlash('error', 'Erro ao processar arquivo XML: ' . $e->getMessage());
                }
            } else {
                // Check if XML exists in docs/banco/CECH/
                $possiblePaths = [
                    $this->getParameter('kernel.project_dir') . "/docs/banco/CECH/lattes{$idLattes}.xml",
                    $this->getParameter('kernel.project_dir') . "/docs/banco/CECH/{$idLattes}.xml",
                ];
                foreach ($possiblePaths as $p) {
                    if (file_exists($p)) {
                        try {
                            $researcher = $this->parserService->parseAndSave($p);
                            break;
                        } catch (\Throwable) {}
                    }
                }
            }

            // 2. Process HTML if pasted directly
            $htmlContent = trim((string)$request->request->get('html_content', ''));
            if (!$researcher && $htmlContent !== '') {
                try {
                    $existing = $this->researcherRepo->findOneBy(['idLattes' => $idLattes]);
                    $researcher = $this->htmlParserService->parseHtmlAndSave($htmlContent, $existing, $idLattes);
                } catch (\Throwable $e) {
                    $this->addFlash('error', 'Erro ao processar HTML colado: ' . $e->getMessage());
                }
            }

            // If no XML was found or parsed, get or create researcher entity
            if (!$researcher) {
                $researcher = $this->researcherRepo->findOneBy(['idLattes' => $idLattes]);
                if (!$researcher) {
                    $researcher = new \App\Entity\Researcher();
                    $researcher->setIdLattes($idLattes);
                    $researcher->setFullName("Pesquisador Lattes {$idLattes}");
                    $this->em->persist($researcher);
                }
            }

            if ($deptCode && $deptName) {
                $researcher->setDepartmentCode($deptCode);
                $researcher->setDepartment($deptName);
            }

            $this->em->flush();

            // 2. Fetch official photo if requested
            $photoSuccess = false;
            if ($fetchPhoto) {
                $url = $photoService->crawlPhoto($researcher);
                if ($url) {
                    $photoSuccess = true;
                }
            }

            $report = $this->parserService->getLastReport() ?? $this->htmlParserService->getLastReport();
            $msg = $report['summaryMessage'] ?? "Docente \"{$researcher->getFullName()}\" cadastrado/atualizado com sucesso!";
            if ($photoSuccess) {
                $msg .= " Foto oficial do CNPq vinculada com sucesso.";
            }
            $this->addFlash('success', $msg);

            return $this->redirectToRoute('app_admin_curriculum_show', ['id' => $researcher->getId()]);
        }

        return $this->render('admin/curriculum/new.html.twig');
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
            /** @var UploadedFile[]|UploadedFile|null $files */
            $files = $request->files->get('xml_files');
            $count = 0;
            $errors = [];

            if ($files) {
                if (!is_array($files)) {
                    $files = [$files];
                }

                foreach ($files as $file) {
                    if ($file instanceof UploadedFile && $file->isValid()) {
                        try {
                            $this->parserService->parseAndSave($file->getRealPath());
                            $count++;
                        } catch (\Throwable $e) {
                            $errors[] = sprintf("%s: %s", $file->getClientOriginalName(), $e->getMessage());
                        }
                    }
                }
            }

            if ($count > 0) {
                $this->addFlash('success', sprintf('%d currículo(s) importado(s) com sucesso!', $count));
            }
            if (!empty($errors)) {
                $this->addFlash('error', 'Erros: ' . implode(' | ', array_slice($errors, 0, 5)));
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
            $url = $photoService->assignUploadedPhoto($researcher, $photoFile);
            $this->addFlash('success', "Foto do pesquisador atualizada com sucesso!");
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

    #[Route('/api/quick-photo', name: 'app_admin_curriculum_quick_photo', methods: ['POST'])]
    public function quickPhoto(Request $request): Response
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

        $binary = base64_decode($base64);
        if (!$binary || strlen($binary) < 500) {
            return $this->json(['success' => false, 'message' => 'Imagem corrompida ou vazia.'], 400);
        }

        $photosDir = $this->getParameter('kernel.project_dir') . '/public/uploads/photos';
        if (!is_dir($photosDir)) {
            @mkdir($photosDir, 0777, true);
        }

        $filename = $idLattes . '.jpg';
        file_put_contents($photosDir . '/' . $filename, $binary);

        $researcher->setPhotoUrl('/uploads/photos/' . $filename);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Foto salva com sucesso!',
            'photoUrl' => '/uploads/photos/' . $filename,
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
            $this->addFlash('success', "Currículo de \"{$name}\" removido com sucesso.");
        }

        return $this->redirectToRoute('app_admin_curriculum_index');
    }
}
