<?php

namespace App\Controller\admin;

use App\Entity\AuthorIdentity;
use App\Entity\Country;
use App\Entity\Institution;
use App\Entity\ProductionItem;
use App\Entity\Researcher;
use App\Repository\ProductionItemRepository;
use App\Repository\ResearcherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin')]
class DashController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResearcherRepository $researcherRepo,
        private readonly ProductionItemRepository $productionRepo
    ) {}

    #[Route('/', name: 'app_admin_dashboard')]
    public function index(): Response
    {
        $stats = [
            'researchers' => $this->researcherRepo->count([]),
            'productions' => $this->productionRepo->count([]),
            'countries' => $this->em->getRepository(Country::class)->count([]),
            'institutions' => $this->em->getRepository(Institution::class)->count([]),
            'authors' => $this->em->getRepository(AuthorIdentity::class)->count([]),
        ];

        $recentResearchers = $this->researcherRepo->findBy([], ['id' => 'DESC'], 5);
        $topDepartments = $this->researcherRepo->findTopDepartments(6);

        return $this->render('admin/dash/index.html.twig', [
            'stats' => $stats,
            'recentResearchers' => $recentResearchers,
            'topDepartments' => $topDepartments,
        ]);
    }
}
