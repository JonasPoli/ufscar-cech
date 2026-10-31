<?php

declare(strict_types=1);

namespace App\Tests\Controller\admin;

use App\Entity\JournalNameCandidate;
use App\Entity\JournalVariation;
use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Entity\User;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tela admin da fila de revisão de nomes de periódicos (docs/evolutions/periodicos-por-base.md, A5.4).
 *
 * Os dados usam sufixo único e são removidos no tearDown, porque `cech_test` é compartilhado com outros agentes.
 * Não se chama JournalNameQueueService::rebuild(): ele apagaria os pendentes dos outros.
 */
class AdminJournalNameTest extends WebTestCase
{
    private const RESEARCHER_NAME = 'Docente Teste Admin Fila de Nomes';

    private ?EntityManagerInterface $em = null;
    private ?Connection $conn = null;

    /** @var int[] */
    private array $journalIds = [];
    /** @var int[] */
    private array $researcherIds = [];
    /** @var string[] nomes normalizados (forma do tesauro) dos candidatos criados */
    private array $candidateNames = [];

    protected function tearDown(): void
    {
        if ($this->conn !== null) {
            $this->cleanup($this->conn);
        }

        parent::tearDown();
    }

    public function testAnonymousIsRedirectedToLoginAndChangesNothing(): void
    {
        $client = $this->anonymousClient();
        $t = $this->token();
        $candidate = $this->createName("Revista Anonima {$t}");

        $client->request('GET', '/admin/journal-names/');
        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', (string)$client->getResponse()->headers->get('Location'));

        $client->request('POST', "/admin/journal-names/{$candidate->getId()}/accept", ['_token' => 'qualquer', 'journal_id' => 1]);
        $this->assertResponseRedirects();
        $this->assertSame(JournalNameCandidate::STATUS_PENDING, $this->statusOf($candidate->getId()));
    }

    public function testIndexShowsTheNameEscapedWithItsSuggestionAndHighlightsTheMenu(): void
    {
        $client = $this->adminClient();
        $t = $this->token();
        $suggested = $this->createJournal("Periódico Sugerido {$t}");
        $raw = "Boletim <i>Teste</i> {$t}";
        $this->createName($raw, $suggested);

        $crawler = $client->request('GET', "/admin/journal-names/?q={$t}");

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Fila de nomes de periódicos');
        $this->assertSelectorTextContains('body', $raw);
        $this->assertStringNotContainsString('<i>Teste</i>', (string)$client->getResponse()->getContent(), 'Texto do Lattes precisa sair escapado');
        $this->assertSelectorTextContains('body', "Periódico Sugerido {$t}");
        $this->assertCount(1, $crawler->filter(sprintf('input[name="journal_id"][value="%d"]', $suggested->getId())), 'ID da sugestão pré-preenchido');
        $this->assertCount(1, $crawler->filter(sprintf('a[href="/admin/journals/%d/edit"]', $suggested->getId())));

        // Destaque no menu: a fila acende e "Periódicos & Qualis" não
        $this->assertStringContainsString('font-semibold', (string)$crawler->filter('nav a[href="/admin/journal-names/"]')->attr('class'));
        $this->assertStringNotContainsString('font-semibold', (string)$crawler->filter('nav a[href="/admin/journals/"]')->attr('class'));
    }

    public function testAcceptLinksTheNameAsCuratedVariantAndRelinksArticles(): void
    {
        $client = $this->adminClient();
        $t = $this->token();
        $raw = "Revista Aceita {$t}";
        $journal = $this->createJournal("Periódico Destino {$t}");
        $candidate = $this->createName($raw);
        $id = $candidate->getId();
        $articleId = $this->articleIdOf($raw);

        $crawler = $client->request('GET', "/admin/journal-names/?q={$t}");
        $client->request('POST', "/admin/journal-names/{$id}/accept", [
            '_token' => $this->tokenFor($crawler, "/admin/journal-names/{$id}/accept"),
            'journal_id' => $journal->getId(),
            'status' => 'pending',
            'q' => $t,
        ]);
        $this->assertResponseRedirects();
        $client->followRedirect();

        $this->assertSelectorTextContains('body', 'aceito como variação de');
        $this->assertSelectorTextContains('body', 'Artigos revinculados: 1');

        $variant = $this->conn->fetchAssociative(
            'SELECT journal_id, variation_type, status FROM journal_name_variants WHERE normalized_name = ?',
            [self::norm($raw)]
        );
        $this->assertIsArray($variant, 'Variante criada');
        $this->assertSame($journal->getId(), (int)$variant['journal_id']);
        $this->assertSame('curated', $variant['variation_type']);

        $row = $this->conn->fetchAssociative(
            'SELECT status, resolved_journal_id, reviewed_by, reviewed_at FROM journal_name_candidates WHERE id = ?',
            [$id]
        );
        $this->assertSame(JournalNameCandidate::STATUS_ACCEPTED, $row['status']);
        $this->assertSame($journal->getId(), (int)$row['resolved_journal_id']);
        $this->assertSame('admin', $row['reviewed_by']);
        $this->assertNotNull($row['reviewed_at']);

        $this->assertSame($journal->getId(), (int)$this->conn->fetchOne('SELECT qualis_journal_id FROM production_items WHERE id = ?', [$articleId]));
    }

    public function testRejectAndReopenChangeOnlyTheStatus(): void
    {
        $client = $this->adminClient();
        $t = $this->token();
        $raw = "Revista Rejeitada {$t}";
        $candidate = $this->createName($raw);
        $id = $candidate->getId();
        $articleId = $this->articleIdOf($raw);

        $crawler = $client->request('GET', "/admin/journal-names/?q={$t}");
        $client->request('POST', "/admin/journal-names/{$id}/reject", [
            '_token' => $this->tokenFor($crawler, "/admin/journal-names/{$id}/reject"),
            'q' => $t,
        ]);
        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'continuam sem vínculo');

        $row = $this->conn->fetchAssociative('SELECT status, reviewed_by, resolved_journal_id FROM journal_name_candidates WHERE id = ?', [$id]);
        $this->assertSame(JournalNameCandidate::STATUS_REJECTED, $row['status']);
        $this->assertSame('admin', $row['reviewed_by']);
        $this->assertNull($row['resolved_journal_id']);
        $this->assertNull($this->conn->fetchOne('SELECT qualis_journal_id FROM production_items WHERE id = ?', [$articleId]));

        // Aba Rejeitados: o nome aparece com o botão Reabrir
        $crawler = $client->request('GET', "/admin/journal-names/?status=rejected&q={$t}");
        $this->assertSelectorTextContains('body', $raw);
        $client->request('POST', "/admin/journal-names/{$id}/reopen", [
            '_token' => $this->tokenFor($crawler, "/admin/journal-names/{$id}/reopen"),
            'status' => 'rejected',
            'q' => $t,
        ]);
        $client->followRedirect();

        $this->assertSame(JournalNameCandidate::STATUS_PENDING, $this->statusOf($id));
        $this->assertNull($this->conn->fetchOne('SELECT reviewed_at FROM journal_name_candidates WHERE id = ?', [$id]));
    }

    public function testInvalidTokenChangesNothing(): void
    {
        $client = $this->adminClient();
        $t = $this->token();
        $journal = $this->createJournal("Periódico Protegido {$t}");
        $raw = "Revista Protegida {$t}";
        $candidate = $this->createName($raw);
        $id = $candidate->getId();
        $articleId = $this->articleIdOf($raw);

        // Token do formulário Rejeitar não serve para Aceitar: cada ação tem o seu
        $crawler = $client->request('GET', "/admin/journal-names/?q={$t}");
        $rejectToken = $this->tokenFor($crawler, "/admin/journal-names/{$id}/reject");

        $client->request('POST', "/admin/journal-names/{$id}/accept", ['_token' => 'token-invalido', 'journal_id' => $journal->getId()]);
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Token de segurança inválido');

        $client->request('POST', "/admin/journal-names/{$id}/accept", ['_token' => $rejectToken, 'journal_id' => $journal->getId()]);
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Token de segurança inválido');

        $client->request('POST', "/admin/journal-names/{$id}/reject", ['_token' => 'token-invalido']);
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Token de segurança inválido');

        // Nada mudou: nome pendente, sem variante no periódico e artigo sem vínculo
        $this->assertSame(JournalNameCandidate::STATUS_PENDING, $this->statusOf($id));
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM journal_name_variants WHERE journal_id = ?', [$journal->getId()]));
        $this->assertNull($this->conn->fetchOne('SELECT qualis_journal_id FROM production_items WHERE id = ?', [$articleId]));

        // Reabrir com token inválido não mexe em um nome rejeitado
        $this->conn->executeStatement("UPDATE journal_name_candidates SET status = 'rejected', reviewed_by = 'admin' WHERE id = ?", [$id]);
        $client->request('POST', "/admin/journal-names/{$id}/reopen", ['_token' => 'token-invalido']);
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Token de segurança inválido');
        $this->assertSame(JournalNameCandidate::STATUS_REJECTED, $this->statusOf($id));
    }

    public function testVariantConflictShowsDangerAndChangesNothing(): void
    {
        $client = $this->adminClient();
        $t = $this->token();
        $raw = "Revista Conflitante {$t}";
        $target = $this->createJournal("Periódico Alvo {$t}");
        $other = $this->createJournal("Periódico Outro {$t}");
        $this->createVariant($other, $raw);
        $candidate = $this->createName($raw);
        $id = $candidate->getId();
        $articleId = $this->articleIdOf($raw);

        $crawler = $client->request('GET', "/admin/journal-names/?q={$t}");
        $client->request('POST', "/admin/journal-names/{$id}/accept", [
            '_token' => $this->tokenFor($crawler, "/admin/journal-names/{$id}/accept"),
            'journal_id' => $target->getId(),
        ]);
        $client->followRedirect();

        $this->assertSelectorTextContains('body', "já pertence ao periódico #{$other->getId()}");
        $this->assertSame(JournalNameCandidate::STATUS_PENDING, $this->statusOf($id));
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM journal_name_variants WHERE journal_id = ?', [$target->getId()]));
        $this->assertNull($this->conn->fetchOne('SELECT qualis_journal_id FROM production_items WHERE id = ?', [$articleId]));
    }

    public function testPaginationKeepsTheSearchAndClampsThePage(): void
    {
        $client = $this->adminClient();
        $t = $this->token();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // 51 nomes: duas páginas de 50 e 1. Linhas diretas, sem artigos, só para medir a paginação.
        for ($i = 1; $i <= 51; $i++) {
            $raw = sprintf('Periodico Paginado %s %02d', $t, $i);
            $this->candidateNames[] = self::norm($raw);
            $this->conn->insert('journal_name_candidates', [
                'normalized_name' => self::norm($raw),
                'raw_name' => $raw,
                'articles_count' => 1,
                'researchers_count' => 1,
                'status' => JournalNameCandidate::STATUS_PENDING,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        }

        $crawler = $client->request('GET', "/admin/journal-names/?q={$t}");
        $this->assertSelectorTextContains('body', 'Página 1 de 2 (51 nomes)');
        $next = $crawler->filter('a:contains("Próxima")');
        $this->assertCount(1, $next);
        $this->assertStringContainsString("q={$t}", (string)$next->attr('href'), 'A paginação mantém a busca');
        $this->assertStringContainsString('page=2', (string)$next->attr('href'));

        // Página além do fim cai na última, com a busca mantida
        $crawler = $client->request('GET', "/admin/journal-names/?q={$t}&page=99");
        $this->assertSelectorTextContains('body', 'Página 2 de 2 (51 nomes)');
        // Só o campo de busca, não os campos ocultos dos formulários de cada linha
        $this->assertCount(1, $crawler->filter('input[type="text"][name="q"][value="' . $t . '"]'));
    }

    /**
     * Cliente anônimo, com os serviços do container de teste já disponíveis para montar os dados.
     */
    private function anonymousClient(): KernelBrowser
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();

        return $client;
    }

    /**
     * Cliente logado como o admin de teste (criado se não existir), como em AdminJournalTest.
     */
    private function adminClient(): KernelBrowser
    {
        $client = $this->anonymousClient();
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);

        if (!$user) {
            $user = new User();
            $user->setUsername('admin');
            $user->setRoles(['ROLE_ADMIN']);
            $hasher = static::getContainer()->get('security.user_password_hasher');
            $user->setPassword($hasher->hashPassword($user, 'admin123'));
            $this->em->persist($user);
            $this->em->flush();
        }

        $client->loginUser($user);

        return $client;
    }

    /**
     * Token do formulário de uma ação, lido da própria tela (o token fica ligado à sessão do cliente).
     */
    private function tokenFor(Crawler $crawler, string $action): string
    {
        $field = $crawler->filter(sprintf('form[action="%s"] input[name="_token"]', $action));
        $this->assertCount(1, $field, "Formulário de {$action} não aparece na tela");

        return (string)$field->attr('value');
    }

    /**
     * Nome em revisão: candidato pendente e o artigo sem vínculo que o originou, como a indexação faz. Sem o artigo,
     * um rebuild() de outro teste trataria o candidato como sumido e o apagaria.
     */
    private function createName(string $rawName, ?QualisJournal $suggested = null): JournalNameCandidate
    {
        $this->createArticle($rawName);

        $candidate = new JournalNameCandidate();
        $candidate->setNormalizedName(self::norm($rawName));
        $candidate->setRawName($rawName);
        $candidate->setIssnRaw('2345-6789');
        $candidate->setArticlesCount(1);
        $candidate->setResearchersCount(1);
        $candidate->setStatus(JournalNameCandidate::STATUS_PENDING);
        $candidate->setSuggestedJournal($suggested);
        $candidate->setSuggestionMethod($suggested !== null ? JournalNameCandidate::SUGGESTION_KEYWORDS : JournalNameCandidate::SUGGESTION_NONE);
        $candidate->setSampleTitles(['Primeiro título da amostra', 'Segundo título da amostra']);
        $this->em->persist($candidate);
        $this->em->flush();
        $this->candidateNames[] = $candidate->getNormalizedName();

        return $candidate;
    }

    private function createArticle(string $journalName): void
    {
        $researcher = new Researcher();
        $researcher->setIdLattes('9988' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT));
        $researcher->setFullName(self::RESEARCHER_NAME);
        $researcher->setSlug('docente-teste-admin-fila-' . bin2hex(random_bytes(4)));
        $this->em->persist($researcher);
        $this->em->flush();
        $this->researcherIds[] = $researcher->getId();

        $article = new ProductionItem();
        $article->setItemType(ProductionItem::TYPE_ARTIGO);
        $article->setTitle('Artigo de teste da fila de nomes');
        $article->setYear(2024);
        $article->setJournalName($journalName);
        $researcher->addProduction($article);
        $this->em->persist($article);
        $this->em->flush();
    }

    private function articleIdOf(string $rawName): int
    {
        return (int)$this->conn->fetchOne(
            "SELECT id FROM production_items WHERE item_type = 'ARTIGO' AND journal_name_normalized = ?",
            [self::norm($rawName)]
        );
    }

    private function createJournal(string $title): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        $this->em->persist($journal);
        $this->em->flush();
        $this->journalIds[] = $journal->getId();

        return $journal;
    }

    /** Variante gravada direto pela entidade, para montar o cenário de conflito. */
    private function createVariant(QualisJournal $journal, string $name): void
    {
        $variation = new JournalVariation();
        $variation->setJournal($journal);
        $variation->setVariationName($name);
        $variation->setNormalizedName(self::norm($name));
        $variation->setVariationType('alternative');
        $variation->setStatus(true);
        $this->em->persist($variation);
        $this->em->flush();
    }

    private function statusOf(?int $candidateId): string
    {
        return (string)$this->conn->fetchOne('SELECT status FROM journal_name_candidates WHERE id = ?', [$candidateId]);
    }

    private function cleanup(Connection $conn): void
    {
        if ($this->candidateNames !== []) {
            $conn->executeStatement(
                'DELETE FROM journal_name_candidates WHERE normalized_name IN (' . $this->placeholders($this->candidateNames) . ')',
                $this->candidateNames
            );
        }
        if ($this->researcherIds !== []) {
            // Os artigos de teste saem junto com o docente (ON DELETE CASCADE)
            $conn->executeStatement(
                'DELETE FROM researchers WHERE id IN (' . $this->placeholders($this->researcherIds) . ')',
                $this->researcherIds
            );
        }
        if ($this->journalIds !== []) {
            $conn->executeStatement(
                'DELETE FROM journal_name_variants WHERE journal_id IN (' . $this->placeholders($this->journalIds) . ')',
                $this->journalIds
            );
            $conn->executeStatement(
                'DELETE FROM qualis_journals WHERE id IN (' . $this->placeholders($this->journalIds) . ')',
                $this->journalIds
            );
        }
    }

    /** @param array<int|string> $values */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    private function token(): string
    {
        return 'Zt' . bin2hex(random_bytes(5));
    }

    private static function norm(string $name): string
    {
        return StringNormalizer::normalizeString($name, true);
    }
}
