<?php

namespace App\Tests\Service;

use App\Entity\Education;
use App\Entity\Orientation;
use App\Entity\ProductionItem;
use App\Entity\Researcher;
use App\Service\Import\LattesHtmlParserService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LattesHtmlParserServiceTest extends KernelTestCase
{
    private LattesHtmlParserService $parser;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->parser = static::getContainer()->get(LattesHtmlParserService::class);
    }

    public function testParseComprehensiveLattesHtml(): void
    {
        $sampleHtml = '
<!DOCTYPE html>
<html>
<body>
    <div class="infpessoa"><h2 class="nome">Luís Fernando Soares Zuin</h2></div>
    <ul class="informacoes-autor">
        <li>Endereço para acessar este CV: http://lattes.cnpq.br/3389666977978800</li>
        <li>Última atualização do currículo em 19/05/2026</li>
    </ul>
    <p class="resumo">Docente do Departamento de Engenharia de Biossistemas da USP. (Texto informado pelo autor)</p>
    <a href="https://orcid.org/0000-0001-8571-7665">ORCID</a>

    <div class="title-wrapper"><a name="Identificacao"></a><h1>Identificação</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="layout-cell layout-cell-3"><b>Nome em citações bibliográficas</b></div>
        <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">ZUIN, L.F.S.; ZUIN, LUIS FERNANDO S.</div></div>
        <div class="layout-cell layout-cell-3"><b>País de Nacionalidade</b></div>
        <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">Brasil</div></div>
    </div>

    <div class="title-wrapper"><a name="Endereco"></a><h1>Endereço</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="layout-cell layout-cell-3"><b>Endereço Profissional</b></div>
        <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">Universidade de São Paulo, FZEA. Av. Duque de Caxias Norte, 255 - Centro, 13635-900 - Pirassununga, SP - Brasil. Telefone: (19) 3565-4369</div></div>
    </div>

    <div class="title-wrapper"><a name="FormacaoAcademicaTitulacao"></a><h1>Formação acadêmica/titulação</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="layout-cell layout-cell-3"><b>2002 - 2007</b></div>
        <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">Doutorado em Engenharia de Produção.<br>Universidade Federal de São Carlos, UFSCAR, Brasil.<br>Título: Processo de desenvolvimento de produtos.<br>Orientador: Dario Henrique Alliprandini.</div></div>
        <div class="layout-cell layout-cell-3"><b>1998 - 2000</b></div>
        <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">Mestrado em Medicina Veterinária.<br>Universidade Federal de Minas Gerais, UFMG, Brasil.</div></div>
        <div class="layout-cell layout-cell-3"><b>1991 - 1997</b></div>
        <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">Graduação em Zootecnia.<br>Universidade Estadual Paulista Júlio de Mesquita Filho, UNESP, Brasil.</div></div>
    </div>

    <div class="title-wrapper"><a name="FormacaoAcademicaPosDoutorado"></a><h1>Pós-doutorado</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="layout-cell layout-cell-3"><b>2015 - 2016</b></div>
        <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">Pós-Doutorado.<br>Escola de Engenharia de São Carlos - USP, EESC, Brasil.</div></div>
    </div>

    <div class="title-wrapper"><a name="FormacaoComplementar"></a><h1>Formação Complementar</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="layout-cell layout-cell-3"><b>2012 - 2012</b></div>
        <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">VII Workshop Engenharia de Biossistemas. (Carga horária: 12h).<br>Faculdade de Zootecnia e Engenharia de Alimentos, FZEA, Brasil.</div></div>
    </div>

    <div class="title-wrapper"><a name="AtuacaoProfissional"></a><h1>Atuação Profissional</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="inst_back"><b>Universidade de São Paulo, USP, Brasil.</b></div>
        <div class="layout-cell layout-cell-3"><b>2009 - Atual</b></div>
        <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">Vínculo: Servidor público, Enquadramento Funcional: Professor Doutor, Carga horária: 40, Regime: Dedicação exclusiva.</div></div>
    </div>

    <div class="title-wrapper"><a name="ProjetosPesquisa"></a><h1>Projetos de pesquisa</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="layout-cell layout-cell-3"><b>2024 - Atual</b></div>
        <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">Comunicação para transferência e compartilhamento de novas tecnologias no campo.<br>Descrição: Propor caminhos comunicacionais digitais.<br>Situação: Em andamento; Natureza: Pesquisa.<br>Integrantes: Luís Fernando Soares Zuin - Coordenador.</div></div>
    </div>

    <div class="title-wrapper"><a name="AreasAtuacao"></a><h1>Áreas de atuação</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="layout-cell layout-cell-3"><b>1. </b></div>
        <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">Grande área: Ciências Agrárias / Área: Zootecnia / Subárea: Extensão Rural.</div></div>
    </div>

    <div class="title-wrapper"><a name="Idiomas"></a><h1>Idiomas</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="layout-cell layout-cell-3"><b>Inglês</b></div>
        <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">Compreende Bem, Fala Razoavelmente, Lê Bem, Escreve Razoavelmente.</div></div>
    </div>

    <div class="title-wrapper"><a name="PremiosTitulos"></a><h1>Prêmios e títulos</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="layout-cell layout-cell-3"><b>2021</b></div>
        <div class="layout-cell layout-cell-9"><div class="layout-cell-pad-5">Segundo lugar no Prêmio Pecuária Sustentável, Confederação da Agricultura e Pecuária do Brasil (CNA).</div></div>
    </div>

    <div class="title-wrapper"><a name="ProducoesCientificas"></a><h1>Produções</h1></div>
    <div id="artigos-completos">
        <div class="cita-artigos"><b><a name="ArtigosCompletos"></a>Artigos completos publicados em periódicos</b></div>
        <div class="artigo-completo">
            <div class="layout-cell layout-cell-1"><b>1. </b></div>
            <div class="layout-cell layout-cell-11"><span class="transform"><a class="icone-producao icone-doi" href="http://dx.doi.org/10.35977/0104-1096.cct2026.v43.27646"></a>MARASSIRO, M. J. ; <b>ZUIN, L. F. S.</b> ; LOPES, R. C. . Tecnologias de informação e comunicação na extensão rural em Moçambique. CADERNOS DE CIÊNCIA &amp; TECNOLOGIA, v. 43, p. 1-12, 2026.</span></div>
        </div>
    </div>

    <div class="cita-artigos"><b><a name="LivrosCapitulos"></a>Livros publicados/organizados ou edições</b></div>
    <div class="layout-cell layout-cell-1"><b>1. </b></div>
    <div class="layout-cell layout-cell-11"><span class="transform"><b>ZUIN, L. F. S.</b>; REDIN, E. . Assistência Técnica e Extensão Rural Digital. 1. ed. São Carlos: Pedro &amp; João Editores, 2026. v. 1. 146p .</span></div>

    <div class="cita-artigos"><b><a name="CapitulosLivros"></a>Capítulos de livros publicados</b></div>
    <div class="layout-cell layout-cell-1"><b>1. </b></div>
    <div class="layout-cell layout-cell-11"><span class="transform">SOUZA, F. L. F. ; <b>Zuin, Luís Fernando Soares</b> . Desafios e oportunidades dos serviços de ATER nas redes sociais. In: Estudos e Pesquisas no Horizonte Rural. 1. ed. São Carlos: Pedro &amp; João Editores, 2025. v. 3. p. 67-80.</span></div>

    <div class="cita-artigos"><b><a name="TextosJornaisRevistas"></a>Textos em jornais de notícias/revistas</b></div>
    <div class="layout-cell layout-cell-1"><b>1. </b></div>
    <div class="layout-cell layout-cell-11"><span class="transform"><b>ZUIN, L. F. S.</b> . O futuro da educação no campo. Folha de S. Paulo, São Paulo, p. A3 - A3, 10 mar. 2023.</span></div>

    <div class="cita-artigos"><b><a name="TrabalhosPublicadosAnaisCongresso"></a>Trabalhos completos publicados em anais de congressos</b></div>
    <div class="layout-cell layout-cell-1"><b>1. </b></div>
    <div class="layout-cell layout-cell-11"><span class="transform"><b>ZUIN, L. F. S.</b>; ALMEIDA, R. . Inovações Pedagógicas no Ensino Superior. In: Congresso Brasileiro de Educação, 2023, Brasília. Anais do CBE. Brasília: CBE, 2023. v. 1. p. 1-12.</span></div>

    <div class="cita-artigos"><b><a name="ResumosExpandidosAnaisCongresso"></a>Resumos expandidos publicados em anais de congressos</b></div>
    <div class="layout-cell layout-cell-1"><b>1. </b></div>
    <div class="layout-cell layout-cell-11"><span class="transform"><b>ZUIN, L. F. S.</b> . Metodologias Ativas no CECH. In: Encontro Nacional de Ensino, 2022, São Carlos. Anais... São Carlos: UFSCar, 2022. v. 1. p. 10-15.</span></div>

    <div class="cita-artigos"><b><a name="ResumosAnaisCongresso"></a>Resumos publicados em anais de congressos</b></div>
    <div class="layout-cell layout-cell-1"><b>1. </b></div>
    <div class="layout-cell layout-cell-11"><span class="transform"><b>ZUIN, L. F. S.</b> . Avaliação Formativa. In: Simpósio de Educação, 2021. Anais... 2021. p. 5.</span></div>

    <div class="cita-artigos"><b><a name="ApresentacaoTrabalho"></a>Apresentações de Trabalho</b></div>
    <div class="layout-cell layout-cell-1"><b>1. </b></div>
    <div class="layout-cell layout-cell-11"><span class="transform"><b>ZUIN, L. F. S.</b> . Conferência Magna sobre Sociedade e Tecnologia. 2024. (Conferência ou palestra).</span></div>

    <div class="cita-artigos"><b><a name="AssessoriaConsultoria"></a>Assessoria e consultoria</b></div>
    <div class="layout-cell layout-cell-1"><b>1. </b></div>
    <div class="layout-cell layout-cell-11"><span class="transform"><b>ZUIN, L. F. S.</b> . Consultoria Ad-hoc para o CNPq e FAPESP. 2024.</span></div>

    <div class="cita-artigos"><b><a name="TrabalhosTecnicos"></a>Trabalhos técnicos</b></div>
    <div class="layout-cell layout-cell-1"><b>1. </b></div>
    <div class="layout-cell layout-cell-11"><span class="transform"><b>ZUIN, L. F. S.</b> . Parecer Técnico sobre Projeto Pedagógico. 2023.</span></div>

    <div class="cita-artigos"><b><a name="DemaisTiposProducaoTecnica"></a>Demais tipos de produção técnica</b></div>
    <div class="layout-cell layout-cell-1"><b>1. </b></div>
    <div class="layout-cell layout-cell-11"><span class="transform"><b>ZUIN, L. F. S.</b> . Podcast Educação e Sociedade. 2023. (Programa de rádio ou TV).</span></div>

    <div class="title-wrapper"><a name="OrientacoesConcluidas"></a><h1>Orientações e supervisões concluídas</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="layout-cell layout-cell-1"><b>1. </b></div>
        <div class="layout-cell layout-cell-11"><div class="layout-cell-pad-5">Carlos Eduardo Santos. <b>A Tecnologia no Ensino de Ciências</b>. 2023. Dissertação (Mestrado em Educação) - Universidade Federal de São Carlos, Coordenação de Aperfeiçoamento de Pessoal de Nível Superior. Orientador: Luís Fernando Soares Zuin.</div></div>
        <div class="layout-cell layout-cell-1"><b>2. </b></div>
        <div class="layout-cell layout-cell-11"><div class="layout-cell-pad-5">Mariana Oliveira. <b>Formação Docente e Novas Mídias</b>. 2022. Tese (Doutorado em Educação) - Universidade Federal de São Carlos. Orientador: Luís Fernando Soares Zuin.</div></div>
    </div>

    <div class="title-wrapper"><a name="OrientacoesAndamento"></a><h1>Orientações e supervisões em andamento</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="layout-cell layout-cell-1"><b>1. </b></div>
        <div class="layout-cell layout-cell-11"><div class="layout-cell-pad-5">Lucas Pereira. <b>Inclusão Digital nas Escolas</b>. 2024. Dissertação (Mestrado em Educação) - Universidade Federal de São Carlos.</div></div>
    </div>

    <div class="title-wrapper"><a name="BancasTrabalhoConclusao"></a><h1>Participação em bancas de trabalhos de conclusão</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="layout-cell layout-cell-1"><b>1. </b></div>
        <div class="layout-cell layout-cell-11"><div class="layout-cell-pad-5">ZUIN, L. F. S.. Participação em banca de João Silva. Avaliação de Impacto. 2023. Dissertação (Mestrado em Educação) - UFSCar.</div></div>
    </div>

    <div class="title-wrapper"><a name="ParticipacaoEventos"></a><h1>Participação em eventos, congressos, exposições e feiras</h1></div>
    <div class="layout-cell layout-cell-12 data-cell">
        <div class="layout-cell layout-cell-1"><b>1. </b></div>
        <div class="layout-cell layout-cell-11"><div class="layout-cell-pad-5">Congresso Internacional de Educação e Tecnologia. 2024. (Congresso).</div></div>
    </div>
</body>
</html>
';

        $researcher = $this->parser->parseHtmlAndSave($sampleHtml);

        $this->assertInstanceOf(Researcher::class, $researcher);
        $this->assertEquals('3389666977978800', $researcher->getIdLattes());
        $this->assertEquals('Luís Fernando Soares Zuin', $researcher->getFullName());
        $this->assertEquals('0000-0001-8571-7665', $researcher->getOrcid());
        $this->assertEquals('Brasil', $researcher->getNationality());
        $this->assertStringContainsString('Docente do Departamento', $researcher->getAbstractResume());

        // Educations check (Doutorado, Mestrado, Graduacao, Pos-Doc, Complementar)
        $educations = $researcher->getEducations();
        $this->assertGreaterThanOrEqual(5, count($educations));

        // Productions check (Article, Book, Chapter, Newspaper, Congress, Expanded summary, Summary, Presentation, Advisory, Technical work, Podcast)
        $productions = $researcher->getProductions();
        $this->assertGreaterThanOrEqual(10, count($productions));

        $types = [];
        foreach ($productions as $prod) {
            $types[$prod->getItemType()] = ($types[$prod->getItemType()] ?? 0) + 1;
        }

        $this->assertArrayHasKey(ProductionItem::TYPE_ARTIGO, $types);
        $this->assertArrayHasKey(ProductionItem::TYPE_LIVRO, $types);
        $this->assertArrayHasKey(ProductionItem::TYPE_CAPITULO, $types);
        $this->assertArrayHasKey(ProductionItem::TYPE_TEXTO_JORNAL, $types);
        $this->assertArrayHasKey(ProductionItem::TYPE_EVENTO, $types);
        $this->assertArrayHasKey(ProductionItem::TYPE_TRABALHO_TECNICO, $types);

        // Orientations check (2 concluidas + 1 andamento)
        $orientations = $researcher->getOrientations();
        $this->assertGreaterThanOrEqual(3, count($orientations));

        $concluidas = $orientations->filter(fn(Orientation $o) => $o->getNature() === Orientation::NATURE_CONCLUIDA);
        $andamento = $orientations->filter(fn(Orientation $o) => $o->getNature() === Orientation::NATURE_EM_ANDAMENTO);
        $this->assertCount(2, $concluidas);
        $this->assertCount(1, $andamento);

        // Projects check
        $this->assertGreaterThanOrEqual(1, count($researcher->getResearchProjects()));

        // Awards check
        $this->assertGreaterThanOrEqual(1, count($researcher->getAwards()));

        // Knowledge areas check
        $this->assertGreaterThanOrEqual(1, count($researcher->getKnowledgeAreas()));

        // Language check
        $this->assertGreaterThanOrEqual(1, count($researcher->getLanguageProficiencies()));

        // Examination boards check
        $this->assertGreaterThanOrEqual(1, count($researcher->getExaminationBoards()));

        // Event participations check
        $this->assertGreaterThanOrEqual(1, count($researcher->getEventParticipations()));
    }
}
