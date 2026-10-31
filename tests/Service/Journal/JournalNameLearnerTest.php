<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Command\JournalsNamesQueueCommand;
use App\Entity\JournalVariation;
use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Service\Journal\JournalNameLearner;
use App\Service\Journal\JournalNameQueueService;
use App\Service\Thesaurus\JournalResolverService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Aprendizado de grafias do Lattes com confirmação por ISSN (docs/evolutions/periodicos-por-base.md, A5.3) e os
 * comandos `app:journals:names:learn` e `app:journals:names:queue`.
 *
 * Os dados são criados com sufixo único dentro de uma transação revertida no tearDown: nada fica gravado em
 * `cech_test`, que outros testes usam ao mesmo tempo. Cada chamada de `learn()` é restrita aos nomes do cenário
 * (`$onlyNames`), para não mexer nos dados dos outros testes, e nada aqui chama `JournalNameQueueService::rebuild()`
 * de verdade (ele apaga candidatos pendentes de todo o banco).
 */
class JournalNameLearnerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private JournalNameLearner $learner;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->learner = $container->get(JournalNameLearner::class);

        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        if (isset($this->em)) {
            $this->em->clear();
        }

        parent::tearDown();
    }

    public function testLearnsAVariantOnTheCanonicalRowOfTheFamilyConfirmedByIssn(): void
    {
        $t = $this->token();
        $issnPrint = $this->issn();
        $issnOnline = $this->issn();
        $alfa = $this->createJournal("REVISTA ALFA $t", $issnPrint);
        $alfaOnline = $this->createJournal("REVISTA ALFA $t ONLINE", null, $issnOnline, $alfa);
        $r1 = $this->createResearcher();
        $r2 = $this->createResearcher();

        $name = "Rev. Alfa $t";
        // O ISSN de uma linha confirma o vínculo mesmo quando o artigo está vinculado a outra linha da família
        $first = $this->createArticle($r1, $name, 'Artigo 1', 2020, $issnOnline, $alfaOnline);
        $this->createArticle($r2, $name, 'Artigo 2', 2021, $issnPrint, $alfa);
        $this->createArticle($r2, strtoupper($name), 'Artigo 3', 2019, $issnPrint, $alfa);

        // Simulação: relata e não grava
        $dry = $this->learner->learn(2, true, [$name]);
        $this->assertSame(1, $dry['learned']);
        $this->assertSame(0, $dry['queued']);
        $this->assertSame(0, $dry['conflicts']);
        $this->assertSame(1, $dry['candidates']);
        $this->assertSame(0, $this->countVariants($name));
        $this->assertNull($this->candidate($name));
        $this->assertSame(
            [['action' => 'learned', 'name' => $name, 'journal_id' => $alfa->getId(), 'journal' => "REVISTA ALFA $t", 'articles' => 3]],
            $dry['examples']
        );

        $result = $this->learner->learn(2, false, [$name]);
        $this->assertSame(1, $result['learned']);
        $this->assertSame(0, $result['queued']);
        $this->assertSame(0, $result['conflicts']);
        $this->assertNull($this->candidate($name), 'Nome aprendido não precisa entrar na fila');

        // Variante `lattes` na canônica (não no membro), com a grafia bruta mais frequente
        $row = $this->variantRow($name);
        $this->assertNotNull($row);
        $this->assertSame($alfa->getId(), (int)$row['journal_id']);
        $this->assertSame($name, $row['variation_name']);
        $this->assertSame(self::norm($name), $row['normalized_name']);
        $this->assertSame('lattes', $row['variation_type']);
        $this->assertSame(1, (int)$row['status']);

        // Os dados do Lattes e os vínculos não foram tocados
        $article = $this->conn->fetchAssociative('SELECT journal_name, issn, qualis_journal_id FROM production_items WHERE id = ?', [$first->getId()]);
        $this->assertSame($name, $article['journal_name']);
        $this->assertSame($issnOnline, $article['issn']);
        $this->assertSame($alfaOnline->getId(), (int)$article['qualis_journal_id']);

        // Rodar de novo não duplica: o nome já é variante
        $again = $this->learner->learn(2, false, [$name]);
        $this->assertSame(0, $again['learned']);
        $this->assertSame(0, $again['candidates']);
        $this->assertSame(1, $this->countVariants($name));
    }

    public function testNameWithFewerArticlesThanTheMinimumGoesToTheQueueWithAnIssnSuggestion(): void
    {
        $t = $this->token();
        $issn = $this->issn();
        $beta = $this->createJournal("REVISTA BETA $t", $issn);
        $r = $this->createResearcher();

        $name = "Rev Beta $t";
        $article = $this->createArticle($r, $name, 'Artigo X', 2022, $issn, $beta);
        // Coluna derivada vazia: a forma do tesauro é calculada na hora
        $this->conn->executeStatement('UPDATE production_items SET journal_name_normalized = NULL WHERE id = ?', [$article->getId()]);

        $dry = $this->learner->learn(2, true, [$name]);
        $this->assertSame(0, $dry['learned']);
        $this->assertSame(1, $dry['queued']);
        $this->assertGreaterThanOrEqual(1, $dry['unnormalized_articles']);
        $this->assertNull($this->candidate($name));

        $result = $this->learner->learn(2, false, [$name]);
        $this->assertSame(0, $result['learned']);
        $this->assertSame(1, $result['queued']);
        $this->assertSame(0, $this->countVariants($name));

        $row = $this->candidate($name);
        $this->assertNotNull($row);
        $this->assertSame(self::norm($name), $row['normalized_name']);
        $this->assertSame($name, $row['raw_name']);
        $this->assertSame($issn, $row['issn_raw']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('issn', $row['suggestion_method']);
        $this->assertSame($beta->getId(), (int)$row['suggested_journal_id']);
        $this->assertSame(1, (int)$row['articles_count']);
        $this->assertSame(1, (int)$row['researchers_count']);
        $this->assertSame(['Artigo X'], json_decode($row['sample_titles'], true));
        $this->assertNull($row['reviewed_at']);

        // Idempotente: a mesma linha, sem duplicar
        $again = $this->learner->learn(2, false, [$name]);
        $this->assertSame(1, $again['queued']);
        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM journal_name_candidates WHERE normalized_name = ?', [self::norm($name)]));
        $this->assertSame((int)$row['id'], (int)$this->candidate($name)['id']);

        // Com mínimo 1 o nome é aprendido na hora, e a linha que estava na fila fica resolvida
        $learned = $this->learner->learn(1, false, [$name]);
        $this->assertSame(1, $learned['learned']);
        $this->assertSame(0, $learned['queued']);
        $this->assertSame(1, $this->countVariants($name));
        $resolved = $this->candidate($name);
        $this->assertSame('accepted', $resolved['status']);
        $this->assertSame($beta->getId(), (int)$resolved['resolved_journal_id']);
        $this->assertSame(JournalNameLearner::REVIEWER, $resolved['reviewed_by']);
        $this->assertNotNull($resolved['reviewed_at']);
    }

    public function testNameConfirmedByDifferentFamiliesIsAmbiguousAndNeverLearned(): void
    {
        $t = $this->token();
        $issnA = $this->issn();
        $issnB = $this->issn();
        $alfa = $this->createJournal("REVISTA ALFA $t", $issnA);
        $beta = $this->createJournal("REVISTA BETA $t", $issnB);
        $r = $this->createResearcher();

        $name = "Rev Gama $t";
        $this->createArticle($r, $name, 'Artigo 1', 2021, $issnA, $alfa);
        $this->createArticle($r, $name, 'Artigo 2', 2020, $issnB, $beta);
        $this->createArticle($r, $name, 'Artigo 3', 2019, $issnB, $beta);

        foreach ([true, false] as $dryRun) {
            $result = $this->learner->learn(2, $dryRun, [$name]);
            $this->assertSame(0, $result['learned']);
            $this->assertSame(0, $result['queued']);
            $this->assertSame(1, $result['conflicts']);
            $this->assertSame(1, $result['candidates']);
            $this->assertSame('conflict', $result['examples'][0]['action']);
            $this->assertNull($result['examples'][0]['journal_id']);
            $this->assertStringContainsString("REVISTA ALFA $t", $result['examples'][0]['journal']);
            $this->assertStringContainsString("REVISTA BETA $t", $result['examples'][0]['journal']);
        }

        $this->assertSame(0, $this->countVariants($name));
        $row = $this->candidate($name);
        $this->assertNotNull($row);
        $this->assertSame('ambiguous', $row['status']);
        $this->assertSame('issn', $row['suggestion_method']);
        $this->assertNull($row['suggested_journal_id'], 'Em conflito não há sugestão: quem revisa escolhe');
        $this->assertSame(3, (int)$row['articles_count']);
    }

    public function testNamesThatAreNotConfirmedOrAreAlreadyKnownAreIgnored(): void
    {
        $t = $this->token();
        $issnA = $this->issn();
        $alfa = $this->createJournal("REVISTA ALFA $t", $issnA);
        $r = $this->createResearcher();
        $names = [];

        // Sem confirmação: o ISSN do artigo não é de nenhuma linha da família
        $names[] = $unconfirmed = "Rev Delta $t";
        $this->createArticle($r, $unconfirmed, 'A1', 2020, $this->issn(), $alfa);
        $this->createArticle($r, $unconfirmed, 'A2', 2021, $this->issn(), $alfa);

        // Sem ISSN
        $names[] = $noIssn = "Rev Sem Issn $t";
        $this->createArticle($r, $noIssn, 'B1', 2020, null, $alfa);
        $this->createArticle($r, $noIssn, 'B2', 2021, null, $alfa);

        // ISSN com dígito verificador inválido na família: não vale como confirmação
        $names[] = $invalid = "Rev Invalido $t";
        $broken = $this->createJournal("REVISTA INVALIDA $t", '1234-5678');
        $this->createArticle($r, $invalid, 'C1', 2020, '1234-5678', $broken);
        $this->createArticle($r, $invalid, 'C2', 2021, '1234-5678', $broken);

        // Sem vínculo
        $names[] = $unlinked = "Rev Sem Vinculo $t";
        $this->createArticle($r, $unlinked, 'D1', 2020, $issnA, null);
        $this->createArticle($r, $unlinked, 'D2', 2021, $issnA, null);

        // O nome já é o título da linha
        $names[] = $isTitle = "Revista Alfa $t";
        $this->createArticle($r, $isTitle, 'E1', 2020, $issnA, $alfa);
        $this->createArticle($r, $isTitle, 'E2', 2021, $issnA, $alfa);

        // O nome sem o complemento entre parênteses é o título sem parênteses (etapa 3 do resolver)
        $issnE = $this->issn();
        $epsilon = $this->createJournal("REVISTA EPSILON $t (ONLINE)", $issnE);
        $names[] = $isCleanTitle = "Revista Epsilon $t";
        $this->createArticle($r, $isCleanTitle, 'F1', 2020, $issnE, $epsilon);
        $this->createArticle($r, $isCleanTitle, 'F2', 2021, $issnE, $epsilon);

        // O nome é o título de uma linha cujo `normalized_title` ainda não foi gravado (antes de normalize-titles)
        $issnZ = $this->issn();
        $zeta = $this->createJournal("REVISTA ZETA $t", $issnZ);
        $this->conn->executeStatement('UPDATE qualis_journals SET normalized_title = NULL WHERE id = ?', [$zeta->getId()]);
        $names[] = $isUnnormalizedTitle = "Revista Zeta $t";
        $this->createArticle($r, $isUnnormalizedTitle, 'G1', 2020, $issnA, $alfa);
        $this->createArticle($r, $isUnnormalizedTitle, 'G2', 2021, $issnA, $alfa);

        // O nome é variante de outra família
        $theta = $this->createJournal("REVISTA TETA $t", $this->issn());
        $this->createVariant($theta, $isVariant = "Nome Variante $t");
        $names[] = $isVariant;
        $this->createArticle($r, $isVariant, 'H1', 2020, $issnA, $alfa);
        $this->createArticle($r, $isVariant, 'H2', 2021, $issnA, $alfa);

        $result = $this->learner->learn(2, false, $names);

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['learned']);
        $this->assertSame(0, $result['queued']);
        $this->assertSame(0, $result['conflicts']);
        $this->assertGreaterThanOrEqual(1, $result['unnormalized_titles']);
        foreach ($names as $name) {
            $this->assertNull($this->candidate($name), "«{$name}» não deve entrar na fila");
            $this->assertSame($name === $isVariant ? 1 : 0, $this->countVariants($name), "«{$name}» não deve virar variante");
        }
    }

    public function testReviewedQueueRowsAreRespectedAndOpenOnesAreResolvedOrRefreshed(): void
    {
        $t = $this->token();
        $issn = $this->issn();
        $alfa = $this->createJournal("REVISTA ALFA $t", $issn);
        $r = $this->createResearcher();

        $rejected = "Rev Rejeitada $t";
        $accepted = "Rev Aceita $t";
        $pendingMany = "Rev Pendente $t";
        $pendingOne = "Rev Aberta $t";
        foreach ([$rejected, $accepted, $pendingMany] as $name) {
            $this->createArticle($r, $name, "$name 1", 2020, $issn, $alfa);
            $this->createArticle($r, $name, "$name 2", 2021, $issn, $alfa);
        }
        $this->createArticle($r, $pendingOne, 'Artigo unico', 2022, $issn, $alfa);

        $this->insertCandidate($rejected, 'rejected', 5, 'none');
        $this->insertCandidate($accepted, 'accepted', 6, 'none');
        $this->insertCandidate($pendingMany, 'pending', 7, 'keywords');
        $this->insertCandidate($pendingOne, 'pending', 9, 'keywords');

        $result = $this->learner->learn(2, false, [$rejected, $accepted, $pendingMany, $pendingOne]);
        $this->assertSame(4, $result['candidates']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(1, $result['learned']);
        $this->assertSame(1, $result['queued']);

        // A decisão da revisão vale mais: nada é aprendido nem alterado
        foreach ([[$rejected, 'rejected', 5], [$accepted, 'accepted', 6]] as [$name, $status, $count]) {
            $row = $this->candidate($name);
            $this->assertSame($status, $row['status']);
            $this->assertSame($count, (int)$row['articles_count']);
            $this->assertSame('none', $row['suggestion_method']);
            $this->assertSame(0, $this->countVariants($name));
        }

        // Pendente que atingiu o mínimo: variante criada e linha resolvida
        $this->assertSame(1, $this->countVariants($pendingMany));
        $row = $this->candidate($pendingMany);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame($alfa->getId(), (int)$row['resolved_journal_id']);
        $this->assertSame(JournalNameLearner::REVIEWER, $row['reviewed_by']);

        // Pendente com 1 artigo: a sugestão por ISSN substitui a antiga e as contagens são atualizadas
        $row = $this->candidate($pendingOne);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('issn', $row['suggestion_method']);
        $this->assertSame($alfa->getId(), (int)$row['suggested_journal_id']);
        $this->assertSame(1, (int)$row['articles_count']);
        $this->assertSame(0, $this->countVariants($pendingOne));
    }

    public function testObsoleteIssnQueueRowsAreRemovedOnlyWithinTheScope(): void
    {
        $t = $this->token();
        $obsolete = "Nome Obsoleto $t";
        $obsoleteAmbiguous = "Nome Obsoleto Ambiguo $t";
        $keywords = "Nome Por Palavras $t";
        $accepted = "Nome Aceito $t";
        $outOfScope = "Nome Fora Do Escopo $t";
        $this->insertCandidate($obsolete, 'pending', 1, 'issn');
        $this->insertCandidate($obsoleteAmbiguous, 'ambiguous', 2, 'issn');
        $this->insertCandidate($keywords, 'pending', 3, 'keywords');
        $this->insertCandidate($accepted, 'accepted', 4, 'issn');
        $this->insertCandidate($outOfScope, 'pending', 5, 'issn');
        $scope = [$obsolete, $obsoleteAmbiguous, $keywords, $accepted];

        // Simulação: conta e não apaga
        $dry = $this->learner->learn(2, true, $scope);
        $this->assertSame(2, $dry['removed']);
        $this->assertNotNull($this->candidate($obsolete));

        $result = $this->learner->learn(2, false, $scope);
        $this->assertSame(2, $result['removed']);
        $this->assertSame(0, $result['candidates']);
        $this->assertNull($this->candidate($obsolete), 'Sugestão por ISSN que não vale mais sai da fila');
        $this->assertNull($this->candidate($obsoleteAmbiguous));
        $this->assertNotNull($this->candidate($keywords), 'Só as sugestões por ISSN são do aprendizado');
        $this->assertNotNull($this->candidate($accepted), 'Linha revisada não é apagada');
        $this->assertNotNull($this->candidate($outOfScope), 'Fora do escopo da execução não é tocado');
    }

    public function testLearnCommandDryRunWritesNothingAndTheRealRunCreatesTheVariant(): void
    {
        $t = $this->token();
        $issn = $this->issn();
        $alfa = $this->createJournal("REVISTA ALFA $t", $issn);
        $r = $this->createResearcher();
        $name = "Rev Alfa Comando $t";
        $this->createArticle($r, $name, 'Artigo 1', 2020, $issn, $alfa);
        $this->createArticle($r, $name, 'Artigo 2', 2021, $issn, $alfa);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:journals:names:learn'));

        $tester->execute(['--dry-run' => true, '--only' => [$name]]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Modo simulação', $tester->getDisplay());
        $this->assertStringContainsString('Variantes "lattes" a criar', $tester->getDisplay());
        $this->assertSame(0, $this->countVariants($name));

        $tester->execute(['--only' => [$name], '--min-articles' => '2']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('1 variante(s) criada(s)', $tester->getDisplay());
        $this->assertSame(1, $this->countVariants($name));

        $tester->execute(['--min-articles' => '0']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--min-articles', $tester->getDisplay());
    }

    public function testQueueCommandShowsTheStatsAndRebuildsThroughTheService(): void
    {
        $application = new Application(self::$kernel);

        // Padrão: contagens por status (somente leitura)
        $tester = new CommandTester($application->find('app:journals:names:queue'));
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Contagens por status', $display);
        $this->assertStringContainsString('Pendentes', $display);
        $this->assertStringContainsString('Ambíguos', $display);
        $this->assertStringContainsString('Total', $display);

        // --rebuild chama o serviço (simulado: rebuild() de verdade apaga candidatos pendentes de todo o banco)
        $queue = $this->createMock(JournalNameQueueService::class);
        $queue->expects($this->once())->method('rebuild')->willReturn(['pending' => 5, 'ambiguous' => 2, 'removed' => 1]);
        $resolver = $this->createMock(JournalResolverService::class);
        $resolver->expects($this->once())->method('loadFullMaps');

        $tester = new CommandTester(new JournalsNamesQueueCommand($queue, $resolver, $this->em));
        $tester->execute(['--rebuild' => true]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('5 pendente(s), 2 ambíguo(s)', preg_replace('/\s+/', ' ', $tester->getDisplay()));
        $this->assertStringNotContainsString('Contagens por status', $tester->getDisplay());

        // --rebuild --stats faz as duas coisas
        $queue = $this->createMock(JournalNameQueueService::class);
        $queue->expects($this->once())->method('rebuild')->willReturn(['pending' => 0, 'ambiguous' => 0, 'removed' => 0]);
        $resolver = $this->createMock(JournalResolverService::class);
        $tester = new CommandTester(new JournalsNamesQueueCommand($queue, $resolver, $this->em));
        $tester->execute(['--rebuild' => true, '--stats' => true]);
        $this->assertStringContainsString('Contagens por status', $tester->getDisplay());
    }

    // ------------------------------------------------------------------------------------------------

    private function token(): string
    {
        return 'Zl' . bin2hex(random_bytes(5));
    }

    /** ISSN válido (dígito verificador conferido), no formato 1234-5679. */
    private function issn(): string
    {
        $digits = str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += (int)$digits[$i] * (8 - $i);
        }
        $check = (11 - ($sum % 11)) % 11;

        return substr($digits, 0, 4) . '-' . substr($digits, 4) . ($check === 10 ? 'X' : (string)$check);
    }

    private static function norm(string $name): string
    {
        return StringNormalizer::normalizeString($name, true);
    }

    private function createJournal(string $title, ?string $issn = null, ?string $issnE = null, ?QualisJournal $canonical = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        if ($issn !== null) {
            $journal->setIssn($issn);
        }
        if ($issnE !== null) {
            $journal->setIssnE($issnE);
        }
        if ($canonical !== null) {
            $journal->setCanonical($canonical);
            $journal->setCanonicalMethod('manual');
        }
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    private function createVariant(QualisJournal $journal, string $name): void
    {
        $variation = new JournalVariation();
        $variation->setJournal($journal);
        $variation->setVariationName($name);
        $variation->setNormalizedName(self::norm($name));
        $variation->setVariationType('alternative');
        $this->em->persist($variation);
        $this->em->flush();
    }

    private function createResearcher(): Researcher
    {
        $researcher = new Researcher();
        $researcher->setIdLattes('9966' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT));
        $researcher->setFullName('Docente Teste Aprendizado de Grafias');
        $researcher->setSlug('docente-teste-aprendizado-' . bin2hex(random_bytes(4)));
        $this->em->persist($researcher);
        $this->em->flush();

        return $researcher;
    }

    private function createArticle(Researcher $researcher, string $journalName, string $title, int $year, ?string $issn, ?QualisJournal $linkedTo): ProductionItem
    {
        $article = new ProductionItem();
        $article->setItemType(ProductionItem::TYPE_ARTIGO);
        $article->setTitle($title);
        $article->setYear($year);
        $article->setJournalName($journalName);
        $article->setIssn($issn);
        $article->setQualisJournal($linkedTo);
        $researcher->addProduction($article);
        $this->em->persist($article);
        $this->em->flush();

        return $article;
    }

    private function insertCandidate(string $name, string $status, int $articles, string $method): void
    {
        $this->conn->executeStatement(
            'INSERT INTO journal_name_candidates (normalized_name, raw_name, articles_count, researchers_count, suggestion_method, status, first_seen_at, last_seen_at)
             VALUES (?, ?, ?, 1, ?, ?, NOW(), NOW())',
            [self::norm($name), $name, $articles, $method, $status]
        );
    }

    /** @return array<string, mixed>|null */
    private function candidate(string $name): ?array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM journal_name_candidates WHERE normalized_name = ?', [self::norm($name)]);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function variantRow(string $name): ?array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM journal_name_variants WHERE normalized_name = ?', [self::norm($name)]);

        return $row === false ? null : $row;
    }

    private function countVariants(string $name): int
    {
        return (int)$this->conn->fetchOne('SELECT COUNT(*) FROM journal_name_variants WHERE normalized_name = ?', [self::norm($name)]);
    }
}
