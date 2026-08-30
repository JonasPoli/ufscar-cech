<?php

namespace App\Controller\admin;

use App\Service\PageCacheService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/cache')]
class AdminCacheController extends AbstractController
{
    public function __construct(
        private readonly PageCacheService $pageCacheService
    ) {}

    #[Route('', name: 'app_admin_cache_index', methods: ['GET'])]
    public function index(): Response
    {
        $stats = $this->pageCacheService->getStats();
        $isEnabled = $this->pageCacheService->isEnabled();

        return $this->render('admin/cache/index.html.twig', [
            'stats' => $stats,
            'isEnabled' => $isEnabled,
            'ttlDays' => 30,
        ]);
    }

    #[Route('/clear', name: 'app_admin_cache_clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('clear_page_cache', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido. Ação cancelada.');
            return $this->redirectToRoute('app_admin_cache_index');
        }

        $success = $this->pageCacheService->clearCache();

        if ($success) {
            $this->addFlash('success', 'Cache de páginas públicas limpo com sucesso! A pasta de cache foi completamente removida.');
        } else {
            $this->addFlash('error', 'Ocorreu um erro ao tentar limpar a pasta de cache.');
        }

        return $this->redirectToRoute('app_admin_cache_index');
    }
}
