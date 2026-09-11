<?php

namespace App\Controller\pub;

use App\Repository\ResearcherRepository;
use App\Service\Crawler\LattesPhotoCrawlerService;
use App\Service\Import\LattesHtmlParserService;
use App\Service\Security\SyncTokenProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints chamados pelos importadores que rodam no navegador do administrador
 * (bookmarklet e script de console executados sobre uma página aberta do Lattes).
 *
 * Como a requisição parte de outra origem, ela não carrega a sessão do painel:
 * a autorização é feita pelo token de sincronização (`SyncTokenProvider`), exibido
 * apenas dentro de `/admin/curriculum/new`. Os cabeçalhos CORS são adicionados
 * centralmente por `App\EventListener\CorsListener` para todo o prefixo `/api/`.
 */
class PhotoApiController extends AbstractController
{
    public function __construct(
        private readonly ResearcherRepository $researcherRepo,
        private readonly SyncTokenProvider $syncToken
    ) {}

    /**
     * Recebe uma foto em base64 capturada na página do Lattes e a vincula ao pesquisador.
     */
    #[Route('/api/photo/save', name: 'app_api_photo_save', methods: ['POST'])]
    public function savePhoto(Request $request, LattesPhotoCrawlerService $photoService): JsonResponse
    {
        $data = $this->decodePayload($request);

        if (!$this->syncToken->isValidRequest($request, $data)) {
            return $this->failure('Token de sincronização ausente ou inválido.', Response::HTTP_UNAUTHORIZED);
        }

        $idLattes = trim((string)($data['idLattes'] ?? ''));
        $base64 = (string)($data['photoData'] ?? '');

        if (!$idLattes || !$base64) {
            return $this->failure('Parâmetros ausentes (idLattes ou photoData).', Response::HTTP_BAD_REQUEST);
        }

        $researcher = $this->researcherRepo->findOneBy(['idLattes' => $idLattes])
            ?? $this->researcherRepo->findOneBy(['slug' => $idLattes]);

        if (!$researcher) {
            return $this->failure("Pesquisador com Lattes '{$idLattes}' não encontrado.", Response::HTTP_NOT_FOUND);
        }

        if (str_contains($base64, ',')) {
            $base64 = explode(',', $base64)[1];
        }

        $binary = base64_decode($base64, true);
        if ($binary === false) {
            return $this->failure('Imagem base64 inválida.', Response::HTTP_BAD_REQUEST);
        }

        $photoUrl = $photoService->storeBinaryPhoto($researcher, $binary);
        if ($photoUrl === null) {
            return $this->failure('O conteúdo enviado não é uma imagem válida ou é muito pequeno.', Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'message' => "Foto de {$researcher->getFullName()} salva com sucesso!",
            'photoUrl' => $photoUrl,
            'researcher' => $researcher->getFullName(),
        ]);
    }

    /**
     * Recebe o HTML completo de um currículo Lattes aberto no navegador, extrai e persiste os dados.
     */
    #[Route('/api/curriculum/import-html', name: 'app_api_curriculum_import_html', methods: ['POST'])]
    public function importHtml(
        Request $request,
        LattesHtmlParserService $htmlParser,
        LattesPhotoCrawlerService $photoCrawler
    ): JsonResponse {
        @ini_set('memory_limit', '512M');
        if (\function_exists('set_time_limit')) {
            @\set_time_limit(300);
        }

        $data = $this->decodePayload($request);

        if (!$this->syncToken->isValidRequest($request, $data)) {
            return $this->failure('Token de sincronização ausente ou inválido.', Response::HTTP_UNAUTHORIZED);
        }

        $idLattes = trim((string)($data['idLattes'] ?? ''));
        $html = (string)($data['html'] ?? '');

        if (!$html) {
            return $this->failure('HTML do currículo não fornecido.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $existing = $idLattes ? $this->researcherRepo->findOneBy(['idLattes' => $idLattes]) : null;
            $researcher = $htmlParser->parseHtmlAndSave($html, $existing, $idLattes ?: null);

            // Se não tiver foto, buscar foto oficial automaticamente via K-ID
            if (!$researcher->getPhotoUrl()) {
                $photoCrawler->crawlPhoto($researcher);
            }

            $report = $htmlParser->getLastReport();

            return new JsonResponse([
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
        } catch (\Throwable $e) {
            return $this->failure('Erro ao processar HTML: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Decodifica o corpo da requisição, aceitando JSON ou formulário.
     *
     * @return array<string, mixed>
     */
    private function decodePayload(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : $request->request->all();
    }

    private function failure(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }
}
