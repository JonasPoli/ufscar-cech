<?php

declare(strict_types=1);

namespace App\Tests\Service\Thesaurus;

use App\Service\Thesaurus\AuthorThesaurusService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `syncAuthorThesaurus()` só acrescenta: o que já está no tesauro (inclusive fusões e correções manuais) e as ligações
 * de `production_authors` com as identidades precisam sobreviver. `rebuildAuthorThesaurus()` é quem apaga, e o comando
 * só o chama com --clean.
 *
 * Tudo roda dentro de uma transação desfeita no tearDown: o tesauro de cech_test é compartilhado com outros testes e
 * agentes, e a sincronização varre todos os docentes e autores do banco. (TRUNCATE faria commit implícito, por isso a
 * limpeza não é exercitada aqui: o comando é testado com o serviço simulado.)
 */
class AuthorThesaurusServiceTest extends KernelTestCase
{
    private Connection $conn;
    private AuthorThesaurusService $service;
    private string $uniq;
    private string $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->conn = $container->get(Connection::class);
        $this->service = $container->get(AuthorThesaurusService::class);
        $this->uniq = 'qz' . bin2hex(random_bytes(4));
        $this->now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }

        parent::tearDown();
    }

    public function testSyncKeepsManualMergesLinksAndOrcidAndCreatesWhatIsMissing(): void
    {
        // Fusão manual: o nome completo do docente Beta virou variante da identidade "Mesclada", que não é a dele
        $merged = $this->insertIdentity("Mesclada Teste {$this->uniq}");
        $betaName = "Docente {$this->uniq} Beta";
        $this->insertVariant($merged, $betaName);
        $this->insertResearcher($betaName);

        // Correção manual: a identidade do docente Gama já tem um ORCID, diferente do que o Lattes traz
        $gamaName = "Docente {$this->uniq} Gama";
        $gamaIdentity = $this->insertIdentity($gamaName);
        $this->insertVariant($gamaIdentity, $gamaName, 'lattes');
        $this->conn->insert('author_external_identifiers', [
            'author_identity_id' => $gamaIdentity,
            'provider' => 'orcid',
            'identifier' => '0000-0000-0000-9999',
            'created_at' => $this->now,
        ]);
        $this->insertResearcher($gamaName, null, '0000-0000-0000-0003');

        // Docente novo no tesauro, com duas formas de citação e ORCID
        $alfaName = "Docente {$this->uniq} Alfa";
        $alfaId = $this->insertResearcher($alfaName, "ALFA, D. {$this->uniq}; ALFA, DOCENTE {$this->uniq}", '0000-0000-0000-0001');

        // Produções: um autor já ligado à identidade manual, outro ainda sem identidade
        $itemId = $this->insertProductionItem($alfaId);
        $linkedAuthor = $this->insertProductionAuthor($itemId, "Beta Coautor {$this->uniq}", $merged);
        $unlinkedAuthor = $this->insertProductionAuthor($itemId, "Gama Coautor {$this->uniq}", null);

        $identitiesBefore = (int)$this->conn->fetchOne('SELECT COUNT(*) FROM author_identities');
        $variantsBefore = (int)$this->conn->fetchOne('SELECT COUNT(*) FROM author_name_variants');

        $stats = $this->service->syncAuthorThesaurus();

        // 1. Nada que existia foi apagado nem desligado
        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM author_identities WHERE id = ?', [$merged]));
        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM author_name_variants WHERE author_identity_id = ? AND normalized_name = ?', [$merged, $this->normalized($betaName)]));
        $this->assertGreaterThanOrEqual($identitiesBefore, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM author_identities'));
        $this->assertGreaterThanOrEqual($variantsBefore, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM author_name_variants'));
        $this->assertSame($merged, (int)$this->conn->fetchOne('SELECT author_identity_id FROM production_authors WHERE id = ?', [$linkedAuthor]), 'A ligação do autor com a identidade segue como estava.');
        $this->assertNull($this->conn->fetchOne('SELECT author_identity_id FROM production_authors WHERE id = ?', [$unlinkedAuthor]), 'Quem liga as produções é a normalização, não o tesauro.');

        // 2. O nome do docente Beta já é variante de outra identidade: não nasce uma identidade nova para ele
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM author_identities WHERE normalized_name = ?', [$this->normalized($betaName)]));

        // 3. O ORCID corrigido à mão continua sendo o único
        $this->assertSame(
            ['0000-0000-0000-9999'],
            $this->conn->fetchFirstColumn("SELECT identifier FROM author_external_identifiers WHERE author_identity_id = ? AND provider = 'orcid'", [$gamaIdentity])
        );

        // 4. O docente novo ganha identidade, as variantes de citação e o ORCID
        $alfaIdentity = (int)$this->conn->fetchOne('SELECT id FROM author_identities WHERE normalized_name = ?', [$this->normalized($alfaName)]);
        $this->assertGreaterThan(0, $alfaIdentity);
        $variants = $this->conn->fetchFirstColumn('SELECT normalized_name FROM author_name_variants WHERE author_identity_id = ? ORDER BY normalized_name', [$alfaIdentity]);
        $this->assertContains($this->normalized($alfaName), $variants);
        $this->assertContains($this->normalized("ALFA, D. {$this->uniq}"), $variants);
        $this->assertContains($this->normalized("ALFA, DOCENTE {$this->uniq}"), $variants);
        $this->assertSame(['0000-0000-0000-0001'], $this->conn->fetchFirstColumn("SELECT identifier FROM author_external_identifiers WHERE author_identity_id = ? AND provider = 'orcid'", [$alfaIdentity]));

        // 5. O coautor que ainda não tinha identidade ganhou uma
        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM author_identities WHERE normalized_name = ?', [$this->normalized("Gama Coautor {$this->uniq}")]));
        $this->assertGreaterThanOrEqual(2, $stats['identitiesCreated'], 'Alfa e o coautor Gama são novos.');
    }

    public function testSyncTwiceDoesNotRecreateIdentitiesOrDuplicateVariants(): void
    {
        $alfaName = "Docente {$this->uniq} Alfa";
        $alfaId = $this->insertResearcher($alfaName, "ALFA, D. {$this->uniq}", '0000-0000-0000-0001');
        $itemId = $this->insertProductionItem($alfaId);
        $this->insertProductionAuthor($itemId, "Silva, Maria {$this->uniq}", null);
        $this->insertProductionAuthor($itemId, "Souza Coautor {$this->uniq}", null);

        $first = $this->service->syncAuthorThesaurus();
        $this->assertGreaterThanOrEqual(3, $first['identitiesCreated']);
        $mine = fn(string $table, string $column) => (int)$this->conn->fetchOne("SELECT COUNT(*) FROM $table WHERE $column LIKE ?", ['%' . strtoupper($this->uniq) . '%']);
        $identitiesAfterFirst = $mine('author_identities', 'normalized_name');

        $second = $this->service->syncAuthorThesaurus();

        $this->assertSame(0, $second['identitiesCreated'], 'Nenhuma identidade é recriada.');
        $this->assertSame($identitiesAfterFirst, $mine('author_identities', 'normalized_name'));
        $this->assertSame([], $this->conn->fetchAllAssociative(
            'SELECT author_identity_id, normalized_name FROM author_name_variants WHERE normalized_name LIKE ? GROUP BY author_identity_id, normalized_name HAVING COUNT(*) > 1',
            ['%' . strtoupper($this->uniq) . '%']
        ), 'Nenhuma variante é gravada duas vezes para a mesma identidade.');
        $this->assertSame(
            1,
            (int)$this->conn->fetchOne(
                "SELECT COUNT(*) FROM author_external_identifiers e JOIN author_identities i ON i.id = e.author_identity_id WHERE i.normalized_name = ? AND e.provider = 'orcid'",
                [$this->normalized($alfaName)]
            ),
            'O ORCID do docente é gravado uma vez só.'
        );
    }

    public function testRebuildWipesTheThesaurusBeforeSyncingAndSyncDoesNot(): void
    {
        // O truncate (DDL, com commit implícito) é trocado por um contador: aqui só interessa quem o chama
        $spy = new class(static::getContainer()->get('doctrine.orm.entity_manager'), $this->conn) extends AuthorThesaurusService {
            public int $truncates = 0;

            public function __construct(EntityManagerInterface $em, Connection $conn)
            {
                parent::__construct($em, $conn, null);
            }

            public function truncateAuthorThesaurus(): void
            {
                $this->truncates++;
            }
        };

        $spy->syncAuthorThesaurus();
        $this->assertSame(0, $spy->truncates, 'Sincronizar não apaga nada.');

        $spy->rebuildAuthorThesaurus();
        $this->assertSame(1, $spy->truncates, 'Refazer apaga o tesauro antes de recriá-lo.');
    }

    /** @return array{identities: int, variants: int, external: int} */
    private function counts(): array
    {
        return [
            'identities' => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM author_identities'),
            'variants' => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM author_name_variants'),
            'external' => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM author_external_identifiers'),
        ];
    }

    private function normalized(string $name): string
    {
        return StringNormalizer::normalizeString($name, true);
    }

    private function insertResearcher(string $fullName, ?string $citations = null, ?string $orcid = null): int
    {
        $this->conn->insert('researchers', [
            'id_lattes' => '9966' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'full_name' => $fullName,
            'slug' => StringNormalizer::slugify($fullName),
            'citation_names' => $citations,
            'orcid' => $orcid,
            'status' => 1,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    private function insertIdentity(string $preferredName): int
    {
        $this->conn->insert('author_identities', [
            'preferred_name' => $preferredName,
            'normalized_name' => $this->normalized($preferredName),
            'status' => 1,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    private function insertVariant(int $identityId, string $name, string $source = 'manual'): void
    {
        $this->conn->insert('author_name_variants', [
            'author_identity_id' => $identityId,
            'original_name' => $name,
            'normalized_name' => $this->normalized($name),
            'display_name' => $name,
            'source' => $source,
            'status' => 1,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
    }

    private function insertProductionItem(int $researcherId): int
    {
        $this->conn->insert('production_items', [
            'researcher_id' => $researcherId,
            'item_type' => 'ARTIGO',
            'title' => "Artigo {$this->uniq}",
            'is_scientific_dissemination' => 0,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    private function insertProductionAuthor(int $itemId, string $name, ?int $identityId): int
    {
        $this->conn->insert('production_authors', [
            'production_item_id' => $itemId,
            'author_name' => $name,
            'citation_name' => $name,
            'author_order' => 1,
            'author_identity_id' => $identityId,
            'is_cech_researcher' => 0,
            'is_indexed' => 0,
        ]);

        return (int)$this->conn->lastInsertId();
    }
}
