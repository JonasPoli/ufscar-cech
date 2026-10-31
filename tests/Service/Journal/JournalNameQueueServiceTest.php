<?php

namespace App\Tests\Service\Journal;

use App\Entity\JournalVariation;
use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Service\Indexing\CurriculumNormalizationService;
use App\Service\Journal\JournalNameQueueService;
use App\Service\Thesaurus\JournalResolverService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Fila de revisão de nomes (journal_name_candidates) e revinculação por nome (docs/evolutions/periodicos-por-base.md, A5.4).
 * Os dados são criados com sufixos únicos e removidos no tearDown; o resolver roda no modo on-demand (clearCache,
 * sem loadFullMaps) e, onde indicado, também no modo lote.
 */
class JournalNameQueueServiceTest extends KernelTestCase
{
    /** Nome dos docentes de teste; normalizeResearcher() cria identidade e variante no tesauro de autores. */
    private const RESEARCHER_NAME = 'Docente Teste Fila de Nomes';

    private JournalNameQueueService $queue;
    private JournalResolverService $resolver;
    private CurriculumNormalizationService $normalization;
    private $em;
    private Connection $conn;

    /** @var int[] */
    private array $journalIds = [];
    /** @var int[] */
    private array $researcherIds = [];
    /** @var string[] nomes normalizados (forma do tesauro) dos candidatos que os testes podem ter criado */
    private array $candidateNames = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->queue = $container->get(JournalNameQueueService::class);
        $this->resolver = $container->get(JournalResolverService::class);
        $this->normalization = $container->get(CurriculumNormalizationService::class);
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->resolver->clearCache();
    }

    protected function tearDown(): void
    {
        $this->resolver->clearCache();

        foreach (array_chunk($this->candidateNames, 100) as $chunk) {
            $this->conn->executeStatement(
                'DELETE FROM journal_name_candidates WHERE normalized_name IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk
            );
        }
        if ($this->researcherIds !== []) {
            $this->conn->executeStatement('DELETE FROM researchers WHERE id IN (' . implode(',', array_map('intval', $this->researcherIds)) . ')');
            $this->conn->executeStatement('DELETE FROM author_name_variants WHERE original_name = ?', [self::RESEARCHER_NAME]);
            $this->conn->executeStatement('DELETE FROM author_identities WHERE preferred_name = ?', [self::RESEARCHER_NAME]);
        }
        if ($this->journalIds !== []) {
            $this->conn->executeStatement('DELETE FROM qualis_journals WHERE id IN (' . implode(',', array_map('intval', $this->journalIds)) . ')');
        }
        $this->em->clear();

        parent::tearDown();
    }

    public function testRebuildCreatesCandidatesWithCountsAmbiguityAndSuggestion(): void
    {
        $t = $this->token();
        $k = $this->token();
        $this->createJournal("CADERNOS DE PESQUISA $t", $this->issn());
        $this->createJournal("Cadernos de Pesquisa $t", $this->issn());
        $keywordJournal = $this->createJournal("CADERNOS DE $k");
        $linkedJournal = $this->createJournal("REVISTA JA VINCULADA $t");

        $r1 = $this->createResearcher();
        $r2 = $this->createResearcher();

        $unknown = "Revista Desconhecida $t";
        $unknownIssn = $this->issn();
        $this->addArticle($r1, $unknown, 'Artigo A', 2019, $unknownIssn);
        $this->addArticle($r1, $unknown, 'Artigo B', 2021, $unknownIssn);
        $this->addArticle($r2, strtoupper($unknown), 'Artigo C', 2023, $this->issn());
        $this->addArticle($r2, $unknown, 'Artigo D', 2020, $unknownIssn);

        $ambiguous = "Cadernos de Pesquisa $t";
        $this->addArticle($r1, $ambiguous, 'Artigo E', 2018);
        $this->addArticle($r2, $ambiguous, 'Artigo F', 2018);

        $suggested = "Revista $k";
        $this->addArticle($r1, $suggested, 'Artigo G', 2022);

        $linked = $this->addArticle($r1, "REVISTA JA VINCULADA $t", 'Artigo H', 2022);
        $this->conn->executeStatement('UPDATE production_items SET qualis_journal_id = ? WHERE id = ?', [$linkedJournal->getId(), $linked->getId()]);

        // Modo on-demand (sem loadFullMaps)
        $result = $this->queue->rebuild();
        $this->assertGreaterThanOrEqual(2, $result['pending']);
        $this->assertGreaterThanOrEqual(1, $result['ambiguous']);

        $unknownRow = $this->candidate($unknown);
        $this->assertSame('pending', $unknownRow['status']);
        $this->assertSame(4, (int)$unknownRow['articles_count']);
        $this->assertSame(2, (int)$unknownRow['researchers_count']);
        $this->assertSame($unknown, $unknownRow['raw_name']);          // grafia mais frequente (3 contra 1)
        $this->assertSame($unknownIssn, $unknownRow['issn_raw']);      // ISSN mais frequente (3 contra 1)
        $this->assertSame(['Artigo C', 'Artigo B', 'Artigo D'], json_decode($unknownRow['sample_titles'], true));  // os mais recentes
        $this->assertSame('none', $unknownRow['suggestion_method']);
        $this->assertNull($unknownRow['suggested_journal_id']);

        $ambiguousRow = $this->candidate($ambiguous);
        $this->assertSame('ambiguous', $ambiguousRow['status']);
        $this->assertSame(2, (int)$ambiguousRow['articles_count']);
        $this->assertNull($ambiguousRow['issn_raw']);

        $suggestedRow = $this->candidate($suggested);
        $this->assertSame('pending', $suggestedRow['status']);
        $this->assertSame(1, (int)$suggestedRow['articles_count']);
        $this->assertSame('keywords', $suggestedRow['suggestion_method']);
        $this->assertSame($keywordJournal->getId(), (int)$suggestedRow['suggested_journal_id']);

        $this->assertNull($this->candidate("REVISTA JA VINCULADA $t"), 'Artigo com vínculo não entra na fila');

        // Os dados do Lattes continuam intactos e a coluna derivada foi preenchida
        $row = $this->conn->fetchAssociative('SELECT journal_name, journal_name_normalized, title, issn FROM production_items WHERE id = ?', [$linked->getId()]);
        $this->assertSame("REVISTA JA VINCULADA $t", $row['journal_name']);
        $this->assertSame(StringNormalizer::normalizeString("REVISTA JA VINCULADA $t", true), $row['journal_name_normalized']);

        // Modo lote: mesmo resultado
        $onDemand = [$unknown, $ambiguous, $suggested];
        $expected = array_map(fn (string $name) => $this->fingerprint($this->candidate($name)), $onDemand);
        $this->conn->executeStatement('DELETE FROM journal_name_candidates WHERE normalized_name IN (?, ?, ?)', array_map(fn (string $n) => StringNormalizer::normalizeString($n, true), $onDemand));

        $this->resolver->clearCache();
        $this->resolver->loadFullMaps();
        $this->queue->rebuild();
        $this->assertSame($expected, array_map(fn (string $name) => $this->fingerprint($this->candidate($name)), $onDemand));
    }

    public function testRebuildKeepsReviewedRowsAndRemovesStaleOnes(): void
    {
        $t = $this->token();
        $journal = $this->createJournal("REVISTA ALVO $t");
        $r = $this->createResearcher();

        $linkedLater = "Revista Que Vai Ganhar Vinculo $t";
        $rejected = "Revista Rejeitada $t";
        $article = $this->addArticle($r, $linkedLater, 'Artigo 1', 2020);
        $this->addArticle($r, $rejected, 'Artigo 2', 2020);

        $first = $this->queue->rebuild();
        $this->assertSame('pending', $this->candidate($linkedLater)['status']);
        $this->assertSame('pending', $this->candidate($rejected)['status']);
        $this->assertSame(1, (int)$this->candidate($rejected)['articles_count']);

        // Idempotente: reconstruir de novo não muda nada
        $again = $this->queue->rebuild();
        $this->assertSame($first['pending'], $again['pending']);
        $this->assertSame($first['ambiguous'], $again['ambiguous']);
        $this->assertSame(1, (int)$this->candidate($rejected)['articles_count']);

        // Nome rejeitado: continua sem vínculo, o status é mantido e as contagens acompanham os artigos
        $this->conn->executeStatement("UPDATE journal_name_candidates SET status = 'rejected' WHERE normalized_name = ?", [$this->norm($rejected)]);
        $this->addArticle($r, $rejected, 'Artigo 3', 2021);
        $this->queue->rebuild();
        $rejectedRow = $this->candidate($rejected);
        $this->assertSame('rejected', $rejectedRow['status']);
        $this->assertSame(2, (int)$rejectedRow['articles_count']);

        // Linhas que não aparecem mais entre os artigos sem vínculo
        $accepted = "Revista Aceita $t";
        $issnSuggestion = "Revista Sugerida Por Issn $t";
        $stalePending = "Revista Sumida $t";
        $this->insertCandidate($accepted, 'accepted', 7, 'none');
        $this->insertCandidate($issnSuggestion, 'pending', 1, 'issn', $journal->getId());
        $this->insertCandidate($stalePending, 'pending', 3, 'none');

        // O artigo ganha vínculo: o candidato pendente sai da fila
        $this->conn->executeStatement('UPDATE production_items SET qualis_journal_id = ? WHERE id = ?', [$journal->getId(), $article->getId()]);
        $result = $this->queue->rebuild();

        $this->assertNull($this->candidate($linkedLater), 'Pendente cujo artigo ganhou vínculo sai da fila');
        $this->assertNull($this->candidate($stalePending), 'Pendente que sumiu sai da fila');
        $this->assertGreaterThanOrEqual(2, $result['removed']);
        $this->assertSame(7, (int)$this->candidate($accepted)['articles_count'], 'Aceito não é tocado');
        $this->assertSame('accepted', $this->candidate($accepted)['status']);
        $issnRow = $this->candidate($issnSuggestion);
        $this->assertNotNull($issnRow, 'Sugestão por ISSN (aprendizado de grafias) não é apagada pela reconstrução');
        $this->assertSame('issn', $issnRow['suggestion_method']);
        $this->assertSame($journal->getId(), (int)$issnRow['suggested_journal_id']);
        $this->assertSame('rejected', $this->candidate($rejected)['status']);
    }

    public function testRebuildWithoutRefreshOnlyResolvesNewNames(): void
    {
        $t = $this->token();
        $this->createJournal("MEDICINA $t", $this->issn());
        $this->createJournal("Medicina $t", $this->issn());
        $r = $this->createResearcher();

        $ambiguous = "Medicina $t";
        $this->addArticle($r, $ambiguous, 'Artigo 1', 2020);

        // Linha antiga, gravada como pendente e sem sugestão, de um nome que hoje é ambíguo
        $this->insertCandidate($ambiguous, 'pending', 99, 'none');

        // rebuild(false): só atualiza contagens e amostras do que já existe
        $this->queue->rebuild(false);
        $row = $this->candidate($ambiguous);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int)$row['articles_count']);
        $this->assertSame(['Artigo 1'], json_decode($row['sample_titles'], true));

        // Um nome novo é resolvido normalmente mesmo sem refresh
        $newAmbiguous = "Medicina $t";
        $other = "Revista Nova $t";
        $this->addArticle($r, $other, 'Artigo 2', 2021);
        $this->queue->rebuild(false);
        $this->assertSame('pending', $this->candidate($other)['status']);
        $this->assertSame('none', $this->candidate($other)['suggestion_method']);
        $this->assertSame('pending', $this->candidate($newAmbiguous)['status'], 'Linha existente não é reavaliada sem refresh');

        // rebuild(): reavalia tudo
        $this->queue->rebuild();
        $this->assertSame('ambiguous', $this->candidate($ambiguous)['status']);
    }

    public function testReindexByNormalizedNameLinksArticlesWithoutTouchingLattesData(): void
    {
        $t = $this->token();
        $journal = $this->createJournal("REVISTA REAL $t", $this->issn(), 'b3');
        $r = $this->createResearcher();

        $spelling = "Rev. Real $t";
        $first = $this->addArticle($r, $spelling, 'Artigo 1', 2020);
        $second = $this->addArticle($r, $spelling, 'Artigo 2', 2021);

        $this->queue->rebuild();
        $this->assertSame('pending', $this->candidate($spelling)['status']);
        $this->assertNull($this->conn->fetchOne('SELECT qualis_journal_id FROM production_items WHERE id = ?', [$first->getId()]));

        // Revisão aceita o nome: variante na linha do periódico e revinculação dos artigos daquele nome
        $variation = new JournalVariation();
        $variation->setJournal($journal);
        $variation->setVariationName($spelling);
        $variation->setNormalizedName($this->norm($spelling));
        $variation->setVariationType('curated');
        $this->em->persist($variation);
        $this->em->flush();

        $linked = $this->normalization->reindexArticlesByNormalizedJournalName($this->norm($spelling));
        $this->assertSame(2, $linked);

        foreach ([$first, $second] as $article) {
            $row = $this->conn->fetchAssociative('SELECT journal_name, qualis, qualis_journal_id FROM production_items WHERE id = ?', [$article->getId()]);
            $this->assertSame($spelling, $row['journal_name'], 'O journal_name do Lattes continua intacto');
            $this->assertSame($journal->getId(), (int)$row['qualis_journal_id']);
            $this->assertSame('B3', $row['qualis']);
        }

        $this->queue->rebuild();
        $this->assertNull($this->candidate($spelling), 'Sem artigos sem vínculo, o candidato pendente sai da fila');
    }

    public function testNormalizeResearcherLinksClearsAndQueuesNames(): void
    {
        $t = $this->token();
        $journal = $this->createJournal("REVISTA ALFA $t", null, 'a2');
        $r = $this->createResearcher();

        $resolvable = $this->addArticle($r, "Revista Alfa $t", 'Artigo 1', 2020);
        $unknown = "Revista Beta $t";
        $unresolved = $this->addArticle($r, $unknown, 'Artigo 2', 2020);

        // Vínculo antigo (por exemplo, de um palpite por palavras-chave) que não se sustenta mais
        $stale = $this->addArticle($r, "Revista Gama $t", 'Artigo 3', 2020);
        $stale->setQualisJournal($journal);
        $stale->setQualis('A1');
        $stale->setIndexedDatabases([['id' => 1, 'name' => 'Scopus', 'acronym' => 'scopus']]);
        $this->em->flush();

        // Coluna derivada gravada como nula/diferente: o normalizeResearcher corrige
        $this->conn->executeStatement('UPDATE production_items SET journal_name_normalized = NULL WHERE researcher_id = ?', [$r->getId()]);
        $this->em->clear();
        $researcher = $this->em->find(Researcher::class, $r->getId());

        $stats = $this->normalization->normalizeResearcher($researcher);
        $this->assertSame(3, $stats['productionsProcessed']);

        $rows = [];
        foreach ($this->conn->fetchAllAssociative('SELECT id, journal_name, journal_name_normalized, qualis, qualis_journal_id, indexed_databases FROM production_items WHERE researcher_id = ?', [$r->getId()]) as $row) {
            $rows[(int)$row['id']] = $row;
        }

        $this->assertSame($journal->getId(), (int)$rows[$resolvable->getId()]['qualis_journal_id']);
        $this->assertSame('A2', $rows[$resolvable->getId()]['qualis']);
        $this->assertSame($this->norm("Revista Alfa $t"), $rows[$resolvable->getId()]['journal_name_normalized']);

        $this->assertNull($rows[$unresolved->getId()]['qualis_journal_id']);
        $this->assertNull($rows[$unresolved->getId()]['qualis']);
        $this->assertSame($this->norm($unknown), $rows[$unresolved->getId()]['journal_name_normalized']);
        $this->assertSame($unknown, $rows[$unresolved->getId()]['journal_name'], 'O journal_name do Lattes continua intacto');

        $this->assertNull($rows[$stale->getId()]['qualis_journal_id'], 'Sem vínculo seguro, o vínculo antigo é removido');
        $this->assertNull($rows[$stale->getId()]['qualis']);
        $this->assertNull($rows[$stale->getId()]['indexed_databases']);

        // Os nomes sem vínculo entraram na fila
        $this->assertSame('pending', $this->candidate($unknown)['status']);
        $this->assertSame('pending', $this->candidate("Revista Gama $t")['status']);
        $this->assertNull($this->candidate("Revista Alfa $t"));
    }

    // ------------------------------------------------------------------------------------------------

    private function token(): string
    {
        return 'Zq' . bin2hex(random_bytes(5));
    }

    private function issn(): string
    {
        return '3' . str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    }

    private function norm(string $name): string
    {
        return StringNormalizer::normalizeString($name, true);
    }

    private function createJournal(string $title, ?string $issn = null, ?string $qualis = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        if ($issn !== null) {
            $journal->setIssn($issn);
        }
        if ($qualis !== null) {
            $journal->setQualis($qualis);
        }
        $this->em->persist($journal);
        $this->em->flush();
        $this->journalIds[] = $journal->getId();

        return $journal;
    }

    private function createResearcher(): Researcher
    {
        $researcher = new Researcher();
        $researcher->setIdLattes('9977' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT));
        $researcher->setFullName(self::RESEARCHER_NAME);
        $researcher->setSlug('docente-teste-fila-' . bin2hex(random_bytes(4)));
        $this->em->persist($researcher);
        $this->em->flush();
        $this->researcherIds[] = $researcher->getId();

        return $researcher;
    }

    private function addArticle(Researcher $researcher, string $journalName, string $title, int $year, ?string $issn = null): ProductionItem
    {
        $article = new ProductionItem();
        $article->setItemType(ProductionItem::TYPE_ARTIGO);
        $article->setTitle($title);
        $article->setYear($year);
        $article->setJournalName($journalName);
        $article->setIssn($issn);
        $researcher->addProduction($article);
        $this->em->persist($article);
        $this->em->flush();
        $this->candidateNames[] = $this->norm($journalName);

        return $article;
    }

    private function insertCandidate(string $name, string $status, int $articles, string $method, ?int $suggestedJournalId = null): void
    {
        $normalized = $this->norm($name);
        $this->candidateNames[] = $normalized;
        $this->conn->executeStatement(
            'INSERT INTO journal_name_candidates (normalized_name, raw_name, articles_count, researchers_count, suggested_journal_id, suggestion_method, status, first_seen_at, last_seen_at)
             VALUES (?, ?, ?, 1, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE articles_count = VALUES(articles_count), suggested_journal_id = VALUES(suggested_journal_id),
                                     suggestion_method = VALUES(suggestion_method), status = VALUES(status)',
            [$normalized, $name, $articles, $suggestedJournalId, $method, $status]
        );
    }

    /** @return array<string, mixed>|null */
    private function candidate(string $name): ?array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM journal_name_candidates WHERE normalized_name = ?', [$this->norm($name)]);

        return $row === false ? null : $row;
    }

    /** @return array{0: string, 1: ?string, 2: ?int, 3: int, 4: int} */
    private function fingerprint(?array $row): array
    {
        $this->assertNotNull($row);

        return [
            $row['status'],
            $row['suggestion_method'],
            $row['suggested_journal_id'] !== null ? (int)$row['suggested_journal_id'] : null,
            (int)$row['articles_count'],
            (int)$row['researchers_count'],
        ];
    }
}
