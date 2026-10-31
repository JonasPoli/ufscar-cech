<?php

declare(strict_types=1);

namespace App\Tests\Controller\pub;

use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;

/**
 * O tesauro de periódicos nos pontos de leitura do site público: a busca (/busca) acha a revista por qualquer nome da
 * família e a página do docente continua mostrando o nome como ele escreveu, com o nome usual na dica e no filtro.
 *
 * Os dados usam sufixo único (só letras) e ficam dentro de uma transação revertida no tearDown; o navegador não
 * reinicia o kernel entre requisições, para que todas vejam as linhas ainda não confirmadas. Todas as requisições
 * levam um cookie de sessão: o `PageCacheService` não lê nem grava páginas de quem tem sessão, e assim nenhuma página
 * de teste fica em var/cache/public_pages.
 */
class JournalNamesReadingTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $conn;
    private string $t;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->client->getCookieJar()->set(new Cookie('TESTE_SESSAO', '1'));
        $this->conn = static::getContainer()->get('doctrine.orm.entity_manager')->getConnection();
        $this->t = 'zq' . strtr(bin2hex(random_bytes(5)), '0123456789', 'ghijklmnop');

        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------
    // /busca
    // ------------------------------------------------------------------------------------------------

    public function testSearchFindsAnArticleByTheTitleOfItsJournalFamilyAndByAnActiveVariant(): void
    {
        $t = $this->t;
        $canonical = $this->insertJournal("REVISTA CANONICA $t", null, "Revista Canônica $t");
        $member = $this->insertJournal("CANONICA ONLINE $t", $canonical, "Revista Canônica $t");
        $this->insertVariant($member, "Rev. Can. $t", "REV CAN $t");
        $researcher = $this->insertResearcher();
        $this->insertItem($researcher, 'ARTIGO', "Artigo escrito com a sigla $t", 'Rev. Cano', $member);
        $this->insertItem($researcher, 'CAPITULO', "Capítulo com vínculo antigo $t", 'Livro qualquer', $canonical);

        // Pelo título da família: o nome escrito pelo docente («Rev. Cano») não contém o termo
        $crawler = $this->client->request('GET', '/busca?q=' . rawurlencode("Revista Canônica $t"));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString("Artigo escrito com a sigla $t", $crawler->filter('body')->text());
        $this->assertStringContainsString('Produções Científicas (1)', $crawler->filter('body')->text(), 'O capítulo não entra por vínculo');
        $this->assertStringNotContainsString("Capítulo com vínculo antigo $t", $crawler->filter('body')->text());

        // Por uma variante ativa da família
        $crawler = $this->client->request('GET', '/busca?q=' . rawurlencode("Rev. Can. $t"));
        $this->assertStringContainsString("Artigo escrito com a sigla $t", $crawler->filter('body')->text());

        // O filtro de sempre continua valendo: título do próprio artigo
        $crawler = $this->client->request('GET', '/busca?q=' . rawurlencode("Capítulo com vínculo antigo $t"));
        $this->assertStringContainsString("Capítulo com vínculo antigo $t", $crawler->filter('body')->text());

        // Termo curto: a busca de sempre, sem erro
        $this->client->request('GET', '/busca?q=ab');
        $this->assertResponseIsSuccessful();
    }

    // ------------------------------------------------------------------------------------------------
    // Página do docente
    // ------------------------------------------------------------------------------------------------

    public function testProfessorPageKeepsTheWrittenNameAndAddsTheUsualNameAsTooltipAndFilterText(): void
    {
        $t = $this->t;
        $canonical = $this->insertJournal("REVISTA USUAL $t", null, "Revista Usual $t");
        $member = $this->insertJournal("USUAL ONLINE $t", $canonical, "Revista Usual $t");
        $researcher = $this->insertResearcher();
        $slug = (string)$this->conn->fetchOne('SELECT slug FROM researchers WHERE id = ?', [$researcher]);

        $this->insertItem($researcher, 'ARTIGO', "Artigo abreviado $t", "Rev. Usual $t", $member, 2021);
        $this->insertItem($researcher, 'ARTIGO', "Artigo igual $t", "REVISTA USUAL $t", $canonical, 2020);
        $this->insertItem($researcher, 'ARTIGO', "Artigo sem vínculo $t", "Outra Revista $t", null, 2019);
        $this->insertItem($researcher, 'ARTIGO', "Artigo só com vínculo $t", null, $canonical, 2018);
        $this->insertItem($researcher, 'CAPITULO', "Capítulo $t", "Livro $t", $canonical, 2017);

        $crawler = $this->client->request('GET', '/professor/' . $slug);
        $this->assertResponseIsSuccessful();

        $item = fn (string $title): Crawler => $crawler->filter('.prod-item')->reduce(
            static fn (Crawler $node): bool => str_contains($node->filter('.prod-title')->text(), $title)
        );

        // Escrito diferente do nome da família: o nome do docente aparece, o usual vai na dica e no atributo do filtro
        $abbreviatedItem = $item("Artigo abreviado $t");
        $this->assertCount(1, $abbreviatedItem);
        $this->assertSame("Revista Usual $t", $abbreviatedItem->attr('data-journal-canonical'));
        $details = $abbreviatedItem->filter('.prod-details span');
        $this->assertSame("Rev. Usual $t", trim($details->text()));
        $this->assertSame("Nome usual do periódico: Revista Usual $t", $details->attr('title'));

        // Só a caixa difere: o nome já é o usual, sem dica nem atributo
        $sameItem = $item("Artigo igual $t");
        $this->assertCount(1, $sameItem);
        $this->assertNull($sameItem->attr('data-journal-canonical'));
        $this->assertNull($sameItem->filter('.prod-details span')->attr('title'));

        // Sem vínculo: nada a acrescentar
        $this->assertNull($item("Artigo sem vínculo $t")->attr('data-journal-canonical'));

        // Vínculo sem nome escrito: o atributo existe (o filtro acha pelo nome usual), não há nome para a dica
        $this->assertSame("Revista Usual $t", $item("Artigo só com vínculo $t")->attr('data-journal-canonical'));

        // O nome do Lattes segue nos metadados acadêmicos, como o docente escreveu
        $this->assertCount(1, $crawler->filter(sprintf('meta[name="citation_journal_title"][content="Rev. Usual %s"]', $t)));
        $this->assertCount(0, $crawler->filter(sprintf('meta[name="citation_journal_title"][content="Revista Usual %s"]', $t)));

        // O filtro em JS considera o atributo
        $this->assertStringContainsString("getAttribute('data-journal-canonical')", (string)$this->client->getResponse()->getContent());

        // A aba carregada sob demanda traz a mesma marcação
        $fragment = $this->client->request('GET', "/professor/$slug/fragment/productions");
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            "Revista Usual $t",
            $fragment->filter('.prod-item')->reduce(static fn (Crawler $node): bool => str_contains($node->text(), "Artigo abreviado $t"))->attr('data-journal-canonical')
        );
    }

    public function testProfessorPageNeverLoadsJournalRowsOneByOne(): void
    {
        $t = $this->t;
        $researcher = $this->insertResearcher();
        $slug = (string)$this->conn->fetchOne('SELECT slug FROM researchers WHERE id = ?', [$researcher]);

        // Doze artigos, cada um em um periódico (e uma linha-membro) diferente
        for ($i = 1; $i <= 12; $i++) {
            $canonical = $this->insertJournal(sprintf('REVISTA N%02d %s', $i, $t), null, sprintf('Revista N%02d %s', $i, $t));
            $member = $this->insertJournal(sprintf('N%02d ONLINE %s', $i, $t), $canonical, null);
            $this->insertItem($researcher, 'ARTIGO', sprintf('Artigo %02d %s', $i, $t), sprintf('Rev. N%02d', $i), $member, 2000 + $i);
        }

        $this->client->enableProfiler();
        $crawler = $this->client->request('GET', '/professor/' . $slug);
        $this->assertResponseIsSuccessful();
        $this->assertCount(12, $crawler->filter('[data-journal-canonical]'));

        $profile = $this->client->getProfile();
        $this->assertNotFalse($profile, 'O profiler precisa estar ligado no ambiente de teste');
        $queries = [];
        foreach ($profile->getCollector('db')->getQueries() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $queries[] = (string)$query['sql'];
            }
        }

        // O profiler também guarda os INSERT da montagem do cenário: só as leituras importam
        $journalQueries = array_filter($queries, static fn (string $sql): bool => str_starts_with(ltrim($sql), 'SELECT') && str_contains($sql, 'qualis_journals'));
        $this->assertCount(1, $journalQueries, "O nome usual dos 12 artigos vem de uma consulta só:\n" . implode("\n", $journalQueries));
    }

    // ------------------------------------------------------------------------------------------------

    private function insertJournal(string $title, ?int $canonicalId, ?string $displayTitle): int
    {
        $this->conn->insert('qualis_journals', [
            'title' => $title,
            'normalized_title' => StringNormalizer::normalizeString($title, true),
            'display_title' => $displayTitle,
            'canonical_journal_id' => $canonicalId,
            'areas_locked' => 0,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    private function insertVariant(int $journalId, string $name, string $normalized): void
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->insert('journal_name_variants', [
            'journal_id' => $journalId,
            'variation_name' => $name,
            'normalized_name' => $normalized,
            'variation_type' => 'curated',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertResearcher(): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->insert('researchers', [
            'id_lattes' => '96' . str_pad((string)random_int(0, 99999999999999), 14, '0', STR_PAD_LEFT),
            'full_name' => 'Docente Teste Leitura de Periódicos',
            'slug' => 'docente-teste-leitura-' . strtolower($this->t),
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    private function insertItem(int $researcherId, string $type, string $title, ?string $journalName, ?int $journalId, int $year = 2020): void
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->insert('production_items', [
            'researcher_id' => $researcherId,
            'item_type' => $type,
            'title' => $title,
            'year' => $year,
            'journal_name' => $journalName,
            'journal_name_normalized' => $journalName !== null ? StringNormalizer::normalizeString($journalName, true) : null,
            'qualis_journal_id' => $journalId,
            'is_scientific_dissemination' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
