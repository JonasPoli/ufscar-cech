<?php

declare(strict_types=1);

namespace App\Controller\pub;

use App\Entity\ThematicTerm;
use App\Repository\ThematicTermRepository;
use App\Repository\ThematicTermResearcherRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ThematicSearchController extends AbstractController
{
    public function __construct(
        private readonly ThematicTermRepository $termRepo,
        private readonly ThematicTermResearcherRepository $researcherRepo
    ) {
    }

    #[Route('/temas', name: 'app_pub_thematic_search', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $termSlugOrId = trim((string)$request->query->get('t', ''));
        $selectedTerm = null;
        $initialResearchers = [];
        $initialDepartments = [];
        $hasMore = false;
        $totalResearchersForTerm = 0;

        if ($termSlugOrId !== '') {
            $selectedTerm = $this->termRepo->findBySlugOrId($termSlugOrId);
            if ($selectedTerm) {
                $totalResearchersForTerm = $this->researcherRepo->countResearchersForTerm($selectedTerm);
                $initialResearchers = $this->researcherRepo->getResearchersForTerm($selectedTerm, 0, 10);
                $initialDepartments = $this->researcherRepo->getTopDepartmentsForTerm($selectedTerm, 4);
                $hasMore = $totalResearchersForTerm > 10;
            }
        }

        $topFeaturedTerms = $this->termRepo->getTopFeaturedTerms(24);
        $globalStats = $this->termRepo->getGlobalStats();

        return $this->render('pub/thematic_search/index.html.twig', [
            'selectedTerm' => $selectedTerm,
            'initialResearchers' => $initialResearchers,
            'initialDepartments' => $initialDepartments,
            'hasMore' => $hasMore,
            'totalResearchersForTerm' => $totalResearchersForTerm,
            'topFeaturedTerms' => $topFeaturedTerms,
            'globalStats' => $globalStats,
        ]);
    }

    #[Route('/api/temas/autocomplete', name: 'app_pub_thematic_search_autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request): JsonResponse
    {
        $query = trim((string)$request->query->get('q', ''));
        if (mb_strlen($query) < 3) {
            return new JsonResponse([
                'query' => $query,
                'count' => 0,
                'terms' => [],
            ]);
        }

        $terms = $this->termRepo->searchTerms($query, 24);

        return new JsonResponse([
            'query' => $query,
            'count' => count($terms),
            'terms' => $terms,
        ]);
    }

    #[Route('/api/temas/docentes', name: 'app_pub_thematic_search_researchers', methods: ['GET'])]
    public function getResearchers(Request $request): JsonResponse
    {
        $termId = $request->query->getInt('term_id');
        $termSlug = trim((string)$request->query->get('slug', ''));
        $offset = max(0, $request->query->getInt('offset', 0));
        $limit = max(1, min(50, $request->query->getInt('limit', 10)));

        /** @var ThematicTerm|null $term */
        $term = null;
        if ($termId > 0) {
            $term = $this->termRepo->find($termId);
        } elseif ($termSlug !== '') {
            $term = $this->termRepo->findBySlugOrId($termSlug);
        }

        if (!$term) {
            return new JsonResponse(['error' => 'Termo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $totalResearchers = $this->researcherRepo->countResearchersForTerm($term);
        $researchers = $this->researcherRepo->getResearchersForTerm($term, $offset, $limit);
        $topDepartments = ($offset === 0) ? $this->researcherRepo->getTopDepartmentsForTerm($term, 4) : [];

        $hasMore = ($offset + count($researchers)) < $totalResearchers;

        return new JsonResponse([
            'term' => [
                'id' => $term->getId(),
                'term' => $term->getTerm(),
                'slug' => $term->getSlug(),
                'totalOccurrences' => $term->getTotalOccurrences(),
                'researcherCount' => $term->getResearcherCount(),
                'sourceType' => $term->getSourceType(),
            ],
            'topDepartments' => $topDepartments,
            'researchers' => $researchers,
            'offset' => $offset,
            'limit' => $limit,
            'total' => $totalResearchers,
            'hasMore' => $hasMore,
            'nextOffset' => $offset + count($researchers),
        ]);
    }
}
