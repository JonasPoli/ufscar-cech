<?php

namespace App\Service\Import;

use App\Entity\Award;
use App\Entity\Education;
use App\Entity\EventParticipation;
use App\Entity\ExaminationBoard;
use App\Entity\KnowledgeArea;
use App\Entity\LanguageProficiency;
use App\Entity\Orientation;
use App\Entity\ProductionAuthor;
use App\Entity\ProductionItem;
use App\Entity\ProfessionalExperience;
use App\Entity\Researcher;
use App\Entity\ResearchProject;
use App\Service\Indexing\CurriculumNormalizationService;
use App\Service\Thesaurus\AuthorThesaurusService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for parsing CNPq Lattes XML curricula and persisting
 * Researchers, Educations, Productions, Orientations, Awards, and Knowledge Areas.
 */
class LattesXmlParserService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthorThesaurusService $authorThesaurusService,
        private readonly CurriculumNormalizationService $normalizationService
    ) {}

    /**
     * Truncates string safely to UTF-8 length to prevent database truncation errors.
     */
    private function truncate(?string $str, int $length): ?string
    {
        if ($str === null) return null;
        $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $str = trim(preg_replace('/\s+/', ' ', $str));
        if (mb_strlen($str) <= $length) return $str;
        return mb_substr($str, 0, $length);
    }

    /**
     * Parses a single Lattes XML file and upserts the Researcher and all related child entities.
     */
    public function parseAndSave(string $filePath): Researcher
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("XML file not found: {$filePath}");
        }

        $xml = @simplexml_load_file($filePath);
        if (!$xml || $xml->getName() !== 'CURRICULO-VITAE') {
            throw new \RuntimeException("Invalid XML structure for Lattes CV: {$filePath}");
        }

        $idLattes = trim((string)($xml['NUMERO-IDENTIFICADOR'] ?? ''));
        if ($idLattes === '') {
            $idLattes = pathinfo($filePath, PATHINFO_FILENAME);
            $idLattes = preg_replace('/[^0-9]/', '', $idLattes);
        }

        if ($idLattes === '') {
            throw new \RuntimeException("Could not determine ID Lattes for file: {$filePath}");
        }

        $repository = $this->em->getRepository(Researcher::class);
        $researcher = $repository->findOneBy(['idLattes' => $idLattes]);
        if (!$researcher) {
            $researcher = new Researcher();
            $researcher->setIdLattes($idLattes);
            $this->em->persist($researcher);
        }

        // Last Lattes update date
        $updateDate = (string)($xml['DATA-ATUALIZACAO'] ?? '');
        if ($updateDate !== '') {
            if (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $updateDate, $matches)) {
                $researcher->setLastLattesUpdate(new \DateTimeImmutable("{$matches[3]}-{$matches[2]}-{$matches[1]}"));
            } elseif ($dateObj = \DateTimeImmutable::createFromFormat('dmY', $updateDate)) {
                $researcher->setLastLattesUpdate($dateObj);
            }
        }

        // DADOS-GERAIS (General Information)
        $generalData = $xml->{'DADOS-GERAIS'} ?? null;
        if ($generalData) {
            $fullName = trim((string)($generalData['NOME-COMPLETO'] ?? ''));
            if ($fullName !== '') {
                $researcher->setFullName($this->truncate($fullName, 255));
                $researcher->setSlug(StringNormalizer::slugify($fullName));
            }
            $researcher->setCitationNames(trim((string)($generalData['NOME-EM-CITACOES-BIBLIOGRAFICAS'] ?? '')));
            $researcher->setNationality($this->truncate((string)($generalData['NACIONALIDADE'] ?? ''), 50));
            $researcher->setBirthCountry($this->truncate((string)($generalData['PAIS-DE-NASCIMENTO'] ?? ''), 100));
            $researcher->setBirthState($this->truncate((string)($generalData['UF-NASCIMENTO'] ?? ''), 50));
            $researcher->setBirthCity($this->truncate((string)($generalData['CIDADE-NASCIMENTO'] ?? ''), 100));
            
            $orcid = trim((string)($generalData['ORCID-ID'] ?? ''));
            if ($orcid !== '') {
                $researcher->setOrcid($this->truncate(preg_replace('#^https?://orcid\.org/#i', '', $orcid), 50));
            }

            // RESUMO-CV (Biography abstract)
            if (isset($generalData->{'RESUMO-CV'})) {
                $resume = (string)($generalData->{'RESUMO-CV'}['TEXTO-RESUMO-CV-RH'] ?? '');
                $researcher->setAbstractResume(trim($resume));
            }

            // ENDERECO (Professional address / Email / Phone / Unit)
            if (isset($generalData->{'ENDERECO'}->{'ENDERECO-PROFISSIONAL'})) {
                $profAddress = $generalData->{'ENDERECO'}->{'ENDERECO-PROFISSIONAL'};
                $email = (string)($profAddress['E-MAIL'] ?? '');
                if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $researcher->setEmail($this->truncate($email, 150));
                }
                $researcher->setWorkAgency($this->truncate((string)($profAddress['NOME-ORGAO'] ?? ''), 255));
                $researcher->setWorkCity($this->truncate((string)($profAddress['CIDADE'] ?? ''), 100));
                $researcher->setWorkState($this->truncate((string)($profAddress['UF'] ?? ''), 50));
                $researcher->setWorkCountry($this->truncate((string)($profAddress['PAIS'] ?? ''), 100));
                $researcher->setWorkPostalCode($this->truncate((string)($profAddress['CEP'] ?? ''), 20));
                $researcher->setWorkPhone($this->truncate((string)($profAddress['TELEFONE'] ?? $profAddress['RAMAL'] ?? ''), 50));
                
                $unit = (string)($profAddress['NOME-UNIDADE'] ?? '');
                if ($unit !== '') {
                    $researcher->setUnit($this->truncate($unit, 150));
                }
            }
        }

        // Flush researcher record to obtain ID
        $this->em->flush();

        // Sync researcher citation names to Author Thesaurus
        $this->authorThesaurusService->syncResearcherCitationNames($researcher);

        // Clear existing child entities for idempotency
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM production_items WHERE researcher_id = ?', [$researcher->getId()]);
        $conn->executeStatement('DELETE FROM educations WHERE researcher_id = ?', [$researcher->getId()]);
        $conn->executeStatement('DELETE FROM orientations WHERE researcher_id = ?', [$researcher->getId()]);
        $conn->executeStatement('DELETE FROM awards WHERE researcher_id = ?', [$researcher->getId()]);
        $conn->executeStatement('DELETE FROM knowledge_areas WHERE researcher_id = ?', [$researcher->getId()]);
        $conn->executeStatement('DELETE FROM professional_experiences WHERE researcher_id = ?', [$researcher->getId()]);
        $conn->executeStatement('DELETE FROM research_projects WHERE researcher_id = ?', [$researcher->getId()]);
        $conn->executeStatement('DELETE FROM examination_boards WHERE researcher_id = ?', [$researcher->getId()]);
        $conn->executeStatement('DELETE FROM event_participations WHERE researcher_id = ?', [$researcher->getId()]);
        $conn->executeStatement('DELETE FROM language_proficiencies WHERE researcher_id = ?', [$researcher->getId()]);

        // Process Language Proficiencies
        $this->parseLanguageProficiencies($generalData, $researcher);

        // Process Educations (Academic & Post-Doctorate)
        $this->parseEducations($generalData, $researcher);

        // Process Complementary Educations (Short courses, Extensions)
        $this->parseComplementaryEducation($xml->{'DADOS-COMPLEMENTARES'} ?? null, $researcher);

        // Process Professional Experiences & Roles
        $this->parseProfessionalExperiences($generalData, $researcher);

        // Process Research & Extension Projects
        $this->parseResearchProjects($generalData, $researcher);

        // Process Examination Boards (Bancas)
        $this->parseExaminationBoards($xml->{'DADOS-COMPLEMENTARES'} ?? null, $researcher);

        // Process Event Participations (Congressos, Seminários)
        $this->parseEventParticipations($xml->{'DADOS-COMPLEMENTARES'} ?? null, $researcher);

        // Process Knowledge Areas
        $this->parseKnowledgeAreas($generalData, $researcher);

        // Process Awards
        $this->parseAwards($generalData, $researcher);

        // Process Bibliographical Production
        $this->parseBibliographicalProduction($xml->{'PRODUCAO-BIBLIOGRAFICA'} ?? null, $researcher);

        // Process Technical Production
        $this->parseTechnicalProduction($xml->{'PRODUCAO-TECNICA'} ?? null, $researcher);

        // Process Artistic and Cultural Production
        $this->parseArtisticProductions($xml->{'OUTRA-PRODUCAO'} ?? null, $researcher);

        // Process Orientations (Completed & Ongoing)
        $this->parseOrientations($xml->{'OUTRA-PRODUCAO'} ?? null, $xml->{'DADOS-COMPLEMENTARES'} ?? null, $researcher);

        $this->em->flush();

        // Automatically normalize and index co-authors, journals and institutions into new index columns
        $this->normalizationService->normalizeResearcher($researcher);

        return $researcher;
    }

    private function parseEducations(?\SimpleXMLElement $generalData, Researcher $researcher): void
    {
        if (!$generalData || !isset($generalData->{'FORMACAO-ACADEMICA-TITULACAO'})) return;

        $formationNode = $generalData->{'FORMACAO-ACADEMICA-TITULACAO'};
        foreach ($formationNode->children() as $tag => $item) {
            $education = new Education();
            $education->setResearcher($researcher);
            $education->setLevel($this->truncate((string)$tag, 50));
            $education->setCourseName($this->truncate((string)($item['NOME-CURSO'] ?? $item['NOME-DO-CURSO'] ?? $item['TITULO-DO-TRABALHO'] ?? ''), 255));
            $education->setInstitutionName($this->truncate((string)($item['NOME-INSTITUICAO'] ?? $item['NOME-DA-INSTITUICAO'] ?? ''), 255));
            
            $startYear = (int)($item['ANO-DE-INICIO'] ?? 0);
            $endYear = (int)($item['ANO-DE-CONCLUSAO'] ?? $item['ANO-DE-OBTENCAO-DO-TITULO'] ?? 0);
            if ($startYear > 0) $education->setStartYear($startYear);
            if ($endYear > 0) $education->setEndYear($endYear);

            $education->setMonographTitle($this->truncate((string)($item['TITULO-DA-DISSERTACAO-TESE'] ?? $item['TITULO-DO-TRABALHO-DE-CONCLUSAO-DE-CURSO'] ?? $item['TITULO-DO-TRABALHO'] ?? ''), 500));
            $education->setAdvisorName($this->truncate((string)($item['NOME-DO-ORIENTADOR'] ?? $item['NOME-DO-SUPERVISOR'] ?? ''), 255));
            $education->setGrantingAgency($this->truncate((string)($item['NOME-AGENCIA-FINANCIADORA'] ?? $item['NOME-DA-AGENCIA-FINANCIADORA'] ?? ''), 150));

            $workload = (int)($item['CARGA-HORARIA'] ?? 0);
            if ($workload > 0) $education->setWorkloadHours($workload);

            $this->em->persist($education);
        }
    }

    private function parseComplementaryEducation(?\SimpleXMLElement $dadosComp, Researcher $researcher): void
    {
        if (!$dadosComp || !isset($dadosComp->{'FORMACAO-COMPLEMENTAR'})) return;

        foreach ($dadosComp->{'FORMACAO-COMPLEMENTAR'}->children() as $tag => $item) {
            $education = new Education();
            $education->setResearcher($researcher);
            
            $level = 'COMPLEMENTAR';
            if (str_contains((string)$tag, 'CURTA-DURACAO')) $level = 'CURTA_DURACAO';
            elseif (str_contains((string)$tag, 'EXTENSAO')) $level = 'EXTENSAO';
            elseif (str_contains((string)$tag, 'APERFEICOAMENTO')) $level = 'APERFEICOAMENTO';

            $education->setLevel($level);
            $education->setCourseName($this->truncate((string)($item['NOME-CURSO'] ?? $item['NOME-DO-CURSO'] ?? ''), 255));
            $education->setInstitutionName($this->truncate((string)($item['NOME-INSTITUICAO'] ?? $item['NOME-DA-INSTITUICAO'] ?? ''), 255));
            
            $startYear = (int)($item['ANO-DE-INICIO'] ?? 0);
            $endYear = (int)($item['ANO-DE-CONCLUSAO'] ?? 0);
            if ($startYear > 0) $education->setStartYear($startYear);
            if ($endYear > 0) $education->setEndYear($endYear);

            $workload = (int)($item['CARGA-HORARIA'] ?? 0);
            if ($workload > 0) $education->setWorkloadHours($workload);

            $this->em->persist($education);
        }
    }

    private function parseProfessionalExperiences(?\SimpleXMLElement $generalData, Researcher $researcher): void
    {
        if (!$generalData || !isset($generalData->{'ATUACOES-PROFISSIONAIS'})) return;

        foreach ($generalData->{'ATUACOES-PROFISSIONAIS'}->{'ATUACAO-PROFISSIONAL'} as $item) {
            $institutionName = trim((string)($item['NOME-INSTITUICAO'] ?? ''));
            if ($institutionName === '') continue;

            $instCode = (string)($item['CODIGO-INSTITUICAO'] ?? '');

            // Vínculos
            if (isset($item->{'VINCULOS'})) {
                foreach ($item->{'VINCULOS'} as $vinculo) {
                    $exp = new \App\Entity\ProfessionalExperience();
                    $exp->setResearcher($researcher);
                    $exp->setInstitutionName($this->truncate($institutionName, 255));
                    $exp->setInstitutionCode($this->truncate($instCode, 50));

                    $role = trim((string)($vinculo['OUTRO-ENQUADRAMENTO-FUNCIONAL-INFORMADO'] ?? ''));
                    if ($role === '' || strtoupper($role) === 'LIVRE') {
                        $role = trim((string)($vinculo['ENQUADRAMENTO-FUNCIONAL'] ?? ''));
                    }
                    if (strtoupper($role) === 'LIVRE') {
                        $role = '';
                    }
                    $exp->setRoleName($this->truncate($role, 255));

                    $contract = trim((string)($vinculo['OUTRO-VINCULO-INFORMADO'] ?? ''));
                    if ($contract === '' || strtoupper($contract) === 'LIVRE') {
                        $contract = trim((string)($vinculo['TIPO-DE-VINCULO'] ?? $vinculo['TIPO-VINCULO'] ?? ''));
                    }
                    if (strtoupper($contract) === 'LIVRE') {
                        $contract = '';
                    }
                    $exp->setContractType($this->truncate($contract, 150));
                    
                    $startYear = (int)($vinculo['ANO-INICIO'] ?? 0);
                    $endYear = (int)($vinculo['ANO-FIM'] ?? 0);
                    if ($startYear > 0) $exp->setStartYear($startYear);
                    if ($endYear > 0) $exp->setEndYear($endYear);

                    $startMonth = (int)($vinculo['MES-INICIO'] ?? 0);
                    $endMonth = (int)($vinculo['MES-FIM'] ?? 0);
                    if ($startMonth > 0) $exp->setStartMonth($startMonth);
                    if ($endMonth > 0) $exp->setEndMonth($endMonth);

                    $flagAtual = (string)($vinculo['FLAG-VINCULO-EMPREGATICIO'] ?? 'NAO');
                    $exp->setIsCurrent($endYear === 0 || $endYear >= (int)date('Y'));

                    $workload = (int)($vinculo['CARGA-HORARIA-SEMANAL'] ?? 0);
                    if ($workload > 0) $exp->setWorkloadHours($workload);

                    $dedicacaoExclusiva = strtoupper((string)($vinculo['FLAG-DEDICACAO-EXCLUSIVA'] ?? 'NAO')) === 'SIM';
                    $outrasInfo = trim((string)($vinculo['OUTRAS-INFORMACOES'] ?? ''));
                    if ($dedicacaoExclusiva) {
                        $outrasInfo = $outrasInfo ? $outrasInfo . ' | Dedicação Exclusiva (DE)' : 'Dedicação Exclusiva (DE)';
                    }
                    $exp->setOtherInfo($this->truncate($outrasInfo, 1000));

                    $this->em->persist($exp);
                }
            } else {
                $exp = new \App\Entity\ProfessionalExperience();
                $exp->setResearcher($researcher);
                $exp->setInstitutionName($this->truncate($institutionName, 255));
                $exp->setInstitutionCode($this->truncate($instCode, 50));
                $this->em->persist($exp);
            }
        }
    }

    private function parseKnowledgeAreas(?\SimpleXMLElement $generalData, Researcher $researcher): void
    {
        if (!$generalData || !isset($generalData->{'AREAS-DE-ATUACAO'})) return;

        foreach ($generalData->{'AREAS-DE-ATUACAO'}->{'AREA-DE-ATUACAO'} as $item) {
            $knowledgeArea = new KnowledgeArea();
            $knowledgeArea->setResearcher($researcher);
            $knowledgeArea->setMajorArea($this->truncate((string)($item['NOME-GRANDE-AREA-DO-CONHECIMENTO'] ?? ''), 150));
            $knowledgeArea->setArea($this->truncate((string)($item['NOME-DA-AREA-DO-CONHECIMENTO'] ?? ''), 150));
            $knowledgeArea->setSubArea($this->truncate((string)($item['NOME-DA-SUB-AREA-DO-CONHECIMENTO'] ?? ''), 150));
            $knowledgeArea->setSpecialty($this->truncate((string)($item['NOME-DA-ESPECIALIDADE'] ?? ''), 150));

            $this->em->persist($knowledgeArea);
        }
    }

    private function parseAwards(?\SimpleXMLElement $generalData, Researcher $researcher): void
    {
        if (!$generalData || !isset($generalData->{'PREMIOS-TITULOS'})) return;

        foreach ($generalData->{'PREMIOS-TITULOS'}->{'PREMIO-TITULO'} as $item) {
            $name = trim((string)($item['NOME-DO-PREMIO-OU-TITULO'] ?? ''));
            if ($name === '') continue;

            $award = new Award();
            $award->setResearcher($researcher);
            $award->setName($this->truncate($name, 255));
            $award->setPromoterEntity($this->truncate((string)($item['NOME-DA-ENTIDADE-PROMOTORA'] ?? ''), 255));
            $year = (int)($item['ANO-DA-PREMIACAO'] ?? 0);
            if ($year > 0) $award->setYear($year);

            $this->em->persist($award);
        }
    }

    private function parseBibliographicalProduction(?\SimpleXMLElement $prodBib, Researcher $researcher): void
    {
        if (!$prodBib) return;

        // 1. Published Articles
        if (isset($prodBib->{'ARTIGOS-PUBLICADOS'})) {
            foreach ($prodBib->{'ARTIGOS-PUBLICADOS'}->{'ARTIGO-PUBLICADO'} as $article) {
                $basicData = $article->{'DADOS-BASICOS-DO-ARTIGO'} ?? null;
                $details = $article->{'DETALHAMENTO-DO-ARTIGO'} ?? null;
                if (!$basicData) continue;

                $production = new ProductionItem();
                $production->setResearcher($researcher);
                $production->setItemType(ProductionItem::TYPE_ARTIGO);
                $production->setTitle(trim((string)($basicData['TITULO-DO-ARTIGO'] ?? '')));
                $production->setNature($this->truncate((string)($basicData['NATUREZA'] ?? ''), 100));
                $production->setYear((int)($basicData['ANO-DO-ARTIGO'] ?? 0) ?: null);
                $production->setDoi($this->truncate((string)($basicData['DOI'] ?? ''), 255));
                $production->setLanguage($this->truncate((string)($basicData['IDIOMA'] ?? ''), 50));
                $production->setCountry($this->truncate((string)($basicData['PAIS-DE-PUBLICACAO'] ?? ''), 100));
                $production->setIsScientificDissemination(((string)($basicData['FLAG-DIVULGACAO-CIENTIFICA'] ?? '')) === 'SIM');

                if ($details) {
                    $production->setJournalName($this->truncate((string)($details['TITULO-DO-PERIODICO-OU-REVISTA'] ?? ''), 500));
                    $production->setIssn($this->truncate((string)($details['ISSN'] ?? ''), 50));
                    $production->setVolume($this->truncate((string)($details['VOLUME'] ?? ''), 50));
                    $production->setIssue($this->truncate((string)($details['FASCICULO'] ?? ''), 50));
                    $production->setPages($this->truncate((string)($details['PAGINA-INICIAL'] ?? '') . '-' . (string)($details['PAGINA-FINAL'] ?? ''), 50));
                }

                $this->parseAuthors($article, $production);
                $this->em->persist($production);
            }
        }

        // 2. Published Books
        if (isset($prodBib->{'LIVROS-E-CAPITULOS'}->{'LIVROS-PUBLICADOS-OU-ORGANIZADOS'})) {
            foreach ($prodBib->{'LIVROS-E-CAPITULOS'}->{'LIVROS-PUBLICADOS-OU-ORGANIZADOS'}->{'LIVRO-PUBLICADO-OU-ORGANIZADO'} as $book) {
                $basicData = $book->{'DADOS-BASICOS-DO-LIVRO'} ?? null;
                $details = $book->{'DETALHAMENTO-DO-LIVRO'} ?? null;
                if (!$basicData) continue;

                $production = new ProductionItem();
                $production->setResearcher($researcher);
                $production->setItemType(ProductionItem::TYPE_LIVRO);
                $production->setTitle(trim((string)($basicData['TITULO-DO-LIVRO'] ?? '')));
                $production->setNature($this->truncate((string)($basicData['TIPO'] ?? $basicData['NATUREZA'] ?? ''), 100));
                $production->setYear((int)($basicData['ANO'] ?? 0) ?: null);
                $production->setDoi($this->truncate((string)($basicData['DOI'] ?? ''), 255));
                $production->setLanguage($this->truncate((string)($basicData['IDIOMA'] ?? ''), 50));
                $production->setCountry($this->truncate((string)($basicData['PAIS-DE-PUBLICACAO'] ?? ''), 100));

                if ($details) {
                    $production->setPublisher($this->truncate((string)($details['NOME-DA-EDITORA'] ?? ''), 500));
                    $production->setIsbn($this->truncate((string)($details['ISBN'] ?? ''), 50));
                }

                $this->parseAuthors($book, $production);
                $this->em->persist($production);
            }
        }

        // 3. Book Chapters
        if (isset($prodBib->{'LIVROS-E-CAPITULOS'}->{'CAPITULOS-DE-LIVROS-PUBLICADOS'})) {
            foreach ($prodBib->{'LIVROS-E-CAPITULOS'}->{'CAPITULOS-DE-LIVROS-PUBLICADOS'}->{'CAPITULO-DE-LIVRO-PUBLICADO'} as $chapter) {
                $basicData = $chapter->{'DADOS-BASICOS-DO-CAPITULO'} ?? null;
                $details = $chapter->{'DETALHAMENTO-DO-CAPITULO'} ?? null;
                if (!$basicData) continue;

                $production = new ProductionItem();
                $production->setResearcher($researcher);
                $production->setItemType(ProductionItem::TYPE_CAPITULO);
                $production->setTitle(trim((string)($basicData['TITULO-DO-CAPITULO-DO-LIVRO'] ?? '')));
                $production->setNature($this->truncate((string)($basicData['TIPO'] ?? ''), 100));
                $production->setYear((int)($basicData['ANO'] ?? 0) ?: null);
                $production->setDoi($this->truncate((string)($basicData['DOI'] ?? ''), 255));
                $production->setLanguage($this->truncate((string)($basicData['IDIOMA'] ?? ''), 50));
                $production->setCountry($this->truncate((string)($basicData['PAIS-DE-PUBLICACAO'] ?? ''), 100));

                if ($details) {
                    $production->setPublisher($this->truncate((string)($details['NOME-DA-EDITORA'] ?? ''), 500));
                    $production->setIsbn($this->truncate((string)($details['ISBN'] ?? ''), 50));
                    $production->setJournalName($this->truncate((string)($details['TITULO-DO-LIVRO'] ?? ''), 500));
                    $production->setPages($this->truncate((string)($details['PAGINA-INICIAL'] ?? '') . '-' . (string)($details['PAGINA-FINAL'] ?? ''), 50));
                }

                $this->parseAuthors($chapter, $production);
                $this->em->persist($production);
            }
        }

        // 4. Conference Papers / Events
        if (isset($prodBib->{'TRABALHOS-EM-EVENTOS'})) {
            foreach ($prodBib->{'TRABALHOS-EM-EVENTOS'}->{'TRABALHO-EM-EVENTOS'} as $event) {
                $basicData = $event->{'DADOS-BASICOS-DO-TRABALHO'} ?? null;
                $details = $event->{'DETALHAMENTO-DO-TRABALHO'} ?? null;
                if (!$basicData) continue;

                $production = new ProductionItem();
                $production->setResearcher($researcher);
                $production->setItemType(ProductionItem::TYPE_EVENTO);
                $production->setTitle(trim((string)($basicData['TITULO-DO-TRABALHO'] ?? '')));
                $production->setNature($this->truncate((string)($basicData['NATUREZA'] ?? ''), 100));
                $production->setYear((int)($basicData['ANO-DO-TRABALHO'] ?? 0) ?: null);
                $production->setDoi($this->truncate((string)($basicData['DOI'] ?? ''), 255));
                $production->setLanguage($this->truncate((string)($basicData['IDIOMA'] ?? ''), 50));
                $production->setCountry($this->truncate((string)($basicData['PAIS-DO-EVENTO'] ?? ''), 100));

                if ($details) {
                    $production->setEventName($this->truncate((string)($details['NOME-DO-EVENTO'] ?? ''), 500));
                    $production->setEventCity($this->truncate((string)($details['CIDADE-DO-EVENTO'] ?? ''), 255));
                    $production->setIsbn($this->truncate((string)($details['ISBN'] ?? ''), 50));
                }

                $this->parseAuthors($event, $production);
                $this->em->persist($production);
            }
        }

        // 5. Newspaper / Magazine Articles
        if (isset($prodBib->{'TEXTOS-EM-JORNAIS-OU-REVISTAS'})) {
            foreach ($prodBib->{'TEXTOS-EM-JORNAIS-OU-REVISTAS'}->{'TEXTO-EM-JORNAL-OU-REVISTA'} as $articleText) {
                $basicData = $articleText->{'DADOS-BASICOS-DO-TEXTO'} ?? null;
                $details = $articleText->{'DETALHAMENTO-DO-TEXTO'} ?? null;
                if (!$basicData) continue;

                $production = new ProductionItem();
                $production->setResearcher($researcher);
                $production->setItemType(ProductionItem::TYPE_TEXTO_JORNAL);
                $production->setTitle(trim((string)($basicData['TITULO-DO-TEXTO'] ?? '')));
                $production->setNature($this->truncate((string)($basicData['NATUREZA'] ?? ''), 100));
                $production->setYear((int)($basicData['ANO-DO-TEXTO'] ?? 0) ?: null);
                $production->setDoi($this->truncate((string)($basicData['DOI'] ?? ''), 255));
                $production->setLanguage($this->truncate((string)($basicData['IDIOMA'] ?? ''), 50));

                if ($details) {
                    $production->setJournalName($this->truncate((string)($details['TITULO-DO-JORNAL-OU-REVISTA'] ?? ''), 500));
                    $production->setIssn($this->truncate((string)($details['ISSN'] ?? ''), 50));
                }

                $this->parseAuthors($articleText, $production);
                $this->em->persist($production);
            }
        }

        // 6. Other Bibliographical Productions
        if (isset($prodBib->{'DEMAIS-TIPOS-DE-PRODUCAO-BIBLIOGRAFICA'})) {
            $otherNode = $prodBib->{'DEMAIS-TIPOS-DE-PRODUCAO-BIBLIOGRAFICA'};
            foreach ($otherNode->children() as $tag => $item) {
                $basicData = $item->children()[0] ?? null;
                if (!$basicData) continue;

                $title = trim((string)($basicData['TITULO'] ?? $basicData['TITULO-DO-ARTIGO'] ?? $basicData['TITULO-DO-TRABALHO'] ?? ''));
                if ($title === '') continue;

                $production = new ProductionItem();
                $production->setResearcher($researcher);
                $production->setItemType(ProductionItem::TYPE_OUTRA);
                $production->setTitle($title);
                $production->setNature($this->truncate((string)$tag, 100));
                $production->setYear((int)($basicData['ANO'] ?? $basicData['ANO-DO-ARTIGO'] ?? 0) ?: null);
                $production->setDoi($this->truncate((string)($basicData['DOI'] ?? ''), 255));

                $this->parseAuthors($item, $production);
                $this->em->persist($production);
            }
        }
    }

    private function parseTechnicalProduction(?\SimpleXMLElement $prodTec, Researcher $researcher): void
    {
        if (!$prodTec) return;

        foreach ($prodTec->children() as $tag => $item) {
            $first = $item->children()[0] ?? null;
            if (!$first) continue;

            $title = trim((string)($first['TITULO'] ?? $first['TITULO-DO-TRABALHO-TECNICO'] ?? $first['TITULO-DO-SOFTWARE'] ?? ''));
            if ($title === '') continue;

            $itemType = match ((string)$tag) {
                'SOFTWARE' => ProductionItem::TYPE_SOFTWARE,
                'PATENTE' => ProductionItem::TYPE_PATENTE,
                'MARCA' => ProductionItem::TYPE_MARCA,
                default => ProductionItem::TYPE_TRABALHO_TECNICO,
            };

            $production = new ProductionItem();
            $production->setResearcher($researcher);
            $production->setItemType($itemType);
            $production->setTitle($title);
            $production->setNature($this->truncate((string)$tag, 100));
            $production->setYear((int)($first['ANO'] ?? 0) ?: null);
            $production->setDoi($this->truncate((string)($first['DOI'] ?? ''), 255));

            $this->parseAuthors($item, $production);
            $this->em->persist($production);
        }
    }

    private function parseAuthors(\SimpleXMLElement $item, ProductionItem $production): void
    {
        if (!isset($item->{'AUTORES'})) return;

        foreach ($item->{'AUTORES'} as $authorNode) {
            $author = new ProductionAuthor();
            $author->setProductionItem($production);
            $author->setAuthorName(trim((string)($authorNode['NOME-COMPLETO-DO-AUTOR'] ?? '')));
            $author->setCitationName(trim((string)($authorNode['NOME-PARA-CITACAO'] ?? '')));
            $author->setIdLattes($this->truncate((string)($authorNode['NRO-ID-CNPQ'] ?? ''), 30) ?: null);
            $order = (int)($authorNode['ORDEM-DE-AUTORIA'] ?? 0);
            if ($order > 0) $author->setAuthorOrder($order);

            $production->addAuthor($author);
        }
    }

    private function parseOrientations(?\SimpleXMLElement $otherProd, ?\SimpleXMLElement $complementaryData, Researcher $researcher): void
    {
        // 1. Completed Orientations (in OUTRA-PRODUCAO -> ORIENTACOES-CONCLUIDAS)
        if ($otherProd && isset($otherProd->{'ORIENTACOES-CONCLUIDAS'})) {
            $completedNode = $otherProd->{'ORIENTACOES-CONCLUIDAS'};
            foreach ($completedNode->children() as $tag => $item) {
                $basicData = null;
                $details = null;
                foreach ($item->children() as $sub) {
                    $subName = $sub->getName();
                    if (str_starts_with($subName, 'DADOS-BASICOS')) {
                        $basicData = $sub;
                    } elseif (str_starts_with($subName, 'DETALHAMENTO')) {
                        $details = $sub;
                    }
                }

                if (!$basicData) continue;

                $orientation = new Orientation();
                $orientation->setResearcher($researcher);
                $orientation->setNature(Orientation::NATURE_CONCLUIDA);
                
                $naturezaAttr = strtoupper((string)($basicData['NATUREZA'] ?? ''));
                $tagStr = (string)$tag;

                $type = match ($tagStr) {
                    'ORIENTACOES-CONCLUIDAS-PARA-MESTRADO' => Orientation::TYPE_MESTRADO,
                    'ORIENTACOES-CONCLUIDAS-PARA-DOUTORADO' => Orientation::TYPE_DOUTORADO,
                    'ORIENTACOES-CONCLUIDAS-PARA-POS-DOUTORADO' => Orientation::TYPE_POS_DOUTORADO,
                    default => null,
                };

                if ($type === null) {
                    if (str_contains($naturezaAttr, 'DOUTORADO')) $type = Orientation::TYPE_DOUTORADO;
                    elseif (str_contains($naturezaAttr, 'MESTRADO')) $type = Orientation::TYPE_MESTRADO;
                    elseif (str_contains($naturezaAttr, 'POS_DOUTORADO') || str_contains($naturezaAttr, 'POS-DOUTORADO') || str_contains($naturezaAttr, 'PÓS-DOUTORADO')) $type = Orientation::TYPE_POS_DOUTORADO;
                    elseif (str_contains($naturezaAttr, 'ESPECIALIZACAO') || str_contains($naturezaAttr, 'ESPECIALIZAÇÃO') || str_contains($naturezaAttr, 'APERFEICOAMENTO') || str_contains($naturezaAttr, 'APERFEIÇOAMENTO')) $type = Orientation::TYPE_ESPECIALIZACAO;
                    elseif (str_contains($naturezaAttr, 'INICIACAO') || str_contains($naturezaAttr, 'INICIAÇÃO') || str_contains($naturezaAttr, 'PIBIC')) $type = Orientation::TYPE_INICIACAO_CIENTIFICA;
                    elseif (str_contains($naturezaAttr, 'GRADUACAO') || str_contains($naturezaAttr, 'GRADUAÇÃO') || str_contains($naturezaAttr, 'CONCLUSAO_DE_CURSO') || str_contains($naturezaAttr, 'TCC')) $type = Orientation::TYPE_TCC_GRADUACAO;
                    else $type = Orientation::TYPE_OUTRA;
                }
                
                $orientation->setOrientationType($type);

                $orientation->setTitle($this->truncate((string)($basicData['TITULO'] ?? $basicData['TITULO-DO-TRABALHO'] ?? ''), 500));
                $orientation->setYear((int)($basicData['ANO'] ?? 0) ?: null);

                if ($details) {
                    $student = trim((string)($details['NOME-DO-ORIENTADO'] ?? $details['NOME-DO-ORIENTANDO'] ?? $details['NOME-DO-BOLSISTA'] ?? $details['NOME-DO-CANDIDATO'] ?? ''));
                    $orientation->setStudentName($this->truncate($student, 255));
                    $orientation->setInstitutionName($this->truncate((string)($details['NOME-DA-INSTITUICAO'] ?? $details['NOME-INSTITUICAO'] ?? ''), 255));
                    $orientation->setCourseName($this->truncate((string)($details['NOME-DO-CURSO'] ?? $details['NOME-CURSO'] ?? ''), 255));
                }

                if ($orientation->getStudentName() !== '') {
                    $this->em->persist($orientation);
                }
            }
        }

        // 2. Ongoing Orientations (in DADOS-COMPLEMENTARES -> ORIENTACOES-EM-ANDAMENTO or OUTRA-PRODUCAO -> ORIENTACOES-EM-ANDAMENTO)
        $ongoingNode = null;
        if ($complementaryData && isset($complementaryData->{'ORIENTACOES-EM-ANDAMENTO'})) {
            $ongoingNode = $complementaryData->{'ORIENTACOES-EM-ANDAMENTO'};
        } elseif ($otherProd && isset($otherProd->{'ORIENTACOES-EM-ANDAMENTO'})) {
            $ongoingNode = $otherProd->{'ORIENTACOES-EM-ANDAMENTO'};
        }

        if ($ongoingNode) {
            foreach ($ongoingNode->children() as $tag => $item) {
                $basicData = null;
                $details = null;
                foreach ($item->children() as $sub) {
                    $subName = $sub->getName();
                    if (str_starts_with($subName, 'DADOS-BASICOS')) {
                        $basicData = $sub;
                    } elseif (str_starts_with($subName, 'DETALHAMENTO')) {
                        $details = $sub;
                    }
                }

                if (!$basicData) continue;

                $orientation = new Orientation();
                $orientation->setResearcher($researcher);
                $orientation->setNature(Orientation::NATURE_EM_ANDAMENTO);

                $naturezaAttr = strtoupper((string)($basicData['NATUREZA'] ?? ''));
                $tagStr = (string)$tag;

                $type = match ($tagStr) {
                    'ORIENTACAO-EM-ANDAMENTO-DE-MESTRADO' => Orientation::TYPE_MESTRADO,
                    'ORIENTACAO-EM-ANDAMENTO-DE-DOUTORADO' => Orientation::TYPE_DOUTORADO,
                    'ORIENTACAO-EM-ANDAMENTO-DE-POS-DOUTORADO' => Orientation::TYPE_POS_DOUTORADO,
                    'ORIENTACAO-EM-ANDAMENTO-DE-INICIACAO-CIENTIFICA' => Orientation::TYPE_INICIACAO_CIENTIFICA,
                    'ORIENTACAO-EM-ANDAMENTO-DE-GRADUACAO' => Orientation::TYPE_TCC_GRADUACAO,
                    'ORIENTACAO-EM-ANDAMENTO-DE-APERFEICOAMENTO-ESPECIALIZACAO' => Orientation::TYPE_ESPECIALIZACAO,
                    default => null,
                };

                if ($type === null) {
                    if (str_contains($naturezaAttr, 'DOUTORADO')) $type = Orientation::TYPE_DOUTORADO;
                    elseif (str_contains($naturezaAttr, 'MESTRADO')) $type = Orientation::TYPE_MESTRADO;
                    elseif (str_contains($naturezaAttr, 'POS_DOUTORADO') || str_contains($naturezaAttr, 'POS-DOUTORADO') || str_contains($naturezaAttr, 'PÓS-DOUTORADO')) $type = Orientation::TYPE_POS_DOUTORADO;
                    elseif (str_contains($naturezaAttr, 'ESPECIALIZACAO') || str_contains($naturezaAttr, 'ESPECIALIZAÇÃO') || str_contains($naturezaAttr, 'APERFEICOAMENTO') || str_contains($naturezaAttr, 'APERFEIÇOAMENTO')) $type = Orientation::TYPE_ESPECIALIZACAO;
                    elseif (str_contains($naturezaAttr, 'INICIACAO') || str_contains($naturezaAttr, 'INICIAÇÃO') || str_contains($naturezaAttr, 'PIBIC')) $type = Orientation::TYPE_INICIACAO_CIENTIFICA;
                    elseif (str_contains($naturezaAttr, 'GRADUACAO') || str_contains($naturezaAttr, 'GRADUAÇÃO') || str_contains($naturezaAttr, 'CONCLUSAO_DE_CURSO') || str_contains($naturezaAttr, 'TCC')) $type = Orientation::TYPE_TCC_GRADUACAO;
                    else $type = Orientation::TYPE_OUTRA;
                }

                $orientation->setOrientationType($type);

                $orientation->setTitle($this->truncate((string)($basicData['TITULO-DO-TRABALHO'] ?? $basicData['TITULO'] ?? ''), 500));
                $orientation->setYear((int)($basicData['ANO'] ?? 0) ?: null);

                if ($details) {
                    $student = trim((string)($details['NOME-DO-ORIENTADO'] ?? $details['NOME-DO-ORIENTANDO'] ?? $details['NOME-DO-BOLSISTA'] ?? $details['NOME-DO-CANDIDATO'] ?? ''));
                    $orientation->setStudentName($this->truncate($student, 255));
                    $orientation->setInstitutionName($this->truncate((string)($details['NOME-DA-INSTITUICAO'] ?? $details['NOME-INSTITUICAO'] ?? ''), 255));
                    $orientation->setCourseName($this->truncate((string)($details['NOME-DO-CURSO'] ?? $details['NOME-CURSO'] ?? ''), 255));
                }

                if ($orientation->getStudentName() !== '') {
                    $this->em->persist($orientation);
                }
            }
        }
    }

    private function parseLanguageProficiencies(?\SimpleXMLElement $generalData, Researcher $researcher): void
    {
        if (!$generalData || !isset($generalData->{'IDIOMAS'})) return;

        foreach ($generalData->{'IDIOMAS'}->{'IDIOMA'} as $item) {
            $langName = trim((string)($item['IDIOMA'] ?? $item['DESCRICAO-DO-IDIOMA'] ?? ''));
            if ($langName === '') continue;

            $lang = new LanguageProficiency();
            $lang->setResearcher($researcher);
            $lang->setLanguage($this->truncate($langName, 100));
            $lang->setReading($this->truncate((string)($item['PROFICIENCIA-DE-LEITURA'] ?? ''), 50));
            $lang->setWriting($this->truncate((string)($item['PROFICIENCIA-DE-ESCRITA'] ?? ''), 50));
            $lang->setSpeaking($this->truncate((string)($item['PROFICIENCIA-DE-FALA'] ?? ''), 50));
            $lang->setComprehension($this->truncate((string)($item['PROFICIENCIA-DE-COMPREENSAO'] ?? ''), 50));

            $this->em->persist($lang);
        }
    }

    private function parseResearchProjects(?\SimpleXMLElement $generalData, Researcher $researcher): void
    {
        if (!$generalData) return;

        $projectNodes = $generalData->xpath('.//PROJETO-DE-PESQUISA');
        if (!$projectNodes) return;

        foreach ($projectNodes as $item) {
            $name = trim((string)($item['NOME-DO-PROJETO'] ?? ''));
            if ($name === '') continue;

            $proj = new ResearchProject();
            $proj->setResearcher($researcher);
            $proj->setName($this->truncate($name, 500));
            $proj->setNature($this->truncate((string)($item['NATUREZA'] ?? 'PESQUISA'), 50));
            $proj->setStatus($this->truncate((string)($item['SITUACAO'] ?? 'EM_ANDAMENTO'), 50));
            
            $startYear = (int)($item['ANO-INICIO'] ?? 0);
            $endYear = (int)($item['ANO-FIM'] ?? 0);
            if ($startYear > 0) $proj->setStartYear($startYear);
            if ($endYear > 0) $proj->setEndYear($endYear);

            $proj->setDescription($this->truncate((string)($item['DESCRICAO-DO-PROJETO'] ?? ''), 2000));

            // Financiador
            if (isset($item->{'FINANCIADORES-DO-PROJETO'}->{'FINANCIADOR-DO-PROJETO'})) {
                $fin = $item->{'FINANCIADORES-DO-PROJETO'}->{'FINANCIADOR-DO-PROJETO'};
                $proj->setAgencyFinancier($this->truncate((string)($fin['NOME-INSTITUICAO'] ?? ''), 255));
            }

            $this->em->persist($proj);
        }
    }

    private function parseExaminationBoards(?\SimpleXMLElement $dadosComp, Researcher $researcher): void
    {
        if (!$dadosComp) return;

        $boardNodes = $dadosComp->xpath('.//PARTICIPACAO-EM-BANCA-TRABALHOS-CONCLUSAO/* | .//PARTICIPACAO-EM-BANCA-DE-EXAME-QUALIFICACAO | .//OUTRAS-BANCAS-JULGADORAS-OU-COMISSOES/*');
        if (!$boardNodes) return;

        foreach ($boardNodes as $item) {
            $tag = $item->getName();
            $basicData = null;
            $details = null;
            foreach ($item->children() as $sub) {
                $subName = $sub->getName();
                if (str_starts_with($subName, 'DADOS-BASICOS')) {
                    $basicData = $sub;
                } elseif (str_starts_with($subName, 'DETALHAMENTO')) {
                    $details = $sub;
                }
            }

            $boardType = match (true) {
                str_contains($tag, 'DOUTORADO') => 'Doutorado',
                str_contains($tag, 'MESTRADO') => 'Mestrado',
                str_contains($tag, 'QUALIFICACAO') => 'Exame de Qualificação',
                str_contains($tag, 'GRADUACAO') => 'Graduação',
                str_contains($tag, 'CONCURSO') => 'Concurso Público',
                str_contains($tag, 'AVALIACAO') => 'Avaliação de Curso',
                default => 'Banca Julgadora',
            };

            $board = new ExaminationBoard();
            $board->setResearcher($researcher);
            $board->setBoardType($boardType);

            if ($basicData) {
                $board->setTitle($this->truncate((string)($basicData['TITULO'] ?? $basicData['TITULO-DO-TRABALHO'] ?? ''), 500));
                $year = (int)($basicData['ANO'] ?? 0);
                if ($year > 0) $board->setYear($year);
            }

            if ($details) {
                $board->setCandidateName($this->truncate((string)($details['NOME-DO-CANDIDATO'] ?? ''), 255));
                $board->setInstitutionName($this->truncate((string)($details['NOME-DA-INSTITUICAO'] ?? $details['NOME-INSTITUICAO'] ?? ''), 255));
            }

            $this->em->persist($board);
        }
    }

    private function parseEventParticipations(?\SimpleXMLElement $dadosComp, Researcher $researcher): void
    {
        if (!$dadosComp || !isset($dadosComp->{'PARTICIPACAO-EM-EVENTOS-CONGRESSOS'})) return;

        foreach ($dadosComp->{'PARTICIPACAO-EM-EVENTOS-CONGRESSOS'}->children() as $item) {
            $tag = $item->getName();
            $basicData = null;
            $details = null;
            foreach ($item->children() as $sub) {
                $subName = $sub->getName();
                if (str_starts_with($subName, 'DADOS-BASICOS')) {
                    $basicData = $sub;
                } elseif (str_starts_with($subName, 'DETALHAMENTO')) {
                    $details = $sub;
                }
            }

            $eventType = match (true) {
                str_contains($tag, 'CONGRESSO') => 'Congresso',
                str_contains($tag, 'SIMPOSIO') => 'Simpósio',
                str_contains($tag, 'SEMINARIO') => 'Seminário',
                str_contains($tag, 'OFICINA') => 'Oficina',
                str_contains($tag, 'ENCONTRO') => 'Encontro',
                default => 'Evento Científico',
            };

            $event = new EventParticipation();
            $event->setResearcher($researcher);
            $event->setEventType($eventType);

            $eventName = '';
            $presentationTitle = '';

            if ($basicData) {
                $presentationTitle = trim((string)($basicData['TITULO'] ?? $basicData['TITULO-DA-APRESENTACAO-DO-TRABALHO'] ?? ''));
                $partType = trim((string)($basicData['TIPO-PARTICIPACAO'] ?? $basicData['FORMA-PARTICIPACAO'] ?? ''));
                $event->setParticipationType($this->truncate($partType, 100));
                $year = (int)($basicData['ANO'] ?? 0);
                if ($year > 0) $event->setYear($year);
            }

            if ($details) {
                $eventName = trim((string)($details['NOME-DO-EVENTO'] ?? ''));
                if (!$presentationTitle && isset($details['TITULO-DA-APRESENTACAO-DO-TRABALHO'])) {
                    $presentationTitle = trim((string)$details['TITULO-DA-APRESENTACAO-DO-TRABALHO']);
                }
            }

            if ($eventName === '' && $presentationTitle !== '') {
                $eventName = $presentationTitle;
            }

            $event->setEventName($this->truncate($eventName, 500));
            $event->setPresentationTitle($this->truncate($presentationTitle, 500));

            if ($event->getEventName() !== '') {
                $this->em->persist($event);
            }
        }
    }

    private function parseArtisticProductions(?\SimpleXMLElement $otherProd, Researcher $researcher): void
    {
        if (!$otherProd || !isset($otherProd->{'PRODUCAO-ARTISTICA-CULTURAL'})) return;

        foreach ($otherProd->{'PRODUCAO-ARTISTICA-CULTURAL'}->children() as $tag => $item) {
            $basicData = null;
            $details = null;
            foreach ($item->children() as $sub) {
                $subName = $sub->getName();
                if (str_starts_with($subName, 'DADOS-BASICOS')) {
                    $basicData = $sub;
                } elseif (str_starts_with($subName, 'DETALHAMENTO')) {
                    $details = $sub;
                }
            }

            if (!$basicData) continue;

            $title = trim((string)($basicData['TITULO'] ?? $basicData['TITULO-DA-OBRA-DE-ARTE-VISUAL'] ?? ''));
            if ($title === '') continue;

            $prod = new ProductionItem();
            $prod->setResearcher($researcher);
            $prod->setItemType(ProductionItem::TYPE_ARTISTICA);
            $prod->setTitle($this->truncate($title, 500));
            $prod->setNature($this->truncate((string)($basicData['NATUREZA'] ?? (string)$tag), 100));
            
            $year = (int)($basicData['ANO'] ?? 0);
            if ($year > 0) $prod->setYear($year);

            $this->parseAuthors($item, $prod);
            $this->em->persist($prod);
        }
    }
}
