<?php

namespace App\Controller\pub;

use App\Service\Project\ProjectCatalogService;
use App\Service\Project\ProjectFilter;
use App\Service\Project\ProjectLabels;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Catálogo institucional de projetos: os projetos de pesquisa, extensão, ensino e desenvolvimento declarados no Lattes
 * pelos docentes do CECH, com um item por projeto mesmo quando vários docentes o declaram ({@see ProjectCatalogService}).
 *
 * - `/projetos`: busca e filtros pela URL (q, situacao, natureza, departamento, ano_inicial, ano_final, page). A URL é
 *   canônica: parâmetro vazio, inválido ou desconhecido redireciona para a URL sem ele, e página além da última é 404,
 *   para o cache de páginas guardar uma cópia por filtro e não por variação da URL.
 * - `/projeto/{id}`: qualquer registro do grupo abre a mesma página; a URL canônica usa o registro representante.
 * - `/api/v1/projetos` e `/api/v1/projetos/{id}`: o mesmo em JSON, só com o que as páginas mostram.
 */
class ProjectController extends AbstractController
{
    private const API_CACHE = 'public, max-age=3600';

    public function __construct(private readonly ProjectCatalogService $catalog)
    {
    }

    #[Route('/projetos', name: 'app_pub_projects', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $departments = $this->catalog->departmentOptions();
        $filter = ProjectFilter::fromQuery($request->query->all(), array_column($departments, 'code'));
        $page = $this->page($request);

        $canonicalQuery = $filter->toQuery() + ($page > 1 ? ['page' => (string)$page] : []);
        if ($request->query->all() !== $canonicalQuery) {
            return $this->redirectToRoute('app_pub_projects', $canonicalQuery);
        }

        $result = $this->catalog->search($filter, $page);
        if ($page > $result['pages']) {
            throw $this->createNotFoundException('Página não encontrada.');
        }

        return $this->render('pub/project/index.html.twig', [
            'filter' => $filter,
            'filterQuery' => $filter->toQuery(),
            'result' => $result,
            'totals' => $this->catalog->totals(),
            'departments' => $departments,
            'statusOptions' => ProjectLabels::STATUS,
            'natureOptions' => ProjectLabels::NATURE,
            'pagination' => $this->paginationWindow($result['page'], $result['pages']),
            'currentYear' => (int)date('Y'),
        ]);
    }

    #[Route('/projeto/{id}', name: 'app_pub_project_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $project = $this->catalog->findGroup($id)
            ?? throw $this->createNotFoundException('Projeto não encontrado.');

        return $this->render('pub/project/show.html.twig', [
            'project' => $project,
            'canonicalUrl' => $this->generateUrl('app_pub_project_show', ['id' => $project['id']], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    #[Route('/api/v1/projetos', name: 'app_api_projects', methods: ['GET'])]
    public function apiIndex(Request $request): JsonResponse
    {
        $filter = ProjectFilter::fromQuery($request->query->all(), $this->catalog->departmentCodes());
        $page = $this->page($request);
        $result = $this->catalog->search($filter, $page);
        if ($page > $result['pages']) {
            return $this->jsonResponse(['error' => 'Página não encontrada.', 'pages' => $result['pages']], Response::HTTP_NOT_FOUND);
        }

        return $this->jsonResponse([
            'count' => $result['count'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'per_page' => $result['per_page'],
            'filters' => (object)$filter->toQuery(),
            'results' => array_map(fn (array $item): array => $this->apiListItem($item), $result['results']),
        ]);
    }

    #[Route('/api/v1/projetos/{id}', name: 'app_api_project_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiShow(int $id): JsonResponse
    {
        $project = $this->catalog->findGroup($id);
        if ($project === null) {
            return $this->jsonResponse(['error' => 'Projeto não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        return $this->jsonResponse([
            'id' => $project['id'],
            'url' => $this->projectUrl($project['id']),
            'title' => $project['title'],
            'status' => $project['status'],
            'status_label' => $project['status_label'],
            'nature' => $project['nature'],
            'nature_label' => $project['nature_label'],
            'start_year' => $project['start_year'],
            'end_year' => $project['end_year'],
            'period' => $project['period'],
            'description' => $project['description'],
            'funders' => $project['funders'],
            'members' => array_map(fn (array $m): array => [
                'name' => $m['name'],
                'role' => $m['is_responsible'] ? 'responsavel' : 'participante',
                'role_label' => $m['is_responsible'] ? 'Responsável' : 'Participante',
                'researcher' => $m['researcher'] !== null ? $this->apiResearcher($m['researcher']) : null,
            ], $project['members']),
            'declared_by' => array_map(fn (array $r): array => $this->apiResearcher($r) + [
                'department' => $r['department'],
                'is_coordinator' => $r['is_coordinator'],
            ], $project['declared_by']),
        ]);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function apiListItem(array $item): array
    {
        return [
            'id' => $item['id'],
            'url' => $this->projectUrl($item['id']),
            'api_url' => $this->generateUrl('app_api_project_show', ['id' => $item['id']], UrlGeneratorInterface::ABSOLUTE_URL),
            'title' => $item['title'],
            'status' => $item['status'],
            'status_label' => $item['status_label'],
            'nature' => $item['nature'],
            'nature_label' => $item['nature_label'],
            'start_year' => $item['start_year'],
            'end_year' => $item['end_year'],
            'period' => $item['period'],
            'summary' => $item['summary'],
            'researchers' => array_map(fn (array $r): array => $this->apiResearcher($r) + [
                'is_responsible' => $r['is_responsible'],
                'declares' => $r['declares'],
            ], $item['researchers']),
        ];
    }

    /**
     * @param array{name: string, slug_or_id: string, department_code: ?string} $researcher
     *
     * @return array{name: string, department_code: ?string, url: string}
     */
    private function apiResearcher(array $researcher): array
    {
        return [
            'name' => $researcher['name'],
            'department_code' => $researcher['department_code'],
            'url' => $this->generateUrl('app_pub_professor_show', ['slugOrId' => $researcher['slug_or_id']], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    private function projectUrl(int $id): string
    {
        return $this->generateUrl('app_pub_project_show', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function jsonResponse(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->headers->set('Cache-Control', self::API_CACHE);

        return $response;
    }

    /** `page` válido (inteiro ≥ 1, sem zeros à esquerda); qualquer outra coisa conta como a primeira página */
    private function page(Request $request): int
    {
        $page = $request->query->all()['page'] ?? '1';

        return is_string($page) && preg_match('/^[1-9]\d{0,5}$/', $page) === 1 ? (int)$page : 1;
    }

    /**
     * Números de página a mostrar: a primeira, a última e duas de cada lado da atual; null marca um salto ("…").
     *
     * @return list<int|null>
     */
    private function paginationWindow(int $page, int $pages): array
    {
        $window = [];
        $previous = 0;
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === 1 || $i === $pages || abs($i - $page) <= 2) {
                if ($previous && $i - $previous > 1) {
                    $window[] = null;
                }
                $window[] = $i;
                $previous = $i;
            }
        }

        return $window;
    }
}
