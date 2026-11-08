<?php

namespace App\Controller\pub;

use App\Entity\ProductionItem;
use App\Entity\Researcher;
use App\Repository\OrientationRepository;
use App\Repository\ProductionItemRepository;
use App\Repository\ResearcherRepository;
use App\Service\Research\ExpertSearchService;
use App\Service\Research\ResearchCatalogService;
use App\Service\Research\ResearchFilter;
use App\Service\Research\ResearchFilterFactory;
use App\Service\Research\ResearchMetricsService;
use App\Service\StatisticsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MainController extends AbstractController
{
    public const SEARCH_MODES = ['especialistas', 'publicacoes', 'pesquisador', 'orientacoes'];

    public function __construct(
        private readonly ResearcherRepository $researcherRepo,
        private readonly ProductionItemRepository $productionRepo,
        private readonly OrientationRepository $orientationRepo,
        private readonly StatisticsService $statisticsService,
        private readonly ResearchFilterFactory $filterFactory,
        private readonly ExpertSearchService $expertSearch,
        private readonly ResearchCatalogService $catalog,
        private readonly ResearchMetricsService $metrics,
    ) {}

    #[Route('/', name: 'app_pub_home')]
    public function index(): Response
    {
        $summary = $this->statisticsService->getGlobalSummary();

        $stats = [
            'researchers' => $summary['totalResearchers'],
            'allResearchers' => $summary['allResearchers'] ?? $this->researcherRepo->count([]),
            'productions' => $summary['totalProductions'],
            'uniqueProductions' => $summary['uniqueProductions'],
            'articles' => $summary['totalArticles'] ?? $this->productionRepo->count(['itemType' => ProductionItem::TYPE_ARTIGO]),
            'uniqueArticles' => $summary['uniqueArticles'] ?? $summary['uniqueArticlesQualis'],
            'articlesQualis' => $summary['totalArticlesQualis'],
            'uniqueArticlesQualis' => $summary['uniqueArticlesQualis'],
            'books' => $summary['totalBooks'] ?? $this->productionRepo->count(['itemType' => ProductionItem::TYPE_LIVRO]),
            'chapters' => $summary['totalChapters'] ?? $this->productionRepo->count(['itemType' => ProductionItem::TYPE_CAPITULO]),
            'booksAndChapters' => $summary['totalBooksAndChapters'],
            'uniqueBooksAndChapters' => $summary['uniqueBooksAndChapters'],
            'orientations' => $summary['totalOrientations'],
            'events' => $summary['totalEvents'] ?? $this->productionRepo->count(['itemType' => ProductionItem::TYPE_EVENTO]),
        ];

        $recentProductions = $this->productionRepo->findBy([], ['year' => 'DESC', 'id' => 'DESC'], 10);
        $topDepartments = $this->researcherRepo->findTopDepartments(8);
        $featuredResearchers = $this->researcherRepo->findFeaturedResearchers(8);
        $yearlyStats = $this->productionRepo->getProductionCountByYear(2015, (int)date('Y'));

        return $this->render('pub/main/home.html.twig', [
            'stats' => $stats,
            'summary' => $summary,
            'recentProductions' => $recentProductions,
            'topDepartments' => $topDepartments,
            'featuredResearchers' => $featuredResearchers,
            'yearlyStats' => $yearlyStats,
            'topTopics' => array_slice($this->catalog->topics(), 0, 14),
            'topicCount' => $this->catalog->topicCount(),
        ]);
    }

    #[Route('/indicadores', name: 'app_pub_indicators')]
    public function indicators(Request $request): Response
    {
        ini_set('memory_limit', '512M');

        $tab = $request->query->get('tab', 'faculty');
        $validTabs = ['faculty', 'training', 'production', 'network', 'all'];
        if (!in_array($tab, $validTabs, true)) {
            $tab = 'faculty';
        }

        $summary = $this->statisticsService->getGlobalSummary();
        $isPrintAll = $request->query->getBoolean('print') || $tab === 'all';

        // Carrega apenas os dados da aba inicial solicitada para manter baixo uso de memória
        $tabData = $this->getIndicatorTabData($isPrintAll ? 'all' : $tab);

        // Panorama filtrável (assunto, departamento, tema, período), calculado e guardado por filtro
        $panoramaFilter = $this->filterFactory->fromRequest($request);

        return $this->render('pub/main/indicadores.html.twig', array_merge([
            'summary' => $summary,
            'initialTab' => $isPrintAll ? 'all' : $tab,
            'isPrintAll' => $isPrintAll,
            'panoramaFilter' => $panoramaFilter,
            'panorama' => $isPrintAll ? null : $this->metrics->metrics($panoramaFilter),
            'departments' => $this->catalog->departments(),
            'topics' => $this->catalog->topics(),
        ], $tabData));
    }

    #[Route('/indicadores/fragment/{tab}', name: 'app_pub_indicators_fragment')]
    public function indicatorFragment(string $tab, Request $request): Response
    {
        ini_set('memory_limit', '512M');

        $validTabs = ['faculty', 'training', 'production', 'network'];
        if (!in_array($tab, $validTabs, true)) {
            throw $this->createNotFoundException('Aba de indicadores não encontrada.');
        }

        $tabData = $this->getIndicatorTabData($tab);

        $template = match ($tab) {
            'faculty' => 'pub/main/indicadores/_tab_faculty.html.twig',
            'training' => 'pub/main/indicadores/_tab_training.html.twig',
            'production' => 'pub/main/indicadores/_tab_production.html.twig',
            'network' => 'pub/main/indicadores/_tab_network.html.twig',
        };

        $response = $this->render($template, $tabData);
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }

    private function getIndicatorTabData(string $tab): array
    {
        $currentYear = (int)date('Y');

        if ($tab === 'faculty') {
            return [
                'fig1Dept' => $this->statisticsService->getFig1InstitutionalAffiliations(),
                'fig2Grad' => $this->statisticsService->getFig2UndergraduateDegrees(),
                'fig3Doc' => $this->statisticsService->getFig3DoctorateDegrees(10),
                'fig4Cnpq' => $this->statisticsService->getFig4KnowledgeAreas(),
                'fig6Geo' => $this->statisticsService->getFig6GeographicalDistribution(),
                'fig8Experiences' => $this->statisticsService->getFig8ProfessionalExperiences(),
                'fig16Sankey' => $this->statisticsService->getFig16AcademicTrajectoriesSankey(),
                'fig17Exits' => $this->statisticsService->getFig17FacultyExitsAndDestinations(),
            ];
        }

        if ($tab === 'training') {
            return [
                'fig7Graduations' => $this->statisticsService->getFig7OrientationsConcludedByYear(2010, $currentYear),
                'fig9Pyramid' => $this->statisticsService->getFig9AcademicLevelsPyramid(),
                'fig5StudentsGrad' => $this->statisticsService->getFig5StudentsUndergradCourses(10),
            ];
        }

        if ($tab === 'production') {
            return [
                'fig10Heatmap' => $this->statisticsService->getFig10ProductionHeatmapMatrix(2010, $currentYear),
                'fig11QualisTimeline' => $this->statisticsService->getFig11QualisVsNonQualisTimeline(2010, $currentYear),
                'fig12QualisStrata' => $this->statisticsService->getFig12QualisStratumTimeline(2010, $currentYear),
                'figQualisResearchers' => $this->statisticsService->getFigQualisResearchersRanking(),
                'figAcademicDatabases' => $this->statisticsService->getFigAcademicDatabases(2010, $currentYear),
            ];
        }

        if ($tab === 'network') {
            return [
                'fig13Coauthors' => $this->statisticsService->getFig13CoauthorshipNetwork(8),
                'fig13CoauthorsFull' => $this->statisticsService->getFig13CoauthorshipNetwork(0),
                'fig13MatrixPayload' => $this->statisticsService->getCoauthorshipMatrixPayload(),
                'fig14National' => $this->statisticsService->getFig14NationalPartners(10),
                'fig15International' => $this->statisticsService->getFig15InternationalPartners(10),
            ];
        }

        if ($tab === 'all') {
            return array_merge(
                $this->getIndicatorTabData('faculty'),
                $this->getIndicatorTabData('training'),
                $this->getIndicatorTabData('production'),
                $this->getIndicatorTabData('network')
            );
        }

        return [];
    }

    /**
     * Busca pública em quatro modos: especialistas (por assunto), publicações (por assunto, periódico ou DOI),
     * pesquisador (por nome) e teses/orientações. Os filtros são os do {@see ResearchFilter}.
     *
     * Os parâmetros antigos continuam valendo para os links já publicados: `type` (all, researchers, productions,
     * orientations), `dept` e `page`.
     */
    #[Route('/busca', name: 'app_pub_search')]
    public function search(Request $request): Response
    {
        $filter = $this->filterFactory->fromRequest($request);
        $mode = self::searchMode($request, $filter);
        $page = max(1, $request->query->getInt('pagina', $request->query->getInt('page', 1)));
        $sorts = $mode === 'publicacoes' ? ExpertSearchService::PUBLICATION_SORTS : ExpertSearchService::EXPERT_SORTS;
        $sort = (string)$request->query->get('ordem', '');
        if (!isset($sorts[$sort])) {
            $sort = ($filter->hasTextCriteria() || $mode === 'pesquisador') ? ($mode === 'pesquisador' ? 'nome' : 'relevancia') : 'recentes';
        }

        $result = null;
        $nameMatches = null;
        $orientations = [];
        $totalOrientations = 0;
        $hasSearch = !$filter->isEmpty();

        if ($hasSearch) {
            switch ($mode) {
                case 'publicacoes':
                    $result = $this->expertSearch->publications($filter, $sort, $page);
                    break;
                case 'pesquisador':
                    $result = $this->expertSearch->researchers($filter, $page);
                    break;
                case 'orientacoes':
                    $orientations = $this->orientationRepo->searchOrientations($filter, $page, 15);
                    $totalOrientations = $this->orientationRepo->countSearchOrientations($filter);
                    $result = ['count' => $totalOrientations, 'page' => $page, 'pages' => max(1, (int)ceil($totalOrientations / 15)), 'results' => []];
                    break;
                default:
                    $result = $this->expertSearch->experts($filter, $sort, $page);
                    // Quem digita um nome na busca rápida também vê os docentes com esse nome
                    if ($filter->q !== '' && $page === 1) {
                        $nameMatches = $this->expertSearch->researchers($filter, 1, 6);
                    }
            }
        }

        return $this->render('pub/main/search.html.twig', [
            'filter' => $filter,
            'mode' => $mode,
            'sort' => $sort,
            'sorts' => $sorts,
            'page' => $page,
            'result' => $result,
            'nameMatches' => $nameMatches,
            'orientations' => $orientations,
            'hasSearch' => $hasSearch,
            'allDepartments' => $this->catalog->departments(),
            'majorAreas' => $this->catalog->majorAreas(),
            'areas' => $this->catalog->areas(),
            'topTopics' => $hasSearch ? [] : array_slice($this->catalog->topics(), 0, 14),
            'currentYear' => (int)date('Y'),
            'heroStats' => $this->heroStats(),
        ]);
    }

    /**
     * Números da capa da busca (guardados no cache da pesquisa): docentes com produção, publicações únicas e assuntos.
     *
     * @return array{researchers: int, publications: int, topics: int}
     */
    private function heroStats(): array
    {
        $all = $this->metrics->metrics(new ResearchFilter(), false);

        return [
            'researchers' => (int)$all['researchers'],
            'publications' => (int)$all['publications'],
            'topics' => $this->catalog->topicCount(),
        ];
    }

    /** Modo da busca: `modo` na URL, o `type` antigo ou, para um DOI digitado, publicações. */
    public static function searchMode(Request $request, ResearchFilter $filter): string
    {
        $mode = (string)$request->query->get('modo', '');
        if (in_array($mode, self::SEARCH_MODES, true)) {
            return $mode;
        }

        $legacy = [
            'researchers' => 'pesquisador',
            'productions' => 'publicacoes',
            'orientations' => 'orientacoes',
        ];
        $type = (string)$request->query->get('type', '');
        if (isset($legacy[$type])) {
            return $legacy[$type];
        }

        if (preg_match('#^(https?://(dx\.)?doi\.org/)?10\.\d{4,}/#i', $filter->q)) {
            return 'publicacoes';
        }

        return 'especialistas';
    }
}
