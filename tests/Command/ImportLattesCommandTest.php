<?php

namespace App\Tests\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ImportLattesCommandTest extends KernelTestCase
{
    private const ID_LATTES = '9999999999990001';

    private Connection $conn;
    private string $xml;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(Connection::class);
        $this->xml = tempnam(sys_get_temp_dir(), 'lattes');
        file_put_contents($this->xml, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE NUMERO-IDENTIFICADOR="%s"><DADOS-GERAIS NOME-COMPLETO="Docente de Teste do Comando"/></CURRICULO-VITAE>',
            self::ID_LATTES
        ));
    }

    protected function tearDown(): void
    {
        $this->conn->delete('researchers', ['id_lattes' => self::ID_LATTES]);
        unlink($this->xml);
        parent::tearDown();
    }

    public function testDeptOptionSavesTheDepartmentThatTheXmlDoesNotCarry(): void
    {
        $tester = $this->runCommand(['--file' => $this->xml, '--dept' => 'TST|Departamento de Teste']);

        $tester->assertCommandIsSuccessful();
        $row = $this->row();
        $this->assertSame('TST', $row['department_code']);
        $this->assertSame('Departamento de Teste', $row['department']);
    }

    public function testImportingAgainWithoutDeptKeepsTheDepartment(): void
    {
        $this->runCommand(['--file' => $this->xml, '--dept' => 'TST|Departamento de Teste']);
        $this->runCommand(['--file' => $this->xml]);

        $this->assertSame('TST', $this->row()['department_code']);
    }

    public function testInvalidDeptFailsBeforeImportingAnything(): void
    {
        $tester = $this->runCommand(['--file' => $this->xml, '--dept' => 'sem barra']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertFalse($this->row(), 'Nenhum currículo é gravado quando a opção é inválida.');
    }

    public function testFileThatFailsMakesTheCommandFail(): void
    {
        file_put_contents($this->xml, '<?xml version="1.0"?><NAO-E-CURRICULO/>');

        $tester = $this->runCommand(['--file' => $this->xml]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'Quem chama o comando precisa saber que algo falhou.');
    }

    public function testAFileThatFailsInTheDatabaseDoesNotStopTheNextOnes(): void
    {
        $dir = sys_get_temp_dir() . '/lattes' . bin2hex(random_bytes(4));
        mkdir($dir);
        // O ano fora da faixa da coluna faz o banco recusar, o que fecha o EntityManager.
        file_put_contents("$dir/a.xml", '<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE NUMERO-IDENTIFICADOR="9999999999990002"><DADOS-GERAIS NOME-COMPLETO="Ano Errado"/><PRODUCAO-TECNICA><SOFTWARE><DADOS-BASICOS-DO-SOFTWARE TITULO-DO-SOFTWARE="X" ANO="99999999999"/></SOFTWARE></PRODUCAO-TECNICA></CURRICULO-VITAE>');
        copy($this->xml, "$dir/b.xml");

        try {
            $tester = $this->runCommand(['--dir' => $dir]);
        } finally {
            unlink("$dir/a.xml");
            unlink("$dir/b.xml");
            rmdir($dir);
        }

        $this->conn->delete('researchers', ['id_lattes' => '9999999999990002']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertNotFalse($this->row(), 'O arquivo seguinte foi importado.');
    }

    public function testOlderXmlDoesNotReplaceANewerCurriculumUnlessForced(): void
    {
        $this->writeXml('15012026', 'Nome Novo');
        $this->runCommand(['--file' => $this->xml]);

        $this->writeXml('10012020', 'Nome Antigo');
        $tester = $this->runCommand(['--file' => $this->xml]);
        $tester->assertCommandIsSuccessful();
        $this->assertSame('Nome Novo', $this->conn->fetchOne('SELECT full_name FROM researchers WHERE id_lattes = ?', [self::ID_LATTES]));
        $this->assertStringContainsString('pulado', $tester->getDisplay());

        $this->runCommand(['--file' => $this->xml, '--force' => true]);
        $this->assertSame('Nome Antigo', $this->conn->fetchOne('SELECT full_name FROM researchers WHERE id_lattes = ?', [self::ID_LATTES]));
    }

    public function testTechnicalProductionOtherTypesAndFormationDataAreImported(): void
    {
        file_put_contents($this->xml, sprintf(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <CURRICULO-VITAE NUMERO-IDENTIFICADOR="%s" DATA-ATUALIZACAO="15012026">
              <DADOS-GERAIS NOME-COMPLETO="Docente de Teste do Comando">
                <FORMACAO-ACADEMICA-TITULACAO>
                  <DOUTORADO NOME-CURSO="Educação" NOME-INSTITUICAO="UFSCar" ANO-DE-CONCLUSAO="" ANO-DE-OBTENCAO-DO-TITULO="2010" NOME-COMPLETO-DO-ORIENTADOR="Maria Orientadora" NOME-AGENCIA="CAPES" TITULO-DA-DISSERTACAO-TESE="Tese"/>
                </FORMACAO-ACADEMICA-TITULACAO>
              </DADOS-GERAIS>
              <PRODUCAO-BIBLIOGRAFICA>
                <ARTIGOS-ACEITOS-PARA-PUBLICACAO>
                  <ARTIGO-ACEITO-PARA-PUBLICACAO><DADOS-BASICOS-DO-ARTIGO TITULO-DO-ARTIGO="Aceito" ANO-DO-ARTIGO="2026"/><DETALHAMENTO-DO-ARTIGO TITULO-DO-PERIODICO-OU-REVISTA="Revista" ISSN="12345678"/></ARTIGO-ACEITO-PARA-PUBLICACAO>
                </ARTIGOS-ACEITOS-PARA-PUBLICACAO>
              </PRODUCAO-BIBLIOGRAFICA>
              <PRODUCAO-TECNICA>
                <PRODUTO-TECNOLOGICO><DADOS-BASICOS-DO-PRODUTO-TECNOLOGICO TITULO-DO-PRODUTO="Produto" ANO="2019"/></PRODUTO-TECNOLOGICO>
                <PROCESSOS-OU-TECNICAS><DADOS-BASICOS-DO-PROCESSOS-OU-TECNICAS TITULO-DO-PROCESSO="Processo" ANO="2018"/></PROCESSOS-OU-TECNICAS>
                <PATENTE><DADOS-BASICOS-DA-PATENTE TITULO="Patente" ANO-DESENVOLVIMENTO="2017"/></PATENTE>
                <DEMAIS-TIPOS-DE-PRODUCAO-TECNICA>
                  <APRESENTACAO-DE-TRABALHO><DADOS-BASICOS-DA-APRESENTACAO-DE-TRABALHO TITULO="Apresentação" ANO="2016" IDIOMA="Português"/><AUTORES NOME-COMPLETO-DO-AUTOR="Docente" NOME-PARA-CITACAO="DOCENTE" ORDEM-DE-AUTORIA="1"/></APRESENTACAO-DE-TRABALHO>
                  <ORGANIZACAO-DE-EVENTO><DADOS-BASICOS-DA-ORGANIZACAO-DE-EVENTO TITULO="Evento" ANO="2015"/></ORGANIZACAO-DE-EVENTO>
                </DEMAIS-TIPOS-DE-PRODUCAO-TECNICA>
              </PRODUCAO-TECNICA>
              <OUTRA-PRODUCAO>
                <DEMAIS-TRABALHOS><DADOS-BASICOS-DE-DEMAIS-TRABALHOS TITULO="Demais" NATUREZA="Outro" ANO="2014"/></DEMAIS-TRABALHOS>
              </OUTRA-PRODUCAO>
            </CURRICULO-VITAE>
            XML, self::ID_LATTES));

        $this->runCommand(['--file' => $this->xml])->assertCommandIsSuccessful();

        $rows = $this->conn->fetchAllAssociative(
            'SELECT p.title, p.item_type, p.nature, p.year FROM production_items p JOIN researchers r ON r.id = p.researcher_id WHERE r.id_lattes = ? ORDER BY p.year DESC',
            [self::ID_LATTES]
        );
        $this->assertSame([
            ['Aceito', 'OUTRA', 'ARTIGO-ACEITO-PARA-PUBLICACAO', 2026],
            ['Produto', 'TRABALHO_TECNICO', 'PRODUTO-TECNOLOGICO', 2019],
            ['Processo', 'TRABALHO_TECNICO', 'PROCESSOS-OU-TECNICAS', 2018],
            ['Patente', 'PATENTE', 'PATENTE', 2017],
            ['Apresentação', 'OUTRA_TECNICA', 'APRESENTACAO-DE-TRABALHO', 2016],
            ['Evento', 'OUTRA_TECNICA', 'ORGANIZACAO-DE-EVENTO', 2015],
            ['Demais', 'OUTRA', 'Outro', 2014],
        ], array_map(static fn (array $r): array => [$r['title'], $r['item_type'], $r['nature'], (int)$r['year']], $rows));

        $education = $this->conn->fetchAssociative(
            'SELECT e.advisor_name, e.granting_agency, e.end_year FROM educations e JOIN researchers r ON r.id = e.researcher_id WHERE r.id_lattes = ?',
            [self::ID_LATTES]
        );
        $this->assertSame('Maria Orientadora', $education['advisor_name']);
        $this->assertSame('CAPES', $education['granting_agency']);
        $this->assertSame(2010, (int)$education['end_year'], 'Atributo vazio não impede de usar o seguinte.');
    }

    public function testFailureInTheMiddleKeepsThePreviousProductions(): void
    {
        file_put_contents($this->xml, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE NUMERO-IDENTIFICADOR="%s"><DADOS-GERAIS NOME-COMPLETO="Docente de Teste do Comando"/><PRODUCAO-TECNICA><SOFTWARE><DADOS-BASICOS-DO-SOFTWARE TITULO-DO-SOFTWARE="Programa" ANO="2020"/></SOFTWARE></PRODUCAO-TECNICA></CURRICULO-VITAE>',
            self::ID_LATTES
        ));
        $this->runCommand(['--file' => $this->xml]);

        // Um ano fora da faixa da coluna faz o flush final falhar depois do DELETE.
        file_put_contents($this->xml, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE NUMERO-IDENTIFICADOR="%s"><DADOS-GERAIS NOME-COMPLETO="Docente de Teste do Comando"/><PRODUCAO-TECNICA><SOFTWARE><DADOS-BASICOS-DO-SOFTWARE TITULO-DO-SOFTWARE="Outro" ANO="99999999999"/></SOFTWARE></PRODUCAO-TECNICA></CURRICULO-VITAE>',
            self::ID_LATTES
        ));
        $tester = $this->runCommand(['--file' => $this->xml]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame(['Programa'], $this->conn->fetchFirstColumn(
            'SELECT p.title FROM production_items p JOIN researchers r ON r.id = p.researcher_id WHERE r.id_lattes = ?',
            [self::ID_LATTES]
        ), 'A produção anterior continua lá.');
    }

    public function testImportKeepsThesesFromTheRepository(): void
    {
        $this->writeXml('15012026', 'Docente de Teste do Comando');
        $this->runCommand(['--file' => $this->xml]);
        $researcherId = (int)$this->conn->fetchOne('SELECT id FROM researchers WHERE id_lattes = ?', [self::ID_LATTES]);
        $this->conn->insert('orientations', [
            'researcher_id' => $researcherId,
            'orientation_type' => 'MESTRADO',
            'nature' => 'CONCLUIDA',
            'title' => 'Dissertação do repositório',
            'student_name' => 'Aluna',
            'source' => 'repository_ufscar',
        ]);

        $this->runCommand(['--file' => $this->xml]);

        $this->assertSame(1, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM orientations WHERE researcher_id = ? AND source = ?', [$researcherId, 'repository_ufscar']));
    }

    public function testAfterImportingTheNormalizerSeesTheNewItems(): void
    {
        file_put_contents($this->xml, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE NUMERO-IDENTIFICADOR="%s" DATA-ATUALIZACAO="15012026"><DADOS-GERAIS NOME-COMPLETO="Docente de Teste do Comando"/><PRODUCAO-BIBLIOGRAFICA><ARTIGOS-PUBLICADOS><ARTIGO-PUBLICADO><DADOS-BASICOS-DO-ARTIGO TITULO-DO-ARTIGO="Artigo" ANO-DO-ARTIGO="2020"/><DETALHAMENTO-DO-ARTIGO TITULO-DO-PERIODICO-OU-REVISTA="Revista" PAGINA-INICIAL="10"/><AUTORES NOME-COMPLETO-DO-AUTOR="Docente de Teste do Comando" NOME-PARA-CITACAO="TESTE, D." NRO-ID-CNPQ="%s" ORDEM-DE-AUTORIA="1"/></ARTIGO-PUBLICADO></ARTIGOS-PUBLICADOS></PRODUCAO-BIBLIOGRAFICA></CURRICULO-VITAE>',
            self::ID_LATTES,
            self::ID_LATTES
        ));

        $this->runCommand(['--file' => $this->xml])->assertCommandIsSuccessful();

        $author = $this->conn->fetchAssociative(
            'SELECT a.is_cech_researcher, p.pages FROM production_authors a JOIN production_items p ON p.id = a.production_item_id JOIN researchers r ON r.id = p.researcher_id WHERE r.id_lattes = ?',
            [self::ID_LATTES]
        );
        $this->assertSame(1, (int)$author['is_cech_researcher'], 'O normalizador alcançou o autor recém-gravado.');
        $this->assertSame('10', $author['pages'], 'Só o lado que existe, sem traço solto.');
    }

    public function testRawTextStaysAsItCameAndTheGetterDecodesForDisplay(): void
    {
        file_put_contents($this->xml, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE NUMERO-IDENTIFICADOR="%s"><DADOS-GERAIS NOME-COMPLETO="Docente de Teste do Comando"/><PRODUCAO-BIBLIOGRAFICA><ARTIGOS-PUBLICADOS><ARTIGO-PUBLICADO><DADOS-BASICOS-DO-ARTIGO TITULO-DO-ARTIGO="Artigo" ANO-DO-ARTIGO="2020"/><DETALHAMENTO-DO-ARTIGO TITULO-DO-PERIODICO-OU-REVISTA="Educa&amp;ccedil;&amp;atilde;o  &amp;amp; Sociedade"/></ARTIGO-PUBLICADO></ARTIGOS-PUBLICADOS></PRODUCAO-BIBLIOGRAFICA></CURRICULO-VITAE>',
            self::ID_LATTES
        ));

        $this->runCommand(['--file' => $this->xml])->assertCommandIsSuccessful();

        $raw = $this->conn->fetchOne('SELECT p.journal_name FROM production_items p JOIN researchers r ON r.id = p.researcher_id WHERE r.id_lattes = ?', [self::ID_LATTES]);
        $this->assertSame('Educa&ccedil;&atilde;o  &amp; Sociedade', $raw);
        $this->assertSame('Educação  & Sociedade', \App\Support\LattesText::display($raw));
    }

    public function testTwoPeopleWithTheSameNameGetDifferentPages(): void
    {
        $other = '9999999999990003';
        $this->writeXml('15012026', 'Homonimo de Teste');
        $this->runCommand(['--file' => $this->xml]);

        $file = tempnam(sys_get_temp_dir(), 'lattes');
        file_put_contents($file, sprintf('<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE NUMERO-IDENTIFICADOR="%s"><DADOS-GERAIS NOME-COMPLETO="Homonimo de Teste"/></CURRICULO-VITAE>', $other));
        try {
            $this->runCommand(['--file' => $file])->assertCommandIsSuccessful();
            $slugs = $this->conn->fetchFirstColumn('SELECT slug FROM researchers WHERE id_lattes IN (?, ?)', [self::ID_LATTES, $other]);
            $this->assertCount(2, array_unique($slugs));

            // Reimportar não troca o endereço de quem já tem.
            $this->writeXml('16012026', 'Homonimo de Teste');
            $this->runCommand(['--file' => $this->xml]);
            $this->assertSame('homonimo-de-teste', $this->conn->fetchOne('SELECT slug FROM researchers WHERE id_lattes = ?', [self::ID_LATTES]));
        } finally {
            unlink($file);
            $this->conn->delete('researchers', ['id_lattes' => $other]);
        }
    }

    private function writeXml(string $updatedAt, string $name): void
    {
        file_put_contents($this->xml, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE NUMERO-IDENTIFICADOR="%s" DATA-ATUALIZACAO="%s"><DADOS-GERAIS NOME-COMPLETO="%s"/></CURRICULO-VITAE>',
            self::ID_LATTES,
            $updatedAt,
            $name
        ));
    }

    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:import:lattes'));
        $tester->execute($input);

        return $tester;
    }

    private function row(): array|false
    {
        return $this->conn->fetchAssociative(
            'SELECT department_code, department FROM researchers WHERE id_lattes = ?',
            [self::ID_LATTES]
        );
    }
}
