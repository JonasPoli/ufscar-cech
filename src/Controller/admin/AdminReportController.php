<?php

namespace App\Controller\admin;

use App\Repository\OrientationRepository;
use App\Repository\ProductionItemRepository;
use App\Repository\ResearcherRepository;
use League\Csv\Writer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/reports')]
class AdminReportController extends AbstractController
{
    public function __construct(
        private readonly ResearcherRepository $researcherRepo,
        private readonly ProductionItemRepository $productionRepo,
        private readonly OrientationRepository $orientationRepo
    ) {}

    #[Route('/', name: 'app_admin_report_index', methods: ['GET'])]
    public function index(): Response
    {
        $topDepartments = $this->researcherRepo->findTopDepartments(50);
        $qualisStats = $this->productionRepo->getQualisDistribution();
        $typeStats = $this->productionRepo->getProductionCountByType();

        return $this->render('admin/report/index.html.twig', [
            'topDepartments' => $topDepartments,
            'qualisStats' => $qualisStats,
            'typeStats' => $typeStats,
        ]);
    }

    #[Route('/departments/export/{format}', name: 'app_admin_report_dept_export', methods: ['GET'])]
    public function exportDepartments(string $format): Response
    {
        $topDepartments = $this->researcherRepo->findTopDepartments(50);

        if ($format === 'json') {
            $response = new Response(json_encode($topDepartments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $response->headers->set('Content-Type', 'application/json');
            $response->headers->set('Content-Disposition', 'attachment; filename="relatorio_departamentos.json"');
            return $response;
        }

        $csv = Writer::createFromString('');
        $csv->insertOne(['codigo_departamento', 'departamento', 'total_docentes', 'total_producoes']);
        foreach ($topDepartments as $d) {
            $csv->insertOne([$d['departmentCode'], $d['department'], $d['totalFaculty'], $d['totalProductions']]);
        }

        $response = new Response("\xEF\xBB\xBF" . $csv->toString());
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="relatorio_departamentos.csv"');
        return $response;
    }

    #[Route('/qualis/export/{format}', name: 'app_admin_report_qualis_export', methods: ['GET'])]
    public function exportQualis(string $format): Response
    {
        $qualisStats = $this->productionRepo->getQualisDistribution();

        if ($format === 'json') {
            $response = new Response(json_encode($qualisStats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $response->headers->set('Content-Type', 'application/json');
            $response->headers->set('Content-Disposition', 'attachment; filename="relatorio_qualis.json"');
            return $response;
        }

        $csv = Writer::createFromString('');
        $csv->insertOne(['estrato_qualis', 'total_artigos']);
        foreach ($qualisStats as $q) {
            $csv->insertOne([$q['qualisStr'], $q['total']]);
        }

        $response = new Response("\xEF\xBB\xBF" . $csv->toString());
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="relatorio_qualis.csv"');
        return $response;
    }
}
