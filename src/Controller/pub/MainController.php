<?php

namespace App\Controller\pub;

use App\Entity\ProductionItem;
use App\Entity\Researcher;
use App\Repository\OrientationRepository;
use App\Repository\ProductionItemRepository;
use App\Repository\ResearcherRepository;
use App\Service\StatisticsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MainController extends AbstractController
{
    public function __construct(
        private readonly ResearcherRepository $researcherRepo,
        private readonly ProductionItemRepository $productionRepo,
        private readonly OrientationRepository $orientationRepo,
        private readonly StatisticsService $statisticsService
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

        return $this->render('pub/main/indicadores.html.twig', array_merge([
            'summary' => $summary,
            'initialTab' => $isPrintAll ? 'all' : $tab,
            'isPrintAll' => $isPrintAll,
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

    #[Route('/busca', name: 'app_pub_search')]
    public function search(Request $request): Response
    {
        $q = trim((string)$request->query->get('q', ''));
        $type = trim((string)$request->query->get('type', 'all'));
        $department = trim((string)$request->query->get('dept', ''));
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = 15;

        $researchers = [];
        $productions = [];
        $orientations = [];
        $totalResearchers = 0;
        $totalProductions = 0;
        $totalOrientations = 0;

        if ($q !== '' || $department !== '') {
            if ($type === 'all' || $type === 'researchers') {
                $researchers = $this->researcherRepo->searchResearchers($q, $department, $page, $limit);
                $totalResearchers = $this->researcherRepo->countSearchResearchers($q, $department);
            }
            if ($type === 'all' || $type === 'productions') {
                $productions = $this->productionRepo->searchProductions($q, $department, $page, $limit);
                $totalProductions = $this->productionRepo->countSearchProductions($q, $department);
            }
            if ($type === 'all' || $type === 'orientations') {
                $orientations = $this->orientationRepo->searchOrientations($q, $department, $page, $limit);
                $totalOrientations = $this->orientationRepo->countSearchOrientations($q, $department);
            }
        }

        $allDepartments = $this->researcherRepo->findAllDistinctDepartments();

        return $this->render('pub/main/search.html.twig', [
            'q' => $q,
            'type' => $type,
            'department' => $department,
            'page' => $page,
            'limit' => $limit,
            'researchers' => $researchers,
            'productions' => $productions,
            'orientations' => $orientations,
            'totalResearchers' => $totalResearchers,
            'totalProductions' => $totalProductions,
            'totalOrientations' => $totalOrientations,
            'allDepartments' => $allDepartments,
        ]);
    }
}
