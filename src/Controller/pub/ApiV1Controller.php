<?php

declare(strict_types=1);

namespace App\Controller\pub;

use App\Entity\Researcher;
use App\Repository\ResearcherRepository;
use App\Service\Research\CoauthorshipGraphService;
use App\Service\Research\ExpertSearchService;
use App\Service\Research\ResearchCatalogService;
use App\Service\Research\ResearcherCard;
use App\Service\Research\ResearchFilter;
use App\Service\Research\ResearchFilterFactory;
use App\Service\Research\ResearchMetricsService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API pública, somente leitura, da pesquisa do CECH. Os filtros são os mesmos das páginas
 * (`q`, `exata`, `departamento`, `tema`, `grande_area`, `area`, `vinculo`, `tipo`, `de`, `ate`) e as respostas não
 * trazem nada além do que as páginas públicas mostram.
 */
#[Route('/api/v1', name: 'app_api_v1_')]
class ApiV1Controller extends AbstractController
{
    private const MAX_AGE = 3600;

    public function __construct(
        private readonly ResearchFilterFactory $filterFactory,
        private readonly ExpertSearchService $search,
        private readonly ResearchMetricsService $metrics,
        private readonly CoauthorshipGraphService $graph,
        private readonly ResearchCatalogService $catalog,
        private readonly ResearcherRepository $researchers,
        private readonly Connection $connection,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'name' => 'API pública do Portal CECH/UFSCar',
            'version' => '1',
            'filters' => ['q', 'exata', 'departamento', 'tema', 'grande_area', 'area', 'vinculo', 'tipo', 'de', 'ate'],
            'endpoints' => [
                'catalogo' => $this->generateUrl('app_api_v1_catalog'),
                'busca' => $this->generateUrl('app_api_v1_search') . '?modo=especialistas|publicacoes|pesquisador&q=…',
                'publicacoes' => $this->generateUrl('app_api_v1_publications'),
                'metricas' => $this->generateUrl('app_api_v1_metrics'),
                'grafo' => $this->generateUrl('app_api_v1_graph') . '?docente=slug&ligacoes=120',
                'grafo_par' => $this->generateUrl('app_api_v1_graph_pair') . '?a=id&b=id',
                'docente' => $this->generateUrl('app_api_v1_researcher', ['slug' => 'slug-do-docente']),
                'projetos' => '/api/v1/projetos',
            ],
        ]);
    }

    #[Route('/catalogo', name: 'catalog', methods: ['GET'])]
    public function catalog(): JsonResponse
    {
        return $this->cached([
            'departments' => $this->catalog->departments(),
            'majorAreas' => $this->catalog->majorAreas(),
            'areas' => $this->catalog->areas(),
            'topics' => $this->catalog->topics(),
            'types' => ResearchFilter::TYPE_LABELS,
            'affiliations' => ResearchFilter::AFFILIATIONS,
        ]);
    }

    #[Route('/busca', name: 'search', methods: ['GET'])]
    public function searchAction(Request $request): JsonResponse
    {
        $filter = $this->filterFactory->fromRequest($request);
        $mode = (string)$request->query->get('modo', 'especialistas');
        $page = max(1, $request->query->getInt('pagina', 1));
        $sort = (string)$request->query->get('ordem', 'relevancia');

        if ($filter->isEmpty()) {
            return $this->json(['error' => 'Informe ao menos um termo (q) ou um filtro.'], 400);
        }

        $result = match ($mode) {
            'publicacoes' => $this->search->publications($filter, $sort, $page),
            'pesquisador' => $this->search->researchers($filter, $page),
            default => $this->search->experts($filter, $sort, $page),
        };

        return $this->cached(['mode' => $mode, 'filter' => $filter->toQuery()] + $result);
    }

    #[Route('/publicacoes', name: 'publications', methods: ['GET'])]
    public function publications(Request $request): JsonResponse
    {
        $filter = $this->filterFactory->fromRequest($request);
        $page = max(1, $request->query->getInt('pagina', 1));

        return $this->cached(['filter' => $filter->toQuery()] + $this->search->publications($filter, (string)$request->query->get('ordem', 'recentes'), $page));
    }

    #[Route('/metricas', name: 'metrics', methods: ['GET'])]
    public function metricsAction(Request $request): JsonResponse
    {
        $filter = $this->filterFactory->fromRequest($request);

        return $this->cached(['filter' => $filter->toQuery()] + $this->metrics->metrics($filter));
    }

    #[Route('/grafo', name: 'graph', methods: ['GET'])]
    public function graphAction(Request $request): JsonResponse
    {
        $filter = $this->filterFactory->fromRequest($request);
        $focus = null;
        $slug = trim((string)$request->query->get('docente', ''));
        if ($slug !== '') {
            $researcher = $this->findResearcher($slug);
            if ($researcher === null) {
                return $this->json(['error' => 'Docente não encontrado.'], 404);
            }
            $focus = (int)$researcher->getId();
        }

        $limit = $request->query->getInt('ligacoes', CoauthorshipGraphService::DEFAULT_MAX_EDGES);

        return $this->cached($this->graph->graph($filter, $focus, max(0, min(5000, $limit))));
    }

    #[Route('/grafo/par', name: 'graph_pair', methods: ['GET'])]
    public function graphPair(Request $request): JsonResponse
    {
        $a = $request->query->getInt('a');
        $b = $request->query->getInt('b');
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return $this->json(['error' => 'Informe dois docentes diferentes (a e b).'], 400);
        }

        return $this->cached($this->graph->pairWorks($a, $b, $this->filterFactory->fromRequest($request)));
    }

    #[Route('/docentes/{slug}', name: 'researcher', methods: ['GET'])]
    public function researcher(string $slug): JsonResponse
    {
        $researcher = $this->findResearcher($slug);
        if ($researcher === null) {
            return $this->json(['error' => 'Docente não encontrado.'], 404);
        }

        $id = (int)$researcher->getId();
        $row = $this->connection->fetchAssociative('SELECT ' . ResearcherCard::columns() . ' FROM researchers r WHERE r.id = :id', ['id' => $id]);
        $filter = new ResearchFilter();
        $filter->researcherId = $id;
        $metrics = $this->metrics->metrics($filter, false);

        return $this->cached(ResearcherCard::fromRow($row ?: []) + [
            'lattesId' => $researcher->getIdLattes(),
            'lattesUrl' => $researcher->getLattesUrl(),
            'orcid' => $researcher->getOrcid(),
            'lastLattesUpdate' => $researcher->getLastLattesUpdate()?->format('Y-m-d'),
            'profileUrl' => $this->generateUrl('app_pub_professor_show', ['slugOrId' => $researcher->getSlug() ?: $researcher->getIdLattes()], 0),
            'stats' => [
                'publications' => $metrics['publications'],
                'latestYear' => $metrics['latestYear'],
            ] + $this->graph->researcherNetworkStats($id),
            'years' => $metrics['years'],
            'kinds' => $metrics['kinds'],
            'topics' => $this->catalog->topicsForResearchers([$id], 12),
        ]);
    }

    private function findResearcher(string $slugOrId): ?Researcher
    {
        $researcher = $this->researchers->findOneBy(['slug' => $slugOrId])
            ?? $this->researchers->findOneBy(['idLattes' => $slugOrId]);
        if ($researcher === null && ctype_digit($slugOrId) && strlen($slugOrId) < 10) {
            $researcher = $this->researchers->find((int)$slugOrId);
        }

        return $researcher;
    }

    /** @param array<string, mixed> $data */
    private function cached(array $data): JsonResponse
    {
        $response = $this->json($data);
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);

        return $response;
    }
}
