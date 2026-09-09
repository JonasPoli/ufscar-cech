<?php

namespace App\Tests\Controller\admin;

use App\Entity\AcademicDatabase;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class AdminAcademicDatabaseTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Ensure admin user
        $userRepo = $this->em->getRepository(User::class);
        $admin = $userRepo->findOneBy(['username' => 'admin_test']);
        if (!$admin) {
            $admin = new User();
            $admin->setUsername('admin_test');
            $admin->setPassword('password');
            $admin->setRoles(['ROLE_ADMIN']);
            $this->em->persist($admin);
            $this->em->flush();
        }
        $this->client->loginUser($admin);
    }

    public function testAdminAcademicDatabaseIndex(): void
    {
        $this->client->request('GET', '/admin/academic-databases/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Bases de Indexação Acadêmica');
    }

    public function testCreateAndEditAcademicDatabase(): void
    {
        $uniqueAcronym = 'testdb_' . uniqid();
        
        // 1. Create
        $this->client->request('GET', '/admin/academic-databases/new');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Cadastrar Base', [
            'name' => 'Database Test Name',
            'acronym' => $uniqueAcronym,
            'url' => 'https://testdb.example.org',
            'file_formats' => 'csv, xlsx',
            'signature_columns' => 'TestID, Title',
            'description' => 'A test database description',
        ]);

        $this->assertResponseRedirects('/admin/academic-databases/');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Database Test Name');

        // 2. Verify in DB
        $db = $this->em->getRepository(AcademicDatabase::class)->findOneBy(['acronym' => $uniqueAcronym]);
        $this->assertNotNull($db);
        $this->assertEquals('Database Test Name', $db->getName());

        // 3. Edit
        $this->client->request('GET', '/admin/academic-databases/' . $db->getId() . '/edit');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Salvar Alterações', [
            'name' => 'Database Test Name Updated',
            'acronym' => $uniqueAcronym,
            'url' => 'https://testdb-updated.example.org',
            'file_formats' => 'csv, json',
            'signature_columns' => 'TestID, Title, Abstract',
            'description' => 'Updated description',
        ]);

        $this->assertResponseRedirects('/admin/academic-databases/');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Database Test Name Updated');
    }

    private function getStreamedResponseContent(Response $response): string
    {
        $ref = new \ReflectionProperty(\Symfony\Component\HttpFoundation\StreamedResponse::class, 'callback');
        $callback = $ref->getValue($response);
        ob_start();
        $callback();
        return (string)ob_get_clean();
    }

    public function testExportThesaurusAllIssnTheFormat(): void
    {
        // Ensure at least one journal exists with ISSN in test DB
        $journalRepo = $this->em->getRepository(\App\Entity\QualisJournal::class);
        $journal = $journalRepo->findOneBy([]) ?: new \App\Entity\QualisJournal();
        if (!$journal->getId()) {
            $journal->setTitle('Revista Teste de Indexação');
            $journal->setIssn('2318-1265');
            $this->em->persist($journal);
            $this->em->flush();
        }

        $this->client->request('GET', '/admin/academic-databases/export-thesaurus-all?key=issn&format=the');
        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $this->assertStringContainsString('text/plain', (string)$response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="tesauro_revistas_issn.the"', (string)$response->headers->get('Content-Disposition'));
        
        $output = $this->getStreamedResponseContent($response);

        $this->assertStringContainsString('**#', $output);
        $this->assertStringContainsString('100 1 ^', $output);
    }

    public function testExportThesaurusAllIssnCsvFormat(): void
    {
        $this->client->request('GET', '/admin/academic-databases/export-thesaurus-all?key=issn&format=csv');
        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $this->assertStringContainsString('text/csv', (string)$response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="tesauro_revistas_issn.csv"', (string)$response->headers->get('Content-Disposition'));

        $output = $this->getStreamedResponseContent($response);

        $this->assertStringContainsString('preferred_name,variant_name', $output);
    }

    public function testExportThesaurusAllIssnJsonFormat(): void
    {
        $this->client->request('GET', '/admin/academic-databases/export-thesaurus-all?key=issn&format=json');
        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $this->assertStringContainsString('application/json', (string)$response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="tesauro_revistas_issn.json"', (string)$response->headers->get('Content-Disposition'));

        $output = $this->getStreamedResponseContent($response);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded);
        $this->assertArrayHasKey('header', $decoded[0]);
        $this->assertArrayHasKey('variants', $decoded[0]);
    }

    public function testExportThesaurusSpecificDatabase(): void
    {
        $journal = $this->em->getRepository(\App\Entity\QualisJournal::class)->createQueryBuilder('j')
            ->where('j.issn IS NOT NULL AND j.issn != \'\'')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$journal) {
            $journal = new \App\Entity\QualisJournal();
            $journal->setTitle('Revista Scopus Test');
            $journal->setIssn('9999-8888');
            $this->em->persist($journal);
        }

        $db = $this->em->getRepository(AcademicDatabase::class)->findOneBy([]) ?: new AcademicDatabase();
        if (!$db->getId()) {
            $db->setName('Test Scopus');
            $db->setAcronym('testscopus');
            $this->em->persist($db);
        }

        if (!$journal->getAcademicDatabases()->contains($db)) {
            $journal->addAcademicDatabase($db);
        }
        $this->em->flush();

        $this->client->request('GET', sprintf('/admin/academic-databases/%d/export-thesaurus?key=issn&format=the', $db->getId()));
        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $this->assertStringContainsString('text/plain', (string)$response->headers->get('Content-Type'));
        $this->assertStringContainsString('tesauro_' . $db->getAcronym() . '_issn.the', (string)$response->headers->get('Content-Disposition'));

        $outputIssn = $this->getStreamedResponseContent($response);
        $this->assertStringContainsString('**#', $outputIssn);

        // Test with key=base
        $this->client->request('GET', sprintf('/admin/academic-databases/%d/export-thesaurus?key=base&format=the', $db->getId()));
        $this->assertResponseIsSuccessful();
        $responseBase = $this->client->getResponse();
        $this->assertStringContainsString('tesauro_' . $db->getAcronym() . '_base.the', (string)$responseBase->headers->get('Content-Disposition'));
        
        $outputBase = $this->getStreamedResponseContent($responseBase);
        $this->assertStringContainsString('**#' . $db->getName(), $outputBase);
    }
}
