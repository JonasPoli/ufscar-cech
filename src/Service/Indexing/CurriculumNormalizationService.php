<?php

namespace App\Service\Indexing;

use App\Entity\AuthorIdentity;
use App\Entity\Education;
use App\Entity\Institution;
use App\Entity\ProductionAuthor;
use App\Entity\ProductionItem;
use App\Entity\ProfessionalExperience;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Repository\ResearcherRepository;
use App\Service\Journal\JournalNameQueueService;
use App\Service\Thesaurus\AuthorResolverService;
use App\Service\Thesaurus\AuthorThesaurusService;
use App\Service\Thesaurus\InstitutionResolverService;
use App\Service\Thesaurus\JournalResolverService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Serviço responsável pela normalização, enriquecimento e indexação das relações de um pesquisador.
 *
 * Executa as seguintes etapas sem alterar os dados brutos do Lattes:
 * 1. Sincroniza nomes e citações bibliográficas com o Tesauro de Autores (author_identities / variants).
 * 2. Resolve o periódico e atribui o melhor estrato Qualis CAPES para cada artigo científico. Nome de periódico
 *    sem vínculo seguro (desconhecido ou ambíguo) não recebe vínculo: entra na fila de revisão de nomes.
 * 3. Resolve todos os coautores de cada produção, identificando docentes internos do CECH (matched_researcher_id, is_cech_researcher).
 * 4. Resolve e vincula instituições de formação acadêmica (Education) e atuação profissional (ProfessionalExperience).
 * 5. Analisa vínculos com a UFSCar para determinar anos de admissão, desligamento e status ativo/aposentado.
 * 6. Marca a data e hora da indexação (last_indexed_at).
 */
class CurriculumNormalizationService
{
    /**
     * @param EntityManagerInterface $em Gerenciador de entidades do Doctrine
     * @param ResearcherRepository $researcherRepo Repositório de pesquisadores
     * @param AuthorResolverService $authorResolver Serviço de resolução e desambiguação de autores
     * @param JournalResolverService $journalResolver Serviço de resolução de periódicos e Qualis
     * @param InstitutionResolverService $institutionResolver Serviço de resolução de instituições
     * @param AuthorThesaurusService $authorThesaurusService Serviço de gerenciamento do tesauro de autores
     * @param JournalNameQueueService $queue Fila de revisão dos nomes de periódicos sem vínculo
     * @param LoggerInterface|null $logger Registro de falhas não fatais (fila de nomes)
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResearcherRepository $researcherRepo,
        private readonly AuthorResolverService $authorResolver,
        private readonly JournalResolverService $journalResolver,
        private readonly InstitutionResolverService $institutionResolver,
        private readonly AuthorThesaurusService $authorThesaurusService,
        private readonly JournalNameQueueService $queue,
        private readonly ?LoggerInterface $logger = null
    ) {}

    /**
     * Normaliza e indexa todas as produções, coautores, periódicos e instituições de um único pesquisador.
     *
     * @param Researcher $researcher Entidade do pesquisador a ser indexado
     * @return array{
     *     productionsProcessed: int,
     *     authorsIndexed: int,
     *     authorsCechMatched: int,
     *     qualisResolved: int,
     *     institutionsResolved: int,
     *     thesaurusVariantsAdded?: int,
     *     admissionYear?: ?int,
     *     leaveYear?: ?int,
     *     isActiveInCech?: bool,
     *     cechPeriodLabel?: string
     * } Estatísticas do processamento de normalização
     */
    public function normalizeResearcher(Researcher $researcher): array
    {
        // Passo 02/03: Sincroniza/atualiza o tesauro com todas as variações de nomes deste pesquisador
        $thesaurusStats = $this->authorThesaurusService->syncResearcher($researcher);

        $stats = [
            'productionsProcessed' => 0,
            'authorsIndexed' => 0,
            'authorsCechMatched' => 0,
            'qualisResolved' => 0,
            'institutionsResolved' => 0,
            'thesaurusVariantsAdded' => $thesaurusStats['variantsAdded'] ?? 0,
        ];

        // 1. Process Productions and Authors
        foreach ($researcher->getProductions() as $prod) {
            $stats['productionsProcessed']++;

            // Qualis & Journal resolution
            $journalName = $prod->getJournalName();
            $issn = $prod->getIssn();

            // Coluna derivada (o journal_name do Lattes não é alterado): chave de agrupamento da fila de nomes
            $normalizedJournalName = $this->normalizeJournalName($journalName);
            if ($prod->getJournalNameNormalized() !== $normalizedJournalName) {
                $prod->setJournalNameNormalized($normalizedJournalName);
            }

            if ($journalName || $issn) {
                $res = $this->journalResolver->resolveJournalIdAndQualis($journalName, $issn);
                if ($res) {
                    $prod->setQualis($res['qualis']);
                    if ($res['qualis']) {
                        $stats['qualisResolved']++;
                    }
                    /** @var QualisJournal $journalRef */
                    $journalRef = $this->em->getReference(QualisJournal::class, $res['id']);
                    $prod->setQualisJournal($journalRef);

                    $dbs = $this->journalResolver->resolveDatabasesForJournalId($res['id']);
                    $prod->setIndexedDatabases(!empty($dbs) ? $dbs : null);
                } else {
                    // Sem vínculo seguro (nome desconhecido ou ambíguo): remove o que uma indexação anterior tenha
                    // gravado, como faz a indexação em lote. O nome vai para a fila de revisão.
                    $prod->setQualis(null);
                    $prod->setQualisJournal(null);
                    $prod->setIndexedDatabases(null);
                }
            }

            // Authors resolution
            foreach ($prod->getAuthors() as $author) {
                $rawName = trim($author->getCitationName() ?: $author->getAuthorName());
                if ($rawName !== '') {
                    $resolved = $this->authorResolver->resolveAuthorData($rawName)
                        ?: ($author->getAuthorName() !== '' ? $this->authorResolver->resolveAuthorData($author->getAuthorName()) : null)
                        ?: ($author->getCitationName() !== '' ? $this->authorResolver->resolveAuthorData($author->getCitationName()) : null);

                    if ($resolved) {
                        if (!empty($resolved['identityId'])) {
                            /** @var AuthorIdentity $identityRef */
                            $identityRef = $this->em->getReference(AuthorIdentity::class, $resolved['identityId']);
                            $author->setAuthorIdentity($identityRef);
                        }

                        if (!empty($resolved['researcher'])) {
                            $matchedId = (int)$resolved['researcher']['id'];
                            /** @var Researcher $researcherRef */
                            $researcherRef = $this->em->getReference(Researcher::class, $matchedId);
                            $author->setMatchedResearcher($researcherRef);
                            $author->setIsCechResearcher(true);
                            $stats['authorsCechMatched']++;
                        } else {
                            $author->setMatchedResearcher(null);
                            $author->setIsCechResearcher(false);
                        }
                    }
                }

                $author->setIsIndexed(true);
                $stats['authorsIndexed']++;
            }
        }

        // 2. Process Educations (Institutions)
        foreach ($researcher->getEducations() as $edu) {
            $instName = $edu->getInstitutionName();
            if ($instName) {
                $instData = $this->institutionResolver->resolveInstitutionData($instName);
                if ($instData && !empty($instData['id'])) {
                    /** @var Institution $instRef */
                    $instRef = $this->em->getReference(Institution::class, $instData['id']);
                    $edu->setInstitution($instRef);
                    $stats['institutionsResolved']++;
                }
            }
        }

        // 3. Process Professional Experiences (Institutions) & Verify CECH Affiliation
        $currentYear = (int)date('Y');
        $hasActiveUfscar = false;
        $careerStartYears = [];
        $ufscarEndYears = [];

        foreach ($researcher->getProfessionalExperiences() as $exp) {
            $instName = $exp->getInstitutionName();
            if ($instName) {
                $instData = $this->institutionResolver->resolveInstitutionData($instName);
                if ($instData && !empty($instData['id'])) {
                    /** @var Institution $instRef */
                    $instRef = $this->em->getReference(Institution::class, $instData['id']);
                    $exp->setInstitution($instRef);
                    $stats['institutionsResolved']++;
                }

                // Check UFSCar affiliation
                $instLower = mb_strtolower($instName);
                if (str_contains($instLower, 'são carlos') || str_contains($instLower, 'ufscar')) {
                    $startYear = $exp->getStartYear();
                    $endYear = $exp->getEndYear();
                    $roleLower = mb_strtolower((string)$exp->getRoleName());
                    $contractLower = mb_strtolower((string)$exp->getContractType());

                    $isTemporary = str_contains($roleLower . ' ' . $contractLower, 'substituto')
                                || str_contains($roleLower . ' ' . $contractLower, 'aluno')
                                || str_contains($roleLower . ' ' . $contractLower, 'bolsista')
                                || str_contains($roleLower . ' ' . $contractLower, 'estagi')
                                || str_contains($roleLower . ' ' . $contractLower, 'temporário');

                    if ($startYear && $startYear > 1960 && $startYear <= $currentYear && !$isTemporary) {
                        $careerStartYears[] = $startYear;
                    }

                    if ($exp->isCurrent() || $endYear === null || $endYear >= $currentYear) {
                        $hasActiveUfscar = true;
                    } elseif ($endYear && $endYear > 1960 && $endYear < $currentYear) {
                        $ufscarEndYears[] = $endYear;
                    }
                }
            }
        }

        // 4. Update Admission Year if not set or found earlier
        if (!empty($careerStartYears)) {
            $earliestCareer = min($careerStartYears);
            if (!$researcher->getAdmissionYear()) {
                $researcher->setAdmissionYear($earliestCareer);
            }
        }

        // 5. Update Leave Year and Status
        if ($hasActiveUfscar) {
            $researcher->setStatus(true);
            // If it had a past leave year but now has an active link, reactivate
            if ($researcher->getLeaveYear() !== null && $researcher->getLeaveYear() < $currentYear) {
                $researcher->setLeaveYear(null);
            }
        } elseif (!empty($ufscarEndYears)) {
            // All UFSCar affiliations ended in the past
            $latestEnd = max($ufscarEndYears);
            if (!$researcher->getLeaveYear() || $researcher->getLeaveYear() >= $currentYear) {
                $researcher->setLeaveYear($latestEnd);
            }
            $researcher->setStatus(false);
        }

        // 6. Mark researcher as indexed
        $researcher->setLastIndexedAt(new \DateTimeImmutable());

        $this->em->flush();

        // Fila de revisão de nomes de periódicos sem vínculo. Só os nomes novos são resolvidos (modo on-demand);
        // uma falha aqui nunca deve impedir a importação do currículo.
        try {
            $this->queue->rebuild(false);
        } catch (\Throwable $e) {
            $this->logger?->warning('Não foi possível atualizar a fila de nomes de periódicos: ' . $e->getMessage(), ['exception' => $e]);
        }

        $stats['admissionYear'] = $researcher->getAdmissionYear();
        $stats['leaveYear'] = $researcher->getLeaveYear();
        $stats['isActiveInCech'] = $researcher->isActiveInCech();
        $stats['cechPeriodLabel'] = $researcher->getCechPeriodLabel();

        return $stats;
    }

    /**
     * Retorna estatísticas consolidadas e KPIs de indexação para o painel de controle administrativo.
     *
     * Permite filtrar por departamento específico ou obter os totais de todo o centro.
     *
     * @param string|null $department Sigla ou nome do departamento (opcional)
     * @return array{
     *     totalResearchers: int,
     *     indexedResearchers: int,
     *     pendingResearchers: int,
     *     activeResearchers: int,
     *     retiredResearchers: int,
     *     totalProductions: int,
     *     totalAuthors: int,
     *     indexedAuthors: int,
     *     cechMatchedAuthors: int,
     *     percentage: float
     * }
     */
    public function getIndexingOverview(?string $department = null): array
    {
        $conn = $this->em->getConnection();
        $currentYear = (int)date('Y');

        $deptFilter = '';
        $params = ['currentYear' => $currentYear];
        if ($department !== null && $department !== '' && $department !== 'all') {
            $deptFilter = ' WHERE (department = :dept OR department_code = :dept) ';
            $params['dept'] = $department;
        }

        $wherePrefix = $deptFilter ? "{$deptFilter} AND " : " WHERE ";

        $totalResearchers = (int)$conn->fetchOne("SELECT COUNT(*) FROM researchers {$deptFilter}", $params);
        
        $indexedFilter = $deptFilter ? "{$deptFilter} AND last_indexed_at IS NOT NULL" : " WHERE last_indexed_at IS NOT NULL";
        $indexedResearchers = (int)$conn->fetchOne("SELECT COUNT(*) FROM researchers {$indexedFilter}", $params);
        $pendingResearchers = max(0, $totalResearchers - $indexedResearchers);

        $activeResearchers = (int)$conn->fetchOne(
            "SELECT COUNT(*) FROM researchers {$wherePrefix} status = 1 AND (leave_year IS NULL OR leave_year >= :currentYear)",
            $params
        );
        $retiredResearchers = (int)$conn->fetchOne(
            "SELECT COUNT(*) FROM researchers {$wherePrefix} (status = 0 OR (leave_year IS NOT NULL AND leave_year < :currentYear))",
            $params
        );

        $totalProductions = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items");
        $totalArticles = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items WHERE item_type = 'ARTIGO'");
        $articlesWithQualis = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items WHERE item_type = 'ARTIGO' AND qualis IS NOT NULL");
        $articlesWithJournal = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items WHERE item_type = 'ARTIGO' AND qualis_journal_id IS NOT NULL");
        $articlesWithDatabases = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_items WHERE item_type = 'ARTIGO' AND indexed_databases IS NOT NULL");

        $totalAuthors = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_authors");
        $indexedAuthors = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_authors WHERE is_indexed = 1");
        $cechMatchedAuthors = (int)$conn->fetchOne("SELECT COUNT(*) FROM production_authors WHERE is_cech_researcher = 1");

        $percentage = $totalResearchers > 0 ? round(($indexedResearchers / $totalResearchers) * 100, 1) : 0.0;

        return [
            'totalResearchers' => $totalResearchers,
            'indexedResearchers' => $indexedResearchers,
            'pendingResearchers' => $pendingResearchers,
            'activeResearchers' => $activeResearchers,
            'retiredResearchers' => $retiredResearchers,
            'totalProductions' => $totalProductions,
            'totalArticles' => $totalArticles,
            'articlesWithQualis' => $articlesWithQualis,
            'articlesWithJournal' => $articlesWithJournal,
            'articlesWithDatabases' => $articlesWithDatabases,
            'totalAuthors' => $totalAuthors,
            'indexedAuthors' => $indexedAuthors,
            'cechMatchedAuthors' => $cechMatchedAuthors,
            'percentage' => $percentage,
        ];
    }

    /**
     * Executa a indexação em lote ultra-rápida de todos os artigos científicos, vinculando
     * qualis_journal_id, qualis e indexed_databases diretamente em production_items.
     *
     * Grava também a coluna derivada journal_name_normalized (o journal_name do Lattes não é alterado) e, ao final,
     * reconstrói a fila de revisão de nomes de periódicos sem vínculo.
     *
     * @param callable|null $progressCallback Callback opcional de progresso
     * @return array{totalProcessed: int, qualisResolved: int, journalsMatched: int, databasesLinked: int, queue: array{pending: int, ambiguous: int, removed: int}, durationSec: float}
     */
    public function indexAllProductionsJournalAndDatabases(?callable $progressCallback = null): array
    {
        $startTime = microtime(true);
        $this->journalResolver->loadFullMaps();
        $conn = $this->em->getConnection();

        $articles = $conn->fetchAllAssociative("SELECT id, journal_name, issn FROM production_items WHERE item_type = 'ARTIGO' ORDER BY id ASC");
        $totalArticles = count($articles);

        $stats = [
            'totalProcessed' => 0,
            'qualisResolved' => 0,
            'journalsMatched' => 0,
            'databasesLinked' => 0,
        ];

        $batchSize = 250;
        $batch = [];

        foreach ($articles as $index => $art) {
            $link = $this->resolveArticleLink($art['journal_name'], $art['issn']);

            if ($link['matched']) {
                $stats['journalsMatched']++;
                if ($link['q'] !== null) {
                    $stats['qualisResolved']++;
                }
                if ($link['dbs'] !== null) {
                    $stats['databasesLinked']++;
                }
            }

            $batch[] = [
                'id' => (int)$art['id'],
                'jid' => $link['jid'],
                'q' => $link['q'],
                'dbs' => $link['dbs'],
                'nn' => $this->normalizeJournalName($art['journal_name']),
            ];

            $stats['totalProcessed']++;

            if (count($batch) >= $batchSize || $index === $totalArticles - 1) {
                // Execute updates
                foreach ($batch as $row) {
                    $conn->executeStatement(
                        'UPDATE production_items SET qualis = :q, qualis_journal_id = :jid, indexed_databases = :dbs, journal_name_normalized = :nn WHERE id = :id',
                        [
                            'q' => $row['q'],
                            'jid' => $row['jid'],
                            'dbs' => $row['dbs'],
                            'nn' => $row['nn'],
                            'id' => $row['id'],
                        ]
                    );
                }
                $batch = [];

                if ($progressCallback) {
                    $percent = (int)round((($index + 1) / $totalArticles) * 100);
                    $progressCallback($index + 1, $totalArticles, $percent, $stats);
                }
            }
        }

        // Nomes sem vínculo (desconhecidos ou ambíguos) vão para a fila de revisão
        $stats['queue'] = $this->queue->rebuild();

        $duration = round(microtime(true) - $startTime, 2);
        $stats['durationSec'] = $duration;

        return $stats;
    }

    /**
     * Revincula os artigos de um nome de periódico (forma do tesauro, como em production_items.journal_name_normalized
     * e journal_name_candidates.normalized_name), por exemplo depois de aceitar o nome na fila de revisão.
     * Aplica o resolver a cada artigo e grava qualis, qualis_journal_id e indexed_databases.
     *
     * @return int Quantidade de artigos que ficaram vinculados a um periódico
     */
    public function reindexArticlesByNormalizedJournalName(string $normalizedName): int
    {
        // O cadastro (variantes) acabou de mudar: descarta o que o resolver memorizou
        $this->journalResolver->clearCache();

        $conn = $this->em->getConnection();
        $articles = $conn->fetchAllAssociative(
            "SELECT id, journal_name, issn FROM production_items WHERE item_type = 'ARTIGO' AND journal_name_normalized = :name ORDER BY id ASC",
            ['name' => $normalizedName]
        );

        $linked = 0;
        foreach ($articles as $art) {
            $link = $this->resolveArticleLink($art['journal_name'], $art['issn']);
            if ($link['matched']) {
                $linked++;
            }

            $conn->executeStatement(
                'UPDATE production_items SET qualis = :q, qualis_journal_id = :jid, indexed_databases = :dbs WHERE id = :id',
                [
                    'q' => $link['q'],
                    'jid' => $link['jid'],
                    'dbs' => $link['dbs'],
                    'id' => (int)$art['id'],
                ]
            );
        }

        return $linked;
    }

    /**
     * Resolve o vínculo de um artigo: periódico, estrato Qualis e bases de indexação (JSON).
     *
     * @return array{matched: bool, jid: ?int, q: ?string, dbs: ?string}
     */
    private function resolveArticleLink(?string $journalName, ?string $issn): array
    {
        $none = ['matched' => false, 'jid' => null, 'q' => null, 'dbs' => null];
        if (!$journalName && !$issn) {
            return $none;
        }

        $res = $this->journalResolver->resolveJournalIdAndQualis($journalName, $issn);
        if (!$res) {
            return $none;
        }

        $dbs = $this->journalResolver->resolveDatabasesForJournalId($res['id']);

        return [
            'matched' => true,
            'jid' => $res['id'],
            'q' => $res['qualis'],
            'dbs' => !empty($dbs) ? json_encode($dbs, JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    /**
     * Forma do tesauro do nome do periódico (valor de production_items.journal_name_normalized); null se vazio.
     */
    private function normalizeJournalName(?string $journalName): ?string
    {
        $normalized = StringNormalizer::normalizeString($journalName, true);

        return $normalized !== '' ? $normalized : null;
    }
}
