<?php

namespace App\Controller\admin;

use App\Support\ImageUpload;
use App\Service\PageCacheService;
use App\Repository\SiteSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/seo')]
class AdminSeoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SiteSettingRepository $siteSettingRepo,
        private readonly PageCacheService $pageCache
    ) {}

    #[Route('/', name: 'app_admin_seo_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $settings = $this->siteSettingRepo->getSettings();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_seo', (string)$request->request->get('_token'))) {
                $this->addFlash('error', 'Token de segurança inválido. Recarregue a página e tente de novo.');
                return $this->redirectToRoute('app_admin_seo_index');
            }

            /** @var UploadedFile|null $ogImageFile */
            $ogImageFile = $request->files->get('ogImageFile');
            $ogImageExtension = null;
            if ($ogImageFile instanceof UploadedFile) {
                $ogImageExtension = ImageUpload::extension($ogImageFile);
                if ($ogImageExtension === null) {
                    $this->addFlash('error', 'A imagem de compartilhamento precisa ser ' . ImageUpload::acceptedLabel() . '. Nada foi alterado.');
                    return $this->redirectToRoute('app_admin_seo_index');
                }
            }

            $googleAnalyticsId = trim((string)$request->request->get('googleAnalyticsId'));
            $googleSearchConsole = trim((string)$request->request->get('googleSearchConsoleVerification'));
            $seoTitle = trim((string)$request->request->get('seoTitle'));
            $seoDescription = trim((string)$request->request->get('seoDescription'));
            $seoKeywords = trim((string)$request->request->get('seoKeywords'));
            $baseUrl = trim((string)$request->request->get('baseUrl'));
            $robotsTxtContent = trim((string)$request->request->get('robotsTxtContent'));

            $settings->setGoogleAnalyticsId($googleAnalyticsId ?: null);
            $settings->setGoogleSearchConsoleVerification($googleSearchConsole ?: null);
            if ($seoTitle !== '') $settings->setSeoTitle($seoTitle);
            if ($seoDescription !== '') $settings->setSeoDescription($seoDescription);
            if ($seoKeywords !== '') $settings->setSeoKeywords($seoKeywords);
            if ($baseUrl !== '') $settings->setBaseUrl($baseUrl);
            $settings->setRobotsTxtContent($robotsTxtContent ?: null);

            if ($ogImageFile instanceof UploadedFile && $ogImageExtension !== null) {
                $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/seo';
                if (!is_dir($uploadsDir)) {
                    @mkdir($uploadsDir, 0755, true);
                }
                $newFilename = 'og-image-' . uniqid() . '.' . $ogImageExtension;
                $ogImageFile->move($uploadsDir, $newFilename);
                $settings->setOgImage('/uploads/seo/' . $newFilename);
            }

            $this->em->flush();
            // Título, descrição e Google Analytics vão no HTML de todas as páginas: o cache de páginas precisa ser refeito
            $this->pageCache->clearCache();

            $this->addFlash('success', 'Configurações de SEO, Google Analytics e Meta Tags salvas com sucesso!');
            return $this->redirectToRoute('app_admin_seo_index');
        }

        return $this->render('admin/seo/index.html.twig', [
            'settings' => $settings,
        ]);
    }
}
