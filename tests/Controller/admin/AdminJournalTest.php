<?php

namespace App\Tests\Controller\admin;

use App\Entity\JournalVariation;
use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Entity\User;
use App\Service\Journal\CapesTaxonomy;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Admin de periódicos: formulário (áreas CAPES, conflitos de variantes, nome de exibição), índice, exportações e
 * importações (docs/evolutions/periodicos-por-base.md, A5.3 e A6.8).
 *
 * Os dados usam sufixo único e saem no tearDown: `cech_test` é compartilhado com outros testes e agentes.
 */
class AdminJournalTest extends WebTestCase
{
    private const LABEL_EDUCACAO = 'EDUCAÇÃO / CIÊNCIAS HUMANAS';

    private ?EntityManagerInterface $em = null;
    private ?Connection $conn = null;
    private string $uniq = '';

    /** @var int[] */
    private array $researcherIds = [];
    /** @var int[] */
    private array $databaseIds = [];
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->uniq = 'qz' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->conn !== null) {
            foreach ($this->researcherIds as $id) {
                // Os artigos de teste saem junto com o docente (ON DELETE CASCADE)
                $this->conn->executeStatement('DELETE FROM researchers WHERE id = ?', [$id]);
            }
            // Áreas, evidências, variantes e bases saem em cascata; as famílias se desfazem por SET NULL
            $this->conn->executeStatement('DELETE FROM qualis_journals WHERE title LIKE ?', ['%' . $this->uniq . '%']);
            foreach ($this->databaseIds as $id) {
                $this->conn->executeStatement('DELETE FROM academic_database WHERE id = ?', [$id]);
            }
        }
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function getAuthenticatedClient(): KernelBrowser
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;
        $this->conn = $em->getConnection();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'admin']);

        if (!$user) {
            $user = new User();
            $user->setUsername('admin');
            $user->setRoles(['ROLE_ADMIN']);
            $hasher = static::getContainer()->get('security.user_password_hasher');
            $user->setPassword($hasher->hashPassword($user, 'admin123'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);

        // A primeira requisição de um cliente roda no mesmo container (e no mesmo EntityManager) em que o teste monta os
        // dados, e ali as entidades ficam desatualizadas em relação ao que o teste grava por SQL: o formulário sairia com
        // valores velhos. Uma requisição de aquecimento faz todas as seguintes rodarem num container novo, como na vida real.
        $client->request('GET', '/login');

        return $client;
    }

    public function testAdminJournalsIndex(): void
    {
        $client = $this->getAuthenticatedClient();
        $client->request('GET', '/admin/journals/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Periódicos & Qualis CAPES');
    }

    public function testAdminJournalNewAndEdit(): void
    {
        $client = $this->getAuthenticatedClient();
        $em = $this->em;

        // New page
        $crawler = $client->request('GET', '/admin/journals/new');
        $this->assertResponseIsSuccessful();
        $token = $this->formToken($crawler);

        // Create (as variações são únicas no tesauro: sufixo novo a cada execução)
        $suffix = $this->uniq;
        $title = "Test Journal CECH Studies {$suffix}";
        $client->request('POST', '/admin/journals/new', [
            '_token' => $token,
            'title' => $title,
            'issn' => '9999-8888',
            'qualis' => 'A1',
            'area' => 'INTERDISCIPLINAR',
            'variations' => "TJCS {$suffix}\nTest Journal of CECH {$suffix}",
        ]);

        $this->assertResponseRedirects('/admin/journals/');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'cadastrado com sucesso');

        $created = $em->getRepository(QualisJournal::class)->findOneBy(['title' => $title]);
        $this->assertNotNull($created);
        $this->assertEquals('A1', $created->getQualis());
        $this->assertCount(2, $created->getVariations());
        foreach ($created->getVariations() as $variation) {
            $this->assertSame('manual', $variation->getVariationType(), 'Variantes digitadas no formulário são manuais');
        }
    }

    public function testAdminJournalExports(): void
    {
        $client = $this->getAuthenticatedClient();

        // CSV export
        $client->request('GET', '/admin/journals/export');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/csv; charset=utf-8');

        // Thesaurus export
        $client->request('GET', '/admin/journals/export-thesaurus?format=the');
        $this->assertResponseIsSuccessful();
    }

    // ------------------------------------------------------------------------------------------------------------
    // CSRF
    // ------------------------------------------------------------------------------------------------------------

    public function testNewAndEditRequireTheFormToken(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("Revista Protegida {$this->uniq}");
        $id = $journal->getId();

        $client->request('POST', '/admin/journals/new', ['title' => "Revista Sem Token {$this->uniq}"]);
        $this->assertResponseRedirects('/admin/journals/new');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Token CSRF inválido');
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE title = ?', ["Revista Sem Token {$this->uniq}"]));

        $client->request('POST', "/admin/journals/{$id}/edit", ['_token' => 'invalido', 'title' => "Titulo Trocado {$this->uniq}"]);
        $this->assertResponseRedirects("/admin/journals/{$id}/edit");
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Token CSRF inválido');
        $this->assertSame("Revista Protegida {$this->uniq}", $this->conn->fetchOne('SELECT title FROM qualis_journals WHERE id = ?', [$id]));
    }

    public function testAnonymousUserCannotUnlockOrEditAndChangesNothing(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $journal = $this->createJournal("Revista Fechada {$this->uniq}", 'Texto original');
        $id = $journal->getId();
        $this->curateEducationDirectly($id);

        foreach (["/admin/journals/{$id}/areas/unlock", "/admin/journals/{$id}/edit"] as $url) {
            $client->request('POST', $url, ['_token' => 'qualquer', 'title' => 'Invasor', 'areas' => ['educacao']]);
            $this->assertResponseRedirects();
            $this->assertStringContainsString('/login', (string)$client->getResponse()->headers->get('Location'));
        }

        $this->assertSame("Revista Fechada {$this->uniq}", $this->conn->fetchOne('SELECT title FROM qualis_journals WHERE id = ?', [$id]));
        $this->assertSame([['educacao', 'manual']], array_map(static fn (array $r): array => [$r['slug'], $r['method']], $this->areasOf($id)), 'A curadoria existente continua');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Áreas CAPES no formulário
    // ------------------------------------------------------------------------------------------------------------

    public function testEditSavesManualAreasLocksAndWritesTheCanonicalLabel(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("Revista Educação {$this->uniq}", 'Texto original');
        $id = $journal->getId();

        $crawler = $client->request('GET', "/admin/journals/{$id}/edit");
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#capesAreasBlock h3', 'Área de Avaliação / Conhecimento (CAPES)');
        $this->assertCount(59, $crawler->filter('input[name="areas[]"]'), 'Grandes áreas e áreas de avaliação');
        $this->assertCount(1, $crawler->filter('select[name="area_primary"]'));
        $this->assertCount(1, $crawler->filter('input[name="areas_locked"]'));
        $this->assertCount(1, $crawler->filter('input[name="area"]:not([readonly])'), 'Sem áreas, o campo `area` é editável como sempre');
        $this->assertSelectorTextContains('label[for="areaPrimary"]', 'Área principal');
        $this->assertCount(0, $crawler->filter('#unlockAreasForm'), 'Sem curadoria, não há o que desfazer');

        $this->postEdit($client, $id, ['area' => 'Texto original', 'areas' => ['educacao', 'sociologia'], 'area_primary' => 'educacao']);
        $this->assertResponseRedirects('/admin/journals/');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'atualizado com sucesso');
        $this->assertSelectorTextContains('body', 'Áreas CAPES gravadas (curadoria manual, travadas): Educação (principal), Sociologia.');

        $rows = $this->areasOf($id);
        $this->assertCount(2, $rows);
        $this->assertSame([['educacao', 1], ['sociologia', 0]], array_map(static fn (array $r): array => [$r['slug'], (int)$r['is_primary']], $rows));
        foreach ($rows as $row) {
            $this->assertSame('manual', $row['method']);
            $this->assertSame('manual', $row['confidence']);
            $this->assertEquals(1, $row['score']);
        }
        $this->assertSame(1, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$id]));
        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => 'Texto original'], $this->columnsOf($id));

        // De volta ao formulário: `area` somente leitura com o rótulo, valor original abaixo, trava e botão de voltar
        $crawler = $client->request('GET', "/admin/journals/{$id}/edit");
        $this->assertCount(1, $crawler->filter('input[name="area"][readonly]'));
        $this->assertSame(self::LABEL_EDUCACAO, $crawler->filter('input[name="area"]')->attr('value'));
        $this->assertSelectorTextContains('body', 'Valor original importado: Texto original');
        $this->assertCount(1, $crawler->filter('input[name="areas_locked"][disabled][checked]'));
        $this->assertCount(1, $crawler->filter('button[form="unlockAreasForm"]'));
        $this->assertCount(2, $crawler->filter('input[name="areas[]"][checked]'));
        $this->assertSame('educacao', $crawler->filter('select[name="area_primary"] option[selected]')->attr('value'));
        $this->assertSelectorTextContains('#capesAreasBlock', 'Curadoria manual vigente (travada).');

        // O servidor ignora um `area` digitado quando a família tem áreas, mesmo sem mexer nas áreas
        $this->postEdit($client, $id, ['area' => 'Outra coisa', 'areas' => ['educacao', 'sociologia'], 'area_primary' => 'educacao']);
        $this->assertSame(self::LABEL_EDUCACAO, $this->columnsOf($id)['area']);
        $this->assertCount(2, $this->areasOf($id));
    }

    public function testSavingTheFormWithTheAutomaticAreasUntouchedKeepsThemAutomaticAndUnlocked(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("Revista Automática {$this->uniq}");
        $id = $journal->getId();
        $this->insertArea($id, 'educacao', 1, 'auto', 'high', '0.7500');
        $this->insertArea($id, 'sociologia', 0, 'auto', 'medium', '0.2500');
        $this->conn->executeStatement('UPDATE qualis_journals SET area = ?, area_raw = ? WHERE id = ?', [self::LABEL_EDUCACAO, 'Original', $id]);

        // O formulário abre com as áreas automáticas marcadas; salvar só com outro título não pode congelar a classificação
        $crawler = $client->request('GET', "/admin/journals/{$id}/edit");
        $this->assertSelectorTextContains('#capesAreasBlock', 'Resultado automático');
        $this->assertCount(2, $crawler->filter('input[name="areas[]"][checked]'));
        $this->postEdit($client, $id, ['title' => "Revista Automática Renomeada {$this->uniq}", 'areas' => ['educacao', 'sociologia'], 'area_primary' => 'educacao']);

        $this->assertSame("Revista Automática Renomeada {$this->uniq}", $this->conn->fetchOne('SELECT title FROM qualis_journals WHERE id = ?', [$id]));
        $this->assertSame(['auto', 'auto'], array_column($this->areasOf($id), 'method'));
        $this->assertSame(0, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$id]));

        // Mudar o conjunto (ou a principal) vira curadoria manual
        $this->postEdit($client, $id, ['title' => "Revista Automática Renomeada {$this->uniq}", 'areas' => ['educacao', 'sociologia'], 'area_primary' => 'sociologia']);
        $rows = $this->areasOf($id);
        $this->assertSame(['manual', 'manual'], array_column($rows, 'method'));
        $this->assertSame('sociologia', $rows[0]['slug'], 'A principal vem primeiro');
        $this->assertSame(1, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$id]));
        $this->assertSame('SOCIOLOGIA / CIÊNCIAS HUMANAS', $this->columnsOf($id)['area']);
    }

    public function testSubmittingTheUntouchedFormChangesNothingAtAll(): void
    {
        $client = $this->getAuthenticatedClient();
        $head = new QualisJournal();
        $head->setTitle("Revista Completa {$this->uniq}");
        $head->setIssnImp('1234-5678');
        $head->setIssnE('8765-4321');
        $head->setQualis('b2');
        $head->setArea('Original');
        $head->setAreaRaw('Original');
        $this->em->persist($head);
        $this->em->flush();
        $member = $this->createJournal("Revista Completa Online {$this->uniq}", null, $head);
        $headId = $head->getId();
        $this->createVariant($head, "Apelido Um {$this->uniq}");
        $this->createVariant($head, "Apelido Dois {$this->uniq}");
        $this->insertArea($headId, 'educacao', 1, 'auto', 'high', '0.6000');
        $this->insertArea($headId, 'psicologia', 0, 'auto', 'medium', '0.4000');
        $this->insertArea($member->getId(), 'educacao', 1, 'auto', 'high', '0.6000');
        $this->conn->executeStatement("UPDATE qualis_journals SET display_title = 'Nome Automático', display_title_source = 'doaj', area = ? WHERE id IN (?, ?)", [self::LABEL_EDUCACAO, $headId, $member->getId()]);
        $databaseId = (int)$this->conn->fetchOne('SELECT MIN(id) FROM academic_database');
        $this->assertGreaterThan(0, $databaseId, 'O banco de teste precisa ter ao menos uma base de indexação');
        $this->conn->executeStatement('INSERT INTO qualis_journal_academic_database (qualis_journal_id, academic_database_id) VALUES (?, ?)', [$headId, $databaseId]);

        $snapshot = fn (): array => [
            $this->conn->fetchAllAssociative('SELECT * FROM qualis_journals WHERE id IN (?, ?) ORDER BY id', [$headId, $member->getId()]),
            $this->conn->fetchAllAssociative('SELECT * FROM journal_areas WHERE journal_id IN (?, ?) ORDER BY id', [$headId, $member->getId()]),
            $this->conn->fetchAllAssociative('SELECT * FROM journal_name_variants WHERE journal_id IN (?, ?) ORDER BY id', [$headId, $member->getId()]),
            $this->conn->fetchAllAssociative('SELECT * FROM qualis_journal_academic_database WHERE qualis_journal_id IN (?, ?)', [$headId, $member->getId()]),
        ];
        $before = $snapshot();

        // Envia o formulário como o navegador envia: todos os campos com os valores que a tela trouxe
        foreach ([$headId, $member->getId()] as $id) {
            $crawler = $client->request('GET', "/admin/journals/{$id}/edit");
            $client->submit($crawler->filter('form#journalForm')->form());
            $this->assertResponseRedirects('/admin/journals/');
            $client->followRedirect();
            $this->assertSelectorTextContains('body', 'atualizado com sucesso');
        }

        $this->assertSame($before, $snapshot(), 'Salvar o formulário sem mexer em nada não pode alterar nenhum dado');
    }

    public function testLockingTheCurrentAutomaticAreasTurnsThemIntoManualCuration(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("Revista Para Travar {$this->uniq}");
        $id = $journal->getId();
        $this->insertArea($id, 'direito', 1, 'auto', 'low', '0.4000');

        $this->postEdit($client, $id, ['areas' => ['direito'], 'area_primary' => 'direito', 'areas_locked' => '1']);

        $this->assertSame(['manual'], array_column($this->areasOf($id), 'method'));
        $this->assertSame(1, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$id]));

        // Sem nenhuma área e pedindo para travar: só marca a trava (o periódico fica fora da classificação automática)
        $other = $this->createJournal("Revista Sem Classificação {$this->uniq}");
        $this->postEdit($client, $other->getId(), ['areas_locked' => '1']);
        $this->assertSame([], $this->areasOf($other->getId()));
        $this->assertSame(1, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$other->getId()]));

        // Sem nenhuma área marcada e sem pedido de trava, nada acontece com uma curadoria existente
        $this->postEdit($client, $id, []);
        $this->assertSame(['manual'], array_column($this->areasOf($id), 'method'));
        $this->assertSame(1, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$id]));
    }

    public function testAreasAreReplicatedToTheMembersAndTheMemberFormIsReadOnly(): void
    {
        $client = $this->getAuthenticatedClient();
        $head = $this->createJournal("Revista Cabeça {$this->uniq}");
        $member = $this->createJournal("Revista Cabeça Online {$this->uniq}", null, $head);
        $headId = $head->getId();
        $memberId = $member->getId();

        $this->postEdit($client, $headId, ['areas' => ['direito', 'educacao'], 'area_primary' => 'direito']);

        $this->assertSame(['direito', 'educacao'], array_column($this->areasOf($headId), 'slug'));
        $this->assertSame(['direito', 'educacao'], array_column($this->areasOf($memberId), 'slug'));
        $this->assertSame('DIREITO / CIÊNCIAS SOCIAIS APLICADAS', $this->columnsOf($memberId)['area']);
        $this->assertSame(1, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$headId]));
        $this->assertSame(0, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$memberId]));

        // Linha membro: bloco somente leitura, com aviso e link para a canônica
        $crawler = $client->request('GET', "/admin/journals/{$memberId}/edit");
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('input[name="areas[]"]'));
        $this->assertCount(0, $crawler->filter('select[name="area_primary"]'));
        $this->assertCount(0, $crawler->filter('#unlockAreasForm'));
        $this->assertSelectorTextContains('#capesAreasBlock', "Agrupado sob #{$headId} «Revista Cabeça {$this->uniq}» — edite as áreas lá.");
        $this->assertCount(1, $crawler->filter(sprintf('#capesAreasBlock a[href="/admin/journals/%d/edit"]', $headId)));
        $this->assertSelectorTextContains('#capesAreasBlock', 'Direito');
        $this->assertCount(1, $crawler->filter('input[name="area"][readonly]'));

        // Um POST forjado com áreas na linha membro não muda nada
        $this->postEdit($client, $memberId, ['title' => "Revista Cabeça Online {$this->uniq}", 'areas' => ['sociologia'], 'area_primary' => 'sociologia']);
        $this->assertSame(['direito', 'educacao'], array_column($this->areasOf($headId), 'slug'));
        $this->assertSame(['direito', 'educacao'], array_column($this->areasOf($memberId), 'slug'));
    }

    public function testUnlockRemovesManualAreasUnlocksAndRunsTheAutomaticClassification(): void
    {
        $client = $this->getAuthenticatedClient();
        $head = $this->createJournal("Revista Destravar {$this->uniq}");
        $member = $this->createJournal("Revista Destravar Online {$this->uniq}", null, $head);
        $headId = $head->getId();
        // Evidência que classifica a família como Direito, quando a curadoria sair do caminho
        $this->insertEvidence($member->getId(), 'wos', 'W1', 'Law');

        $this->postEdit($client, $headId, ['areas' => ['educacao'], 'area_primary' => 'educacao']);
        $this->assertSame(['manual'], array_column($this->areasOf($headId), 'method'));

        $crawler = $client->request('GET', "/admin/journals/{$headId}/edit");
        $this->assertCount(1, $crawler->filter('button[form="unlockAreasForm"]'));
        $evidenceCells = $crawler->filter('#capesAreasBlock table tbody tr')->first()->filter('td');
        $this->assertSame(['wos', 'Law', '1,00', '—'], array_map('trim', $evidenceCells->each(static fn (Crawler $td): string => $td->text())), 'A evidência da linha membro aparece no formulário da canônica');
        $unlockToken = (string)$crawler->filter('form#unlockAreasForm input[name="_token"]')->attr('value');
        $formToken = $this->formToken($crawler);
        $this->assertSame("/admin/journals/{$headId}/areas/unlock", $crawler->filter('form#unlockAreasForm')->attr('action'));

        // Token inválido: nada muda
        $client->request('POST', "/admin/journals/{$headId}/areas/unlock", ['_token' => 'invalido']);
        $this->assertResponseRedirects("/admin/journals/{$headId}/edit");
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Token de segurança inválido');
        $this->assertSame(['manual'], array_column($this->areasOf($headId), 'method'));
        $this->assertSame(1, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$headId]));

        // O token de outra ação (o do formulário principal) também não serve
        $client->request('POST', "/admin/journals/{$headId}/areas/unlock", ['_token' => $formToken]);
        $client->followRedirect();
        $this->assertSame(1, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$headId]));

        $client->request('POST', "/admin/journals/{$headId}/areas/unlock", ['_token' => $unlockToken]);
        $this->assertResponseRedirects("/admin/journals/{$headId}/edit");
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Curadoria manual de «Revista Destravar ' . $this->uniq . '» removida');
        $this->assertSelectorTextContains('body', 'Classificação automática: Direito (confiança alta)');

        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM journal_areas WHERE method = 'manual' AND journal_id IN (?, ?)", [$headId, $member->getId()]));
        $this->assertSame(0, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$headId]));
        foreach ([$headId, $member->getId()] as $rowId) {
            $rows = $this->areasOf($rowId);
            $this->assertSame([['direito', 'auto', 'high']], array_map(static fn (array $r): array => [$r['slug'], $r['method'], $r['confidence']], $rows));
            $this->assertSame('DIREITO / CIÊNCIAS SOCIAIS APLICADAS', $this->columnsOf($rowId)['area']);
        }
        $this->assertCount(0, $client->getCrawler()->filter('#unlockAreasForm'), 'Já voltou ao automático');
    }

    public function testUnlockWithoutEvidenceRestoresTheOriginalAreaText(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("Revista Sem Evidência {$this->uniq}", 'Texto original');
        $id = $journal->getId();
        $this->postEdit($client, $id, ['area' => 'Texto original', 'areas' => ['educacao'], 'area_primary' => 'educacao']);
        $this->assertSame(self::LABEL_EDUCACAO, $this->columnsOf($id)['area']);

        $crawler = $client->request('GET', "/admin/journals/{$id}/edit");
        $client->request('POST', "/admin/journals/{$id}/areas/unlock", ['_token' => $crawler->filter('form#unlockAreasForm input[name="_token"]')->attr('value')]);
        $client->followRedirect();

        $this->assertSelectorTextContains('body', 'Sem evidência suficiente para classificar');
        $this->assertSame([], $this->areasOf($id));
        $this->assertSame(0, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$id]));
        $this->assertSame(['area' => 'Texto original', 'area_raw' => 'Texto original'], $this->columnsOf($id));
    }

    public function testFreeTextAreaStaysEditableWithoutAreasAndKeepsAreaRawInSync(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("Revista Texto Livre {$this->uniq}", 'Antigo');
        $id = $journal->getId();

        $this->postEdit($client, $id, ['area' => 'Novo texto']);
        $this->assertSame(['area' => 'Novo texto', 'area_raw' => 'Novo texto'], $this->columnsOf($id));

        // Esvaziar o campo também vale (a próxima classificação não o desfaz porque `area_raw` acompanha)
        $this->postEdit($client, $id, ['area' => '']);
        $this->assertSame(['area' => null, 'area_raw' => null], $this->columnsOf($id));

        // Um rótulo canônico digitado à mão não vai para `area_raw`
        $this->postEdit($client, $id, ['area' => self::LABEL_EDUCACAO]);
        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => null], $this->columnsOf($id));
    }

    public function testNewJournalCanBeCreatedWithAreasAndDisplayTitle(): void
    {
        $client = $this->getAuthenticatedClient();
        $crawler = $client->request('GET', '/admin/journals/new');
        $this->assertCount(59, $crawler->filter('input[name="areas[]"]'));
        $this->assertCount(0, $crawler->filter('#unlockAreasForm'));
        $token = $this->formToken($crawler);

        $client->request('POST', '/admin/journals/new', [
            '_token' => $token,
            'title' => "Revista Nova Com Áreas {$this->uniq}",
            'area' => 'Texto digitado',
            'display_title' => "Nome Bonito {$this->uniq}",
            'areas' => ['psicologia'],
            'variations' => "Rev Nova {$this->uniq}",
        ]);
        $this->assertResponseRedirects('/admin/journals/');

        $row = $this->conn->fetchAssociative('SELECT id, area, area_raw, areas_locked, display_title, display_title_source FROM qualis_journals WHERE title = ?', ["Revista Nova Com Áreas {$this->uniq}"]);
        $this->assertIsArray($row);
        $this->assertSame(['psicologia'], array_column($this->areasOf((int)$row['id']), 'slug'));
        $this->assertSame('PSICOLOGIA / CIÊNCIAS HUMANAS', $row['area']);
        $this->assertSame('Texto digitado', $row['area_raw']);
        $this->assertSame(1, (int)$row['areas_locked']);
        $this->assertSame("Nome Bonito {$this->uniq}", $row['display_title']);
        $this->assertSame('manual', $row['display_title_source']);
    }

    public function testInvalidAreaSlugIsRejectedWithoutSavingAnything(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("Revista Área Inválida {$this->uniq}");
        $id = $journal->getId();

        $this->postEdit($client, $id, ['title' => "Outro Título {$this->uniq}", 'areas' => ['educacao', 'nao-existe'], 'area_primary' => 'educacao']);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorTextContains('body', 'Área CAPES desconhecida: «nao-existe».');
        $this->assertSame("Revista Área Inválida {$this->uniq}", $this->conn->fetchOne('SELECT title FROM qualis_journals WHERE id = ?', [$id]));
        $this->assertSame([], $this->areasOf($id));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Nome de exibição
    // ------------------------------------------------------------------------------------------------------------

    public function testDisplayTitleIsManualAndReplicatedToTheWholeFamily(): void
    {
        $client = $this->getAuthenticatedClient();
        $head = $this->createJournal("Revista Nome {$this->uniq}");
        $member = $this->createJournal("Revista Nome Online {$this->uniq}", null, $head);
        $other = $this->createJournal("Revista Alheia {$this->uniq}");
        $this->conn->executeStatement("UPDATE qualis_journals SET display_title = 'Automático', display_title_source = 'scielo' WHERE id IN (?, ?)", [$head->getId(), $member->getId()]);

        // Salvar sem mexer no campo não troca a fonte automática por manual
        $this->postEdit($client, $member->getId(), ['title' => "Revista Nome Online {$this->uniq}", 'display_title' => 'Automático']);
        $this->assertSame('scielo', $this->conn->fetchOne('SELECT display_title_source FROM qualis_journals WHERE id = ?', [$head->getId()]));

        // Preenchido pela linha membro: vale para a família inteira, como manual
        $crawler = $client->request('GET', "/admin/journals/{$member->getId()}/edit");
        $this->assertSame('Automático', $crawler->filter('input[name="display_title"]')->attr('value'));
        $this->postEdit($client, $member->getId(), ['title' => "Revista Nome Online {$this->uniq}", 'display_title' => 'Revista Escolhida']);
        foreach ([$head->getId(), $member->getId()] as $rowId) {
            $this->assertSame(['Revista Escolhida', 'manual'], array_values($this->conn->fetchAssociative('SELECT display_title, display_title_source FROM qualis_journals WHERE id = ?', [$rowId])));
        }
        $this->assertNull($this->conn->fetchOne('SELECT display_title FROM qualis_journals WHERE id = ?', [$other->getId()]));

        // Em branco: volta ao cálculo automático (a próxima reconstrução de famílias preenche de novo)
        $this->postEdit($client, $head->getId(), ['title' => "Revista Nome {$this->uniq}", 'display_title' => '']);
        foreach ([$head->getId(), $member->getId()] as $rowId) {
            $this->assertSame([null, null], array_values($this->conn->fetchAssociative('SELECT display_title, display_title_source FROM qualis_journals WHERE id = ?', [$rowId])));
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Conflitos de variantes (A5.3)
    // ------------------------------------------------------------------------------------------------------------

    public function testVariationAlreadyOwnedByAnotherJournalShowsTheMessageAndSavesNothing(): void
    {
        $client = $this->getAuthenticatedClient();
        $owner = $this->createJournal("Revista Dona {$this->uniq}");
        $otherOwner = $this->createJournal("Revista Dona Dois {$this->uniq}");
        $journal = $this->createJournal("Revista Que Quer {$this->uniq}");
        $this->createVariant($owner, "Nome Reservado {$this->uniq}");
        $this->createVariant($otherOwner, "Outro Reservado {$this->uniq}");
        $id = $journal->getId();

        $this->postEdit($client, $id, [
            'title' => "Título Que Não Deve Gravar {$this->uniq}",
            'qualis' => 'B2',
            'variations' => "Nome Livre {$this->uniq}\nNOME RESERVADO {$this->uniq}\noutro reservado {$this->uniq}",
        ]);

        // Resposta 200 (o formulário volta) com a mensagem exata de cada conflito, e nada gravado
        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorTextContains(
            'body',
            "A variação «NOME RESERVADO {$this->uniq}» já pertence ao periódico #{$owner->getId()} «Revista Dona {$this->uniq}». Remova-a daqui ou mescle os periódicos."
        );
        $this->assertSelectorTextContains(
            'body',
            "A variação «outro reservado {$this->uniq}» já pertence ao periódico #{$otherOwner->getId()} «Revista Dona Dois {$this->uniq}». Remova-a daqui ou mescle os periódicos."
        );
        $this->assertSame(
            ["Revista Que Quer {$this->uniq}", null],
            array_values($this->conn->fetchAssociative('SELECT title, qualis FROM qualis_journals WHERE id = ?', [$id])),
            'O título e o Qualis digitados não foram gravados'
        );
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_name_variants WHERE journal_id = ?', [$id]), 'Nem a variação livre foi gravada');
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Nome Reservado {$this->uniq}")]));

        // O que foi digitado continua na tela para corrigir
        $crawler = $client->getCrawler();
        $this->assertSame("Título Que Não Deve Gravar {$this->uniq}", $crawler->filter('input[name="title"]')->attr('value'));
        $this->assertStringContainsString("NOME RESERVADO {$this->uniq}", $crawler->filter('textarea[name="variations"]')->text());
        $this->assertSame('B2', $crawler->filter('select[name="qualis"] option[selected]')->attr('value'));
    }

    public function testVariationThatIsTheTitleOfAnotherJournalAndNewVariationFieldAreAlsoConflicts(): void
    {
        $client = $this->getAuthenticatedClient();
        $other = $this->createJournal("Revista Titulo Alheio {$this->uniq}");
        $journal = $this->createJournal("Revista Candidata {$this->uniq}");
        $id = $journal->getId();

        $this->postEdit($client, $id, ['variations' => "revista titulo alheio {$this->uniq}"]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorTextContains('body', "já pertence ao periódico #{$other->getId()} «Revista Titulo Alheio {$this->uniq}»");

        // O campo avulso `new_variation` passa pela mesma trava
        $this->postEdit($client, $id, ['variations' => '', 'new_variation' => "REVISTA TITULO ALHEIO {$this->uniq}"]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorTextContains('body', "A variação «REVISTA TITULO ALHEIO {$this->uniq}» já pertence ao periódico #{$other->getId()}");
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_name_variants WHERE journal_id = ?', [$id]));
    }

    public function testNewJournalWithAConflictingVariationCreatesNothing(): void
    {
        $client = $this->getAuthenticatedClient();
        $owner = $this->createJournal("Revista Dona Nova {$this->uniq}");
        $this->createVariant($owner, "Apelido Ocupado {$this->uniq}");

        $crawler = $client->request('GET', '/admin/journals/new');
        $client->request('POST', '/admin/journals/new', [
            '_token' => $this->formToken($crawler),
            'title' => "Revista Nova Que Não Entra {$this->uniq}",
            'variations' => "Apelido Livre {$this->uniq}\napelido ocupado {$this->uniq}",
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSelectorTextContains('body', "A variação «apelido ocupado {$this->uniq}» já pertence ao periódico #{$owner->getId()} «Revista Dona Nova {$this->uniq}». Remova-a daqui ou mescle os periódicos.");
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE title = ?', ["Revista Nova Que Não Entra {$this->uniq}"]));
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Apelido Livre {$this->uniq}")]));
        $this->assertSame("Revista Nova Que Não Entra {$this->uniq}", $client->getCrawler()->filter('input[name="title"]')->attr('value'));
    }

    public function testEditAddsManualVariationsKeepsExistingOnesAndRemovesTheDeletedOnes(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("Revista Variantes {$this->uniq}");
        $id = $journal->getId();
        $this->createVariant($journal, "Ficar {$this->uniq}");
        $this->createVariant($journal, "Sair {$this->uniq}");
        $keptId = (int)$this->conn->fetchOne('SELECT id FROM journal_name_variants WHERE journal_id = ? AND variation_name = ?', [$id, "Ficar {$this->uniq}"]);

        // A grafia do título (e da variante que fica) não conta como novidade nem como erro
        $this->postEdit($client, $id, ['variations' => "ficar {$this->uniq}\nNova Um {$this->uniq}\nRevista Variantes {$this->uniq}\n\n  Nova Dois {$this->uniq}  "]);
        $this->assertResponseRedirects('/admin/journals/');

        $variants = $this->conn->fetchAllAssociative('SELECT id, variation_name, variation_type FROM journal_name_variants WHERE journal_id = ? ORDER BY variation_name', [$id]);
        $this->assertSame(
            ["Ficar {$this->uniq}", "Nova Dois {$this->uniq}", "Nova Um {$this->uniq}"],
            array_column($variants, 'variation_name'),
            'Sair foi removida, as novas entraram sem espaços e o título não virou variante'
        );
        $byName = array_column($variants, null, 'variation_name');
        $this->assertSame($keptId, (int)$byName["Ficar {$this->uniq}"]['id'], 'A variante que ficou é o mesmo registro');
        $this->assertSame('alternative', $byName["Ficar {$this->uniq}"]['variation_type']);
        $this->assertSame('manual', $byName["Nova Um {$this->uniq}"]['variation_type']);
        $this->assertSame('manual', $byName["Nova Dois {$this->uniq}"]['variation_type']);
    }

    public function testVariationAddedOnAMemberFormIsStoredOnTheCanonical(): void
    {
        $client = $this->getAuthenticatedClient();
        $head = $this->createJournal("Revista Canônica Var {$this->uniq}");
        $member = $this->createJournal("Revista Membro Var {$this->uniq}", null, $head);

        $this->postEdit($client, $member->getId(), ['title' => "Revista Membro Var {$this->uniq}", 'variations' => "Apelido do Membro {$this->uniq}"]);
        $this->assertResponseRedirects('/admin/journals/');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', "A variação «Apelido do Membro {$this->uniq}» foi gravada no periódico canônico #{$head->getId()}");

        $this->assertSame($head->getId(), (int)$this->conn->fetchOne('SELECT journal_id FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Apelido do Membro {$this->uniq}")]));

        // Nome que a família já conhece (variante da canônica) não é conflito nem duplica
        $this->postEdit($client, $member->getId(), ['title' => "Revista Membro Var {$this->uniq}", 'variations' => "apelido do membro {$this->uniq}"]);
        $this->assertResponseRedirects('/admin/journals/');
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Apelido do Membro {$this->uniq}")]));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Índice
    // ------------------------------------------------------------------------------------------------------------

    public function testIndexShowsThePrimaryAreaWithTheConfidenceDotAndFallsBackToTheAreaText(): void
    {
        $client = $this->getAuthenticatedClient();
        $manual = $this->createJournal("Revista Manual {$this->uniq}", 'Texto antigo');
        $auto = $this->createJournal("Revista Auto {$this->uniq}");
        $low = $this->createJournal("Revista Baixa {$this->uniq}");
        $plain = $this->createJournal("Revista Só Texto {$this->uniq}", 'ÁREA SÓ TEXTO');
        $this->postEdit($client, $manual->getId(), ['area' => 'Texto antigo', 'areas' => ['educacao'], 'area_primary' => 'educacao']);
        $this->insertArea($auto->getId(), 'direito', 1, 'auto', 'high', '0.9000');
        $this->insertArea($low->getId(), 'psicologia', 1, 'auto', 'low', '0.4000');
        $this->conn->executeStatement('UPDATE qualis_journals SET area = ? WHERE id = ?', ['DIREITO / CIÊNCIAS SOCIAIS APLICADAS', $auto->getId()]);

        $crawler = $client->request('GET', '/admin/journals/?search=' . $this->uniq);
        $this->assertResponseIsSuccessful();

        $row = static fn (int $id): Crawler => $crawler->filter(sprintf('input.journal-checkbox[value="%d"]', $id))->closest('tr');

        $manualRow = $row($manual->getId());
        $this->assertStringContainsString('Educação', $manualRow->text());
        $this->assertCount(1, $manualRow->filter('span.bg-sky-500'), 'Azul: curadoria manual');
        $this->assertStringContainsString(self::LABEL_EDUCACAO, (string)$manualRow->filter('span[title]')->first()->attr('title'));
        $this->assertStringNotContainsString('Texto antigo', $manualRow->text(), 'A área CAPES substitui o texto de `area`');

        $autoRow = $row($auto->getId());
        $this->assertStringContainsString('Direito', $autoRow->text());
        $this->assertCount(1, $autoRow->filter('span.bg-emerald-500'), 'Verde: confiança alta');

        $lowRow = $row($low->getId());
        $this->assertStringContainsString('Psicologia', $lowRow->text());
        $this->assertCount(1, $lowRow->filter('span.bg-slate-400'), 'Cinza: confiança baixa');

        $plainRow = $row($plain->getId());
        $this->assertStringContainsString('ÁREA SÓ TEXTO', $plainRow->text(), 'Sem área CAPES, o texto de `area`');
        $this->assertCount(0, $plainRow->filter('span.rounded-full'));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Exportações
    // ------------------------------------------------------------------------------------------------------------

    public function testExportCsvAppendsTheCapesColumnsAtTheEndWithoutChangingTheOthers(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("Revista Exportada {$this->uniq}", 'Original');
        $plain = $this->createJournal("Revista Sem Área Exportada {$this->uniq}");
        $this->postEdit($client, $journal->getId(), ['area' => 'Original', 'areas' => ['sociologia', 'educacao'], 'area_primary' => 'educacao']);

        $client->request('GET', '/admin/journals/export');
        $this->assertResponseIsSuccessful();
        $content = (string)$client->getInternalResponse()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $lines = preg_split('/\r?\n/', trim(substr($content, 3)));
        $this->assertSame(
            ['issn', 'title', 'qualis', 'area', 'area_capes_primary', 'areas_capes', 'display_title', 'canonical_journal_id'],
            str_getcsv($lines[0], ';', '"', '\\'),
            'As colunas da família (nome de exibição e canônica) vêm depois das de áreas CAPES, no fim'
        );

        $rows = array_filter($lines, fn (string $l): bool => str_contains($l, $this->uniq));
        $parsed = [];
        foreach ($rows as $line) {
            $fields = str_getcsv($line, ';', '"', '\\');
            $parsed[$fields[1]] = $fields;
        }
        $this->assertSame(['', "Revista Exportada {$this->uniq}", '', self::LABEL_EDUCACAO, 'Educação', 'Educação;Sociologia', '', ''], $parsed["Revista Exportada {$this->uniq}"]);
        $this->assertSame(['', "Revista Sem Área Exportada {$this->uniq}", '', '', '', '', '', ''], $parsed["Revista Sem Área Exportada {$this->uniq}"]);
    }

    public function testExportFullCatalogCsvAndJsonIncludeTheCapesColumnsAtTheEnd(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("Revista Catálogo {$this->uniq}");
        $this->postEdit($client, $journal->getId(), ['areas' => ['direito', 'psicologia'], 'area_primary' => 'psicologia']);

        $client->request('GET', '/admin/journals/export-full?format=csv');
        $this->assertResponseIsSuccessful();
        $reader = Reader::fromString((string)$client->getResponse()->getContent());
        $reader->setHeaderOffset(0);
        $header = $reader->getHeader();
        $this->assertSame(['area_capes_primary', 'areas_capes', 'display_title', 'canonical_journal_id'], array_slice($header, -4), 'As colunas de áreas CAPES e as da família ficam no fim');
        $this->assertSame(['ID', 'Título do Periódico'], array_slice($header, 0, 2), 'As colunas existentes continuam onde estavam');
        $found = null;
        foreach ($reader->getRecords() as $record) {
            if (str_contains((string)$record['Título do Periódico'], $this->uniq)) {
                $found = $record;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame('Psicologia', $found['area_capes_primary']);
        $this->assertSame('Psicologia;Direito', $found['areas_capes']);

        $client->request('GET', '/admin/journals/export-full?format=json');
        $this->assertResponseIsSuccessful();
        $data = json_decode((string)$client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $item = current(array_filter($data, fn (array $i): bool => $i['id'] === $journal->getId()));
        $this->assertSame('Psicologia', $item['area_capes_primary']);
        $this->assertSame(['Psicologia', 'Direito'], $item['areas_capes']);
        $this->assertSame(['area_capes_primary', 'areas_capes', 'display_title', 'canonical_journal_id'], array_slice(array_keys($item), -4));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Importações
    // ------------------------------------------------------------------------------------------------------------

    public function testImportCsvAcceptsTheExportDelimiterAndSavesAreasCapesAsLockedManual(): void
    {
        $client = $this->getAuthenticatedClient();
        // O ISSN é único por execução: a importação procura o periódico pelo ISSN, e o `cech_test` compartilhado já tem
        // linhas (algumas com a área travada) com ISSNs fixos de outros testes
        $issn = $this->uniqueIssn();
        // Como a exportação do admin: BOM e `;`; o campo `areas_capes` traz várias áreas separadas por `;`, entre aspas
        $csv = "\xEF\xBB\xBF" . implode("\n", [
            'title;issn;qualis;area;areas_capes',
            "Importada Um {$this->uniq};{$issn};a1;Texto da planilha;\"Educação;Sociologia\"",
            "Importada Dois {$this->uniq};;;;",
            "Importada Três {$this->uniq};;b2;Só texto;Astrologia",
            "Importada Quatro {$this->uniq};;;;\"direito; Psicologia\"",
        ]) . "\n";

        $crawler = $client->request('GET', '/admin/journals/');
        $client->request(
            'POST',
            '/admin/journals/import',
            ['_token' => $crawler->filter('form[action="/admin/journals/import"] input[name="_token"]')->attr('value')],
            ['csv_file' => $this->uploadedFile($csv, 'csv')]
        );
        $this->assertResponseRedirects('/admin/journals/');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Importação concluída! 4 periódicos processados. Áreas CAPES gravadas em 2');
        $this->assertSelectorTextContains('body', 'não são áreas CAPES foram ignorados: Astrologia');

        $one = $this->journalRow("Importada Um {$this->uniq}");
        $this->assertSame(['educacao', 'sociologia'], array_column($this->areasOf((int)$one['id']), 'slug'));
        $this->assertSame(['manual', 'manual'], array_column($this->areasOf((int)$one['id']), 'method'));
        $this->assertSame(1, (int)$one['areas_locked']);
        $this->assertSame(self::LABEL_EDUCACAO, $one['area']);
        $this->assertSame('Texto da planilha', $one['area_raw']);
        $this->assertSame('A1', $one['qualis']);
        $this->assertSame($issn, $one['issn']);
        $this->assertSame(self::norm("Importada Um {$this->uniq}"), $one['normalized_title']);

        $two = $this->journalRow("Importada Dois {$this->uniq}");
        $this->assertSame([], $this->areasOf((int)$two['id']));
        $this->assertSame(0, (int)$two['areas_locked']);

        // Valor que não é área: o periódico entra mesmo assim, sem áreas e com o texto da coluna `area`
        $three = $this->journalRow("Importada Três {$this->uniq}");
        $this->assertSame([], $this->areasOf((int)$three['id']));
        $this->assertSame('Só texto', $three['area']);

        $four = $this->journalRow("Importada Quatro {$this->uniq}");
        $this->assertSame(['direito', 'psicologia'], array_column($this->areasOf((int)$four['id']), 'slug'));
        $this->assertSame('direito', array_column($this->areasOf((int)$four['id']), 'slug')[0], 'A primeira da lista é a principal');
    }

    public function testImportCsvWithCommasStillWorks(): void
    {
        $client = $this->getAuthenticatedClient();
        $issn = $this->uniqueIssn();
        $csv = "title,issn,qualis,area\n\"Importada Vírgula {$this->uniq}\",{$issn},b1,Interdisciplinar\n";

        $crawler = $client->request('GET', '/admin/journals/');
        $client->request(
            'POST',
            '/admin/journals/import',
            ['_token' => $crawler->filter('form[action="/admin/journals/import"] input[name="_token"]')->attr('value')],
            ['csv_file' => $this->uploadedFile($csv, 'csv')]
        );
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Importação concluída! 1 periódicos processados.');
        $row = $this->journalRow("Importada Vírgula {$this->uniq}");
        // O texto da planilha é o dado bruto (`area_raw`); `area` recebe o rótulo canônico da área que o tesauro reconhece nele
        $this->assertSame(['B1', 'Interdisciplinar', $issn], [$row['qualis'], $row['area_raw'], $row['issn']]);
        $this->assertSame('INTERDISCIPLINAR / MULTIDISCIPLINAR', $row['area']);
        $this->assertSame(0, (int)$row['areas_locked'], 'O texto da coluna area não trava a família: a classificação automática continua valendo');
    }

    public function testImportThesaurusUsesTheGuardSkipsConflictsAndCountsThem(): void
    {
        $client = $this->getAuthenticatedClient();
        $a = $this->createJournal("Revista Do Tesauro {$this->uniq}");
        $b = $this->createJournal("Revista Dona Do Nome {$this->uniq}");
        $this->createVariant($a, "Ja Conhecida {$this->uniq}");
        $this->createVariant($b, "Nome Do Outro {$this->uniq}");

        $csv = implode("\n", [
            'preferred_name,variant_name',
            // Periódico existente: uma variação nova, uma que a família já tem, uma que é de outro periódico e uma que é o título de outro
            "Revista Do Tesauro {$this->uniq},Variação Nova {$this->uniq}",
            "Revista Do Tesauro {$this->uniq},ja conhecida {$this->uniq}",
            "Revista Do Tesauro {$this->uniq},Nome do outro {$this->uniq}",
            "Revista Do Tesauro {$this->uniq},Revista Dona Do Nome {$this->uniq}",
            // Periódico novo, com uma variação livre
            "Revista Criada {$this->uniq},Apelido Criado {$this->uniq}",
            // Periódico novo cujo nome já é variante de outro: não entra (nem a variação dele)
            "Nome do outro {$this->uniq},Variação Órfã {$this->uniq}",
        ]) . "\n";

        $crawler = $client->request('GET', '/admin/journals/');
        $client->request(
            'POST',
            '/admin/journals/import-thesaurus',
            ['_token' => $crawler->filter('form[action="/admin/journals/import-thesaurus"] input[name="_token"]')->attr('value')],
            ['thesaurus_file' => $this->uploadedFile($csv, 'csv')]
        );
        $this->assertResponseRedirects('/admin/journals/');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Importação do Tesauro concluída! Novos Periódicos: 1, Novas Variações: 2.');
        $this->assertSelectorTextContains('body', '3 nomes ignorados por já pertencerem a outro periódico');
        $this->assertSelectorTextContains('body', "«Nome do outro {$this->uniq}» → #{$b->getId()} «Revista Dona Do Nome {$this->uniq}»");

        $owner = fn (string $name): ?int => ($v = $this->conn->fetchOne('SELECT journal_id FROM journal_name_variants WHERE normalized_name = ?', [self::norm($name)])) === false ? null : (int)$v;
        $created = (int)$this->conn->fetchOne('SELECT id FROM qualis_journals WHERE title = ?', [mb_convert_case("Revista Criada {$this->uniq}", MB_CASE_TITLE, 'UTF-8')]);
        $this->assertGreaterThan(0, $created);
        $this->assertSame($a->getId(), $owner("Variação Nova {$this->uniq}"));
        $this->assertSame($created, $owner("Apelido Criado {$this->uniq}"));
        $this->assertSame($b->getId(), $owner("Nome do outro {$this->uniq}"), 'O dono do nome continua sendo o mesmo');
        $this->assertNull($owner("Variação Órfã {$this->uniq}"));
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Ja Conhecida {$this->uniq}")]));
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE normalized_title = ?', [self::norm("Nome do outro {$this->uniq}")]), 'Nenhum periódico novo com nome de variante alheia');
        $this->assertSame('alternative', $this->conn->fetchOne('SELECT variation_type FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Variação Nova {$this->uniq}")]));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Mesclagem pela tela
    // ------------------------------------------------------------------------------------------------------------

    public function testMergeFromTheAdminKeepsTheArticlesOfTheSource(): void
    {
        $client = $this->getAuthenticatedClient();
        $source = $this->createJournal("Revista Fonte Tela {$this->uniq}");
        $master = $this->createJournal("Revista Mestre Tela {$this->uniq}");
        $this->createVariant($source, "Alt Fonte Tela {$this->uniq}");
        $articleId = $this->createArticle($source);

        $crawler = $client->request('GET', '/admin/journals/?search=' . $this->uniq);
        $client->request('POST', '/admin/journals/merge', [
            '_token' => $crawler->filter('form[action="/admin/journals/merge"] input[name="_token"]')->attr('value'),
            'master_id' => $master->getId(),
            'source_ids' => [$master->getId(), $source->getId()],
        ]);
        $this->assertResponseRedirects('/admin/journals/');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Periódicos mesclados com sucesso');

        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE id = ?', [$source->getId()]));
        $this->assertSame($master->getId(), (int)$this->conn->fetchOne('SELECT qualis_journal_id FROM production_items WHERE id = ?', [$articleId]));
        $this->assertSame($master->getId(), (int)$this->conn->fetchOne('SELECT journal_id FROM journal_name_variants WHERE normalized_name = ?', [self::norm("Alt Fonte Tela {$this->uniq}")]));
    }

    public function testChangingTheQualisInTheFormUpdatesTheArticles(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("Revista Qualis Editado {$this->uniq}");
        $articleId = $this->createArticle($journal);
        $this->conn->update('production_items', ['qualis' => 'B2'], ['id' => $articleId]);
        $this->conn->update('qualis_journals', ['qualis' => 'B2'], ['id' => $journal->getId()]);

        $this->postEdit($client, $journal->getId(), ['qualis' => 'A1']);
        $this->assertResponseRedirects('/admin/journals/');

        $this->assertSame('A1', $this->conn->fetchOne('SELECT qualis FROM production_items WHERE id = ?', [$articleId]));
    }

    public function testReimportingAThesaurusWhoseHeaderIsTheDisplayTitleDoesNotCreateAJournal(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("REVISTA CADASTRADA {$this->uniq}");
        $this->conn->update('qualis_journals', ['display_title' => "Revista Exibida {$this->uniq}"], ['id' => $journal->getId()]);
        $this->em->clear();
        $csv = "preferred_name,variant_name\nRevista Exibida {$this->uniq},Grafia Exportada {$this->uniq}\n";

        $crawler = $client->request('GET', '/admin/journals/');
        $client->request(
            'POST',
            '/admin/journals/import-thesaurus',
            ['_token' => $crawler->filter('form[action="/admin/journals/import-thesaurus"] input[name="_token"]')->attr('value')],
            ['thesaurus_file' => $this->uploadedFile($csv, 'csv')]
        );
        $this->assertResponseRedirects('/admin/journals/');

        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE title = ?', [mb_convert_case("Revista Exibida {$this->uniq}", MB_CASE_TITLE, 'UTF-8')]));
        $this->assertSame($journal->getId(), (int)$this->conn->fetchOne('SELECT journal_id FROM journal_name_variants WHERE variation_name = ?', ["Grafia Exportada {$this->uniq}"]));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Excluir
    // ------------------------------------------------------------------------------------------------------------

    public function testTheDeleteButtonCarriesATokenThatTheServerAcceptsAndTheJournalIsRemoved(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("Revista Para Excluir {$this->uniq}");
        $stays = $this->createJournal("Revista Que Fica {$this->uniq}");
        $id = $journal->getId();

        $crawler = $client->request('GET', '/admin/journals/?search=' . $this->uniq);
        $this->assertResponseIsSuccessful();
        $button = $crawler->filter(sprintf('button[data-id="%d"][data-token]', $id));
        $this->assertCount(1, $button, 'O botão Excluir da linha traz o token em data-token');
        $token = (string)$button->attr('data-token');
        $this->assertNotSame('', $token);
        $this->assertSame("/admin/journals/{$id}/delete", $button->attr('data-action'));
        $this->assertSame("Revista Para Excluir {$this->uniq}", $button->attr('data-title'));

        // O modal tem o campo do token e o script o preenche com o do botão (antes ele ia sempre vazio)
        $this->assertCount(1, $crawler->filter('form#deleteForm input[name="_token"]#deleteCsrfToken'));
        $this->assertStringContainsString("getElementById('deleteCsrfToken').value = button.dataset.token", (string)$client->getResponse()->getContent());

        // Sem token (o que o formulário enviava) e com o token de outro periódico: recusado, nada some
        $otherToken = (string)$crawler->filter(sprintf('button[data-id="%d"][data-token]', $stays->getId()))->attr('data-token');
        $this->assertNotSame($token, $otherToken, 'O token é por periódico');
        foreach (['', $otherToken] as $wrong) {
            $client->request('POST', "/admin/journals/{$id}/delete", ['_token' => $wrong]);
            $this->assertResponseRedirects('/admin/journals/');
            $client->followRedirect();
            $this->assertSelectorTextContains('body', 'Token de segurança inválido');
            $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE id = ?', [$id]));
        }

        // Com o token do botão, o periódico é excluído e só ele
        $client->request('POST', "/admin/journals/{$id}/delete", ['_token' => $token]);
        $this->assertResponseRedirects('/admin/journals/');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', "Periódico \"Revista Para Excluir {$this->uniq}\" removido com sucesso.");
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE id = ?', [$id]));
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE id = ?', [$stays->getId()]));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Importar CSV: upsert
    // ------------------------------------------------------------------------------------------------------------

    public function testImportCsvTwiceDoesNotDuplicateAndReportsCreatedCompletedAndIgnored(): void
    {
        $client = $this->getAuthenticatedClient();
        $issn = $this->uniqueIssn();
        $csv = implode("\n", [
            'title;issn;qualis;area',
            "Importada Com ISSN {$this->uniq};{$issn};b1;",
            "Importada Sem Qualis {$this->uniq};;;",
        ]) . "\n";

        $this->importCsv($client, $csv);
        $this->assertSelectorTextContains('body', 'Importação concluída! 2 periódicos processados.');
        $this->assertSelectorTextContains('body', 'Criados: 2; completados: 0; ignorados: 0.');
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE title LIKE ?', ['Importada %' . $this->uniq]));

        // O mesmo arquivo de novo: ninguém é criado, e o que já está completo é ignorado
        $this->importCsv($client, $csv);
        $this->assertSelectorTextContains('body', 'Criados: 0; completados: 0; ignorados: 2.');
        $this->assertSelectorTextContains('body', '2 já cadastrados sem nada a completar');
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE title LIKE ?', ['Importada %' . $this->uniq]), 'Importar duas vezes não duplica');

        // Um arquivo que traz o que faltava: completa o vazio, sem sobrescrever o que já existe
        $this->importCsv($client, implode("\n", [
            'title;issn;qualis;area',
            "Importada Com ISSN {$this->uniq};{$issn};a1;",
            "Importada Sem Qualis {$this->uniq};;a2;",
        ]) . "\n");
        $this->assertSelectorTextContains('body', 'Criados: 0; completados: 1; ignorados: 1.');
        $this->assertSame('B1', $this->journalRow("Importada Com ISSN {$this->uniq}")['qualis'], 'O Qualis que já estava não é sobrescrito');
        $this->assertSame('A2', $this->journalRow("Importada Sem Qualis {$this->uniq}")['qualis'], 'O Qualis vazio é completado, e o periódico é achado pelo título porque a linha não tem ISSN');
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE title LIKE ?', ['Importada %' . $this->uniq]));
    }

    public function testTheExportedCsvCanBeImportedAgainWithoutDuplicatesOrErrors(): void
    {
        $client = $this->getAuthenticatedClient();
        $head = $this->createJournal("Revista Exportada Cabeça {$this->uniq}", 'Texto original');
        $member = $this->createJournal("Revista Exportada Online {$this->uniq}", null, $head);
        $plain = $this->createJournal("Revista Exportada Solta {$this->uniq}");
        // A curadoria de áreas pela tela vem primeiro: o formulário regrava ISSN e Qualis com o que recebe
        $this->postEdit($client, $head->getId(), ['area' => 'Texto original', 'areas' => ['educacao'], 'area_primary' => 'educacao']);
        $issn = $this->uniqueIssn();
        $this->conn->executeStatement(
            "UPDATE qualis_journals SET issn = ?, normalized_issn = ?, qualis = 'A2' WHERE id = ?",
            [$issn, str_replace('-', '', $issn), $head->getId()]
        );
        $this->conn->executeStatement("UPDATE qualis_journals SET display_title = 'Nome de Exibição', display_title_source = 'manual' WHERE id IN (?, ?)", [$head->getId(), $member->getId()]);

        // O arquivo exportado (BOM, `;`, colunas de áreas e da família), só com as linhas deste teste
        $client->request('GET', '/admin/journals/export');
        $lines = preg_split('/\r?\n/', trim(substr((string)$client->getInternalResponse()->getContent(), 3)));
        $kept = array_values(array_filter($lines, fn (string $line, int $i): bool => $i === 0 || str_contains($line, $this->uniq), ARRAY_FILTER_USE_BOTH));
        $this->assertCount(4, $kept, 'O cabeçalho e as três linhas do teste');

        $this->importCsv($client, implode("\n", $kept) . "\n");

        $this->assertSelectorTextContains('body', 'Importação concluída! 3 periódicos processados.');
        $this->assertSelectorTextContains('body', 'Criados: 0; completados: 0; ignorados: 3.');
        $this->assertSame(3, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE title LIKE ?', ['Revista Exportada %' . $this->uniq]), 'Reimportar o próprio CSV exportado não duplica');
        // A cabeça tem as áreas travadas: a importação nem toca nela (nem na coluna areas_capes, que ela mesma exportou)
        $this->assertSame(['educacao'], array_column($this->areasOf($head->getId()), 'slug'));
        $this->assertSame('A2', $this->conn->fetchOne('SELECT qualis FROM qualis_journals WHERE id = ?', [$head->getId()]));
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE id = ?', [$plain->getId()]));
    }

    public function testImportCsvInvalidRowsBecomeWarningsAndTheOthersAreStillImported(): void
    {
        $client = $this->getAuthenticatedClient();
        $csv = implode("\n", [
            'title;issn;qualis;area',
            "Importada Válida {$this->uniq};;a1;",
            str_repeat('T', 501 - strlen($this->uniq)) . $this->uniq . ';;;',
            "Importada Qualis Longo {$this->uniq};;ABCDEFGHIJK;",
            "Importada Válida Dois {$this->uniq};;b2;",
        ]) . "\n";

        $this->importCsv($client, $csv);

        $this->assertSelectorTextContains('body', 'Importação concluída! 2 periódicos processados.');
        $this->assertSelectorTextContains('body', 'Criados: 2; completados: 0; ignorados: 2.');
        $this->assertSelectorTextContains('body', '2 linhas inválidas');
        $this->assertSelectorTextContains('body', 'Linha 3: título com 501 caracteres (máximo 500)');
        $this->assertSelectorTextContains('body', 'Linha 4: Qualis «ABCDEFGHIJK…» com mais de 10 caracteres');
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE title LIKE ?', ['Importada V%' . $this->uniq]));
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM qualis_journals WHERE title LIKE ?', ['Importada Qualis Longo %']));
    }

    public function testImportCsvAreaTextGoesToAreaRawAndTheCanonicalLabelWithoutLockingTheFamily(): void
    {
        $client = $this->getAuthenticatedClient();
        $csv = implode("\n", [
            'title;issn;qualis;area;areas_capes',
            "Importada Área Reconhecida {$this->uniq};;;Educação;",
            "Importada Área Livre {$this->uniq};;;Categoria sem mapa {$this->uniq};",
            "Importada Área Curada {$this->uniq};;;Educação;Psicologia",
        ]) . "\n";

        $this->importCsv($client, $csv);

        $recognized = $this->journalRow("Importada Área Reconhecida {$this->uniq}");
        $this->assertSame([self::LABEL_EDUCACAO, 'Educação', 0], [$recognized['area'], $recognized['area_raw'], (int)$recognized['areas_locked']]);
        $this->assertSame([], $this->areasOf((int)$recognized['id']), 'O texto de area não grava áreas CAPES: a classificação automática continua valendo');

        $free = $this->journalRow("Importada Área Livre {$this->uniq}");
        $this->assertSame(["Categoria sem mapa {$this->uniq}", "Categoria sem mapa {$this->uniq}"], [$free['area'], $free['area_raw']]);

        // A coluna areas_capes segue como era: curadoria manual travada (e o texto bruto de area continua em area_raw)
        $curated = $this->journalRow("Importada Área Curada {$this->uniq}");
        $this->assertSame(['psicologia'], array_column($this->areasOf((int)$curated['id']), 'slug'));
        $this->assertSame(1, (int)$curated['areas_locked']);
        $this->assertSame('Educação', $curated['area_raw']);
        $this->assertSame('PSICOLOGIA / CIÊNCIAS HUMANAS', $curated['area']);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Mesclagem: o que ela deixava velho
    // ------------------------------------------------------------------------------------------------------------

    public function testMergeFromTheAdminRecalculatesTheArticlesTheAreasAndClearsThePageCache(): void
    {
        $client = $this->getAuthenticatedClient();
        $source = $this->createJournal("Revista B1 Fonte {$this->uniq}");
        $master = $this->createJournal("Revista A2 Mestre {$this->uniq}");
        $this->conn->executeStatement("UPDATE qualis_journals SET qualis = 'B1' WHERE id = ?", [$source->getId()]);
        $this->conn->executeStatement("UPDATE qualis_journals SET qualis = 'A2' WHERE id = ?", [$master->getId()]);
        $databaseId = $this->createDatabase('x');
        $this->linkBase($source->getId(), $databaseId);
        $articleId = $this->createArticle($source);
        $this->conn->executeStatement("UPDATE production_items SET qualis = 'B1', indexed_databases = NULL WHERE id = ?", [$articleId]);
        // Evidência que classifica a família como Direito
        $this->insertEvidence($source->getId(), 'wos', 'W1', 'Law');
        $marker = $this->pageCacheMarker();

        $crawler = $client->request('GET', '/admin/journals/?search=' . $this->uniq);
        $client->request('POST', '/admin/journals/merge', [
            '_token' => $crawler->filter('form[action="/admin/journals/merge"] input[name="_token"]')->attr('value'),
            'master_id' => $master->getId(),
            'source_ids' => [$master->getId(), $source->getId()],
        ]);
        $this->assertResponseRedirects('/admin/journals/');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Periódicos mesclados com sucesso');
        $this->assertSelectorTextContains('body', 'Qualis e bases recalculados em 1 artigo');
        $this->assertSelectorTextContains('body', 'Áreas CAPES reclassificadas: Direito (confiança alta)');
        $this->assertSelectorTextContains('body', 'Cache de páginas públicas limpo.');

        // Um B1 mesclado num A2 deixa o artigo com A2, e as bases da fonte passam para o artigo
        $this->assertSame($master->getId(), (int)$this->conn->fetchOne('SELECT qualis_journal_id FROM production_items WHERE id = ?', [$articleId]));
        $this->assertSame('A2', $this->conn->fetchOne('SELECT qualis FROM production_items WHERE id = ?', [$articleId]));
        $databases = json_decode((string)$this->conn->fetchOne('SELECT indexed_databases FROM production_items WHERE id = ?', [$articleId]), true);
        $this->assertSame(["{$this->uniq}x"], array_column($databases ?? [], 'acronym'));
        $this->assertSame([$databaseId], array_map('intval', $this->conn->fetchFirstColumn('SELECT academic_database_id FROM qualis_journal_academic_database WHERE qualis_journal_id = ?', [$master->getId()])));

        // A área da família foi reclassificada com a evidência que veio da fonte
        $this->assertSame([['direito', 'auto', 'high']], array_map(static fn (array $r): array => [$r['slug'], $r['method'], $r['confidence']], $this->areasOf($master->getId())));
        $this->assertSame('DIREITO / CIÊNCIAS SOCIAIS APLICADAS', $this->columnsOf($master->getId())['area']);

        // O cache de páginas públicas foi limpo, como na tela de cache
        $this->assertFileDoesNotExist($marker);
    }

    public function testMergeKeepsTheManualAreasOfALockedFamilyAndSaysSo(): void
    {
        $client = $this->getAuthenticatedClient();
        $source = $this->createJournal("Revista Fonte Travada {$this->uniq}");
        $master = $this->createJournal("Revista Mestre Travado {$this->uniq}");
        $this->curateEducationDirectly($master->getId());
        $this->insertEvidence($source->getId(), 'wos', 'W1', 'Law');

        $crawler = $client->request('GET', '/admin/journals/?search=' . $this->uniq);
        $client->request('POST', '/admin/journals/merge', [
            '_token' => $crawler->filter('form[action="/admin/journals/merge"] input[name="_token"]')->attr('value'),
            'master_id' => $master->getId(),
            'source_ids' => [$master->getId(), $source->getId()],
        ]);
        $client->followRedirect();

        $this->assertSelectorTextContains('body', 'Áreas CAPES mantidas: a curadoria manual da família está travada.');
        $this->assertSame([['educacao', 'manual']], array_map(static fn (array $r): array => [$r['slug'], $r['method']], $this->areasOf($master->getId())));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Bases de indexação por família
    // ------------------------------------------------------------------------------------------------------------

    public function testSavingTheBasesOfAFamilyRowReplicatesThemToTheWholeFamilyAndRemovalReachesItToo(): void
    {
        $client = $this->getAuthenticatedClient();
        $head = $this->createJournal("Revista Bases Cabeça {$this->uniq}");
        $member = $this->createJournal("Revista Bases Online {$this->uniq}", null, $head);
        $sibling = $this->createJournal("Revista Bases Impressa {$this->uniq}", null, $head);
        $alien = $this->createJournal("Revista Bases Alheia {$this->uniq}");
        $dbX = $this->createDatabase('x');
        $dbY = $this->createDatabase('y');
        $this->linkBase($alien->getId(), $dbX);
        $family = [$head->getId(), $member->getId(), $sibling->getId()];

        // Marcar uma base numa linha membro vale para a canônica e para a outra linha da família
        $this->postEdit($client, $member->getId(), ['academic_databases' => [(string)$dbX]]);
        $this->assertResponseRedirects('/admin/journals/');
        foreach ($family as $id) {
            $this->assertSame([$dbX], $this->linkedDatabases($id), "Linha #{$id}");
        }

        // Marcar outra na canônica, mantendo a primeira
        $this->postEdit($client, $head->getId(), ['academic_databases' => [(string)$dbX, (string)$dbY]]);
        foreach ($family as $id) {
            $this->assertSame([$dbX, $dbY], $this->linkedDatabases($id), "Linha #{$id}");
        }

        // Desmarcar numa linha só tira da família toda (senão a réplica traria de volta)
        $this->postEdit($client, $sibling->getId(), ['academic_databases' => [(string)$dbY]]);
        foreach ($family as $id) {
            $this->assertSame([$dbY], $this->linkedDatabases($id), "Linha #{$id}");
        }

        // Outra família não é tocada
        $this->assertSame([$dbX], $this->linkedDatabases($alien->getId()));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Exportações por família
    // ------------------------------------------------------------------------------------------------------------

    public function testExportedCsvsCarryTheDisplayTitleAndTheCanonicalRow(): void
    {
        $client = $this->getAuthenticatedClient();
        $head = $this->createJournal("Revista Família Exportada {$this->uniq}");
        $member = $this->createJournal("Revista Família Exportada Online {$this->uniq}", null, $head);
        $this->conn->executeStatement("UPDATE qualis_journals SET display_title = 'Nome Bonito', display_title_source = 'manual' WHERE id IN (?, ?)", [$head->getId(), $member->getId()]);

        $client->request('GET', '/admin/journals/export');
        $lines = preg_split('/\r?\n/', trim(substr((string)$client->getInternalResponse()->getContent(), 3)));
        $rows = [];
        foreach ($lines as $line) {
            if (str_contains($line, $this->uniq)) {
                $fields = str_getcsv($line, ';', '"', '\\');
                $rows[$fields[1]] = $fields;
            }
        }
        $this->assertSame(['Nome Bonito', ''], array_slice($rows["Revista Família Exportada {$this->uniq}"], -2));
        $this->assertSame(['Nome Bonito', (string)$head->getId()], array_slice($rows["Revista Família Exportada Online {$this->uniq}"], -2));

        $client->request('GET', '/admin/journals/export-full?format=csv');
        $reader = Reader::fromString((string)$client->getResponse()->getContent());
        $reader->setHeaderOffset(0);
        $this->assertSame(['display_title', 'canonical_journal_id'], array_slice($reader->getHeader(), -2));
        $found = [];
        foreach ($reader->getRecords() as $record) {
            if (str_contains((string)$record['Título do Periódico'], $this->uniq)) {
                $found[(int)$record['ID']] = [$record['display_title'], $record['canonical_journal_id']];
            }
        }
        $this->assertSame(['Nome Bonito', ''], $found[$head->getId()]);
        $this->assertSame(['Nome Bonito', (string)$head->getId()], $found[$member->getId()]);
    }

    public function testExportedThesaurusHasOneEntryPerFamilyUnderTheDisplayName(): void
    {
        $client = $this->getAuthenticatedClient();
        $head = $this->createJournal("Revista Tesauro Cabeça {$this->uniq}");
        $member = $this->createJournal("Revista Tesauro Online {$this->uniq}", null, $head);
        $this->conn->executeStatement("UPDATE qualis_journals SET display_title = ?, display_title_source = 'manual' WHERE id IN (?, ?)", ["Nome Tesauro {$this->uniq}", $head->getId(), $member->getId()]);
        $this->createVariant($head, "Apelido Tesauro {$this->uniq}");
        $this->createVariant($member, "Apelido Online Tesauro {$this->uniq}");

        // .the: um cabeçalho para a família e as variantes (o arquivo .the do admin grava tudo em minúsculas)
        $client->request('GET', '/admin/journals/export-thesaurus?format=the');
        $the = (string)$client->getResponse()->getContent();
        $this->assertSame(1, substr_count($the, "**#nome tesauro {$this->uniq}\r\n"));
        $this->assertStringNotContainsString("**#revista tesauro online {$this->uniq}", $the, 'A linha membro não é uma entrada à parte');
        $this->assertStringNotContainsString("**#revista tesauro cabeça {$this->uniq}", $the, 'O cabeçalho é o nome de exibição');
        foreach (["revista tesauro cabeça {$this->uniq}", "revista tesauro online {$this->uniq}", "apelido tesauro {$this->uniq}", "apelido online tesauro {$this->uniq}"] as $variant) {
            $this->assertStringContainsString("100 1 ^{$variant}$\r\n", $the);
        }

        // CSV: as mesmas variantes sob o mesmo cabeçalho
        $client->request('GET', '/admin/journals/export-thesaurus?format=csv');
        $reader = Reader::fromString((string)$client->getResponse()->getContent());
        $reader->setHeaderOffset(0);
        $variants = [];
        foreach ($reader->getRecords() as $record) {
            if (str_contains((string)$record['preferred_name'], $this->uniq)) {
                $variants[$record['preferred_name']][] = $record['variant_name'];
            }
        }
        $this->assertSame(["Nome Tesauro {$this->uniq}"], array_keys($variants));
        $this->assertEqualsCanonicalizing(
            ["Revista Tesauro Cabeça {$this->uniq}", "Revista Tesauro Online {$this->uniq}", "Apelido Tesauro {$this->uniq}", "Apelido Online Tesauro {$this->uniq}"],
            $variants["Nome Tesauro {$this->uniq}"]
        );
    }

    // ------------------------------------------------------------------------------------------------------------
    // Texto livre de área reconhecido pelo tesauro de categorias
    // ------------------------------------------------------------------------------------------------------------

    public function testFreeTextAreaRecognizedByTheThesaurusBecomesTheCapesSelection(): void
    {
        $client = $this->getAuthenticatedClient();
        $journal = $this->createJournal("Revista Texto Reconhecido {$this->uniq}");
        $id = $journal->getId();

        $this->postEdit($client, $id, ['area' => 'Educação']);
        $this->assertResponseRedirects('/admin/journals/');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'o texto «Educação» foi reconhecido como: Educação (principal).');
        $this->assertSelectorTextContains('body', 'Áreas CAPES gravadas (curadoria manual, travadas): Educação (principal).');

        $this->assertSame([['educacao', 'manual', 1]], array_map(static fn (array $r): array => [$r['slug'], $r['method'], (int)$r['is_primary']], $this->areasOf($id)));
        $this->assertSame(1, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$id]));
        $this->assertSame(['area' => self::LABEL_EDUCACAO, 'area_raw' => 'Educação'], $this->columnsOf($id), 'O texto digitado fica em area_raw');

        // Texto que o tesauro não reconhece segue como sempre: só o texto, sem áreas
        $other = $this->createJournal("Revista Texto Não Reconhecido {$this->uniq}");
        $this->postEdit($client, $other->getId(), ['area' => "Categoria sem mapa {$this->uniq}"]);
        $this->assertSame([], $this->areasOf($other->getId()));
        $this->assertSame(["Categoria sem mapa {$this->uniq}", "Categoria sem mapa {$this->uniq}"], array_values($this->columnsOf($other->getId())));
        $this->assertSame(0, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$other->getId()]));

        // Com áreas CAPES marcadas, são elas que valem, não o texto
        $marked = $this->createJournal("Revista Texto E Áreas {$this->uniq}");
        $this->postEdit($client, $marked->getId(), ['area' => 'Educação', 'areas' => ['direito'], 'area_primary' => 'direito']);
        $this->assertSame(['direito'], array_column($this->areasOf($marked->getId()), 'slug'));

        // Família que já tem áreas: o texto livre não mexe nelas (o campo area nem aceita edição)
        $this->postEdit($client, $id, ['area' => 'Psicologia', 'areas' => ['educacao'], 'area_primary' => 'educacao']);
        $this->assertSame(['educacao'], array_column($this->areasOf($id), 'slug'));
    }

    public function testNewJournalWithRecognizedAreaTextGetsTheCapesSelectionAndAnUnchangedTextIsNotTurnedIntoCuration(): void
    {
        $client = $this->getAuthenticatedClient();
        $crawler = $client->request('GET', '/admin/journals/new');
        $client->request('POST', '/admin/journals/new', [
            '_token' => $this->formToken($crawler),
            'title' => "Revista Nova Texto {$this->uniq}",
            'area' => 'Psicologia',
        ]);
        $this->assertResponseRedirects('/admin/journals/');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'o texto «Psicologia» foi reconhecido como: Psicologia (principal).');
        $id = (int)$this->journalRow("Revista Nova Texto {$this->uniq}")['id'];
        $this->assertSame(['psicologia'], array_column($this->areasOf($id), 'slug'));

        // O texto já gravado (reconhecível, mas sem áreas) não vira curadoria travada só por salvar o formulário
        $stored = $this->createJournal("Revista Texto Guardado {$this->uniq}", 'Educação');
        $crawler = $client->request('GET', "/admin/journals/{$stored->getId()}/edit");
        $client->submit($crawler->filter('form#journalForm')->form());
        $this->assertResponseRedirects('/admin/journals/');
        $this->assertSame([], $this->areasOf($stored->getId()));
        $this->assertSame(0, $this->scalar('SELECT areas_locked FROM qualis_journals WHERE id = ?', [$stored->getId()]));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Utilitários
    // ------------------------------------------------------------------------------------------------------------

    private static function norm(string $name): string
    {
        return StringNormalizer::normalizeString($name, true);
    }

    /**
     * ISSN (só a forma NNNN-NNNN, sem dígito verificador) que nenhuma outra linha do banco de teste tem.
     */
    private function uniqueIssn(): string
    {
        do {
            $issn = sprintf('%04d-%04d', random_int(1000, 9999), random_int(1000, 9999));
            $norm = str_replace('-', '', $issn);
            $taken = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM qualis_journals WHERE normalized_issn = ? OR normalized_issn_e = ? OR normalized_issn_l = ? OR normalized_issn_imp = ?',
                [$norm, $norm, $norm, $norm]
            );
        } while ((int)$taken > 0);

        return $issn;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function scalar(string $sql, array $params = []): int
    {
        return (int)$this->conn->fetchOne($sql, $params);
    }

    /**
     * Token do formulário principal de periódico (o de "Voltar ao automático" é outro).
     */
    private function formToken(Crawler $crawler): string
    {
        $field = $crawler->filter('form#journalForm input[name="_token"]');
        $this->assertCount(1, $field, 'Formulário do periódico não aparece na tela');

        return (string)$field->attr('value');
    }

    /**
     * Salva o formulário de edição como o navegador faria: abre a tela (para o token, ligado à sessão) e envia os
     * valores atuais do periódico por cima dos campos dados.
     *
     * @param array<string, mixed> $fields
     */
    private function postEdit(KernelBrowser $client, int $id, array $fields): Crawler
    {
        $crawler = $client->request('GET', "/admin/journals/{$id}/edit");
        $this->assertResponseIsSuccessful();

        $current = $this->conn->fetchAssociative('SELECT title FROM qualis_journals WHERE id = ?', [$id]);

        return $client->request('POST', "/admin/journals/{$id}/edit", $fields + [
            '_token' => $this->formToken($crawler),
            'title' => $current['title'],
        ]);
    }

    /**
     * Curadoria manual gravada direto pelo repositório (sem passar pela tela), para montar o cenário.
     */
    private function curateEducationDirectly(int $id): void
    {
        $journal = $this->em->find(QualisJournal::class, $id);
        static::getContainer()->get(\App\Repository\JournalAreaRepository::class)->replaceManual($journal, ['educacao'], 'educacao');
    }

    /**
     * Envia um CSV pela tela de importação (com o token da própria tela) e segue o redirecionamento, deixando as
     * mensagens da importação no `body` para o teste conferir.
     */
    private function importCsv(KernelBrowser $client, string $content): Crawler
    {
        $crawler = $client->request('GET', '/admin/journals/');
        $client->request(
            'POST',
            '/admin/journals/import',
            ['_token' => $crawler->filter('form[action="/admin/journals/import"] input[name="_token"]')->attr('value')],
            ['csv_file' => $this->uploadedFile($content, 'csv')]
        );
        $this->assertResponseRedirects('/admin/journals/');

        return $client->followRedirect();
    }

    private function createDatabase(string $suffix): int
    {
        $database = new \App\Entity\AcademicDatabase();
        $database->setName("Base teste {$suffix} {$this->uniq}");
        $database->setAcronym("{$this->uniq}{$suffix}");
        $this->em->persist($database);
        $this->em->flush();
        $this->databaseIds[] = (int)$database->getId();

        return (int)$database->getId();
    }

    private function linkBase(int $journalId, int $databaseId): void
    {
        $this->conn->insert('qualis_journal_academic_database', ['qualis_journal_id' => $journalId, 'academic_database_id' => $databaseId]);
    }

    /**
     * @return list<int> ids das bases ligadas à linha, em ordem
     */
    private function linkedDatabases(int $journalId): array
    {
        return array_map('intval', $this->conn->fetchFirstColumn(
            'SELECT academic_database_id FROM qualis_journal_academic_database WHERE qualis_journal_id = ? ORDER BY academic_database_id',
            [$journalId]
        ));
    }

    /**
     * Arquivo no diretório do cache de páginas públicas; some quando a tela de cache (ou a mesclagem) o limpa.
     */
    private function pageCacheMarker(): string
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/' . \App\Service\PageCacheService::CACHE_SUBDIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . $this->uniq . '.html';
        file_put_contents($file, '<html>página antiga</html>');
        $this->tempFiles[] = $file;
        $this->assertFileExists($file);

        return $file;
    }

    private function createJournal(string $title, ?string $area = null, ?QualisJournal $canonical = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        $journal->setArea($area);
        $journal->setAreaRaw($area);
        $journal->setCanonical($canonical);
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
        $variation->setStatus(true);
        $this->em->persist($variation);
        $this->em->flush();
    }

    private function createArticle(QualisJournal $journal): int
    {
        $researcher = new Researcher();
        $researcher->setIdLattes('9966' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT));
        $researcher->setFullName('Docente Teste Admin Periódicos ' . $this->uniq);
        $researcher->setSlug('docente-teste-admin-periodicos-' . bin2hex(random_bytes(4)));
        $this->em->persist($researcher);
        $this->em->flush();
        $this->researcherIds[] = $researcher->getId();

        $article = new ProductionItem();
        $article->setItemType(ProductionItem::TYPE_ARTIGO);
        $article->setTitle('Artigo de teste do admin de periódicos');
        $article->setYear(2024);
        $article->setJournalName($journal->getTitle());
        $researcher->addProduction($article);
        $this->em->persist($article);
        $this->em->flush();
        $this->conn->executeStatement('UPDATE production_items SET qualis_journal_id = ? WHERE id = ?', [$journal->getId(), $article->getId()]);

        return (int)$article->getId();
    }

    private function insertArea(int $journalId, string $slug, int $primary, string $method, string $confidence, string $score = '1.0000'): void
    {
        $this->conn->insert('journal_areas', [
            'journal_id' => $journalId,
            'area_id' => static::getContainer()->get(CapesTaxonomy::class)->bySlug($slug)['id'],
            'is_primary' => $primary,
            'score' => $score,
            'confidence' => $confidence,
            'method' => $method,
            'run_id' => $method === 'auto' ? 'teste' : null,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function insertEvidence(int $journalId, string $source, string $recordKey, string $label): void
    {
        $this->conn->insert('journal_area_evidence', [
            'journal_id' => $journalId,
            'source' => $source,
            'record_key' => $recordKey,
            'label' => $label,
            'weight' => '1.0000',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<array{slug: string, is_primary: string|int, score: string, confidence: string, method: string}>
     */
    private function areasOf(int $journalId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT a.slug, ja.is_primary, ja.score, ja.confidence, ja.method
               FROM journal_areas ja JOIN capes_areas a ON a.id = ja.area_id
              WHERE ja.journal_id = ? ORDER BY ja.is_primary DESC, a.slug',
            [$journalId]
        );
    }

    /**
     * @return array{area: ?string, area_raw: ?string}
     */
    private function columnsOf(int $journalId): array
    {
        $row = $this->conn->fetchAssociative('SELECT area, area_raw FROM qualis_journals WHERE id = ?', [$journalId]);

        return ['area' => $row['area'], 'area_raw' => $row['area_raw']];
    }

    /**
     * @return array<string, mixed>
     */
    private function journalRow(string $title): array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM qualis_journals WHERE title = ?', [$title]);
        $this->assertIsArray($row, "Periódico «{$title}» não foi criado");

        return $row;
    }

    private function uploadedFile(string $content, string $extension): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'journal_test_');
        $file = $path . '.' . $extension;
        rename($path, $file);
        file_put_contents($file, $content);
        $this->tempFiles[] = $file;

        return new UploadedFile($file, 'arquivo.' . $extension, null, null, true);
    }
}
