<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Entity\JournalNameCandidate;
use App\Entity\QualisJournal;
use App\Service\Journal\JournalExternalNameLearner;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Títulos das listas externas no tesauro de periódicos. Cada teste roda numa transação revertida no tearDown.
 */
class JournalExternalNameLearnerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private JournalExternalNameLearner $learner;
    private string $artifact;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->learner = $container->get(JournalExternalNameLearner::class);
        $this->artifact = tempnam(sys_get_temp_dir(), 'evidence') . '.jsonl.gz';

        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        $this->em->clear();
        @unlink($this->artifact);
        parent::tearDown();
    }

    public function testTitleCloseToTheCatalogBecomesAVariantOnTheCanonicalRow(): void
    {
        $t = $this->token();
        $issnPrint = $this->issn();
        $issnOnline = $this->issn();
        $canonical = $this->journal("REVISTA $t DE EDUCACAO", $issnPrint);
        $this->journal("REVISTA $t DE EDUCACAO ONLINE", $issnOnline, $canonical);
        $name = "Revista $t de Educação e Sociedade";
        $this->writeArtifact([['src' => 'scopus', 'issns' => [$this->digits($issnOnline)], 'title' => $name]]);

        $dry = $this->learner->learn($this->artifact, true);
        $this->assertSame(1, $dry['learned']);
        $this->assertNull($this->variant($name), 'A simulação não grava.');

        $this->assertSame(1, $this->learner->learn($this->artifact)['learned']);
        $row = $this->variant($name);
        $this->assertSame($canonical->getId(), (int)$row['journal_id']);
        $this->assertSame('external', $row['variation_type']);
        $this->assertSame($name, $row['variation_name']);

        $this->assertSame(0, $this->learner->learn($this->artifact)['learned'], 'Rodar de novo não duplica.');
    }

    public function testAlternativeTitlesOfOpenAlexAlsoCount(): void
    {
        $t = $this->token();
        $issn = $this->issn();
        $this->journal("JOURNAL $t OF ONCOLOGY", $issn);
        $name = "Journal $t of Oncology Reports";
        $this->writeArtifact([[
            'src' => 'openalex',
            'issns' => [$this->digits($issn)],
            'title' => "Journal $t of Oncology",
            'extra' => ['alt_titles' => [$name]],
        ]]);

        $this->learner->learn($this->artifact);

        $this->assertNotNull($this->variant($name));
    }

    public function testDifferentNameNeedsTwoLists(): void
    {
        $t = $this->token();
        $issn = $this->issn();
        $this->journal("JOURNAL $t OF PRACTICAL ONCOLOGY", $issn);
        $name = "Shiyong $t zhongliu zazhi";

        $this->writeArtifact([['src' => 'doaj', 'issns' => [$this->digits($issn)], 'title' => $name]]);
        $result = $this->learner->learn($this->artifact);
        $this->assertSame(1, $result['incoherent']);
        $this->assertNull($this->variant($name), 'Uma lista só não basta para um nome tão diferente.');

        $this->writeArtifact([
            ['src' => 'doaj', 'issns' => [$this->digits($issn)], 'title' => $name],
            ['src' => 'scopus', 'issns' => [$this->digits($issn)], 'title' => $name],
        ]);
        $this->learner->learn($this->artifact);
        $this->assertNotNull($this->variant($name), 'Duas listas confirmam.');
    }

    public function testShortNameContainedInTheTitleNeedsARareWord(): void
    {
        $rare = strtolower($this->token());
        $common = strtolower($this->token());
        $issnRare = $this->issn();
        $issnCommon = $this->issn();
        $this->journal("$rare ENGLISH STUDIES IN CULTURE AND EDUCATION IN THE WORLD", $issnRare);
        $this->journal("$common INTERNATIONAL STUDIES OF ADVANCED HUMANITIES AND LANGUAGES", $issnCommon);
        for ($i = 0; $i < 6; ++$i) {
            $this->journal("$common OUTRA REVISTA $i");
        }

        $this->writeArtifact([
            ['src' => 'doaj', 'issns' => [$this->digits($issnRare)], 'title' => "$rare English"],
            ['src' => 'doaj', 'issns' => [$this->digits($issnCommon)], 'title' => $common],
        ]);
        $this->learner->learn($this->artifact);

        $this->assertNotNull($this->variant("$rare English"));
        $this->assertNull($this->variant($common), 'Palavra presente em muitos títulos não identifica a revista.');
    }

    public function testNameEqualToTheTitleWithoutParenthesesOfAnotherJournalIsLeftOut(): void
    {
        $t = $this->token();
        $issn = $this->issn();
        $this->journal("PSYCHE $t (PASADENA)");
        $this->journal("PSYCHE $t (STUTTGART) / PSYCHOANALYSE", $issn);
        $this->writeArtifact([['src' => 'doaj', 'issns' => [$this->digits($issn)], 'title' => "Psyche $t"]]);

        $result = $this->learner->learn($this->artifact);

        $this->assertSame(0, $result['candidates'], 'Se virasse variante, o resolver deixaria de achar a revista de Pasadena.');
        $this->assertNull($this->variant("Psyche $t"));
    }

    public function testSpellingsThatTheDatabaseTreatsAsEqualDoNotBreakTheRun(): void
    {
        $t = $this->token();
        $issn = $this->issn();
        $this->journal("BRITISH $t HOMOEOPATHIC JOURNAL OF MEDICINE", $issn);
        $this->writeArtifact([
            ['src' => 'doaj', 'issns' => [$this->digits($issn)], 'title' => "British $t Homoeopathic Journal"],
            ['src' => 'scopus', 'issns' => [$this->digits($issn)], 'title' => "British $t Homœopathic Journal"],
        ]);

        $result = $this->learner->learn($this->artifact);

        $this->assertSame(2, $result['candidates']);
        $this->assertNotNull($this->variant("British $t Homoeopathic Journal"));
    }

    public function testNameInTwoFamiliesIsAmbiguous(): void
    {
        $t = $this->token();
        $issnA = $this->issn();
        $issnB = $this->issn();
        $this->journal("REVISTA $t DE SAUDE", $issnA);
        $this->journal("REVISTA $t DE SAUDE PUBLICA", $issnB);
        $name = "Revista $t de Saude Coletiva";
        $this->writeArtifact([
            ['src' => 'doaj', 'issns' => [$this->digits($issnA)], 'title' => $name],
            ['src' => 'scopus', 'issns' => [$this->digits($issnB)], 'title' => $name],
        ]);

        $result = $this->learner->learn($this->artifact);

        $this->assertSame(1, $result['ambiguous']);
        $this->assertNull($this->variant($name));
    }

    public function testIssnThatBelongsToTwoFamiliesDecidesNothing(): void
    {
        $t = $this->token();
        $issn = $this->issn();
        $this->journal("REVISTA $t ALFA", $issn);
        $this->journal("REVISTA $t BETA", $issn);
        $name = "Revista $t Alfa Nova";
        $this->writeArtifact([['src' => 'doaj', 'issns' => [$this->digits($issn)], 'title' => $name]]);

        $result = $this->learner->learn($this->artifact);

        $this->assertSame(0, $result['candidates']);
        $this->assertNull($this->variant($name));
    }

    public function testNameRejectedInTheQueueIsRespected(): void
    {
        $t = $this->token();
        $issn = $this->issn();
        $this->journal("REVISTA $t GAMA", $issn);
        $name = "Revista $t Gama Nova";
        $candidate = new JournalNameCandidate();
        $candidate->setRawName($name);
        $candidate->setNormalizedName(StringNormalizer::normalizeString($name, true));
        $candidate->setStatus(JournalNameCandidate::STATUS_REJECTED);
        $this->em->persist($candidate);
        $this->em->flush();
        $this->writeArtifact([['src' => 'doaj', 'issns' => [$this->digits($issn)], 'title' => $name]]);

        $result = $this->learner->learn($this->artifact);

        $this->assertSame(0, $result['candidates']);
        $this->assertNull($this->variant($name));
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function writeArtifact(array $records): void
    {
        $lines = [json_encode(['_meta' => ['built_at' => '2026-10-31T00:00:00+00:00', 'sources' => []]])];
        foreach ($records as $i => $record) {
            $lines[] = json_encode($record + ['rec' => 'r' . $i, 'labels' => [], 'extra' => []], JSON_UNESCAPED_UNICODE);
        }
        file_put_contents($this->artifact, gzencode(implode("\n", $lines) . "\n"));
    }

    private function journal(string $title, ?string $issn = null, ?QualisJournal $canonical = null): QualisJournal
    {
        $journal = new QualisJournal();
        $journal->setTitle($title);
        if ($issn !== null) {
            $journal->setIssn($issn);
        }
        if ($canonical !== null) {
            $journal->setCanonical($canonical);
            $journal->setCanonicalMethod('manual');
        }
        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function variant(string $name): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT journal_id, variation_name, variation_type FROM journal_name_variants WHERE normalized_name = ?',
            [StringNormalizer::normalizeString($name, true)]
        );

        return $row === false ? null : $row;
    }

    private function token(): string
    {
        return 'Zl' . bin2hex(random_bytes(5));
    }

    private function digits(string $issn): string
    {
        return strtolower(str_replace('-', '', $issn));
    }

    private function issn(): string
    {
        $digits = str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 7; ++$i) {
            $sum += (int)$digits[$i] * (8 - $i);
        }
        $check = (11 - ($sum % 11)) % 11;

        return substr($digits, 0, 4) . '-' . substr($digits, 4) . ($check === 10 ? 'X' : (string)$check);
    }
}
