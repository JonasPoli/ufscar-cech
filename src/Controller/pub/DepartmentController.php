<?php

namespace App\Controller\pub;

use App\Repository\ProductionItemRepository;
use App\Repository\ResearcherRepository;
use App\Service\Research\CoauthorshipGraphService;
use App\Service\Research\ResearchFilter;
use App\Service\Research\ResearchMetricsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DepartmentController extends AbstractController
{
    public function __construct(
        private readonly ResearcherRepository $researcherRepo,
        private readonly ProductionItemRepository $productionRepo,
        private readonly ResearchMetricsService $metrics,
        private readonly CoauthorshipGraphService $graph,
    ) {}

    #[Route('/departamentos', name: 'app_pub_department_list')]
    public function index(): Response
    {
        $departments = $this->researcherRepo->findTopDepartments(100);

        return $this->render('pub/department/index.html.twig', [
            'departments' => $departments,
        ]);
    }

    #[Route('/departamento/{codeOrSlug}', name: 'app_pub_department_show')]
    public function show(string $codeOrSlug): Response
    {
        $researchers = $this->researcherRepo->findBy(['departmentCode' => $codeOrSlug], ['fullName' => 'ASC']);
        if (empty($researchers)) {
            $researchers = $this->researcherRepo->findBy(['department' => $codeOrSlug], ['fullName' => 'ASC']);
        }

        if (empty($researchers)) {
            throw $this->createNotFoundException('Departamento não encontrado.');
        }

        $deptName = $researchers[0]->getDepartment() ?: $codeOrSlug;
        $deptCode = $researchers[0]->getDepartmentCode() ?: $codeOrSlug;

        $totalProductions = $this->productionRepo->countByDepartment($deptCode, $deptName);

        $filter = new ResearchFilter();
        $filter->department = $deptCode;

        return $this->render('pub/department/show.html.twig', [
            'deptName' => $deptName,
            'deptCode' => $deptCode,
            'researchers' => $researchers,
            'totalProductions' => $totalProductions,
            'deptMetrics' => $this->metrics->metrics($filter),
            'deptPairs' => $this->graph->topPairs($filter, 6),
            'deptGraphSrc' => $this->generateUrl('app_api_v1_graph', ['departamento' => $deptCode, 'ligacoes' => 150]),
            'deptPairSrc' => $this->generateUrl('app_api_v1_graph_pair', ['departamento' => $deptCode]),
        ]);
    }
}
