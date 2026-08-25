<?php

namespace App\Controller\pub;

use App\Repository\ResearcherRepository;
use App\Repository\SiteSettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SeoController extends AbstractController
{
    public function __construct(
        private readonly ResearcherRepository $researcherRepo,
        private readonly SiteSettingRepository $siteSettingRepo
    ) {}

    #[Route('/sitemap.xml', name: 'app_pub_sitemap', defaults: ['_format' => 'xml'])]
    public function sitemap(): Response
    {
        $settings = $this->siteSettingRepo->getSettings();
        $baseUrl = rtrim($settings->getBaseUrl(), '/');

        $urls = [];

        // Static routes
        $urls[] = [
            'loc' => $baseUrl . $this->generateUrl('app_pub_home'),
            'changefreq' => 'daily',
            'priority' => '1.0',
            'lastmod' => date('Y-m-d'),
        ];
        $urls[] = [
            'loc' => $baseUrl . $this->generateUrl('app_pub_indicators'),
            'changefreq' => 'weekly',
            'priority' => '0.8',
            'lastmod' => date('Y-m-d'),
        ];
        $urls[] = [
            'loc' => $baseUrl . $this->generateUrl('app_pub_department_list'),
            'changefreq' => 'weekly',
            'priority' => '0.8',
            'lastmod' => date('Y-m-d'),
        ];

        // Departments
        $departments = $this->researcherRepo->findTopDepartments(100);
        foreach ($departments as $dept) {
            $code = $dept['departmentCode'] ?: $dept['department'];
            $urls[] = [
                'loc' => $baseUrl . $this->generateUrl('app_pub_department_show', ['codeOrSlug' => $code]),
                'changefreq' => 'weekly',
                'priority' => '0.8',
                'lastmod' => date('Y-m-d'),
            ];
        }

        // All 414 Researchers
        $researchers = $this->researcherRepo->findAll();
        foreach ($researchers as $r) {
            $identifier = $r->getSlug() ?: $r->getIdLattes();
            $lastmod = $r->getLastLattesUpdate()
                ? $r->getLastLattesUpdate()->format('Y-m-d')
                : ($r->getUpdatedAt() ? $r->getUpdatedAt()->format('Y-m-d') : date('Y-m-d'));

            $urls[] = [
                'loc' => $baseUrl . $this->generateUrl('app_pub_professor_show', ['slugOrId' => $identifier]),
                'changefreq' => 'monthly',
                'priority' => '0.9',
                'lastmod' => $lastmod,
            ];
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach ($urls as $u) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($u['loc']) . "</loc>\n";
            $xml .= "    <lastmod>" . $u['lastmod'] . "</lastmod>\n";
            $xml .= "    <changefreq>" . $u['changefreq'] . "</changefreq>\n";
            $xml .= "    <priority>" . $u['priority'] . "</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>";

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }

    #[Route('/robots.txt', name: 'app_pub_robots', defaults: ['_format' => 'txt'])]
    public function robots(): Response
    {
        $settings = $this->siteSettingRepo->getSettings();
        $baseUrl = rtrim($settings->getBaseUrl(), '/');

        if ($settings->getRobotsTxtContent()) {
            $content = $settings->getRobotsTxtContent();
        } else {
            $content = "User-agent: *\n";
            $content .= "Disallow: /admin/\n";
            $content .= "Disallow: /login\n";
            $content .= "Allow: /\n\n";
            $content .= "Sitemap: " . $baseUrl . "/sitemap.xml\n";
        }

        return new Response($content, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
