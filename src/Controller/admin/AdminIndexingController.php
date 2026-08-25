<?php

namespace App\Controller\admin;

use App\Entity\Researcher;
use App\Repository\ResearcherRepository;
use App\Service\Indexing\CurriculumNormalizationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/indexing')]
class AdminIndexingController extends AbstractController
{
    public function __construct(
        private readonly ResearcherRepository $researcherRepo,
        private readonly CurriculumNormalizationService $normalizationService
    ) {}

    #[Route('', name: 'app_admin_indexing_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $dept = $request->query->get('dept');
        $overview = $this->normalizationService->getIndexingOverview($dept);
        $departments = $this->researcherRepo->findAllDistinctDepartments();

        return $this->render('admin/indexing/index.html.twig', [
            'overview' => $overview,
            'departments' => $departments,
            'selectedDept' => $dept,
        ]);
    }

    #[Route('/queue', name: 'app_admin_indexing_queue', methods: ['GET'])]
    public function getQueue(Request $request): JsonResponse
    {
        $dept = $request->query->get('dept');
        $onlyPending = $request->query->getBoolean('only_pending', false);

        $qb = $this->researcherRepo->createQueryBuilder('r')
            ->select('r.id', 'r.fullName', 'r.idLattes', 'r.department', 'r.departmentCode', 'r.admissionYear', 'r.leaveYear', 'r.status', 'r.lastIndexedAt')
            ->orderBy('r.fullName', 'ASC');

        if ($dept && $dept !== 'all') {
            $qb->andWhere('r.department = :dept OR r.departmentCode = :dept')
               ->setParameter('dept', $dept);
        }

        if ($onlyPending) {
            $qb->andWhere('r.lastIndexedAt IS NULL');
        }

        $items = $qb->getQuery()->getArrayResult();
        $currentYear = (int)date('Y');

        return $this->json([
            'success' => true,
            'total' => count($items),
            'items' => array_map(function($item) use ($currentYear) {
                $isActive = $item['status'] && ($item['leaveYear'] === null || (int)$item['leaveYear'] >= $currentYear);
                $adm = $item['admissionYear'] ? (int)$item['admissionYear'] : null;
                $leave = $item['leaveYear'] ? (int)$item['leaveYear'] : null;
                
                $period = $adm 
                    ? ($isActive ? "Desde {$adm}" : "{$adm}–" . ($leave ?: $currentYear))
                    : ($isActive ? 'Ativo' : 'Histórico');

                return [
                    'id' => (int)$item['id'],
                    'fullName' => $item['fullName'],
                    'idLattes' => $item['idLattes'],
                    'department' => $item['department'],
                    'admissionYear' => $adm,
                    'leaveYear' => $leave,
                    'isActive' => $isActive,
                    'periodLabel' => $period,
                    'isIndexed' => $item['lastIndexedAt'] !== null,
                ];
            }, $items),
        ]);
    }

    #[Route('/step/{id}', name: 'app_admin_indexing_step', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function processStep(Researcher $researcher): JsonResponse
    {
        $startTime = microtime(true);
        $stats = $this->normalizationService->normalizeResearcher($researcher);
        $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);

        return $this->json([
            'success' => true,
            'researcherId' => $researcher->getId(),
            'researcherName' => $researcher->getFullName(),
            'idLattes' => $researcher->getIdLattes(),
            'stats' => $stats,
            'elapsedMs' => $elapsedMs,
        ]);
    }
}
