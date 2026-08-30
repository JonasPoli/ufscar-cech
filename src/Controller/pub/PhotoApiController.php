<?php

namespace App\Controller\pub;

use App\Repository\ResearcherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PhotoApiController extends AbstractController
{
    #[Route('/api/photo/save', name: 'app_api_photo_save', methods: ['POST', 'OPTIONS'])]
    public function savePhoto(
        Request $request,
        ResearcherRepository $researcherRepo,
        EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] string $projectDir
    ): Response {
        if ($request->isMethod('OPTIONS')) {
            $response = new Response();
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
            return $response;
        }

        $data = json_decode($request->getContent(), true) ?: $request->request->all();
        $idLattes = trim((string)($data['idLattes'] ?? ''));
        $base64 = (string)($data['photoData'] ?? '');

        if (!$idLattes || !$base64) {
            $res = new JsonResponse(['success' => false, 'message' => 'Parâmetros ausentes (idLattes ou photoData).'], 400);
            $res->headers->set('Access-Control-Allow-Origin', '*');
            return $res;
        }

        $researcher = $researcherRepo->findOneBy(['idLattes' => $idLattes]);
        if (!$researcher) {
            $researcher = $researcherRepo->findOneBy(['slug' => $idLattes]);
        }

        if (!$researcher) {
            $res = new JsonResponse(['success' => false, 'message' => "Pesquisador com Lattes '{$idLattes}' não encontrado."], 404);
            $res->headers->set('Access-Control-Allow-Origin', '*');
            return $res;
        }

        if (str_contains($base64, ',')) {
            $base64 = explode(',', $base64)[1];
        }

        $binary = base64_decode($base64);
        if (!$binary || strlen($binary) < 300) {
            $res = new JsonResponse(['success' => false, 'message' => 'Imagem base64 inválida ou muito pequena.'], 400);
            $res->headers->set('Access-Control-Allow-Origin', '*');
            return $res;
        }

        $photosDir = $projectDir . '/public/uploads/photos';
        if (!is_dir($photosDir)) {
            @mkdir($photosDir, 0777, true);
        }

        $filename = $researcher->getIdLattes() . '.jpg';
        file_put_contents($photosDir . '/' . $filename, $binary);

        $researcher->setPhotoUrl('/uploads/photos/' . $filename);
        $em->flush();

        $res = new JsonResponse([
            'success' => true,
            'message' => "Foto de {$researcher->getFullName()} salva com sucesso!",
            'photoUrl' => '/uploads/photos/' . $filename,
            'researcher' => $researcher->getFullName()
        ]);
        $res->headers->set('Access-Control-Allow-Origin', '*');
        return $res;
    }

    #[Route('/api/curriculum/import-html', name: 'app_api_curriculum_import_html', methods: ['POST', 'OPTIONS'])]
    public function importHtml(
        Request $request,
        \App\Service\Import\LattesHtmlParserService $htmlParser,
        \App\Service\Crawler\LattesPhotoCrawlerService $photoCrawler,
        ResearcherRepository $researcherRepo
    ): Response {
        @ini_set('memory_limit', '512M');
        @\set_time_limit(300);

        if ($request->isMethod('OPTIONS')) {
            $response = new Response();
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
            return $response;
        }

        $data = json_decode($request->getContent(), true) ?: $request->request->all();
        $idLattes = trim((string)($data['idLattes'] ?? ''));
        $html = (string)($data['html'] ?? '');

        if (!$html) {
            $res = new JsonResponse(['success' => false, 'message' => 'HTML do currículo não fornecido.'], 400);
            $res->headers->set('Access-Control-Allow-Origin', '*');
            return $res;
        }

        try {
            $existing = $idLattes ? $researcherRepo->findOneBy(['idLattes' => $idLattes]) : null;
            $researcher = $htmlParser->parseHtmlAndSave($html, $existing, $idLattes ?: null);

            // Se não tiver foto, buscar foto oficial automaticamente via K-ID
            if (!$researcher->getPhotoUrl()) {
                $photoResult = $photoCrawler->crawlPhoto($researcher->getIdLattes());
                if ($photoResult['success']) {
                    $researcher->setPhotoUrl($photoResult['photoUrl']);
                }
            }

            $report = $htmlParser->getLastReport();

            $res = new JsonResponse([
                'success' => true,
                'message' => $report['summaryMessage'] ?? "Currículo de {$researcher->getFullName()} importado e atualizado com sucesso!",
                'researcher' => [
                    'id' => $researcher->getId(),
                    'fullName' => $researcher->getFullName(),
                    'idLattes' => $researcher->getIdLattes(),
                    'orcid' => $researcher->getOrcid(),
                    'photoUrl' => $researcher->getPhotoUrl(),
                    'productionsCount' => count($researcher->getProductions()),
                    'orientationsCount' => count($researcher->getOrientations()),
                ],
                'report' => $report,
            ]);
            $res->headers->set('Access-Control-Allow-Origin', '*');
            return $res;
        } catch (\Throwable $e) {
            $res = new JsonResponse(['success' => false, 'message' => 'Erro ao processar HTML: ' . $e->getMessage()], 500);
            $res->headers->set('Access-Control-Allow-Origin', '*');
            return $res;
        }
    }
}
