<?php

namespace App\Controller\pub;

use App\Repository\ThematicTermRepository;
use App\Service\Research\CoauthorshipGraphService;
use App\Service\Research\ExpertSearchService;
use App\Service\Research\MajorAreaCatalog;
use App\Service\Research\ResearchCatalogService;
use App\Service\Research\ResearchFilter;
use App\Service\Research\ResearchFilterFactory;
use App\Service\Research\ResearchMetricsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Explorar pesquisa": catálogos de assuntos (índice temático), áreas de atuação e grandes áreas (Lattes), e a
 * página de cada um, com o panorama, os docentes, a rede e as publicações daquele campo.
 */
class ExploreController extends AbstractController
{
    public function __construct(
        private readonly ResearchCatalogService $catalog,
        private readonly ResearchFilterFactory $filterFactory,
        private readonly ResearchMetricsService $metrics,
        private readonly ExpertSearchService $search,
        private readonly CoauthorshipGraphService $graph,
        private readonly ThematicTermRepository $terms,
    ) {
    }

    #[Route('/explorar', name: 'app_pub_explore', methods: ['GET'])]
    public function topics(): Response
    {
        return $this->render('pub/explore/catalog.html.twig', [
            'tab' => 'assuntos',
            'items' => array_map(fn(array $t) => [
                'name' => $t['term'],
                'url' => $this->generateUrl('app_pub_explore_topic', ['slug' => $t['slug']]),
                'count' => $t['occurrences'],
                'countLabel' => 'ocorrências',
                'researchers' => $t['researchers'],
                'group' => null,
            ], $this->catalog->topics()),
            'total' => $this->catalog->topicCount(),
        ] + $this->catalogTotals());
    }

    #[Route('/explorar/areas', name: 'app_pub_explore_areas', methods: ['GET'])]
    public function areas(): Response
    {
        $items = array_map(fn(array $a) => [
            'name' => $a['area'],
            'url' => $this->generateUrl('app_pub_explore_area', ['slug' => $a['slug']]),
            'count' => $a['publications'],
            'countLabel' => 'publicações',
            'researchers' => $a['researchers'],
            'group' => $a['majorCode'] !== '' ? $a['majorCode'] : 'OUTROS',
        ], array_values(array_filter($this->catalog->areas(), static fn($a) => $a['slug'] !== '')));

        return $this->render('pub/explore/catalog.html.twig', ['tab' => 'areas', 'items' => $items, 'total' => count($items)] + $this->catalogTotals());
    }

    #[Route('/explorar/grandes-areas', name: 'app_pub_explore_major_areas', methods: ['GET'])]
    public function majorAreas(): Response
    {
        $items = array_map(fn(array $m) => [
            'name' => $m['label'],
            'url' => $this->generateUrl('app_pub_explore_major_area', ['slug' => $m['slug']]),
            'count' => $m['publications'],
            'countLabel' => 'publicações',
            'researchers' => $m['researchers'],
            'group' => $m['code'],
        ], $this->catalog->majorAreas());

        return $this->render('pub/explore/catalog.html.twig', ['tab' => 'grandes-areas', 'items' => $items, 'total' => count($items)] + $this->catalogTotals());
    }

    /** Números da capa do Explorar: quantos assuntos, áreas e grandes áreas há. */
    private function catalogTotals(): array
    {
        return [
            'totals' => [
                'topics' => $this->catalog->topicCount(),
                'areas' => count(array_filter($this->catalog->areas(), static fn($a) => $a['slug'] !== '')),
                'majorAreas' => count($this->catalog->majorAreas()),
            ],
        ];
    }

    #[Route('/explorar/assunto/{slug}', name: 'app_pub_explore_topic', methods: ['GET'])]
    public function topic(string $slug, Request $request): Response
    {
        $term = $this->terms->findBySlugOrId($slug);
        if ($term === null) {
            throw $this->createNotFoundException('Assunto não encontrado.');
        }

        $filter = $this->scopedFilter($request);
        $filter->topicSlug = (string)($term->getSlug() ?: $term->getId());
        $filter->topicText = trim((string)$term->getTerm());

        return $this->scope($request, $filter, [
            'kind' => 'Assunto de pesquisa',
            'name' => $filter->topicText,
            'route' => 'app_pub_explore_topic',
            'slug' => $slug,
            'thematicUrl' => $this->generateUrl('app_pub_thematic_search', ['t' => $filter->topicSlug]),
            'related' => $this->terms->getRelatedConcepts($term, 12),
        ]);
    }

    #[Route('/explorar/area/{slug}', name: 'app_pub_explore_area', methods: ['GET'])]
    public function area(string $slug, Request $request): Response
    {
        $area = $this->catalog->findArea($slug);
        if ($area === null) {
            throw $this->createNotFoundException('Área de atuação não encontrada.');
        }

        $filter = $this->scopedFilter($request);
        $filter->area = $area['area'];

        return $this->scope($request, $filter, [
            'kind' => 'Área de atuação · ' . $area['majorLabel'],
            'name' => $area['area'],
            'route' => 'app_pub_explore_area',
            'slug' => $slug,
        ]);
    }

    #[Route('/explorar/grande-area/{slug}', name: 'app_pub_explore_major_area', methods: ['GET'])]
    public function majorArea(string $slug, Request $request): Response
    {
        $code = MajorAreaCatalog::codeFromSlug($slug);
        if ($code === null) {
            throw $this->createNotFoundException('Grande área não encontrada.');
        }

        $filter = $this->scopedFilter($request);
        $filter->majorArea = $code;

        return $this->scope($request, $filter, [
            'kind' => 'Grande área',
            'name' => MajorAreaCatalog::LABELS[$code],
            'route' => 'app_pub_explore_major_area',
            'slug' => $slug,
        ]);
    }

    /** Só os recortes adicionais que a página de um campo aceita: texto, departamento e período. */
    private function scopedFilter(Request $request): ResearchFilter
    {
        $requested = ResearchFilter::fromRequest($request);
        $filter = new ResearchFilter();
        $filter->q = $requested->q;
        $filter->exact = $requested->exact;
        $filter->department = $requested->department;
        $filter->from = $requested->from;
        $filter->to = $requested->to;

        return $filter;
    }

    /** @param array<string, mixed> $scope */
    private function scope(Request $request, ResearchFilter $filter, array $scope): Response
    {
        $page = max(1, $request->query->getInt('pagina', 1));
        $params = $filter->toQuery();
        // Os parâmetros que definem o campo já estão na rota; na URL da página ficam só os recortes adicionais
        $pageParams = array_diff_key($params, array_flip(['tema', 'area', 'grande_area']));

        return $this->render('pub/explore/scope.html.twig', [
            'scope' => $scope,
            'filter' => $filter,
            'pageParams' => $pageParams,
            'departments' => $this->catalog->departments(),
            'metrics' => $this->metrics->metrics($filter),
            'experts' => $this->search->experts($filter, 'publicacoes', 1, 12),
            'publications' => $this->search->publications($filter, 'recentes', $page, 12),
            'pairs' => $this->graph->topPairs($filter, 6),
            'graphSrc' => $this->generateUrl('app_api_v1_graph', $params + ['ligacoes' => 150]),
            'pairSrc' => $this->generateUrl('app_api_v1_graph_pair', $params),
            'searchUrl' => $this->generateUrl('app_pub_search', $params),
        ]);
    }
}
