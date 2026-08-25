<?php

namespace App\Controller\pub;

use App\Entity\Education;
use App\Entity\Orientation;
use App\Entity\ProductionItem;
use App\Entity\Researcher;
use App\Repository\ProductionItemRepository;
use App\Repository\ResearcherRepository;
use App\Service\Export\CurriculumExporterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProfessorController extends AbstractController
{
    public function __construct(
        private readonly ResearcherRepository $researcherRepo,
        private readonly ProductionItemRepository $productionRepo,
        private readonly CurriculumExporterService $exporter,
        private readonly \App\Service\Thesaurus\AuthorResolverService $authorResolver
    ) {}

    #[Route('/professor/{slugOrId}', name: 'app_pub_professor_show')]
    public function show(string $slugOrId): Response
    {
        $researcher = $this->researcherRepo->findWithAllDetails($slugOrId);

        if (!$researcher) {
            throw $this->createNotFoundException('Pesquisador não encontrado.');
        }

        // 1. Categorize all production items
        $articles = [];
        $books = [];
        $chapters = [];
        $newspaperTexts = [];
        $events = [];
        $techWorks = [];
        $software = [];
        $patents = [];
        $artistic = [];
        $otherProds = [];

        $totalWithDoi = 0;
        $allYears = [];

        foreach ($researcher->getProductions() as $prod) {
            if ($prod->getDoi()) {
                $totalWithDoi++;
            }
            if ($prod->getYear()) {
                $allYears[$prod->getYear()] = true;
            }

            match ($prod->getItemType()) {
                ProductionItem::TYPE_ARTIGO => $articles[] = $prod,
                ProductionItem::TYPE_LIVRO => $books[] = $prod,
                ProductionItem::TYPE_CAPITULO => $chapters[] = $prod,
                ProductionItem::TYPE_TEXTO_JORNAL => $newspaperTexts[] = $prod,
                ProductionItem::TYPE_EVENTO => $events[] = $prod,
                ProductionItem::TYPE_TRABALHO_TECNICO => $techWorks[] = $prod,
                ProductionItem::TYPE_SOFTWARE => $software[] = $prod,
                ProductionItem::TYPE_PATENTE, ProductionItem::TYPE_MARCA => $patents[] = $prod,
                ProductionItem::TYPE_ARTISTICA => $artistic[] = $prod,
                default => $otherProds[] = $prod,
            };
        }

        // Sort items in each category by year DESC
        $sortFn = fn($a, $b) => ($b->getYear() ?? 0) <=> ($a->getYear() ?? 0);
        usort($articles, $sortFn);
        usort($books, $sortFn);
        usort($chapters, $sortFn);
        usort($newspaperTexts, $sortFn);
        usort($events, $sortFn);
        usort($techWorks, $sortFn);
        usort($software, $sortFn);
        usort($patents, $sortFn);
        usort($artistic, $sortFn);
        usort($otherProds, $sortFn);

        // 2. Categorize Orientations (Completed & Ongoing) with Level Breakdown
        $completedOrientations = [];
        $ongoingOrientations = [];

        $orientationsCount = [
            'doutorado_concluido' => 0,
            'mestrado_concluido' => 0,
            'pos_doc_concluido' => 0,
            'tcc_concluido' => 0,
            'iniciacao_concluido' => 0,
            'especializacao_concluido' => 0,
            'outras_concluido' => 0,
            'doutorado_andamento' => 0,
            'mestrado_andamento' => 0,
            'pos_doc_andamento' => 0,
            'tcc_andamento' => 0,
            'iniciacao_andamento' => 0,
            'especializacao_andamento' => 0,
            'outras_andamento' => 0,
        ];

        foreach ($researcher->getOrientations() as $orientation) {
            $isAndamento = $orientation->getNature() === Orientation::NATURE_EM_ANDAMENTO || stripos($orientation->getNature(), 'Andamento') !== false;
            $type = $orientation->getOrientationType();

            if ($orientation->getYear()) {
                $allYears[$orientation->getYear()] = true;
            }

            if ($isAndamento) {
                $ongoingOrientations[] = $orientation;
                match ($type) {
                    Orientation::TYPE_DOUTORADO => $orientationsCount['doutorado_andamento']++,
                    Orientation::TYPE_MESTRADO => $orientationsCount['mestrado_andamento']++,
                    Orientation::TYPE_POS_DOUTORADO => $orientationsCount['pos_doc_andamento']++,
                    Orientation::TYPE_TCC_GRADUACAO => $orientationsCount['tcc_andamento']++,
                    Orientation::TYPE_INICIACAO_CIENTIFICA => $orientationsCount['iniciacao_andamento']++,
                    Orientation::TYPE_ESPECIALIZACAO => $orientationsCount['especializacao_andamento']++,
                    default => $orientationsCount['outras_andamento']++,
                };
            } else {
                $completedOrientations[] = $orientation;
                match ($type) {
                    Orientation::TYPE_DOUTORADO => $orientationsCount['doutorado_concluido']++,
                    Orientation::TYPE_MESTRADO => $orientationsCount['mestrado_concluido']++,
                    Orientation::TYPE_POS_DOUTORADO => $orientationsCount['pos_doc_concluido']++,
                    Orientation::TYPE_TCC_GRADUACAO => $orientationsCount['tcc_concluido']++,
                    Orientation::TYPE_INICIACAO_CIENTIFICA => $orientationsCount['iniciacao_concluido']++,
                    Orientation::TYPE_ESPECIALIZACAO => $orientationsCount['especializacao_concluido']++,
                    default => $orientationsCount['outras_concluido']++,
                };
            }
        }

        $sortOrientFn = fn($a, $b) => ($b->getYear() ?? 0) <=> ($a->getYear() ?? 0);
        usort($completedOrientations, $sortOrientFn);
        usort($ongoingOrientations, $sortOrientFn);

        // 3. Build Timeline Datasets for Chart.js
        ksort($allYears);
        $timelineYears = array_keys($allYears);
        if (empty($timelineYears)) {
            $timelineYears = [(int)date('Y')];
        }

        // Fill complete continuous range of years for clean charting
        $minYear = min($timelineYears);
        $maxYear = max($timelineYears);
        $continuousYears = range(max(1980, $minYear), max((int)date('Y'), $maxYear));

        $productionTimeline = [
            'artigos' => array_fill_keys($continuousYears, 0),
            'livros_capitulos' => array_fill_keys($continuousYears, 0),
            'jornais_revistas' => array_fill_keys($continuousYears, 0),
            'eventos' => array_fill_keys($continuousYears, 0),
            'tecnicos_inovacao' => array_fill_keys($continuousYears, 0),
            'outras' => array_fill_keys($continuousYears, 0),
        ];

        foreach ($researcher->getProductions() as $prod) {
            $y = $prod->getYear();
            if (!$y || !isset($productionTimeline['artigos'][$y])) continue;

            match ($prod->getItemType()) {
                ProductionItem::TYPE_ARTIGO => $productionTimeline['artigos'][$y]++,
                ProductionItem::TYPE_LIVRO, ProductionItem::TYPE_CAPITULO => $productionTimeline['livros_capitulos'][$y]++,
                ProductionItem::TYPE_TEXTO_JORNAL => $productionTimeline['jornais_revistas'][$y]++,
                ProductionItem::TYPE_EVENTO => $productionTimeline['eventos'][$y]++,
                ProductionItem::TYPE_TRABALHO_TECNICO, ProductionItem::TYPE_SOFTWARE, ProductionItem::TYPE_PATENTE, ProductionItem::TYPE_MARCA => $productionTimeline['tecnicos_inovacao'][$y]++,
                default => $productionTimeline['outras'][$y]++,
            };
        }

        $orientationsTimeline = [
            'doutorado' => array_fill_keys($continuousYears, 0),
            'mestrado' => array_fill_keys($continuousYears, 0),
            'pos_doutorado' => array_fill_keys($continuousYears, 0),
            'tcc_graduacao' => array_fill_keys($continuousYears, 0),
            'iniciacao_cientifica' => array_fill_keys($continuousYears, 0),
            'especializacao' => array_fill_keys($continuousYears, 0),
            'outras' => array_fill_keys($continuousYears, 0),
        ];

        foreach ($completedOrientations as $orientation) {
            $y = $orientation->getYear();
            if (!$y || !isset($orientationsTimeline['doutorado'][$y])) continue;

            match ($orientation->getOrientationType()) {
                Orientation::TYPE_DOUTORADO => $orientationsTimeline['doutorado'][$y]++,
                Orientation::TYPE_MESTRADO => $orientationsTimeline['mestrado'][$y]++,
                Orientation::TYPE_POS_DOUTORADO => $orientationsTimeline['pos_doutorado'][$y]++,
                Orientation::TYPE_TCC_GRADUACAO => $orientationsTimeline['tcc_graduacao'][$y]++,
                Orientation::TYPE_INICIACAO_CIENTIFICA => $orientationsTimeline['iniciacao_cientifica'][$y]++,
                Orientation::TYPE_ESPECIALIZACAO => $orientationsTimeline['especializacao'][$y]++,
                default => $orientationsTimeline['outras'][$y]++,
            };
        }

        // Yearly production simple count
        $yearlyProduction = [];
        foreach ($continuousYears as $y) {
            $totalInYear = $productionTimeline['artigos'][$y]
                + $productionTimeline['livros_capitulos'][$y]
                + $productionTimeline['jornais_revistas'][$y]
                + $productionTimeline['eventos'][$y]
                + $productionTimeline['tecnicos_inovacao'][$y]
                + $productionTimeline['outras'][$y];
            if ($totalInYear > 0 || (isset($allYears[$y]))) {
                $yearlyProduction[$y] = $totalInYear;
            }
        }

        // Category distribution for donut chart
        $categoryDistribution = [
            'Artigos em Periódicos' => count($articles),
            'Livros e Capítulos' => count($books) + count($chapters),
            'Textos em Jornais/Revistas' => count($newspaperTexts),
            'Trabalhos em Eventos' => count($events),
            'Trabalhos Técnicos' => count($techWorks),
            'Inovação (Softwares & Patentes)' => count($software) + count($patents),
            'Produção Artística' => count($artistic),
            'Outras Produções' => count($otherProds),
        ];
        $categoryDistribution = array_filter($categoryDistribution, fn($v) => $v > 0);

        // 4. Calculate KPI statistics
        $totalProds = count($researcher->getProductions());
        $percentWithDoi = $totalProds > 0 ? round(($totalWithDoi / $totalProds) * 100, 1) : 0;
        $articlesWithDoi = count(array_filter($articles, fn($p) => !empty($p->getDoi())));
        $percentArticlesWithDoi = count($articles) > 0 ? round(($articlesWithDoi / count($articles)) * 100, 1) : 0;

        $activeYearsCount = count($yearlyProduction) > 0 ? count($yearlyProduction) : 1;
        $avgPerYear = $activeYearsCount > 0 ? round($totalProds / $activeYearsCount, 1) : 0;

        $kpiStats = [
            'totalProductions' => $totalProds,
            'totalWithDoi' => $totalWithDoi,
            'percentWithDoi' => $percentWithDoi,
            'articlesCount' => count($articles),
            'articlesWithDoi' => $articlesWithDoi,
            'percentArticlesWithDoi' => $percentArticlesWithDoi,
            'booksCount' => count($books),
            'chaptersCount' => count($chapters),
            'eventsCount' => count($events),
            'techCount' => count($techWorks) + count($software) + count($patents),
            'newspaperCount' => count($newspaperTexts),
            'orientationsCompleted' => count($completedOrientations),
            'orientationsOngoing' => count($ongoingOrientations),
            'orientationsBreakdown' => $orientationsCount,
            'projectsCount' => count($researcher->getResearchProjects()),
            'boardsCount' => count($researcher->getExaminationBoards()),
            'eventsParticipatedCount' => count($researcher->getEventParticipations()),
            'awardsCount' => count($researcher->getAwards()),
            'firstYear' => $minYear,
            'lastYear' => $maxYear,
            'activeYearsCount' => $activeYearsCount,
            'averagePerYear' => $avgPerYear,
        ];

        // 5. Compute Top Co-authors / Collaborators
        $coauthorsMap = [];
        $myNames = array_map('mb_strtolower', array_filter(array_merge(
            [$researcher->getFullName()],
            explode(';', (string)$researcher->getCitationNames())
        )));

        foreach ($researcher->getProductions() as $prod) {
            foreach ($prod->getAuthors() as $author) {
                $rawName = trim($author->getCitationName() ?: $author->getAuthorName());
                if ($rawName === '' || mb_strlen($rawName) < 3) continue;

                $lower = mb_strtolower($rawName);
                $isSelf = false;
                foreach ($myNames as $myName) {
                    if ($myName !== '' && (str_contains($lower, $myName) || str_contains($myName, $lower))) {
                        $isSelf = true;
                        break;
                    }
                }
                if ($isSelf) continue;

                if (!isset($coauthorsMap[$rawName])) {
                    $coauthorsMap[$rawName] = [
                        'name' => $rawName,
                        'count' => 0,
                        'researcher' => null,
                    ];
                }
                $coauthorsMap[$rawName]['count']++;
            }
        }

        uasort($coauthorsMap, fn($a, $b) => $b['count'] <=> $a['count']);
        $topCoauthors = array_slice($coauthorsMap, 0, 12, true);

        // Check if any coauthors match teachers in our database to link their profile (via cached authorResolver)
        foreach ($topCoauthors as $key => &$coauthorData) {
            $resolved = $this->authorResolver->resolveAuthorData($coauthorData['name']);
            if ($resolved && $resolved['researcher'] !== null && $resolved['researcher']['id'] !== $researcher->getId()) {
                $coauthorData['researcher'] = [
                    'slug' => $resolved['researcher']['slug'],
                    'idLattes' => $resolved['researcher']['idLattes'],
                    'fullName' => $resolved['researcher']['fullName'],
                    'department' => $resolved['researcher']['department'],
                ];
            }
        }
        unset($coauthorData);

        // 6. Compute Research Keyword / Topic Cloud
        $stopWords = [
            'de', 'da', 'do', 'das', 'dos', 'em', 'no', 'na', 'nos', 'nas', 'por', 'para', 'com', 'sem',
            'uma', 'um', 'umas', 'uns', 'o', 'a', 'os', 'as', 'e', 'ou', 'se', 'que', 'como', 'sob', 'sobre',
            'estudo', 'analise', 'análise', 'pesquisa', 'brasil', 'educacao', 'educação', 'ensino', 'caso',
            'desenvolvimento', 'processo', 'reflexoes', 'perspectivas', 'proposta', 'aspectos', 'artigo',
            'the', 'and', 'for', 'with', 'from', 'about', 'study', 'analysis', 'brazil', 'education', 'social'
        ];
        $keywordsMap = [];

        foreach ($researcher->getProductions() as $prod) {
            $tokens = preg_split('/[\s\-_,.:;()\/\'"]+/u', mb_strtolower($prod->getTitle()));
            foreach ($tokens as $token) {
                $token = trim($token);
                if (mb_strlen($token) >= 4 && !in_array($token, $stopWords, true) && !is_numeric($token)) {
                    $keywordsMap[$token] = ($keywordsMap[$token] ?? 0) + 1;
                }
            }
        }

        foreach ($researcher->getResearchProjects() as $proj) {
            $tokens = preg_split('/[\s\-_,.:;()\/\'"]+/u', mb_strtolower($proj->getName()));
            foreach ($tokens as $token) {
                $token = trim($token);
                if (mb_strlen($token) >= 4 && !in_array($token, $stopWords, true) && !is_numeric($token)) {
                    $keywordsMap[$token] = ($keywordsMap[$token] ?? 0) + 2;
                }
            }
        }

        arsort($keywordsMap);
        $topKeywords = array_slice($keywordsMap, 0, 24, true);

        return $this->render('pub/professor/show.html.twig', [
            'researcher' => $researcher,
            'articles' => $articles,
            'books' => $books,
            'chapters' => $chapters,
            'newspaperTexts' => $newspaperTexts,
            'events' => $events,
            'techWorks' => $techWorks,
            'software' => $software,
            'patents' => $patents,
            'artistic' => $artistic,
            'otherProds' => $otherProds,
            'completedOrientations' => $completedOrientations,
            'ongoingOrientations' => $ongoingOrientations,
            'timelineYears' => $continuousYears,
            'productionTimeline' => $productionTimeline,
            'orientationsTimeline' => $orientationsTimeline,
            'yearlyProduction' => $yearlyProduction,
            'categoryDistribution' => $categoryDistribution,
            'kpiStats' => $kpiStats,
            'topCoauthors' => $topCoauthors,
            'topKeywords' => $topKeywords,
        ]);
    }

    #[Route('/professor/{slugOrId}/export/{format}', name: 'app_pub_professor_export', requirements: ['format' => 'bibtex|json|csv'])]
    public function export(string $slugOrId, string $format): Response
    {
        $researcher = null;
        if (ctype_digit($slugOrId)) {
            $researcher = $this->researcherRepo->findOneBy(['idLattes' => $slugOrId])
                ?: $this->researcherRepo->find((int)$slugOrId);
        }

        if (!$researcher) {
            $researcher = $this->researcherRepo->findOneBy(['slug' => $slugOrId]);
        }

        if (!$researcher) {
            throw $this->createNotFoundException('Pesquisador não encontrado.');
        }

        return match ($format) {
            'bibtex' => $this->exporter->exportBibtex($researcher),
            'json' => $this->exporter->exportSingleJson($researcher),
            'csv' => $this->exporter->exportSingleCsv($researcher),
            default => throw $this->createNotFoundException('Formato de exportação não suportado.'),
        };
    }
}
