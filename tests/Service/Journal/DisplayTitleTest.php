<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Service\Journal\DisplayTitle;
use PHPUnit\Framework\TestCase;

/**
 * Nome exibido da família (A4 passo 6): caixa de título e ordem de prioridade das fontes.
 */
class DisplayTitleTest extends TestCase
{
    public function testTitleCaseOfAnUppercaseTitle(): void
    {
        $this->assertSame(
            'Revista Brasileira de Educação Especial',
            DisplayTitle::titleCase('REVISTA BRASILEIRA DE EDUCAÇÃO ESPECIAL')
        );
    }

    /**
     * @dataProvider titleCaseProvider
     */
    public function testTitleCaseKeepsConnectivesLowercaseExceptTheFirstWord(string $input, string $expected): void
    {
        $this->assertSame($expected, DisplayTitle::titleCase($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function titleCaseProvider(): array
    {
        return [
            'português' => ['REVISTA DA FACULDADE DE EDUCAÇÃO DOS ALUNOS E DAS ESCOLAS', 'Revista da Faculdade de Educação dos Alunos e das Escolas'],
            'artigos e preposições' => ['EDUCAÇÃO EM REVISTA PARA O ENSINO COM ARTE', 'Educação em Revista para o Ensino com Arte'],
            'primeira palavra é conectivo' => ['A VOZ DO MESTRE', 'A Voz do Mestre'],
            'primeira palavra é "o"' => ['O SOCIAL EM QUESTÃO', 'O Social em Questão'],
            'espanhol' => ['REVISTA DE LA FACULTAD DE CIENCIAS Y TECNOLOGÍA DEL SUR', 'Revista de la Facultad de Ciencias y Tecnología del Sur'],
            'inglês' => ['JOURNAL OF THE HISTORY AND PHILOSOPHY OF EDUCATION', 'Journal of the History and Philosophy of Education'],
            'italiano' => ['STUDI DI STORIA', 'Studi di Storia'],
            'entrada em minúsculas' => ['educação e sociedade', 'Educação e Sociedade'],
            'espaços repetidos e nas pontas' => ["  REVISTA   DE  HISTÓRIA \n", 'Revista de História'],
            'hífen' => ['ENSINO-APRENDIZAGEM EM FOCO', 'Ensino-Aprendizagem em Foco'],
            'algarismo romano' => ['ESTUDOS DE FILOLOGIA II', 'Estudos de Filologia II'],
            'palavra que só parece romano' => ['MIX CIVIL', 'Mix Civil'],
            'vazio' => ['', ''],
        ];
    }

    public function testChooseFollowsTheSourcePriorityOrder(): void
    {
        $candidates = [
            'pubmed' => 'T pubmed',
            'openalex' => 'T openalex',
            'wos' => 'T wos',
            'scopus' => 'T scopus',
            'latindex' => 'T latindex',
            'doaj' => 'T doaj',
            'scielo' => 'T scielo',
        ];

        foreach (['scielo', 'doaj', 'latindex', 'scopus', 'wos', 'openalex', 'pubmed'] as $source) {
            $this->assertSame(
                ['title' => 'T ' . $source, 'source' => $source],
                DisplayTitle::choose($candidates, ['Grafia do Lattes' => 5], 'TÍTULO DA LINHA')
            );
            unset($candidates[$source]);
        }

        $this->assertSame(
            ['title' => 'Grafia do Lattes', 'source' => 'lattes'],
            DisplayTitle::choose($candidates, ['Grafia do Lattes' => 5], 'TÍTULO DA LINHA')
        );
    }

    public function testPortugueseSourcesBeatScopusThatLosesAccents(): void
    {
        $chosen = DisplayTitle::choose(
            ['scopus' => 'Paideia', 'scielo' => 'Paidéia (Ribeirão Preto)'],
            [],
            'PAIDÉIA (USP. RIBEIRAO PRETO. IMPRESSO)'
        );

        $this->assertSame(['title' => 'Paidéia (Ribeirão Preto)', 'source' => 'scielo'], $chosen);
    }

    public function testChooseAcceptsAListPerSourceAndSkipsBlankTitles(): void
    {
        $chosen = DisplayTitle::choose(
            ['scielo' => ['', '   ', 'Segundo título', 'Terceiro'], 'doaj' => 'Do DOAJ', 'wos' => null],
            [],
            'Título da linha'
        );

        $this->assertSame(['title' => 'Segundo título', 'source' => 'scielo'], $chosen);
    }

    public function testChooseFallsThroughWhenTheHigherSourceIsBlank(): void
    {
        $chosen = DisplayTitle::choose(['scielo' => '  ', 'doaj' => [], 'latindex' => 'Do Latindex'], [], 'Título da linha');

        $this->assertSame(['title' => 'Do Latindex', 'source' => 'latindex'], $chosen);
    }

    public function testChooseIgnoresSourcesOutsideTheOrder(): void
    {
        $chosen = DisplayTitle::choose(['scielo_area' => 'Não vale', 'ai' => 'Nem este', 'legacy' => 'Nem este'], [], 'Título da Linha');

        $this->assertSame(['title' => 'Título da Linha', 'source' => 'title'], $chosen);
    }

    public function testChooseDecodesHtmlEntitiesAndCollapsesSpaces(): void
    {
        $chosen = DisplayTitle::choose(['scielo' => "  Cultura &amp;   Educação\n"], [], 'x');

        $this->assertSame(['title' => 'Cultura & Educação', 'source' => 'scielo'], $chosen);
    }

    public function testLattesSpellingNeedsAtLeastTwoOccurrences(): void
    {
        $once = DisplayTitle::choose([], ['Revista Só Uma Vez' => 1], 'REVISTA DA LINHA');
        $this->assertSame(['title' => 'Revista da Linha', 'source' => 'title'], $once);

        $twice = DisplayTitle::choose([], ['Revista Duas Vezes' => 2], 'REVISTA DA LINHA');
        $this->assertSame(['title' => 'Revista Duas Vezes', 'source' => 'lattes'], $twice);
    }

    public function testLattesNamesCanBeAListWithOneEntryPerArticle(): void
    {
        $chosen = DisplayTitle::choose([], ['Educação e Sociedade', 'Educação & Sociedade', 'Educação e Sociedade'], 'EDUCAÇÃO & SOCIEDADE');

        $this->assertSame(['title' => 'Educação e Sociedade', 'source' => 'lattes'], $chosen);
    }

    public function testLattesPicksTheMostFrequentSpelling(): void
    {
        $chosen = DisplayTitle::choose([], ['Revista A' => 2, 'Revista B' => 5, 'Revista C' => 1], 'x');

        $this->assertSame(['title' => 'Revista B', 'source' => 'lattes'], $chosen);
    }

    public function testLattesTieGoesToTheSpellingWithMoreAccentsThenToByteOrder(): void
    {
        $accents = DisplayTitle::choose([], ['Educacao e Sociedade' => 3, 'Educação e Sociedade' => 3], 'x');
        $this->assertSame('Educação e Sociedade', $accents['title']);

        $bytes = DisplayTitle::choose([], ['Revista Zeta' => 2, 'Revista Alfa' => 2], 'x');
        $this->assertSame('Revista Alfa', $bytes['title']);
    }

    public function testLattesCountsSpellingsThatDifferOnlyInSpacing(): void
    {
        $chosen = DisplayTitle::choose([], ['Revista  Nova' => 1, 'Revista Nova' => 1], 'x');

        $this->assertSame(['title' => 'Revista Nova', 'source' => 'lattes'], $chosen);
    }

    public function testNumericLattesNameKeepsWorkingBecausePhpTurnsNumericKeysIntoIntegers(): void
    {
        $chosen = DisplayTitle::choose([], ['2020' => 2], 'x');

        $this->assertSame(['title' => '2020', 'source' => 'lattes'], $chosen);
    }

    public function testSourceTitleInUppercaseGetsTitleCaseAndMixedCaseIsKept(): void
    {
        // O WoS grava todos os títulos em maiúsculas
        $this->assertSame(
            ['title' => 'Academia-Revista Latinoamericana de Administracion', 'source' => 'wos'],
            DisplayTitle::choose(['wos' => 'ACADEMIA-REVISTA LATINOAMERICANA DE ADMINISTRACION'], [], 'x')
        );
        $this->assertSame(
            ['title' => 'Revista Brasileira de Educação Especial', 'source' => 'scielo'],
            DisplayTitle::choose(['scielo' => 'Revista Brasileira de Educação Especial'], [], 'x')
        );
        $this->assertSame(
            ['title' => 'BMJ Open', 'source' => 'scopus'],
            DisplayTitle::choose(['scopus' => 'BMJ Open'], [], 'x')
        );
    }

    public function testLattesSpellingInUppercaseGetsTitleCaseAndMixedCaseIsKept(): void
    {
        $this->assertSame(
            ['title' => 'Cadernos Adenauer (São Paulo)', 'source' => 'lattes'],
            DisplayTitle::choose([], ['CADERNOS ADENAUER (SÃO PAULO)' => 7], 'x')
        );
        $this->assertSame(
            ['title' => 'Argumento (Jundiaí)', 'source' => 'lattes'],
            DisplayTitle::choose([], ['Argumento (Jundiaí)' => 8], 'x')
        );
    }

    public function testRowTitleInUppercaseGetsTitleCase(): void
    {
        $this->assertSame(
            ['title' => 'Revista Brasileira de Educação Especial', 'source' => 'title'],
            DisplayTitle::choose([], [], 'REVISTA BRASILEIRA DE EDUCAÇÃO ESPECIAL')
        );
        $this->assertSame(
            ['title' => 'Revista em Minúsculas', 'source' => 'title'],
            DisplayTitle::choose([], [], 'revista em minúsculas')
        );
    }

    public function testRowTitleThatAlreadyMixesCaseIsKept(): void
    {
        // A lista de conectivos não cobre "for", e "UFSCar" viraria "Ufscar"
        $this->assertSame(
            ['title' => 'Journal of Education for Teaching', 'source' => 'title'],
            DisplayTitle::choose([], [], 'Journal of Education for Teaching')
        );
        $this->assertSame(
            ['title' => 'RAE-Revista de Administração da UFSCar', 'source' => 'title'],
            DisplayTitle::choose([], [], "RAE-Revista  de Administração da UFSCar\n")
        );
    }

    public function testEmptyRowTitleGivesAnEmptyTitle(): void
    {
        $this->assertSame(['title' => '', 'source' => 'title'], DisplayTitle::choose([], [], '  '));
    }
    public function testTitleWithoutAccentsTakesTheAccentedSpellingOfTheSameJournal(): void
    {
        $chosen = DisplayTitle::choose(['scopus' => 'Ciencia e Cultura'], [], 'CIÊNCIA E CULTURA');
        $this->assertSame(['title' => 'Ciência e Cultura', 'source' => 'scopus'], $chosen);

        $chosen = DisplayTitle::choose(['wos' => 'REVISTA BRASILEIRA DE GESTAO'], ['Revista Brasileira de Gestão' => 3], 'OUTRO TITULO');
        $this->assertSame('Revista Brasileira de Gestão', $chosen['title']);

        $chosen = DisplayTitle::choose(['scopus' => 'Educacao Matematica'], [], 'TITULO DA CANONICA', ['EDUCAÇÃO MATEMÁTICA']);
        $this->assertSame('Educação Matemática', $chosen['title']);
    }

    public function testAccentsComeOnlyFromASpellingOfTheSameTitle(): void
    {
        $chosen = DisplayTitle::choose(['scopus' => 'Revista de Educacao'], [], 'REVISTA DE EDUCAÇÃO FÍSICA');
        $this->assertSame('Revista de Educacao', $chosen['title']);

        $chosen = DisplayTitle::choose(['doaj' => 'Educação em Foco'], [], 'EDUCACAO EM FOCO');
        $this->assertSame('Educação em Foco', $chosen['title']);
    }
}
