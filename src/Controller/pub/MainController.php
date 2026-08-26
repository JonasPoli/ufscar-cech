<?php

namespace App\Controller\pub;

use App\Entity\ProductionItem;
use App\Entity\Researcher;
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
    public function indicators(): Response
    {
        ini_set('memory_limit', '512M');

        $summary = $this->statisticsService->getGlobalSummary();
        $fig1Dept = $this->statisticsService->getFig1InstitutionalAffiliations();
        $fig2Grad = $this->statisticsService->getFig2UndergraduateDegrees();
        $fig3Doc = $this->statisticsService->getFig3DoctorateDegrees(10);
        $fig4Cnpq = $this->statisticsService->getFig4KnowledgeAreas();
        $fig5StudentsGrad = $this->statisticsService->getFig5StudentsUndergradCourses(10);
        $fig6Geo = $this->statisticsService->getFig6GeographicalDistribution();
        $fig7Graduations = $this->statisticsService->getFig7OrientationsConcludedByYear(2010, (int)date('Y'));
        $fig8Experiences = $this->statisticsService->getFig8ProfessionalExperiences();
        $fig9Pyramid = $this->statisticsService->getFig9AcademicLevelsPyramid();
        $fig10Heatmap = $this->statisticsService->getFig10ProductionHeatmapMatrix(2010, (int)date('Y'));
        $fig11QualisTimeline = $this->statisticsService->getFig11QualisVsNonQualisTimeline(2010, (int)date('Y'));
        $fig12QualisStrata = $this->statisticsService->getFig12QualisStratumTimeline(2010, (int)date('Y'));
        $figAcademicDatabases = $this->statisticsService->getFigAcademicDatabases(2010, (int)date('Y'));
        $fig13Coauthors = $this->statisticsService->getFig13CoauthorshipNetwork(8);
        $fig14National = $this->statisticsService->getFig14NationalPartners(10);
        $fig15International = $this->statisticsService->getFig15InternationalPartners(10);
        $fig16Sankey = $this->statisticsService->getFig16AcademicTrajectoriesSankey();
        $fig17Exits = $this->statisticsService->getFig17FacultyExitsAndDestinations();

        return $this->render('pub/main/indicadores.html.twig', [
            'summary' => $summary,
            'fig1Dept' => $fig1Dept,
            'fig2Grad' => $fig2Grad,
            'fig3Doc' => $fig3Doc,
            'fig4Cnpq' => $fig4Cnpq,
            'fig5StudentsGrad' => $fig5StudentsGrad,
            'fig6Geo' => $fig6Geo,
            'fig7Graduations' => $fig7Graduations,
            'fig8Experiences' => $fig8Experiences,
            'fig9Pyramid' => $fig9Pyramid,
            'fig10Heatmap' => $fig10Heatmap,
            'fig11QualisTimeline' => $fig11QualisTimeline,
            'fig12QualisStrata' => $fig12QualisStrata,
            'figAcademicDatabases' => $figAcademicDatabases,
            'fig13Coauthors' => $fig13Coauthors,
            'fig14National' => $fig14National,
            'fig15International' => $fig15International,
            'fig16Sankey' => $fig16Sankey,
            'fig17Exits' => $fig17Exits,
        ]);
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
        $totalResearchers = 0;
        $totalProductions = 0;

        if ($q !== '' || $department !== '') {
            if ($type === 'all' || $type === 'researchers') {
                $researchers = $this->researcherRepo->searchResearchers($q, $department, $page, $limit);
                $totalResearchers = $this->researcherRepo->countSearchResearchers($q, $department);
            }
            if ($type === 'all' || $type === 'productions') {
                $productions = $this->productionRepo->searchProductions($q, $department, $page, $limit);
                $totalProductions = $this->productionRepo->countSearchProductions($q, $department);
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
            'totalResearchers' => $totalResearchers,
            'totalProductions' => $totalProductions,
            'allDepartments' => $allDepartments,
        ]);
    }
}
