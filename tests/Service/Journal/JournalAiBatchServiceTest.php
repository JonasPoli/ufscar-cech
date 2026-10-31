<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Service\Journal\CapesTaxonomy;
use App\Service\Journal\JournalAiBatchService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lotes de classificação por IA (docs/evolutions/periodicos-por-base.md, A7 e T15): seleção e formato da exportação,
 * importação com linhas inválidas e reimportação. Os dados são criados numa transação desfeita no tearDown; os
 * arquivos vão para pastas temporárias removidas no fim.
 */
class JournalAiBatchServiceTest extends KernelTestCase
{
    private JournalAiBatchService $service;
    private CapesTaxonomy $taxonomy;
    private EntityManagerInterface $em;
    private Connection $conn;
    private ?Researcher $researcher = null;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->service = $container->get(JournalAiBatchService::class);
        $this->taxonomy = $container->get(CapesTaxonomy::class);
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        $this->em->clear();
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }

        parent::tearDown();
    }

    public function testExportSelectsOnlyFamiliesWithoutAreaOrWithLowConfidence(): void
    {
        $token = $this->token();

        $noArea = $this->journal('Revista Sem Area ' . $token, $issnNoArea = $this->issn());
        $this->article($noArea, 'Artigo sem area um ' . $token);
        $this->article($noArea, 'Artigo sem area dois ' . $token);

        $high = $this->journal('Revista Area Alta ' . $token, $issnHigh = $this->issn());
        $this->article($high, 'Artigo area alta ' . $token);
        $this->area($high, 'educacao', 'high');

        $low = $this->journal('Revista Area Baixa ' . $token, $issnLow = $this->issn());
        $this->article($low, 'Artigo area baixa ' . $token);
        $this->area($low, 'psicologia', 'low');

        $medium = $this->journal('Revista Area Media ' . $token, $issnMedium = $this->issn());
        $this->article($medium, 'Artigo area media ' . $token);
        $this->area($medium, 'sociologia', 'medium');

        $titleOnly = $this->journal('Revista Sem ISSN ' . $token);
        $this->article($titleOnly, 'Artigo sem issn ' . $token);

        [$lines, $dir] = $this->export(false);
        $keys = array_column($lines, 'key');
        $titleKey = 'title:' . StringNormalizer::normalizeString('Revista Sem ISSN ' . $token, true);

        self::assertContains('issn:' . $this->n8($issnNoArea), $keys);
        self::assertContains('issn:' . $this->n8($issnLow), $keys);
        self::assertContains($titleKey, $keys);
        self::assertNotContains('issn:' . $this->n8($issnHigh), $keys);
        self::assertNotContains('issn:' . $this->n8($issnMedium), $keys);
        self::assertLessThan(
            array_search($titleKey, $keys, true),
            array_search('issn:' . $this->n8($issnNoArea), $keys, true),
            'Famílias com mais artigos vêm antes.'
        );
        self::assertStringContainsString('Você classifica periódicos', (string) file_get_contents($dir . '/PROMPT.md'));
        self::assertStringNotContainsString('Há apenas título e país', (string) file_get_contents($dir . '/PROMPT.md'));

        self::assertSame(
            ['primary' => 'psicologia', 'confidence' => 'low'],
            $this->lineFor($lines, 'issn:' . $this->n8($issnLow))['current'],
            'A família com área low leva a área atual para a IA revisar.'
        );

        [$withMedium] = $this->export(true);
        $mediumKeys = array_column($withMedium, 'key');
        self::assertContains('issn:' . $this->n8($issnMedium), $mediumKeys);
        self::assertNotContains('issn:' . $this->n8($issnHigh), $mediumKeys);
    }

    public function testExportLineDescribesTheWholeFamily(): void
    {
        $token = $this->token();

        $canonical = $this->journal('Revista Familia ' . $token, $issnCanonical = $this->issn());
        $member = $this->journal('Rev. Fam. ' . $token, $issnMember = $this->issn());
        $this->conn->update('qualis_journals', ['canonical_journal_id' => $canonical->getId()], ['id' => $member->getId()]);
        $this->article($member, 'Titulo do artigo da familia ' . $token);
        $this->base($member, 'scopus');
        $this->variant($canonical, 'Variante da Familia ' . $token);
        $this->evidence($canonical, 'wos', 'Education & Educational Research', '1.0000');
        $this->evidence($canonical, 'scopus', '3304', '0.5000');
        $this->evidence($canonical, 'scopus', '3312', '0.5000');
        $this->evidence($canonical, 'openalex', '3304', '0.6000', ['works' => 5, 'publisher' => 'Editora Teste', 'country' => 'BR']);
        $this->evidence($canonical, 'ai', 'direito', '1.0000', ['role' => 'primary', 'confidence' => 0.9]);

        [$lines] = $this->export(false);
        $line = $this->lineFor($lines, 'issn:' . $this->n8($issnCanonical));

        self::assertSame('Revista Familia ' . $token, $line['title']);
        self::assertSame([$issnCanonical, $issnMember], $line['issns']);
        self::assertSame(['scopus'], $line['bases']);
        self::assertSame(1, $line['articles']);
        self::assertContains('Rev. Fam. ' . $token, $line['other_titles']);
        self::assertContains('Variante da Familia ' . $token, $line['other_titles']);
        self::assertSame(['Education & Educational Research'], $line['evidence']['wos']);
        self::assertSame(['3304', '3312'], $line['evidence']['asjc']);
        self::assertSame([], $line['evidence']['doaj']);
        self::assertSame([['code' => '3304', 'share' => 0.6]], $line['evidence']['openalex']);
        self::assertSame('Editora Teste', $line['publisher']);
        self::assertSame('BR', $line['country']);
        self::assertSame(['primary' => null, 'confidence' => null], $line['current']);
        self::assertSame(['Titulo do artigo da familia ' . $token], $line['sample_articles']);
        self::assertStringNotContainsString('direito', json_encode($line, JSON_THROW_ON_ERROR));
    }

    public function testExportUnclassifiedKeepsOnlyKeyTitleAndCountry(): void
    {
        $token = $this->token();

        $noArea = $this->journal('Revista Sem Area ' . $token, $issnNoArea = $this->issn());
        $this->article($noArea, 'Artigo sem area ' . $token);

        $high = $this->journal('Revista Area Alta ' . $token, $issnHigh = $this->issn());
        $this->article($high, 'Artigo area alta ' . $token);
        $this->area($high, 'educacao', 'high');

        $low = $this->journal('Revista Area Baixa ' . $token, $issnLow = $this->issn());
        $this->article($low, 'Artigo area baixa ' . $token);
        $this->area($low, 'psicologia', 'low');

        $titleOnly = $this->journal('Revista Sem ISSN ' . $token);
        $this->article($titleOnly, 'Artigo sem issn ' . $token);

        $noArticle = $this->journal('Revista Sem Artigos ' . $token, $issnNoArticle = $this->issn());
        $this->evidence($noArticle, 'openalex', '3304', '1.0000', ['country' => 'PT']);

        [$lines, $dir] = $this->exportUnclassified();
        $keys = array_column($lines, 'key');

        self::assertContains('issn:' . $this->n8($issnNoArea), $keys);
        self::assertContains('issn:' . $this->n8($issnNoArticle), $keys);
        self::assertContains('title:' . StringNormalizer::normalizeString('Revista Sem ISSN ' . $token, true), $keys);
        self::assertNotContains('issn:' . $this->n8($issnHigh), $keys);
        self::assertNotContains('issn:' . $this->n8($issnLow), $keys);

        foreach ($lines as $line) {
            $fields = array_keys($line);
            sort($fields);
            self::assertSame(['country', 'key', 'title'], $fields);
        }
        self::assertSame('PT', $this->lineFor($lines, 'issn:' . $this->n8($issnNoArticle))['country']);
        self::assertNull($this->lineFor($lines, 'issn:' . $this->n8($issnNoArea))['country']);

        self::assertFileExists($dir . '/title_001.jsonl');
        self::assertSame([], glob($dir . '/batch_*') ?: []);
        self::assertStringContainsString(
            JournalAiBatchService::PROMPT_UNCLASSIFIED,
            (string) file_get_contents($dir . '/PROMPT.md')
        );
    }

    public function testImportAcceptsValidLineAndSkipsInvalidOnes(): void
    {
        $token = $this->token();

        $journal = $this->journal('Revista Importacao ' . $token, $issn = $this->issn());
        $this->article($journal, 'Artigo importacao ' . $token);
        $this->evidence($journal, 'wos', 'Education & Educational Research', '1.0000');

        $titleOnly = $this->journal('Revista Importacao Sem ISSN ' . $token);
        $this->article($titleOnly, 'Artigo sem issn importacao ' . $token);

        $keyIssn = 'issn:' . $this->n8($issn);
        $keyTitle = 'title:' . StringNormalizer::normalizeString('Revista Importacao Sem ISSN ' . $token, true);
        $path = $this->writeInput('batch_901.out.jsonl', [
            json_encode(['key' => $keyIssn, 'primary' => 'educacao', 'secondary' => [], 'confidence' => 0.9, 'note' => 'Escopo claro.'], JSON_THROW_ON_ERROR),
            '{"key": "' . $keyTitle . '", "primary": ',
            json_encode(['key' => $keyTitle, 'primary' => 'nao-existe', 'secondary' => [], 'confidence' => 0.5], JSON_THROW_ON_ERROR),
            json_encode(['key' => $keyTitle, 'primary' => null, 'secondary' => [], 'confidence' => 0.3], JSON_THROW_ON_ERROR),
            json_encode(['key' => 'issn:' . $this->n8($this->issn()), 'primary' => 'educacao', 'confidence' => 0.5], JSON_THROW_ON_ERROR),
            json_encode(['key' => $keyIssn, 'primary' => 'psicologia', 'confidence' => 0.4], JSON_THROW_ON_ERROR),
        ]);

        $result = $this->service->import($path);

        self::assertSame(6, $result['lines']);
        self::assertSame(1, $result['imported']);
        self::assertSame([2, 3, 4, 5, 6], array_column($result['skipped'], 'line'));
        self::assertSame(
            ['invalid_json', 'invalid_primary', 'unclassifiable', 'unknown_family', 'duplicate_family'],
            array_column($result['skipped'], 'reason')
        );

        $rows = $this->aiRows('batch_901.out.jsonl');
        self::assertCount(1, $rows);
        self::assertSame($journal->getId(), (int) $rows[0]['journal_id']);
        self::assertSame('educacao', $rows[0]['label']);
        self::assertSame('1.0000', $rows[0]['weight']);
        self::assertSame($keyIssn, $rows[0]['record_key']);

        $payload = json_decode((string) $rows[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('primary', $payload['role']);
        self::assertSame(0.9, $payload['confidence']);
        self::assertSame('claude-sonnet', $payload['model']);
        self::assertSame('Escopo claro.', $payload['linha']['note']);

        self::assertSame(
            1,
            (int) $this->conn->fetchOne("SELECT COUNT(*) FROM journal_area_evidence WHERE journal_id = ? AND source = 'wos'", [$journal->getId()]),
            'A importação não mexe nas evidências das outras fontes.'
        );
    }

    public function testImportResolvesSharedIssnToTheFamilyOfTheKey(): void
    {
        $token = $this->token();
        $issnA = $this->issn();
        $issnB = $this->issn();
        $issnZ = $this->issn();

        // A tem o ISSN X como principal; B tem o ISSN Y e um membro com X. A chave issn:X é a de A.
        $a = $this->journal('Revista Chave A ' . $token, $issnA);
        $b = $this->journal('Revista Chave B ' . $token, $issnB);
        $member = $this->journal('Revista Chave B Membro ' . $token, $issnA);
        $this->conn->update('qualis_journals', ['canonical_journal_id' => $b->getId()], ['id' => $member->getId()]);

        // C e D são duas famílias com o mesmo ISSN Z como principal: a chave não distingue, então a linha é ambígua.
        $this->journal('Revista Ambigua C ' . $token, $issnZ);
        $this->journal('Revista Ambigua D ' . $token, $issnZ);

        $path = $this->writeInput('batch_903.out.jsonl', [
            json_encode(['key' => 'issn:' . $this->n8($issnA), 'primary' => 'educacao', 'confidence' => 0.9], JSON_THROW_ON_ERROR),
            json_encode(['key' => 'issn:' . $this->n8($issnZ), 'primary' => 'educacao', 'confidence' => 0.9], JSON_THROW_ON_ERROR),
        ]);

        $result = $this->service->import($path);

        self::assertSame(1, $result['imported']);
        self::assertSame(['ambiguous_family'], array_column($result['skipped'], 'reason'));
        $rows = $this->aiRows('batch_903.out.jsonl');
        self::assertCount(1, $rows);
        self::assertSame($a->getId(), (int) $rows[0]['journal_id'], 'A linha vai para a família cuja chave exportada é esta.');
    }

    public function testReimportReplacesOnlyTheSameBatch(): void
    {
        $token = $this->token();

        $journal = $this->journal('Revista Reimportacao ' . $token, $issn = $this->issn());
        $this->article($journal, 'Artigo reimportacao ' . $token);
        $key = 'issn:' . $this->n8($issn);

        $path = $this->writeInput('batch_902.out.jsonl', [
            json_encode(['key' => $key, 'primary' => 'educacao', 'secondary' => ['sociologia'], 'confidence' => 0.8], JSON_THROW_ON_ERROR),
        ]);

        $first = $this->service->import($path, 'claude-haiku');
        self::assertSame(1, $first['imported']);

        $rows = $this->aiRows('batch_902.out.jsonl');
        self::assertSame(['educacao' => '1.0000', 'sociologia' => '0.5000'], array_column($rows, 'weight', 'label'));
        $roles = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $roles[$row['label']] = [$payload['role'], $payload['model']];
        }
        self::assertSame(
            ['educacao' => ['primary', 'claude-haiku'], 'sociologia' => ['secondary', 'claude-haiku']],
            $roles
        );

        $this->service->import($path, 'claude-haiku');
        self::assertCount(2, $this->aiRows('batch_902.out.jsonl'), 'Reimportar o mesmo arquivo não duplica.');

        file_put_contents($path, json_encode(['key' => $key, 'primary' => 'psicologia', 'confidence' => 0.7], JSON_THROW_ON_ERROR) . "\n");
        $this->service->import($path, 'claude-haiku');
        self::assertSame(['psicologia'], array_column($this->aiRows('batch_902.out.jsonl'), 'label'));
    }

    public function testPromptListsExactlyTheTaxonomySlugs(): void
    {
        preg_match('/^Slugs permitidos \(use exatamente\): (.+)\.$/m', JournalAiBatchService::PROMPT, $match);
        self::assertArrayHasKey(1, $match, 'A lista de slugs deve estar no prompt.');

        $listed = explode(', ', $match[1]);
        $expected = array_column($this->taxonomy->all(), 'slug');
        sort($listed);
        sort($expected);

        self::assertSame($expected, $listed);
    }

    private function token(): string
    {
        return strtoupper(bin2hex(random_bytes(3)));
    }

    /** ISSN válido e aleatório, na forma de exibição (0000-000X). */
    private function issn(): string
    {
        $digits = str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 7; ++$i) {
            $sum += (int) $digits[$i] * (8 - $i);
        }
        $check = (11 - ($sum % 11)) % 11;

        return substr($digits, 0, 4) . '-' . substr($digits, 4, 3) . ($check === 10 ? 'X' : (string) $check);
    }

    private function n8(string $issn): string
    {
        return strtolower(str_replace('-', '', $issn));
    }

    private function journal(string $title, ?string $issn = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        if ($issn !== null) {
            $journal->setIssn($issn);
        }
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    private function article(QualisJournal $journal, string $title): void
    {
        $item = new ProductionItem();
        $item->setItemType(ProductionItem::TYPE_ARTIGO);
        $item->setTitle($title);
        $item->setYear(2024);
        $item->setJournalName($journal->getTitle());
        $item->setQualisJournal($journal);
        $item->setResearcher($this->researcher());
        $this->em->persist($item);
        $this->em->flush();
    }

    private function researcher(): Researcher
    {
        if ($this->researcher === null) {
            $researcher = new Researcher();
            $researcher->setIdLattes('9966' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT));
            $researcher->setFullName('Docente Teste Lotes IA');
            $researcher->setSlug('docente-teste-lotes-ia-' . bin2hex(random_bytes(4)));
            $this->em->persist($researcher);
            $this->em->flush();
            $this->researcher = $researcher;
        }

        return $this->researcher;
    }

    private function area(QualisJournal $journal, string $slug, string $confidence): void
    {
        $this->conn->insert('journal_areas', [
            'journal_id' => $journal->getId(),
            'area_id' => (int) $this->conn->fetchOne('SELECT id FROM capes_areas WHERE slug = ?', [$slug]),
            'is_primary' => 1,
            'score' => '0.9000',
            'confidence' => $confidence,
            'method' => 'auto',
            'run_id' => 'teste-lotes-ia',
            'updated_at' => '2026-10-30 10:00:00',
        ]);
    }

    /** @param array<string, mixed>|null $payload */
    private function evidence(QualisJournal $journal, string $source, string $label, string $weight, ?array $payload = null): void
    {
        $this->conn->insert('journal_area_evidence', [
            'journal_id' => $journal->getId(),
            'source' => $source,
            'record_key' => 'teste-' . $this->token(),
            'label' => $label,
            'weight' => $weight,
            'source_title' => null,
            'batch' => null,
            'payload' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => '2026-10-30 10:00:00',
        ]);
    }

    private function base(QualisJournal $journal, string $acronym): void
    {
        $this->conn->insert('qualis_journal_academic_database', [
            'qualis_journal_id' => $journal->getId(),
            'academic_database_id' => (int) $this->conn->fetchOne('SELECT id FROM academic_database WHERE acronym = ?', [$acronym]),
        ]);
    }

    private function variant(QualisJournal $journal, string $name): void
    {
        $this->conn->insert('journal_name_variants', [
            'journal_id' => $journal->getId(),
            'variation_name' => $name,
            'normalized_name' => StringNormalizer::normalizeString($name, true),
            'variation_type' => 'abbreviation',
            'status' => 1,
            'created_at' => '2026-10-30 10:00:00',
            'updated_at' => '2026-10-30 10:00:00',
        ]);
    }

    /** @return array{0: list<array<string, mixed>>, 1: string} linhas do lote visível e pasta usada */
    private function export(bool $includeMedium): array
    {
        $dir = $this->tempDir();
        $this->service->export($dir, 40, $includeMedium);

        return [$this->readLines($dir, 'batch_*.jsonl'), $dir];
    }

    /** @return array{0: list<array<string, mixed>>, 1: string} linhas do escopo unclassified e pasta usada */
    private function exportUnclassified(): array
    {
        $dir = $this->tempDir();
        $this->service->exportUnclassified($dir);

        return [$this->readLines($dir, 'title_*.jsonl'), $dir];
    }

    /** @return list<array<string, mixed>> */
    private function readLines(string $dir, string $pattern): array
    {
        $lines = [];
        foreach (glob($dir . '/' . $pattern) ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $lines[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function lineFor(array $lines, string $key): array
    {
        $found = array_values(array_filter($lines, static fn (array $line): bool => $line['key'] === $key));
        self::assertCount(1, $found, 'Família ausente no lote: ' . $key);

        return $found[0];
    }

    /** @param list<string> $lines */
    private function writeInput(string $name, array $lines): string
    {
        $path = $this->tempDir() . '/' . $name;
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    /** @return list<array<string, mixed>> */
    private function aiRows(string $batch): array
    {
        return $this->conn->fetchAllAssociative(
            "SELECT * FROM journal_area_evidence WHERE source = 'ai' AND batch = ? ORDER BY id",
            [$batch]
        );
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/cech-lotes-ia-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    public function testThePromptListsExactlyTheSlugsOfTheTaxonomy(): void
    {
        preg_match('/Slugs permitidos \(use exatamente\): ([^\n]+)\./', \App\Service\Journal\JournalAiBatchService::PROMPT, $match);
        $inPrompt = array_map('trim', explode(',', $match[1]));
        sort($inPrompt);

        $expected = array_column((new \App\Service\Journal\CapesTaxonomy())->all(), 'slug');
        sort($expected);

        $this->assertSame($expected, $inPrompt, 'O texto do prompt é uma cópia da taxonomia: precisa acompanhar o arquivo.');
    }
}
