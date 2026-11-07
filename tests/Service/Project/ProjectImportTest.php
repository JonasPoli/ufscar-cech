<?php

declare(strict_types=1);

namespace App\Tests\Service\Project;

use App\Service\Import\LattesHtmlParserService;
use App\Service\Import\LattesXmlParserService;
use App\Service\Project\ProjectGroupKey;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integrantes e financiadores dos projetos na importação (XML e HTML) e no preenchimento dos projetos já importados
 * (`app:projects:backfill-members`). Os docentes usam IDs Lattes e nomes únicos e saem no tearDown, com tudo o que é
 * deles (ON DELETE CASCADE).
 */
class ProjectImportTest extends KernelTestCase
{
    private Connection $conn;
    private string $uniq;

    /** @var string[] */
    private array $createdIds = [];

    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(Connection::class);
        $this->uniq = 'zq' . strtr(bin2hex(random_bytes(4)), '0123456789', 'ghijklmnop');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $idLattes) {
            $this->conn->executeStatement('DELETE FROM researchers WHERE id_lattes = ?', [$idLattes]);
        }
        $this->conn->executeStatement('DELETE FROM author_identities WHERE normalized_name LIKE ?', ['%' . strtoupper($this->uniq) . '%']);
        foreach (array_reverse($this->tempFiles) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }

        parent::tearDown();
    }

    public function testXmlImportStoresMembersAndAllFundersAsTheyCame(): void
    {
        $owner = $this->newId();
        $colleague = $this->newId();
        $this->insertResearcher($colleague, "Colega Já Cadastrada {$this->uniq}");

        $this->parser()->parseAndSave($this->xmlFile($owner, "Docente Dona {$this->uniq}", $this->projectXml($owner, $colleague)));

        $project = $this->conn->fetchAssociative(
            'SELECT p.* FROM research_projects p JOIN researchers r ON r.id = p.researcher_id WHERE r.id_lattes = ? AND p.name LIKE ?',
            [$owner, 'Educa%']
        );
        $this->assertNotFalse($project);
        $this->assertSame(1, (int)$project['is_coordinator'], 'is_coordinator continua calculado como antes');
        $this->assertSame('Fundação de Amparo &amp; Pesquisa', $project['agency_financier'], 'agency_financier continua com o primeiro financiador, como veio');
        $this->assertSame(ProjectGroupKey::compute($project['name'], 2019), $project['project_group_key']);

        $members = $this->conn->fetchAllAssociative('SELECT * FROM research_project_members WHERE research_project_id = ? ORDER BY member_order', [$project['id']]);
        $this->assertCount(3, $members, 'O integrante sem nome é ignorado');
        $this->assertSame("Docente Dona {$this->uniq}", $members[0]['full_name']);
        $this->assertSame('DONA, D.;DONA, DOCENTE', $members[0]['citation_name']);
        $this->assertSame(1, (int)$members[0]['is_responsible']);
        $this->assertSame($owner, $members[0]['id_lattes']);
        $this->assertSame($this->researcherId($owner), (int)$members[0]['matched_researcher_id'], 'O dono do currículo é ligado a si mesmo');

        $this->assertSame($colleague, $members[1]['id_lattes']);
        $this->assertSame($this->researcherId($colleague), (int)$members[1]['matched_researcher_id'], 'Integrante com o ID de um docente do banco é ligado a ele');
        $this->assertSame(0, (int)$members[1]['is_responsible']);

        $this->assertSame('Aluna &quot;Externa&quot;', $members[2]['full_name'], 'Nome bruto, com a entidade HTML como veio');
        $this->assertNull($members[2]['id_lattes'], 'NRO-ID-CNPQ vazio vira NULL');
        $this->assertNull($members[2]['matched_researcher_id']);

        $funders = $this->conn->fetchAllAssociative('SELECT * FROM research_project_funders WHERE research_project_id = ? ORDER BY funder_order', [$project['id']]);
        $this->assertSame(
            [['Fundação de Amparo &amp; Pesquisa', '0001', 'AUXILIO_FINANCEIRO', 1], ['CNPq', null, 'BOLSA', 2]],
            array_map(static fn (array $f): array => [$f['name'], $f['institution_code'], $f['nature'], (int)$f['funder_order']], $funders),
            'Todos os financiadores; o nó vazio (NAO_INFORMADO) fica de fora'
        );

        // Projeto sem equipe nem financiador: nada a gravar, sem aviso
        $other = $this->conn->fetchOne('SELECT p.id FROM research_projects p JOIN researchers r ON r.id = p.researcher_id WHERE r.id_lattes = ? AND p.name = ?', [$owner, 'Projeto sem equipe']);
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM research_project_members WHERE research_project_id = ?', [$other]));
    }

    public function testImportingAResearcherLinksTheMembersOfOtherCurriculaThatCarryHisId(): void
    {
        $owner = $this->newId();
        $newcomer = $this->newId();
        $this->parser()->parseAndSave($this->xmlFile($owner, "Docente Dona {$this->uniq}", $this->projectXml($owner, $newcomer)));

        $member = fn (): mixed => $this->conn->fetchOne('SELECT matched_researcher_id FROM research_project_members WHERE id_lattes = ?', [$newcomer]);
        $this->assertNull($member(), 'Antes de importar, o integrante não é docente do banco');

        $this->parser()->parseAndSave($this->xmlFile($newcomer, "Docente Nova {$this->uniq}", ''));

        $this->assertSame($this->researcherId($newcomer), (int)$member(), 'A importação do docente liga as equipes de outros currículos a ele');
    }

    public function testReimportReplacesTheTeamInsteadOfDuplicatingIt(): void
    {
        $owner = $this->newId();
        $file = $this->xmlFile($owner, "Docente Dona {$this->uniq}", $this->projectXml($owner, $this->newId()));

        $this->parser()->parseAndSave($file);
        $this->parser()->parseAndSave($file);

        $this->assertSame(3, (int)$this->conn->fetchOne(
            'SELECT COUNT(*) FROM research_project_members m JOIN research_projects p ON p.id = m.research_project_id JOIN researchers r ON r.id = p.researcher_id WHERE r.id_lattes = ?',
            [$owner]
        ));
    }

    public function testHtmlImportReadsTeamAndFundersFromTheProjectText(): void
    {
        $idLattes = $this->newId();
        $html = '<div class="infpessoa"><h2 class="nome">Docente Html ' . $this->uniq . '</h2></div>'
            . '<ul class="informacoes-autor"><li>Endereço para acessar este CV: http://lattes.cnpq.br/' . $idLattes . '</li></ul>'
            . '<div class="title-wrapper"><a name="ProjetosPesquisa"></a><h1>Projetos de pesquisa</h1></div>
            <div class="layout-cell layout-cell-12 data-cell">
                <div class="layout-cell layout-cell-3"><b>2021 - Atual</b></div>
                <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">Projeto do HTML ' . $this->uniq . '</div></div>
                <div class="layout-cell layout-cell-3"></div>
                <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">Descrição: Estudo.<br>Situação: Em andamento; Natureza: Pesquisa.<br>Integrantes: Docente Html ' . $this->uniq . ' - Coordenador / Maria da Silva - Integrante / João Souza - Integrante.<br>Financiador(es): Conselho Nacional de Desenvolvimento Científico e Tecnológico - Bolsa / Universidade Federal de São Carlos - Auxílio financeiro.</div></div>
            </div>';

        static::getContainer()->get(LattesHtmlParserService::class)->parseHtmlAndSave($html);

        $projectId = (int)$this->conn->fetchOne('SELECT p.id FROM research_projects p JOIN researchers r ON r.id = p.researcher_id WHERE r.id_lattes = ?', [$idLattes]);
        $members = $this->conn->fetchAllAssociative('SELECT full_name, member_order, is_responsible, id_lattes FROM research_project_members WHERE research_project_id = ? ORDER BY member_order', [$projectId]);
        $this->assertSame(
            [["Docente Html {$this->uniq}", 1, 1], ['Maria da Silva', 2, 0], ['João Souza', 3, 0]],
            array_map(static fn (array $m): array => [$m['full_name'], (int)$m['member_order'], (int)$m['is_responsible']], $members)
        );
        $this->assertNull($members[0]['id_lattes'], 'O HTML não traz o ID Lattes dos integrantes');

        $funders = $this->conn->fetchAllAssociative('SELECT name, nature FROM research_project_funders WHERE research_project_id = ? ORDER BY funder_order', [$projectId]);
        $this->assertSame(
            [['Conselho Nacional de Desenvolvimento Científico e Tecnológico', 'Bolsa'], ['Universidade Federal de São Carlos', 'Auxílio financeiro']],
            array_map(static fn (array $f): array => [$f['name'], $f['nature']], $funders)
        );
    }

    public function testBackfillCompletesExistingProjectsWithoutTouchingThem(): void
    {
        $owner = $this->newId();
        $colleague = $this->newId();
        $ownerId = $this->insertResearcher($owner, "Docente Antiga {$this->uniq}", '2026-01-15');
        $colleagueId = $this->insertResearcher($colleague, "Colega {$this->uniq}");

        // Registros como um parser antigo os deixou: sem equipe, um deles com a entidade HTML do nome já decodificada
        $first = $this->insertProject($ownerId, 'Educação & Sociedade', 2019);
        $second = $this->insertProject($ownerId, 'Projeto sem equipe', 2020);
        $orphan = $this->insertProject($ownerId, 'Projeto que não está no XML', 2018);
        $before = $this->conn->fetchAllAssociative('SELECT id, name, status, description, agency_financier, is_coordinator FROM research_projects WHERE researcher_id = ? ORDER BY id', [$ownerId]);

        $dir = $this->tempDir();
        $this->xmlFile($owner, "Docente Antiga {$this->uniq}", $this->projectXml($owner, $colleague), $dir . "/lattes{$owner}.xml");

        $tester = $this->runCommand(['--dir' => $dir]);
        $tester->assertCommandIsSuccessful();

        $this->assertSame($before, $this->conn->fetchAllAssociative('SELECT id, name, status, description, agency_financier, is_coordinator FROM research_projects WHERE researcher_id = ? ORDER BY id', [$ownerId]), 'Os projetos não mudam');
        $this->assertSame(3, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM research_project_members WHERE research_project_id = ?', [$first]), 'Casado pelo nome normalizado + ano');
        $this->assertSame($colleagueId, (int)$this->conn->fetchOne('SELECT matched_researcher_id FROM research_project_members WHERE research_project_id = ? AND id_lattes = ?', [$first, $colleague]));
        $this->assertSame(2, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM research_project_funders WHERE research_project_id = ?', [$first]));
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM research_project_members WHERE research_project_id IN (?, ?)', [$second, $orphan]));
        $this->assertNotNull($this->conn->fetchOne('SELECT project_group_key FROM research_projects WHERE id = ?', [$first]), 'A indexação roda no fim');

        // Rodar de novo dá o mesmo resultado
        $this->runCommand(['--dir' => $dir])->assertCommandIsSuccessful();
        $this->assertSame(3, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM research_project_members WHERE research_project_id = ?', [$first]));
    }

    public function testBackfillSkipsAnXmlFromAnotherDateAndTheDryRunWritesNothing(): void
    {
        $owner = $this->newId();
        $ownerId = $this->insertResearcher($owner, "Docente Datada {$this->uniq}", '2026-03-01');
        $project = $this->insertProject($ownerId, 'Educação &amp; Sociedade', 2019);
        $file = $this->xmlFile($owner, "Docente Datada {$this->uniq}", $this->projectXml($owner, $this->newId()));

        $tester = $this->runCommand(['--file' => $file]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('data de atualização diferente', $tester->getDisplay());
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM research_project_members WHERE research_project_id = ?', [$project]));

        $this->runCommand(['--file' => $file, '--ignore-date' => true, '--dry-run' => true])->assertCommandIsSuccessful();
        $this->assertSame(0, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM research_project_members WHERE research_project_id = ?', [$project]));

        $this->runCommand(['--file' => $file, '--ignore-date' => true])->assertCommandIsSuccessful();
        $this->assertSame(3, (int)$this->conn->fetchOne('SELECT COUNT(*) FROM research_project_members WHERE research_project_id = ?', [$project]));
    }

    public function testBackfillWithoutSourceFails(): void
    {
        $this->assertSame(Command::FAILURE, $this->runCommand([])->getStatusCode());
    }

    // ------------------------------------------------------------------------------------------------

    private function parser(): LattesXmlParserService
    {
        return static::getContainer()->get(LattesXmlParserService::class);
    }

    private function runCommand(array $options): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:projects:backfill-members'));
        $tester->execute($options);

        return $tester;
    }

    /** Dois projetos: um com equipe (dono responsável, colega, externa e um nó sem nome) e três financiadores; outro sem nada. */
    private function projectXml(string $owner, string $colleague): string
    {
        return <<<XML
            <ATUACOES-PROFISSIONAIS><ATUACAO-PROFISSIONAL NOME-INSTITUICAO="UFSCar"><ATIVIDADES-DE-PARTICIPACAO-EM-PROJETO><PARTICIPACAO-EM-PROJETO>
              <PROJETO-DE-PESQUISA ANO-INICIO="2019" ANO-FIM="" NOME-DO-PROJETO="Educação &amp;amp; Sociedade" SITUACAO="EM_ANDAMENTO" NATUREZA="PESQUISA" DESCRICAO-DO-PROJETO="Estudo.">
                <EQUIPE-DO-PROJETO>
                  <INTEGRANTES-DO-PROJETO NOME-COMPLETO="Docente Dona {$this->uniq}" NOME-PARA-CITACAO="DONA, D.;DONA, DOCENTE" ORDEM-DE-INTEGRACAO="1" FLAG-RESPONSAVEL="SIM" NRO-ID-CNPQ="{$owner}"/>
                  <INTEGRANTES-DO-PROJETO NOME-COMPLETO="Colega {$this->uniq}" NOME-PARA-CITACAO="COLEGA, C." ORDEM-DE-INTEGRACAO="2" FLAG-RESPONSAVEL="NAO" NRO-ID-CNPQ="{$colleague}"/>
                  <INTEGRANTES-DO-PROJETO NOME-COMPLETO="Aluna &amp;quot;Externa&amp;quot;" NOME-PARA-CITACAO="" ORDEM-DE-INTEGRACAO="3" FLAG-RESPONSAVEL="NAO" NRO-ID-CNPQ=""/>
                  <INTEGRANTES-DO-PROJETO NOME-COMPLETO="" NOME-PARA-CITACAO="" ORDEM-DE-INTEGRACAO="4" FLAG-RESPONSAVEL="NAO" NRO-ID-CNPQ=""/>
                </EQUIPE-DO-PROJETO>
                <FINANCIADORES-DO-PROJETO>
                  <FINANCIADOR-DO-PROJETO SEQUENCIA-FINANCIADOR="1" CODIGO-INSTITUICAO="0001" NOME-INSTITUICAO="Fundação de Amparo &amp;amp; Pesquisa" NATUREZA="AUXILIO_FINANCEIRO"/>
                  <FINANCIADOR-DO-PROJETO SEQUENCIA-FINANCIADOR="2" CODIGO-INSTITUICAO="" NOME-INSTITUICAO="CNPq" NATUREZA="BOLSA"/>
                  <FINANCIADOR-DO-PROJETO SEQUENCIA-FINANCIADOR="3" CODIGO-INSTITUICAO="" NOME-INSTITUICAO="" NATUREZA="NAO_INFORMADO"/>
                </FINANCIADORES-DO-PROJETO>
              </PROJETO-DE-PESQUISA>
              <PROJETO-DE-PESQUISA ANO-INICIO="2020" ANO-FIM="2021" NOME-DO-PROJETO="Projeto sem equipe" SITUACAO="CONCLUIDO" NATUREZA="EXTENSAO" DESCRICAO-DO-PROJETO=""/>
            </PARTICIPACAO-EM-PROJETO></ATIVIDADES-DE-PARTICIPACAO-EM-PROJETO></ATUACAO-PROFISSIONAL></ATUACOES-PROFISSIONAIS>
            XML;
    }

    private function xmlFile(string $idLattes, string $name, string $generalBody, ?string $path = null): string
    {
        $path ??= tempnam(sys_get_temp_dir(), 'lattes');
        $this->tempFiles[] = $path;
        file_put_contents($path, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><CURRICULO-VITAE NUMERO-IDENTIFICADOR="%s" DATA-ATUALIZACAO="15012026"><DADOS-GERAIS NOME-COMPLETO="%s">%s</DADOS-GERAIS></CURRICULO-VITAE>',
            $idLattes,
            $name,
            $generalBody
        ));

        return $path;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/lattes-projetos-' . $this->uniq;
        mkdir($dir);
        $this->tempFiles[] = $dir;

        return $dir;
    }

    private function newId(): string
    {
        $id = '9933' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        $this->createdIds[] = $id;

        return $id;
    }

    private function insertResearcher(string $idLattes, string $name, ?string $lastUpdate = null): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->insert('researchers', [
            'id_lattes' => $idLattes,
            'full_name' => $name,
            'slug' => 'docente-' . $idLattes,
            'status' => 1,
            'last_lattes_update' => $lastUpdate,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    private function insertProject(int $researcherId, string $name, int $startYear): int
    {
        $this->conn->insert('research_projects', [
            'researcher_id' => $researcherId,
            'name' => $name,
            'nature' => 'PESQUISA',
            'status' => 'EM_ANDAMENTO',
            'start_year' => $startYear,
            'is_coordinator' => 0,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    private function researcherId(string $idLattes): int
    {
        return (int)$this->conn->fetchOne('SELECT id FROM researchers WHERE id_lattes = ?', [$idLattes]);
    }
}
