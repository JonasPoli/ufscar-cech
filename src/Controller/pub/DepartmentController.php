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
        // O mesmo departamento aparece com códigos e nomes diferentes (CS e DCSo, por exemplo). A lista junta as grafias
        // equivalentes, como o cartão de /departamentos e o panorama da própria página já fazem.
        $equivalents = ResearcherRepository::getDepartmentFilterValues($codeOrSlug);
        $researchers = $this->researcherRepo->createQueryBuilder('r')
            ->where('r.departmentCode IN (:values) OR r.department IN (:values)')
            ->setParameter('values', $equivalents)
            ->orderBy('r.fullName', 'ASC')
            ->getQuery()
            ->getResult();

        if (empty($researchers)) {
            throw $this->createNotFoundException('Departamento não encontrado.');
        }

        $deptName = $researchers[0]->getDepartment() ?: $codeOrSlug;
        $deptCode = isset(ResearcherRepository::DEPARTMENT_MAP[$codeOrSlug]) ? $codeOrSlug : ($researchers[0]->getDepartmentCode() ?: $codeOrSlug);

        $totalProductions = $this->productionRepo->countByDepartmentValues($equivalents);

        // Uma consulta para todos os cartões: `r.productions|length` no Twig carregava todas as produções de cada docente
        $productionCounts = $this->productionRepo->countPerResearcher(array_map(static fn($r) => (int)$r->getId(), $researchers));

        $filter = new ResearchFilter();
        $filter->department = $deptCode;

        return $this->render('pub/department/show.html.twig', [
            'deptName' => $deptName,
            'deptCode' => $deptCode,
            'researchers' => $researchers,
            'totalProductions' => $totalProductions,
            'productionCounts' => $productionCounts,
            'deptMetrics' => $this->metrics->metrics($filter),
            'deptPairs' => $this->graph->topPairs($filter, 6),
            'deptGraphSrc' => $this->generateUrl('app_api_v1_graph', ['departamento' => $deptCode, 'ligacoes' => 150]),
            'deptPairSrc' => $this->generateUrl('app_api_v1_graph_pair', ['departamento' => $deptCode]),
        ]);
    }
}
