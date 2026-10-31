<?php

namespace App\Controller\pub;

use App\Service\JournalsByDatabaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Página pública com os periódicos em que o CECH publica, por base de indexação e por área CAPES
 * (docs/evolutions/periodicos-por-base.md, A1).
 *
 * As URLs válidas são em número finito (visão geral, bases somadas + 5 bases, cada uma com "sem filtro" ou uma das áreas), e é isso que
 * limita o cache de páginas: `area` inválido responde 404 e parâmetros extras redirecionam, e o `PageCacheService` não grava nenhum dos dois.
 */
class JournalController extends AbstractController
{
    private const AREA_PATTERN = '/^[a-z0-9-]{1,80}$/';

    public function __construct(private readonly JournalsByDatabaseService $journals)
    {
    }

    #[Route('/periodicos', name: 'app_pub_journals_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $area = $this->resolveArea($request);
        if ($redirect = $this->redirectToCanonical($request, $area, 'app_pub_journals_index')) {
            return $redirect;
        }

        return $this->render('pub/journal/index.html.twig', [
            'overview' => $this->journals->getOverview($area['id'] ?? null),
            'areaOptions' => $this->journals->getAreaOptions(),
            'selectedArea' => $area['slug'] ?? '',
        ]);
    }

    #[Route('/periodicos/geral', name: 'app_pub_journals_general', methods: ['GET'])]
    public function general(Request $request): Response
    {
        $area = $this->resolveArea($request);
        if ($redirect = $this->redirectToCanonical($request, $area, 'app_pub_journals_general')) {
            return $redirect;
        }

        return $this->render('pub/journal/general.html.twig', [
            'section' => $this->journals->getGeneralList($area['id'] ?? null),
            'areaOptions' => $this->journals->getAreaOptions(),
            'selectedArea' => $area['slug'] ?? '',
        ]);
    }

    #[Route('/periodicos/{base}', name: 'app_pub_journals_database', requirements: ['base' => 'scopus|pubmed|scielo|wos|latindex'], methods: ['GET'])]
    public function database(string $base, Request $request): Response
    {
        $area = $this->resolveArea($request);
        if ($redirect = $this->redirectToCanonical($request, $area, 'app_pub_journals_database', ['base' => $base])) {
            return $redirect;
        }

        return $this->render('pub/journal/database.html.twig', [
            'acronym' => $base,
            'section' => $this->journals->getDatabaseList($base, $area['id'] ?? null),
            'areaOptions' => $this->journals->getAreaOptions(),
            'selectedArea' => $area['slug'] ?? '',
        ]);
    }

    /**
     * Área escolhida no filtro (`?area=<slug de capes_areas>`). Vazio ou ausente é "todas as áreas"; formato inválido,
     * valor que não seja texto (`area[]=x`) ou slug inexistente respondem 404.
     *
     * @return array{id: int, slug: string, name: string, level: int, parent_id: int|null}|null
     */
    private function resolveArea(Request $request): ?array
    {
        $slug = $request->query->all()['area'] ?? '';
        if ($slug === '') {
            return null;
        }

        if (!is_string($slug) || !preg_match(self::AREA_PATTERN, $slug)) {
            throw $this->createNotFoundException('Área não encontrada.');
        }

        return $this->journals->resolveAreaSlug($slug)
            ?? throw $this->createNotFoundException('Área não encontrada.');
    }

    /**
     * Na URL canônica, a query string tem só `area`, e só quando há filtro. O cache de páginas grava um arquivo por URL e
     * estas páginas têm centenas de KB: sem isto, cada `?utm_x=1` criaria uma cópia. Redirecionamentos não entram no cache.
     *
     * @param array{slug: string}|null $area
     * @param array<string, string> $routeParams
     */
    private function redirectToCanonical(Request $request, ?array $area, string $route, array $routeParams = []): ?Response
    {
        $canonicalQuery = $area !== null ? ['area' => $area['slug']] : [];
        if ($request->query->all() === $canonicalQuery) {
            return null;
        }

        return $this->redirectToRoute($route, $routeParams + $canonicalQuery, Response::HTTP_MOVED_PERMANENTLY);
    }
}
