<?php

declare(strict_types=1);

namespace App\Tests\Controller\admin;

use App\Entity\JournalNameCandidate;
use App\Entity\JournalVariation;
use App\Entity\QualisJournal;
use App\Entity\User;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Busca de periódico pelo nome na fila de nomes (`app_admin_journal_name_search`, GET /admin/journal-names/search).
 *
 * Os dados usam sufixo único (só letras) e ficam dentro de uma transação revertida no tearDown: o navegador não
 * reinicia o kernel entre requisições, para que todas vejam as linhas ainda não confirmadas.
 */
class AdminJournalNameSearchTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();

        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }

        parent::tearDown();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/admin/journal-names/search?q=revista');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', (string)$this->client->getResponse()->headers->get('Location'));
    }

    public function testANonAdminUserIsForbidden(): void
    {
        $this->client->loginUser($this->createUser('usuario-comum-' . $this->token(), ['ROLE_USER']));
        $this->client->request('GET', '/admin/journal-names/search?q=revista');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testFindsTheFamilyByVariantAndAnswersWithTheCanonicalRow(): void
    {
        $t = $this->token();
        $canonical = $this->createJournal("REVISTA BUSCA $t", null, "Revista Busca $t", '0103-863X', 'a2');
        $member = $this->createJournal("BUSCA ONLINE $t", $canonical, "Revista Busca $t", null, null);
        $this->createVariant($member, "Rev. Bsc $t");
        $other = $this->createJournal("REVISTA SEM RELACAO $t", null, null, null, null);
        $this->client->loginUser($this->adminUser());

        // Pela variante (gravada na linha-membro): a resposta é a família, pela linha canônica
        $data = $this->search("Rev. Bsc $t");
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
        $this->assertSame("Rev. Bsc $t", $data['q']);
        $this->assertSame(
            [['id' => $canonical->getId(), 'name' => "Revista Busca $t", 'issn' => '0103-863X', 'qualis' => 'A2']],
            $data['journals']
        );

        // Pelo título da própria canônica e pelo da linha-membro: a mesma família, uma vez só
        $this->assertSame([$canonical->getId()], array_column($this->search("Revista Busca $t")['journals'], 'id'));
        $this->assertSame([$canonical->getId()], array_column($this->search("Busca Online $t")['journals'], 'id'));

        // Sem nome de exibição, o nome é o título; sem ISSN válido nem Qualis, nulos
        $this->assertSame(
            [['id' => $other->getId(), 'name' => "REVISTA SEM RELACAO $t", 'issn' => null, 'qualis' => null]],
            $this->search("Sem Relacao $t")['journals']
        );

        $this->assertSame([], $this->search("Revista Inexistente $t")['journals']);
    }

    public function testShortTermsReturnAnEmptyListAndTheListIsLimitedToFifteenFamilies(): void
    {
        $t = $this->token();
        for ($i = 0; $i < 17; $i++) {
            $this->createJournal(sprintf('REVISTA LOTE %s %s', $t, chr(65 + $i)), null, null, null, null);
        }
        $this->client->loginUser($this->adminUser());

        foreach (['', 'ab', ' a ', '12'] as $short) {
            $this->assertSame([], $this->search($short)['journals'], "Termo «{$short}»");
            $this->assertResponseIsSuccessful();
        }

        $journals = $this->search("Revista Lote $t")['journals'];
        $this->assertCount(15, $journals);
        $this->assertSame(15, count(array_unique(array_column($journals, 'id'))));
    }

    public function testTheQueueScreenKeepsTheNumericIdFieldAndAddsTheSearchField(): void
    {
        $t = $this->token();
        $raw = "Boletim Pendente $t";
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->insert('journal_name_candidates', [
            'normalized_name' => StringNormalizer::normalizeString($raw, true),
            'raw_name' => $raw,
            'articles_count' => 1,
            'researchers_count' => 1,
            'status' => JournalNameCandidate::STATUS_PENDING,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/journal-names/?q=' . rawurlencode($t));

        $this->assertResponseIsSuccessful();
        // O campo numérico segue obrigatório e é o único enviado; a busca não tem `name` e fica oculta até o JS rodar
        $this->assertCount(1, $crawler->filter('form input[name="journal_id"][type="number"][required]'));
        $this->assertCount(1, $crawler->filter('[data-journal-picker][hidden] input[type="search"]:not([name])'));
        $this->assertStringContainsString('/admin/journal-names/search', (string)$this->client->getResponse()->getContent());
    }

    // ------------------------------------------------------------------------------------------------

    /** @return array{q: string, journals: list<array{id: int, name: string, issn: ?string, qualis: ?string}>} */
    private function search(string $term): array
    {
        $this->client->request('GET', '/admin/journal-names/search?q=' . rawurlencode($term));

        return json_decode((string)$this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Só letras: o termo da busca precisa de 3 letras. */
    private function token(): string
    {
        return 'zq' . strtr(bin2hex(random_bytes(5)), '0123456789', 'ghijklmnop');
    }

    private function createJournal(string $title, ?QualisJournal $canonical, ?string $displayTitle, ?string $issn, ?string $qualis): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        $journal->setDisplayTitle($displayTitle);
        if ($issn !== null) {
            $journal->setIssnImp($issn);
        }
        if ($qualis !== null) {
            $journal->setQualis($qualis);
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
        $variation->setNormalizedName(StringNormalizer::normalizeString($name, true));
        $variation->setVariationType('curated');
        $variation->setStatus(true);
        $this->em->persist($variation);
        $this->em->flush();
    }

    /** @param list<string> $roles */
    private function createUser(string $username, array $roles): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setRoles($roles);
        $user->setPassword(static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'senha-de-teste'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** O admin de teste dos outros testes (criado, dentro da transação, se não existir). */
    private function adminUser(): User
    {
        return $this->em->getRepository(User::class)->findOneBy(['username' => 'admin'])
            ?? $this->createUser('admin', ['ROLE_ADMIN']);
    }
}
