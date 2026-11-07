<?php

declare(strict_types=1);

namespace App\Tests\Service\Research;

use App\Service\Research\ExpertSearchService;
use App\Service\Research\ResearchFilter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ExpertSearchServiceTest extends KernelTestCase
{
    use ResearchFixtures;

    private ExpertSearchService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->service = static::getContainer()->get(ExpertSearchService::class);
        $this->t = $this->newToken();
        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        parent::tearDown();
    }

    private function filter(string $q, array $extra = []): ResearchFilter
    {
        $f = new ResearchFilter();
        $f->q = $q;
        $f->department = $this->dept();
        foreach ($extra as $k => $v) {
            $f->$k = $v;
        }

        return $f;
    }

    public function testTitleMatchOutranksKeywordMatchAndPhraseInTitleWins(): void
    {
        $t = $this->t;
        $title = $this->insertResearcher("Ana Título $t");
        $keyword = $this->insertResearcher("Bruno Palavra $t");
        $phrase = $this->insertResearcher("Carla Frase $t");

        // Ana: as duas palavras no título, separadas; Carla: a expressão completa no título; Bruno: só nas palavras-chave
        $this->insertItem($title, "Professores em formação continuada $t");
        $this->insertItem($keyword, "Estudo de caso $t", 2024, 'ARTIGO', null, ["formação de professores $t"]);
        $this->insertItem($phrase, "A formação de professores $t no Brasil");

        // "de" é palavra vazia: Ana entra mesmo sem ela; a expressão completa no título dá a Carla o primeiro lugar
        $result = $this->service->experts($this->filter("formação de professores $t"));

        $this->assertSame(3, $result['count']);
        $names = array_map(fn($r) => $r['researcher']['name'], $result['results']);
        $this->assertSame(["Carla Frase $t", "Ana Título $t", "Bruno Palavra $t"], $names);
        $this->assertSame("A formação de professores $t no Brasil", $result['results'][0]['publications'][0]['title']);
    }

    public function testSearchIgnoresAccentsAndCaseAndRequiresAllWords(): void
    {
        $t = $this->t;
        $rid = $this->insertResearcher("Docente Acento $t");
        $this->insertItem($rid, "EDUCAÇÃO INCLUSIVA $t");
        $this->insertItem($rid, "Educação especial $t");

        $this->assertSame(1, $this->service->experts($this->filter("educacao inclusiva $t"))['publications']);
        $this->assertSame(2, $this->service->experts($this->filter("educacao $t"))['publications']);
    }

    public function testExactPhraseKeepsWordOrder(): void
    {
        $t = $this->t;
        $rid = $this->insertResearcher("Docente Exata $t");
        $this->insertItem($rid, "Políticas públicas de leitura $t");
        $this->insertItem($rid, "Leitura e públicas políticas $t");

        $loose = $this->service->experts($this->filter("políticas públicas $t"));
        $exact = $this->service->experts($this->filter("políticas públicas de leitura $t", ['exact' => true]));

        $this->assertSame(2, $loose['publications']);
        $this->assertSame(1, $exact['publications']);
    }

    public function testFiltersByTypePeriodAndAffiliation(): void
    {
        $t = $this->t;
        $active = $this->insertResearcher("Docente Ativa $t", null, 1);
        $former = $this->insertResearcher("Docente Aposentada $t", null, 0, 1980, 2015);
        $this->insertItem($active, "Infância e escola $t", 2019, 'ARTIGO');
        $this->insertItem($active, "Infância e cidade $t", 2010, 'LIVRO');
        $this->insertItem($former, "Infância no campo $t", 2012, 'ARTIGO');

        $this->assertSame(3, $this->service->experts($this->filter("infância $t"))['publications']);
        $this->assertSame(2, $this->service->experts($this->filter("infância $t", ['type' => 'artigo']))['publications']);
        $this->assertSame(2, $this->service->experts($this->filter("infância $t", ['from' => 2011]))['publications']);
        $this->assertSame(1, $this->service->experts($this->filter("infância $t", ['affiliation' => 'encerrado']))['count']);

        $formerCard = $this->service->experts($this->filter("infância $t", ['affiliation' => 'encerrado']))['results'][0]['researcher'];
        $this->assertSame('encerrado', $formerCard['affiliation']);
        $this->assertSame('1980–2015', $formerCard['period']);
    }

    public function testFiltersByKnowledgeAreaInBothSpellings(): void
    {
        $t = $this->t;
        $a = $this->insertResearcher("Docente Agrária Código $t");
        $b = $this->insertResearcher("Docente Agrária Nome $t");
        $c = $this->insertResearcher("Docente Humanas $t");
        $this->insertKnowledgeArea($a, 'CIENCIAS_AGRARIAS', 'Agronomia');
        $this->insertKnowledgeArea($b, 'Ciências Agrárias', 'Agronomia');
        $this->insertKnowledgeArea($c, 'CIENCIAS_HUMANAS', 'Educação');
        foreach ([$a, $b, $c] as $rid) {
            $this->insertItem($rid, "Solo e sociedade $t $rid");
        }

        $this->assertSame(2, $this->service->experts($this->filter("solo $t", ['majorArea' => 'CIENCIAS_AGRARIAS']))['count']);
        $this->assertSame(1, $this->service->experts($this->filter("solo $t", ['area' => 'Educação']))['count']);
    }

    public function testPublicationsAreUniqueAcrossCurriculaAndLinkCechAuthors(): void
    {
        $t = $this->t;
        $a = $this->insertResearcher("Docente Um $t");
        $b = $this->insertResearcher("Docente Dois $t");
        $this->insertSharedWork([$a, $b], "Obra compartilhada $t", 2022, "10.1234/$t");
        $this->insertItem($a, "Obra individual $t", 2018);

        $result = $this->service->publications($this->filter("obra $t"), 'recentes');

        $this->assertSame(2, $result['count'], 'A obra em dois currículos conta uma vez');
        $shared = $result['results'][0];
        $this->assertSame("Obra compartilhada $t", $shared['title']);
        $this->assertSame("10.1234/$t", $shared['doi']);
        $this->assertCount(2, $shared['owners']);
        $this->assertNotNull($shared['authors'][0]['slug'], 'Autor do CECH vira link');
    }

    public function testPublicationsAlsoMatchJournalAndDoi(): void
    {
        $t = $this->t;
        $rid = $this->insertResearcher("Docente Periódico $t");
        $this->insertItem($rid, "Um título qualquer", 2020, 'ARTIGO', "10.9999/doi-$t", [], "Revista Teste $t");

        $this->assertSame(1, $this->service->publications($this->filter("Revista Teste $t"))['count']);
        $this->assertSame(1, $this->service->publications($this->filter("doi-$t"))['count']);
        $this->assertSame(0, $this->service->experts($this->filter("Revista Teste $t"))['count'], 'Especialistas usam só título e palavras-chave');
    }

    public function testResearchersByNameWithAffiliationFilter(): void
    {
        $t = $this->t;
        $this->insertResearcher("Maria Nome $t", null, 1);
        $this->insertResearcher("Maria Antiga $t", null, 0, 1990, 2010);

        $all = $this->service->researchers($this->filter("Maria"));
        $former = $this->service->researchers($this->filter("Maria", ['affiliation' => 'encerrado']));

        $this->assertSame(2, $all['count']);
        $this->assertSame(1, $former['count']);
        $this->assertSame("Maria Antiga $t", $former['results'][0]['name']);
    }
}
