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

/**
 * Controlador público responsável pela exibição do perfil completo e exportações do currículo de um pesquisador.
 *
 * Realiza a agregação em memória de:
 * - Produções por categoria (artigos, livros, capítulos, eventos, softwares, patentes, artes).
 * - Orientações concluídas e em andamento por nível acadêmico.
 * - Séries temporais de produtividade e distribuição de estratos Qualis para gráficos.
 * - Exportações sob demanda em JSON, CSV e BibTeX.
 */
class ProfessorController extends AbstractController
{
    /**
     * @param ResearcherRepository $researcherRepo Repositório de pesquisadores
     * @param ProductionItemRepository $productionRepo Repositório de produções
     * @param CurriculumExporterService $exporter Serviço de exportação de currículos
     * @param \App\Service\Thesaurus\AuthorResolverService $authorResolver Serviço de resolução de autores
     */
    public function __construct(
        private readonly ResearcherRepository $researcherRepo,
        private readonly ProductionItemRepository $productionRepo,
        private readonly CurriculumExporterService $exporter,
        private readonly \App\Service\Thesaurus\AuthorResolverService $authorResolver
    ) {}

    /**
     * Exibe a página de perfil público completo do pesquisador (layout modular de 4 abas).
     *
     * @param string $slugOrId Slug textual amigável ou ID Lattes de 16 dígitos
     * @return Response Página HTML renderizada
     */
    #[Route('/professor/{slugOrId}', name: 'app_pub_professor_show')]
    public function show(string $slugOrId): Response
    {
        $researcher = $this->researcherRepo->findWithAllDetails($slugOrId);

        if (!$researcher) {
            throw $this->createNotFoundException('Pesquisador não encontrado.');
        }

        $allYears = [];
        $prods = $this->categorizeProductions($researcher, $allYears);
        $orients = $this->categorizeOrientations($researcher, $allYears);
        $kpiStats = $this->calculateKpiStats($researcher, $prods, $orients, $allYears);

        return $this->render('pub/professor/show.html.twig', array_merge([
            'researcher' => $researcher,
            'kpiStats' => $kpiStats,
        ], $prods, $orients));
    }

    /**
     * Renderiza o fragmento HTML de uma aba específica sob demanda (Lazy Loading).
     */
    #[Route('/professor/{slugOrId}/fragment/{tab}', name: 'app_pub_professor_fragment')]
    public function fragment(string $slugOrId, string $tab): Response
    {
        $researcher = $this->researcherRepo->findWithAllDetails($slugOrId);

        if (!$researcher) {
            throw $this->createNotFoundException('Pesquisador não encontrado.');
        }

        $allYears = [];
        $prods = $this->categorizeProductions($researcher, $allYears);
        $orients = $this->categorizeOrientations($researcher, $allYears);
        $kpiStats = $this->calculateKpiStats($researcher, $prods, $orients, $allYears);

        $response = match ($tab) {
            'productions' => $this->render('pub/professor/_tab_productions.html.twig', array_merge([
                'researcher' => $researcher,
                'kpiStats' => $kpiStats,
            ], $prods)),
            'orientations' => $this->render('pub/professor/_tab_orientations.html.twig', array_merge([
                'researcher' => $researcher,
                'kpiStats' => $kpiStats,
            ], $orients)),
            'activities' => $this->render('pub/professor/_tab_activities.html.twig', [
                'researcher' => $researcher,
                'kpiStats' => $kpiStats,
            ]),
            'analytics' => $this->render('pub/professor/_tab_analytics.html.twig', array_merge([
                'researcher' => $researcher,
                'kpiStats' => $kpiStats,
                'completedOrientations' => $orients['completedOrientations'],
                'academicDatabases' => $prods['academicDatabases'],
            ], $this->buildAnalyticsData($researcher, $prods['articles'], $orients['completedOrientations'], $allYears, $kpiStats, $prods['categoryDistribution']))),
            default => throw $this->createNotFoundException('Aba não encontrada.'),
        };

        $response->headers->set('Cache-Control', 'public, max-age=3600');
        return $response;
    }

    /**
     * Categoriza e ordena as produções científicas e calcula metadados de bases internacionais.
     */
    private function categorizeProductions(Researcher $researcher, array &$allYears = []): array
    {
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

        // Academic Databases Indexing Stats
        $totalsByDb = [];
        $timelineByDb = [];
        $dbMeta = [];
        $totalArticlesCount = count($articles);
        $totalIndexedArticlesCount = 0;
        $dbYearsRange = !empty($allYears) ? range(min(array_keys($allYears)), max(array_keys($allYears))) : range(2010, (int)date('Y'));

        $colorMap = [
            'scopus' => '#ea580c',
            'wos' => '#7c3aed',
            'web of science' => '#7c3aed',
            'latindex' => '#0d9488',
            'scielo' => '#e11d48',
            'pubmed' => '#2563eb',
            'doaj' => '#d97706',
            'openalex' => '#6366f1',
            'lens' => '#059669',
            'crossref' => '#0284c7',
        ];

        foreach ($articles as $art) {
            $y = $art->getYear() ? (int)$art->getYear() : null;
            $dbs = $art->getIndexedDatabases();
            if ($dbs !== null && !empty($dbs)) {
                $totalIndexedArticlesCount++;
                foreach ($dbs as $db) {
                    $name = $db['name'];
                    $acronym = strtolower((string)($db['acronym'] ?? $name));
                    if (!isset($totalsByDb[$name])) {
                        $totalsByDb[$name] = 0;
                        $timelineByDb[$name] = array_fill_keys($dbYearsRange, 0);
                        $dbMeta[$name] = [
                            'name' => $name,
                            'acronym' => $acronym,
                            'logo' => $db['logo'] ?? null,
                            'color' => $colorMap[$acronym] ?? $colorMap[$name] ?? ($db['color'] ?? '#64748b'),
                        ];
                    }
                    $totalsByDb[$name]++;
                    if ($y && isset($timelineByDb[$name][$y])) {
                        $timelineByDb[$name][$y]++;
                    }
                }
            }
        }

        arsort($totalsByDb);

        $dbRanking = [];
        foreach ($totalsByDb as $name => $count) {
            $dbRanking[] = [
                'name' => $name,
                'acronym' => $dbMeta[$name]['acronym'],
                'logo' => $dbMeta[$name]['logo'],
                'color' => $dbMeta[$name]['color'],
                'total' => $count,
                'percentage' => $totalArticlesCount > 0 ? round(($count / $totalArticlesCount) * 100, 1) : 0.0,
                'indexedPercentage' => $totalIndexedArticlesCount > 0 ? round(($count / $totalIndexedArticlesCount) * 100, 1) : 0.0,
            ];
        }

        $dbTimelineSeries = [];
        foreach (array_keys($totalsByDb) as $name) {
            $dbTimelineSeries[] = [
                'name' => $name,
                'acronym' => $dbMeta[$name]['acronym'],
                'color' => $dbMeta[$name]['color'],
                'data' => array_values($timelineByDb[$name]),
            ];
        }

        $academicDatabasesStats = [
            'totalArticles' => $totalArticlesCount,
            'totalIndexedArticles' => $totalIndexedArticlesCount,
            'indexedPercentage' => $totalArticlesCount > 0 ? round(($totalIndexedArticlesCount / $totalArticlesCount) * 100, 1) : 0.0,
            'ranking' => $dbRanking,
            'years' => $dbYearsRange,
            'timelineSeries' => $dbTimelineSeries,
        ];

        return [
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
            'totalWithDoi' => $totalWithDoi,
            'categoryDistribution' => $categoryDistribution,
            'academicDatabases' => $academicDatabasesStats,
        ];
    }

    /**
     * Categoriza e ordena as orientações concluídas e em andamento.
     */
    private function categorizeOrientations(Researcher $researcher, array &$allYears = []): array
    {
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
            $isAndamento = $orientation->getNature() === Orientation::NATURE_EM_ANDAMENTO || stripos((string)$orientation->getNature(), 'Andamento') !== false;
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

        return [
            'completedOrientations' => $completedOrientations,
            'ongoingOrientations' => $ongoingOrientations,
            'orientationsCount' => $orientationsCount,
        ];
    }

    /**
     * Calcula métricas agregadas de resumo (KPIs).
     */
    private function calculateKpiStats(Researcher $researcher, array $prods, array $orients, array $allYears): array
    {
        $totalProds = count($researcher->getProductions());
        $articles = $prods['articles'];
        $totalWithDoi = $prods['totalWithDoi'];
        $completedOrientations = $orients['completedOrientations'];
        $ongoingOrientations = $orients['ongoingOrientations'];
        $orientationsCount = $orients['orientationsCount'];

        $percentWithDoi = $totalProds > 0 ? round(($totalWithDoi / $totalProds) * 100, 1) : 0;
        $articlesWithDoi = count(array_filter($articles, fn($p) => !empty($p->getDoi())));
        $percentArticlesWithDoi = count($articles) > 0 ? round(($articlesWithDoi / count($articles)) * 100, 1) : 0;

        $minYear = !empty($allYears) ? min(array_keys($allYears)) : (int)date('Y');
        $maxYear = !empty($allYears) ? max(array_keys($allYears)) : (int)date('Y');
        $activeYearsCount = count($allYears) > 0 ? count($allYears) : 1;
        $avgPerYear = $activeYearsCount > 0 ? round($totalProds / $activeYearsCount, 1) : 0;

        return [
            'totalProductions' => $totalProds,
            'totalWithDoi' => $totalWithDoi,
            'percentWithDoi' => $percentWithDoi,
            'articlesCount' => count($articles),
            'articlesWithDoi' => $articlesWithDoi,
            'percentArticlesWithDoi' => $percentArticlesWithDoi,
            'booksCount' => count($prods['books']),
            'chaptersCount' => count($prods['chapters']),
            'eventsCount' => count($prods['events']),
            'techCount' => count($prods['techWorks']) + count($prods['software']) + count($prods['patents']),
            'newspaperCount' => count($prods['newspaperTexts']),
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
    }

    /**
     * Constrói os dados analíticos avançados para a Aba 4 (Timeline Chart.js, Rede de Coautoria, Nuvem de Palavras-Chave).
     */
    private function buildAnalyticsData(Researcher $researcher, array $articles, array $completedOrientations, array $allYears, array $kpiStats, array $categoryDistribution): array
    {
        ksort($allYears);
        $timelineYears = array_keys($allYears);
        if (empty($timelineYears)) {
            $timelineYears = [(int)date('Y')];
        }

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

        // Top Co-authors
        $myId = $researcher->getId();
        $myLattes = $researcher->getIdLattes();
        $myNorm = \App\Service\Thesaurus\StringNormalizer::normalizeString((string)$researcher->getFullName(), true);

        $collaboratorsMap = [];

        foreach ($researcher->getProductions() as $prod) {
            $seenInProd = [];

            foreach ($prod->getAuthors() as $author) {
                $matchedR = $author->getMatchedResearcher();
                if ($matchedR && $matchedR->getId() === $myId) continue;
                if ($author->getIdLattes() && $myLattes && $author->getIdLattes() === $myLattes) continue;

                $rawName = trim((string)($author->getCitationName() ?: $author->getAuthorName()));
                if ($rawName === '' || mb_strlen($rawName) < 3) continue;

                $authorNorm = \App\Service\Thesaurus\StringNormalizer::normalizeString($rawName, true);
                if ($authorNorm === $myNorm) continue;

                if ($matchedR) {
                    $key = 'researcher_' . $matchedR->getId();
                    $name = $matchedR->getFullName();
                    $resData = [
                        'slug' => $matchedR->getSlug() ?: $matchedR->getIdLattes(),
                        'idLattes' => $matchedR->getIdLattes(),
                        'fullName' => $matchedR->getFullName(),
                        'department' => $matchedR->getDepartment(),
                        'photoUrl' => $matchedR->getPhotoUrl(),
                    ];
                } elseif ($author->getAuthorIdentity()) {
                    $key = 'identity_' . $author->getAuthorIdentity()->getId();
                    $name = $author->getAuthorIdentity()->getPreferredName();
                    $resData = null;
                } else {
                    $resolved = $this->authorResolver->resolveAuthorData($rawName)
                        ?: ($author->getAuthorName() !== '' ? $this->authorResolver->resolveAuthorData($author->getAuthorName()) : null)
                        ?: ($author->getCitationName() !== '' ? $this->authorResolver->resolveAuthorData($author->getCitationName()) : null);

                    if ($resolved && !empty($resolved['researcher'])) {
                        if ((int)$resolved['researcher']['id'] === $myId) continue;
                        $key = 'researcher_' . $resolved['researcher']['id'];
                        $name = $resolved['researcher']['fullName'];
                        $resData = [
                            'slug' => $resolved['researcher']['slug'],
                            'idLattes' => $resolved['researcher']['idLattes'],
                            'fullName' => $resolved['researcher']['fullName'],
                            'department' => $resolved['researcher']['department'],
                            'photoUrl' => $resolved['researcher']['photoUrl'] ?? null,
                        ];
                    } elseif ($resolved && !empty($resolved['identityId'])) {
                        $key = 'identity_' . $resolved['identityId'];
                        $name = $resolved['preferredName'];
                        $resData = null;
                    } else {
                        $inv = \App\Service\Thesaurus\AuthorResolverService::invertName($rawName);
                        $displayName = ($inv && str_contains($rawName, ',')) ? $inv : ($author->getAuthorName() ?: $rawName);
                        $key = 'name_' . $authorNorm;
                        $name = $displayName;
                        $resData = null;
                    }
                }

                if (str_contains($name, ',')) {
                    $inv = \App\Service\Thesaurus\AuthorResolverService::invertName($name);
                    if ($inv) $name = $inv;
                }

                if (isset($seenInProd[$key])) continue;
                $seenInProd[$key] = true;

                if (!isset($collaboratorsMap[$key])) {
                    $collaboratorsMap[$key] = [
                        'name' => $name,
                        'count' => 0,
                        'researcher' => $resData,
                        'years' => [],
                        'types' => [],
                        'sampleWorks' => [],
                    ];
                }
                $collaboratorsMap[$key]['count']++;
                if ($prod->getYear()) {
                    $collaboratorsMap[$key]['years'][] = (int)$prod->getYear();
                }
                $itemType = (string)$prod->getItemType();
                if ($itemType !== '') {
                    $collaboratorsMap[$key]['types'][$itemType] = ($collaboratorsMap[$key]['types'][$itemType] ?? 0) + 1;
                }
                if (count($collaboratorsMap[$key]['sampleWorks']) < 2) {
                    $collaboratorsMap[$key]['sampleWorks'][] = [
                        'title' => $prod->getTitle(),
                        'year' => $prod->getYear(),
                        'type' => $prod->getItemType(),
                    ];
                }
            }
        }

        foreach ($collaboratorsMap as &$collab) {
            if (!empty($collab['years'])) {
                $minY = min($collab['years']);
                $maxY = max($collab['years']);
                $collab['period'] = ($minY === $maxY) ? (string)$minY : ($minY . '–' . $maxY);
                $collab['lastYear'] = $maxY;
            } else {
                $collab['period'] = '';
                $collab['lastYear'] = null;
            }
            if (!empty($collab['types'])) {
                arsort($collab['types']);
            }
        }
        unset($collab);

        uasort($collaboratorsMap, fn($a, $b) => $b['count'] <=> $a['count']);
        $topCoauthors = array_slice($collaboratorsMap, 0, 12, true);

        // Keyword & Topic Cloud
        $stopWords = [
            'de', 'da', 'do', 'das', 'dos', 'em', 'no', 'na', 'nos', 'nas', 'por', 'para', 'com', 'sem',
            'sob', 'sobre', 'entre', 'até', 'ante', 'após', 'uma', 'um', 'umas', 'uns', 'o', 'a', 'os', 'as',
            'e', 'ou', 'se', 'que', 'como', 'qual', 'quais', 'onde', 'quando', 'mais', 'menos', 'muito', 'muita',
            'sua', 'seu', 'suas', 'seus', 'este', 'esta', 'estes', 'estas', 'esse', 'essa', 'esses', 'essas',
            'aquele', 'aquela', 'aqueles', 'aquelas', 'isto', 'isso', 'aquilo', 'estudo', 'analise', 'análise',
            'pesquisa', 'brasil', 'caso', 'desenvolvimento', 'processo', 'reflexoes', 'reflexões', 'perspectivas',
            'proposta', 'aspectos', 'artigo', 'relatorio', 'relatório', 'projeto', 'trabalho', 'volume', 'anais',
            'revista', 'caderno', 'livro', 'capitulo', 'capítulo', 'resumo', 'edição', 'parte', 'partir', 'base',
            'uso', 'guia', 'apresentação', 'considerações', 'introdução', 'conclusão', 'ad', 'hoc', 'parecerista',
            'parecer', 'consultor', 'consultoria', 'cnpq', 'fapesp', 'capes', 'encaminhado', 'concedida', 'submetido', 'submetida',
            'apreciação', 'comitê', 'ética', 'hospital', 'clínicas', 'faculdade', 'universidade', 'instituto',
            'departamento', 'escola', 'ribeirão', 'preto', 'são', 'paulo', 'usp', 'ufscar', 'unesp', 'unicamp',
            'sociedade', 'encontro', 'reunião', 'congresso', 'simpósio', 'seminário', 'jornada', 'progresso',
            'fundação', 'amparo', 'nacional', 'internacional', 'brasileira', 'brasileiro', 'estado', 'periódico',
            'submetidos', 'trabalhos', 'membro', 'avaliador', 'comissão', 'coordenador', 'coordenadora',
            'the', 'and', 'for', 'with', 'from', 'about', 'study', 'analysis', 'brazil', 'social', 'using',
            'between', 'into', 'through', 'during', 'before', 'after', 'above', 'below', 'year', 'data', 'journal'
        ];
        $connectors = ['de', 'da', 'do', 'das', 'dos', 'e', 'em', 'na', 'no', 'para', 'of', 'in', 'and'];

        $unigramCounts = [];
        $compoundCounts = [];

        $titleSources = [];
        foreach ($researcher->getProductions() as $prod) {
            $titleSources[] = ['text' => $prod->getTitle(), 'weight' => 1];
        }
        foreach ($researcher->getResearchProjects() as $proj) {
            $titleSources[] = ['text' => $proj->getName(), 'weight' => 2];
        }

        foreach ($titleSources as $source) {
            $text = $source['text'] ?? '';
            $weight = $source['weight'] ?? 1;
            if (!$text) continue;

            $clean = mb_strtolower(trim($text));
            preg_match_all('/[a-záàâãéèêíïóôõöúçñ0-9]+/u', $clean, $matches);
            $words = $matches[0] ?? [];
            $totalWords = count($words);

            for ($i = 0; $i < $totalWords; $i++) {
                $w1 = $words[$i];
                if (mb_strlen($w1) < 3 || in_array($w1, $stopWords, true) || is_numeric($w1) || preg_match('/^[ivxlcdm]+$/i', $w1)) continue;

                $unigramCounts[$w1] = ($unigramCounts[$w1] ?? 0) + $weight;

                if ($i + 1 < $totalWords) {
                    $w2 = $words[$i + 1];
                    if (mb_strlen($w2) >= 3 && !in_array($w2, $stopWords, true) && !is_numeric($w2) && !preg_match('/^[ivxlcdm]+$/i', $w2)) {
                        $compoundCounts[$w1 . ' ' . $w2] = ($compoundCounts[$w1 . ' ' . $w2] ?? 0) + $weight;
                    }
                    if (in_array($w2, $connectors, true) && $i + 2 < $totalWords) {
                        $w3 = $words[$i + 2];
                        if (mb_strlen($w3) >= 3 && !in_array($w3, $stopWords, true) && !is_numeric($w3) && !preg_match('/^[ivxlcdm]+$/i', $w3)) {
                            $compoundCounts[$w1 . ' ' . $w2 . ' ' . $w3] = ($compoundCounts[$w1 . ' ' . $w2 . ' ' . $w3] ?? 0) + $weight;
                        }
                    }
                }
            }
        }

        $validCompounds = array_filter($compoundCounts, fn($c) => $c >= 2);
        arsort($validCompounds);

        $absorbed = [];
        foreach ($validCompounds as $phrase => $count) {
            foreach (explode(' ', $phrase) as $p) {
                if (!in_array($p, $connectors, true)) {
                    $absorbed[$p] = ($absorbed[$p] ?? 0) + $count;
                }
            }
        }

        $finalKeywords = $validCompounds;
        foreach ($unigramCounts as $word => $count) {
            $netCount = $count - ($absorbed[$word] ?? 0);
            if ($netCount >= 2) {
                $finalKeywords[$word] = $netCount;
            }
        }

        arsort($finalKeywords);
        $topKeywords = array_slice($finalKeywords, 0, 24, true);

        // Author-Declared Keywords (Lattes)
        $rawAuthorKeywords = [];
        $canonFormMap = [];

        foreach ($researcher->getProductions() as $prod) {
            foreach ($prod->getKeywords() as $kw) {
                $cleaned = trim(trim($kw), " \t\n\r\0\x0B.,;:-");
                if (mb_strlen($cleaned) < 2) continue;

                $lower = mb_strtolower($cleaned, 'UTF-8');
                if (!isset($canonFormMap[$lower])) {
                    $canonFormMap[$lower] = mb_convert_case($cleaned, MB_CASE_TITLE, 'UTF-8');
                }
                $rawAuthorKeywords[$lower] = ($rawAuthorKeywords[$lower] ?? 0) + 1;
            }
        }

        arsort($rawAuthorKeywords);
        $authorKeywords = [];
        foreach (array_slice($rawAuthorKeywords, 0, 24, true) as $lower => $count) {
            $authorKeywords[$canonFormMap[$lower]] = $count;
        }

        return [
            'timelineYears' => $continuousYears,
            'productionTimeline' => $productionTimeline,
            'orientationsTimeline' => $orientationsTimeline,
            'yearlyProduction' => $yearlyProduction,
            'categoryDistribution' => $categoryDistribution,
            'topCoauthors' => $topCoauthors,
            'topKeywords' => $topKeywords,
            'authorKeywords' => $authorKeywords,
        ];
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
