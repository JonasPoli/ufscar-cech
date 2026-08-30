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
use App\Entity\ResearchProject;
use App\Entity\Researcher;
use App\Service\Indexing\CurriculumNormalizationService;
use App\Service\Thesaurus\AuthorThesaurusService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Serviço responsável pelo parsing de currículos Lattes em formato HTML público.
 *
 * Utiliza o parser DOMDocument e consultas XPath para extrair:
 * - Informações biográficas, resumo e citações.
 * - Formação acadêmica (Education).
 * - Atuações profissionais (ProfessionalExperience) e Projetos (ResearchProject).
 * - Produções bibliográficas, técnicas e artísticas (ProductionItem).
 * - Coautores (ProductionAuthor).
 * - Orientações (Orientation), Bancas (ExaminationBoard), Prêmios (Award) e Idiomas (LanguageProficiency).
 *
 * REGRA FIXA: Todos os dados brutos são preservados sem alterações.
 */
class LattesHtmlParserService
{
    private ?array $lastReport = null;

    /**
     * @param EntityManagerInterface $em Gerenciador de entidades do Doctrine
     * @param AuthorThesaurusService $authorThesaurusService Serviço de sincronização do tesauro de autores
     * @param CurriculumNormalizationService $normalizationService Serviço de normalização e enriquecimento
     * @param CurriculumDiffService $diffService Serviço de cálculo de diff de importação
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthorThesaurusService $authorThesaurusService,
        private readonly CurriculumNormalizationService $normalizationService,
        private readonly CurriculumDiffService $diffService
    ) {}

    public function getLastReport(): ?array
    {
        return $this->lastReport;
    }

    /**
     * Trunca uma string de forma segura em UTF-8 para evitar estouro de tamanho de colunas no banco de dados.
     *
     * @param string|null $str String de entrada
     * @param int $length Tamanho máximo permitido
     * @return string|null String decodificada e truncada
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
     * Realiza o parsing de uma string HTML de currículo Lattes e salva as entidades no banco.
     *
     * @param string $html Código HTML completo da página Lattes
     * @param Researcher|null $existingResearcher Entidade existente opcional para atualização
     * @param string|null $idLattes ID Lattes de 16 dígitos opcional
     * @return Researcher Entidade do pesquisador persistida
     */
    public function parseHtmlAndSave(string $html, ?Researcher $existingResearcher = null, ?string $idLattes = null): Researcher
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        // 1. Sanitize HTML encoding to prevent mojibake/double encoding
        $cleanHtml = preg_replace('/<meta[^>]+charset=[^>]+>/i', '', $html);
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $cleanHtml . '</body></html>');
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);

        // 2. Extrair Nome Completo
        $nomeNode = $xpath->query('//h2[contains(@class, "nome")] | //div[contains(@class, "infpessoa")]//h2 | //div[contains(@class, "informacoes-autor")]//h2 | //div[@class="title-wrapper"]//h2')->item(0);
        $fullName = $nomeNode ? trim($nomeNode->textContent) : null;

        // 3. Extrair ID Lattes se não fornecido
        if (!$idLattes) {
            $lattesNode = $xpath->query('//li[contains(text(), "Endereço para acessar este CV")] | //span[contains(text(), "lattes.cnpq.br")] | //input[@name="id"]')->item(0);
            if ($lattesNode) {
                if ($lattesNode instanceof \DOMElement && $lattesNode->hasAttribute('value') && $lattesNode->getAttribute('value')) {
                    $idLattes = $lattesNode->getAttribute('value');
                } elseif (preg_match('~\b(\d{16})\b~', $lattesNode->textContent, $m)) {
                    $idLattes = $m[1];
                }
            }
        }

        if (!$idLattes && preg_match('~lattes\.cnpq\.br/(\d{16})~i', $html, $m)) {
            $idLattes = $m[1];
        }

        if (!$idLattes && $existingResearcher) {
            $idLattes = $existingResearcher->getIdLattes();
        }

        if (!$idLattes) {
            throw new \InvalidArgumentException('Não foi possível identificar o ID Lattes no HTML informado.');
        }

        $repo = $this->em->getRepository(Researcher::class);
        $researcher = $existingResearcher ?: $repo->findOneBy(['idLattes' => $idLattes]);
        if (!$researcher) {
            $researcher = new Researcher();
            $researcher->setIdLattes($idLattes);
            $this->em->persist($researcher);
        }

        if ($fullName && $fullName !== '') {
            $researcher->setFullName($this->truncate($fullName, 255));
        }

        // 4. Extrair Resumo
        $resumoNode = $xpath->query('//p[contains(@class, "resumo")] | //div[contains(@class, "resumo")]//p | //div[contains(@class, "texto-resumo")]')->item(0);
        if ($resumoNode) {
            $resume = trim(preg_replace('/\s+/', ' ', $resumoNode->textContent));
            $resume = preg_replace('~\(Texto informado pelo autor\)\s*$~i', '', $resume);
            $researcher->setAbstractResume(trim($resume));
        }

        // 5. Extrair ORCID
        $orcidNode = $xpath->query('//a[contains(@href, "orcid.org")]')->item(0);
        if ($orcidNode && preg_match('~\b(\d{4}-\d{4}-\d{4}-[\dX]{4})\b~', $orcidNode->getAttribute('href'), $m)) {
            $researcher->setOrcid($m[1]);
        }

        // 6. Data de atualização
        $updateNode = $xpath->query('//span[contains(text(), "Última atualização do currículo")] | //li[contains(text(), "Última atualização")]')->item(0);
        if ($updateNode && preg_match('~(\d{2})/(\d{2})/(\d{4})~', $updateNode->textContent, $m)) {
            $researcher->setLastLattesUpdate(new \DateTimeImmutable("{$m[3]}-{$m[2]}-{$m[1]}"));
        }

        // 7. Dados de Identificação adicionais (Citação, Nacionalidade)
        $identNodes = $xpath->query('//div[contains(@class, "layout-cell-3") and .//b[contains(text(), "Nome em citações")]]/following-sibling::div[1]');
        if ($identNodes->length > 0) {
            $researcher->setCitationNames($this->truncate($identNodes->item(0)->textContent, 500));
        }
        $nacNodes = $xpath->query('//div[contains(@class, "layout-cell-3") and .//b[contains(text(), "Nacionalidade")]]/following-sibling::div[1]');
        if ($nacNodes->length > 0) {
            $researcher->setNationality($this->truncate($nacNodes->item(0)->textContent, 50));
        }

        // 8. Endereço Profissional
        $addrNodes = $xpath->query('//div[contains(@class, "layout-cell-3") and .//b[contains(text(), "Endereço Profissional")]]/following-sibling::div[1]');
        if ($addrNodes->length > 0) {
            $addrText = trim(preg_replace('/\s+/', ' ', $addrNodes->item(0)->textContent));
            $researcher->setWorkAgency($this->truncate($addrText, 255));
            if (preg_match('~(\d{5}-?\d{3})~', $addrText, $pm)) {
                $researcher->setWorkPostalCode($pm[1]);
            }
            if (preg_match('~Telefone:\s*([^<\n,]+)~i', $addrText, $tm)) {
                $researcher->setWorkPhone($this->truncate($tm[1], 50));
            }
            if (preg_match('~([A-Za-zÀ-ÿ\s\-]+),\s*([A-Z]{2})\s*-\s*Brasil~u', $addrText, $cm)) {
                $researcher->setWorkCity($this->truncate(trim($cm[1]), 100));
                $researcher->setWorkState($this->truncate(trim($cm[2]), 50));
                $researcher->setWorkCountry('Brasil');
            }
        }

        // 8.5. Capturar snapshot para cálculo do relatório de alterações (diff report)
        $snapshot = $this->diffService->takeSnapshot($researcher);

        // 9. Limpar coleções existentes para sincronização limpa e idempotente
        foreach ($researcher->getProductions() as $oldProd) $this->em->remove($oldProd);
        foreach ($researcher->getEducations() as $oldEdu) $this->em->remove($oldEdu);
        foreach ($researcher->getOrientations() as $oldOrient) $this->em->remove($oldOrient);
        foreach ($researcher->getAwards() as $oldAward) $this->em->remove($oldAward);
        foreach ($researcher->getKnowledgeAreas() as $oldKa) $this->em->remove($oldKa);
        foreach ($researcher->getProfessionalExperiences() as $oldPe) $this->em->remove($oldPe);
        foreach ($researcher->getResearchProjects() as $oldRp) $this->em->remove($oldRp);
        foreach ($researcher->getExaminationBoards() as $oldEb) $this->em->remove($oldEb);
        foreach ($researcher->getEventParticipations() as $oldEp) $this->em->remove($oldEp);
        foreach ($researcher->getLanguageProficiencies() as $oldLp) $this->em->remove($oldLp);
        $this->em->flush();

        // 10. Parse Formação Acadêmica & Pós-Doutorado & Formação Complementar
        $this->parseEducations($xpath, $researcher);

        // 11. Parse Atuação Profissional
        $this->parseProfessionalExperiences($xpath, $researcher);

        // 12. Parse Projetos de Pesquisa, Extensão e Desenvolvimento
        $this->parseProjects($xpath, $researcher);

        // 13. Parse Áreas de Atuação
        $this->parseKnowledgeAreas($xpath, $researcher);

        // 14. Parse Idiomas
        $this->parseLanguages($xpath, $researcher);

        // 15. Parse Prêmios e Títulos
        $this->parseAwards($xpath, $researcher);

        // 16. Parse Todas as Produções Bibliográficas, Técnicas e Artísticas
        $this->parseAllProductions($xpath, $researcher);

        // 17. Parse Orientações Concluídas e Em Andamento
        $this->parseOrientations($xpath, $researcher);

        // 18. Parse Bancas Examinadoras
        $this->parseExaminationBoards($xpath, $researcher);

        // 19. Parse Eventos (Participação e Organização)
        $this->parseEventParticipations($xpath, $researcher);

        $this->em->flush();

        // Sync researcher citation names to Author Thesaurus
        $this->authorThesaurusService->syncResearcherCitationNames($researcher);

        // Automatically normalize and index co-authors, journals and institutions into new index columns
        $this->normalizationService->normalizeResearcher($researcher);

        // Calcular e salvar relatório de alterações
        $this->lastReport = $this->diffService->computeReport($researcher, $snapshot);

        return $researcher;
    }

    /**
     * Parse Formação Acadêmica / Titulação, Pós-Doutorado e Formação Complementar.
     */
    private function parseEducations(\DOMXPath $xpath, Researcher $researcher): void
    {
        $sectionNodes = $xpath->query('//div[contains(@class, "title-wrapper") and (.//a[contains(@name, "Formacao") or contains(@name, "PosDoutorado")] or .//h1[contains(text(), "Formação") or contains(text(), "Pós-doutorado") or contains(text(), "Complementar")])]');
        
        foreach ($sectionNodes as $secWrapper) {
            $dataCell = $xpath->query('.//div[contains(@class, "data-cell")] | following-sibling::div[contains(@class, "data-cell")][1]', $secWrapper)->item(0);
            if (!$dataCell) continue;

            $yearCells = $xpath->query('.//div[contains(@class, "layout-cell-3")]', $dataCell);
            $detailCells = $xpath->query('.//div[contains(@class, "layout-cell-9")]', $dataCell);

            for ($i = 0; $i < $yearCells->length; $i++) {
                $yearText = trim($yearCells->item($i)->textContent);
                $detailText = $detailCells->item($i) ? trim($detailCells->item($i)->textContent) : '';
                if ($detailText === '') continue;

                $edu = new Education();
                $edu->setResearcher($researcher);

                if (preg_match('~\b(19\d{2}|20\d{2})\s*-\s*(19\d{2}|20\d{2}|Atual)\b~iu', $yearText . ' ' . $detailText, $m)) {
                    $edu->setStartYear((int)$m[1]);
                    if (is_numeric($m[2])) {
                        $edu->setEndYear((int)$m[2]);
                    }
                }

                $level = 'GRADUACAO';
                $lower = mb_strtolower($detailText);
                $secText = mb_strtolower($secWrapper->textContent);

                if (str_contains($secText, 'complementar') || str_contains($lower, 'workshop') || str_contains($lower, 'curta duração') || str_contains($lower, 'curso de curta') || str_contains($lower, 'extensão universitária')) {
                    $level = 'CURTA_DURACAO';
                } elseif (str_contains($lower, 'doutorado') && !str_contains($lower, 'pós-doutorado')) {
                    $level = 'DOUTORADO';
                } elseif (str_contains($lower, 'pós-doutorado') || str_contains($lower, 'pos-doutorado') || str_contains($secText, 'pós-doutorado')) {
                    $level = 'POS_DOUTORADO';
                } elseif (str_contains($lower, 'mestrado')) {
                    $level = 'MESTRADO';
                } elseif (str_contains($lower, 'especialização') || str_contains($lower, 'especializacao')) {
                    $level = 'ESPECIALIZACAO';
                } elseif (str_contains($lower, 'aperfeiçoamento')) {
                    $level = 'APERFEICOAMENTO';
                } elseif (str_contains($lower, 'graduação') || str_contains($lower, 'graduacao')) {
                    $level = 'GRADUACAO';
                }

                $edu->setLevel($level);

                if (preg_match('~(?:Doutorado|Mestrado|Graduação|Especialização|Pós-Doutorado)\s+em\s+([^.]+)\.~iu', $detailText, $cm)) {
                    $edu->setCourseName($this->truncate(trim($cm[1]), 255));
                } elseif (preg_match('~^([^.]+?)(?:\.|\s*\(Carga horária|\s*,\s*Ano)~u', $detailText, $cm2)) {
                    $edu->setCourseName($this->truncate(trim($cm2[1]), 255));
                }

                if (preg_match('~Título:\s*([^,<\n]+)~iu', $detailText, $tm)) {
                    $edu->setMonographTitle($this->truncate(trim($tm[1]), 500));
                }

                if (preg_match('~Orientador:\s*([^.<\n]+)~iu', $detailText, $om)) {
                    $edu->setAdvisorName($this->truncate(trim($om[1]), 255));
                }

                if (preg_match('~Carga horária:\s*(\d+)h~i', $detailText, $wm)) {
                    $edu->setWorkloadHours((int)$wm[1]);
                }

                if (preg_match('~Bolsista do\(a\):\s*([^,<\n]+)~iu', $detailText, $gm)) {
                    $edu->setGrantingAgency($this->truncate(trim($gm[1]), 150));
                }

                // Extract institution
                $lines = array_map('trim', explode("\n", $detailText));
                $inst = null;
                foreach ($lines as $line) {
                    if (str_contains($line, 'Universidade') || str_contains($line, 'Faculdade') || str_contains($line, 'Escola') || str_contains($line, 'Instituto') || str_contains($line, 'UFSCAR') || str_contains($line, 'USP') || str_contains($line, 'UNESP') || str_contains($line, 'UNICAMP') || str_contains($line, 'UFMG')) {
                        $inst = $line;
                        break;
                    }
                }
                $edu->setInstitutionName($this->truncate($inst ?: $detailText, 255));

                $this->em->persist($edu);
                $researcher->addEducation($edu);
            }
        }
    }

    /**
     * Parse Atuação Profissional comprehensively with dates, roles, contracts, workload and activities.
     */
    private function parseProfessionalExperiences(\DOMXPath $xpath, Researcher $researcher): void
    {
        $secWrapper = $xpath->query('//div[contains(@class, "title-wrapper") and (.//a[@name="AtuacaoProfissional"] or .//h1[contains(text(), "Atuação Profissional")])]')->item(0);
        if (!$secWrapper) return;

        $dataCell = $xpath->query('.//div[contains(@class, "data-cell")] | following-sibling::div[contains(@class, "data-cell")][1]', $secWrapper)->item(0);
        if (!$dataCell) return;

        $instNodes = $xpath->query('.//div[contains(@class, "inst_back")]', $dataCell);
        foreach ($instNodes as $instNode) {
            $instName = $this->truncate($instNode->textContent, 255);
            if (!$instName) continue;

            $currNode = $instNode->nextSibling;
            $hasCreatedAny = false;

            while ($currNode) {
                if ($currNode instanceof \DOMElement) {
                    if (str_contains($currNode->getAttribute('class'), 'inst_back')) {
                        break; // Reached next institution
                    }

                    $class = $currNode->getAttribute('class');
                    if (str_contains($class, 'layout-cell-3')) {
                        $cell3Text = trim(preg_replace('/\s+/', ' ', $currNode->textContent));
                        
                        // Check if this cell contains a date range (e.g. 2009 - Atual, 10/2020 - Atual, 2005 - 2009, 03/2004 - 02/2005)
                        if (preg_match('/\b(?:(\d{2}\/)?(19\d{2}|20\d{2}))\s*-\s*(?:(\d{2}\/)?(19\d{2}|20\d{2})|Atual)\b/iu', $cell3Text, $ym)) {
                            // Find corresponding layout-cell-9
                            $valNode = $currNode->nextSibling;
                            while ($valNode && (!($valNode instanceof \DOMElement) || !str_contains($valNode->getAttribute('class'), 'layout-cell-9'))) {
                                $valNode = $valNode->nextSibling;
                            }
                            $valText = $valNode ? trim(preg_replace('/\s+/', ' ', $valNode->textContent)) : '';

                            $exp = new ProfessionalExperience();
                            $exp->setResearcher($researcher);
                            $exp->setInstitutionName($instName);

                            $startYear = isset($ym[2]) ? (int)$ym[2] : (int)$ym[1];
                            $exp->setStartYear($startYear);

                            if (stripos($cell3Text, 'Atual') !== false) {
                                $exp->setIsCurrent(true);
                            } elseif (preg_match('/-\s*(?:(\d{2}\/)?(19\d{2}|20\d{2}))\b/u', $cell3Text, $eym)) {
                                $exp->setEndYear((int)$eym[2]);
                                $exp->setIsCurrent(false);
                            }

                            if (preg_match('/Enquadramento Funcional:\s*([^,<\n]+)/i', $valText, $rm)) {
                                $exp->setRoleName($this->truncate(trim($rm[1]), 255));
                            }
                            if (preg_match('/Vínculo:\s*([^,<\n]+)/i', $valText, $ctm)) {
                                $exp->setContractType($this->truncate(trim($ctm[1]), 150));
                            }
                            if (preg_match('/Carga horária:\s*(\d+)/i', $valText, $wm)) {
                                $exp->setWorkloadHours((int)$wm[1]);
                            }

                            $otherDetails = [];
                            if (stripos($valText, 'Dedicação exclusiva') !== false) {
                                $otherDetails[] = 'Regime: Dedicação exclusiva';
                            }
                            if ($valText !== '') {
                                $otherDetails[] = $valText;
                            }
                            $exp->setOtherInfo($this->truncate(implode(' | ', $otherDetails), 500));

                            $this->em->persist($exp);
                            $researcher->addProfessionalExperience($exp);
                            $hasCreatedAny = true;
                        }
                    }
                }
                $currNode = $currNode->nextSibling;
            }

            // Fallback: If no specific date rows found under institution, create base institution entry
            if (!$hasCreatedAny) {
                $exp = new ProfessionalExperience();
                $exp->setResearcher($researcher);
                $exp->setInstitutionName($instName);
                $this->em->persist($exp);
                $researcher->addProfessionalExperience($exp);
            }
        }
    }

    /**
     * Parse Projetos de Pesquisa, Extensão e Desenvolvimento.
     */
    private function parseProjects(\DOMXPath $xpath, Researcher $researcher): void
    {
        $projSections = $xpath->query('//div[contains(@class, "title-wrapper") and (.//a[@name="ProjetosPesquisa" or @name="ProjetosExtensao" or @name="ProjetosDesenvolvimento"] or .//h1[contains(text(), "Projetos")])]');
        
        foreach ($projSections as $secWrapper) {
            $secText = mb_strtolower($secWrapper->textContent);
            $nature = 'PESQUISA';
            if (str_contains($secText, 'extens') || str_contains($secText, 'extensão')) $nature = 'EXTENSAO';
            elseif (str_contains($secText, 'desenvolvimento')) $nature = 'DESENVOLVIMENTO';

            $dataCell = $xpath->query('.//div[contains(@class, "data-cell")] | following-sibling::div[contains(@class, "data-cell")][1]', $secWrapper)->item(0);
            if (!$dataCell) continue;

            $yearCells = $xpath->query('.//div[contains(@class, "layout-cell-3")]', $dataCell);
            $detailCells = $xpath->query('.//div[contains(@class, "layout-cell-9")]', $dataCell);

            for ($i = 0; $i < $yearCells->length; $i += 2) {
                $yearText = trim($yearCells->item($i)->textContent);
                $titleText = $detailCells->item($i) ? trim($detailCells->item($i)->textContent) : '';
                $descText = ($i + 1 < $detailCells->length && $detailCells->item($i + 1)) ? trim($detailCells->item($i + 1)->textContent) : '';

                if ($titleText === '' && $descText === '') continue;

                $proj = new ResearchProject();
                $proj->setResearcher($researcher);
                $proj->setNature($nature);
                $proj->setName($this->truncate($titleText ?: 'Projeto sem título', 500));

                if (preg_match('~\b(19\d{2}|20\d{2})\s*-\s*(19\d{2}|20\d{2}|Atual)\b~iu', $yearText, $ym)) {
                    $proj->setStartYear((int)$ym[1]);
                    if (is_numeric($ym[2])) {
                        $proj->setEndYear((int)$ym[2]);
                    }
                }

                $status = 'EM_ANDAMENTO';
                if (stripos($descText, 'Concluído') !== false || stripos($descText, 'Concluido') !== false) $status = 'CONCLUIDO';
                elseif (stripos($descText, 'Desativado') !== false) $status = 'DESATIVADO';
                $proj->setStatus($status);

                if (preg_match('~Descrição:\s*(.*?)(?:Situação:|Integrantes:|Financiador:|$)~us', $descText, $dm)) {
                    $proj->setDescription(trim($dm[1]));
                }
                if (preg_match('~Financiador\(es\):\s*([^.<\n]+)~iu', $descText, $fm)) {
                    $proj->setAgencyFinancier($this->truncate(trim($fm[1]), 255));
                }
                if (stripos($descText, 'Coordenador') !== false) {
                    $proj->setIsCoordinator(true);
                }

                $this->em->persist($proj);
                $researcher->addResearchProject($proj);
            }
        }
    }

    /**
     * Parse Áreas de Atuação.
     */
    private function parseKnowledgeAreas(\DOMXPath $xpath, Researcher $researcher): void
    {
        $secWrapper = $xpath->query('//div[contains(@class, "title-wrapper") and (.//a[@name="AreasAtuacao"] or .//h1[contains(text(), "Áreas de atuação")])]')->item(0);
        if (!$secWrapper) return;

        $dataCell = $xpath->query('.//div[contains(@class, "data-cell")] | following-sibling::div[contains(@class, "data-cell")][1]', $secWrapper)->item(0);
        if (!$dataCell) return;

        $nodes = $xpath->query('.//div[contains(@class, "layout-cell-9")]', $dataCell);
        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            if ($text === '') continue;

            $ka = new KnowledgeArea();
            $ka->setResearcher($researcher);

            if (preg_match('~Grande área:\s*([^/]+)~iu', $text, $gm)) {
                $ka->setMajorArea($this->truncate(trim($gm[1]), 150));
            }
            if (preg_match('~Área:\s*([^/]+)~iu', $text, $am)) {
                $ka->setArea($this->truncate(trim($am[1]), 150));
            }
            if (preg_match('~Subárea:\s*([^/]+)~iu', $text, $sm)) {
                $ka->setSubArea($this->truncate(trim($sm[1]), 150));
            }
            if (preg_match('~Especialidade:\s*([^.]+)~iu', $text, $em)) {
                $ka->setSpecialty($this->truncate(trim($em[1]), 255));
            }

            $this->em->persist($ka);
            $researcher->addKnowledgeArea($ka);
        }
    }

    /**
     * Parse Idiomas.
     */
    private function parseLanguages(\DOMXPath $xpath, Researcher $researcher): void
    {
        $secWrapper = $xpath->query('//div[contains(@class, "title-wrapper") and (.//a[@name="Idiomas"] or .//h1[contains(text(), "Idiomas")])]')->item(0);
        if (!$secWrapper) return;

        $dataCell = $xpath->query('.//div[contains(@class, "data-cell")] | following-sibling::div[contains(@class, "data-cell")][1]', $secWrapper)->item(0);
        if (!$dataCell) return;

        $langCells = $xpath->query('.//div[contains(@class, "layout-cell-3")]', $dataCell);
        $descCells = $xpath->query('.//div[contains(@class, "layout-cell-9")]', $dataCell);

        for ($i = 0; $i < $langCells->length; $i++) {
            $langName = trim($langCells->item($i)->textContent);
            $descText = $descCells->item($i) ? trim($descCells->item($i)->textContent) : '';
            if ($langName === '') continue;

            $lp = new LanguageProficiency();
            $lp->setResearcher($researcher);
            $lp->setLanguage($this->truncate($langName, 100));

            if (preg_match('~Compreende\s*([A-Za-zÀ-ÿ]+)~iu', $descText, $cm)) $lp->setComprehension($this->truncate(trim($cm[1]), 50));
            if (preg_match('~Fala\s*([A-Za-zÀ-ÿ]+)~iu', $descText, $sm)) $lp->setSpeaking($this->truncate(trim($sm[1]), 50));
            if (preg_match('~Lê\s*([A-Za-zÀ-ÿ]+)~iu', $descText, $rm)) $lp->setReading($this->truncate(trim($rm[1]), 50));
            if (preg_match('~Escreve\s*([A-Za-zÀ-ÿ]+)~iu', $descText, $wm)) $lp->setWriting($this->truncate(trim($wm[1]), 50));

            $this->em->persist($lp);
            $researcher->addLanguageProficiency($lp);
        }
    }

    /**
     * Parse Prêmios e Títulos.
     */
    private function parseAwards(\DOMXPath $xpath, Researcher $researcher): void
    {
        $secWrapper = $xpath->query('//div[contains(@class, "title-wrapper") and (.//a[@name="PremiosTitulos"] or .//h1[contains(text(), "Prêmios e títulos")])]')->item(0);
        if (!$secWrapper) return;

        $dataCell = $xpath->query('.//div[contains(@class, "data-cell")] | following-sibling::div[contains(@class, "data-cell")][1]', $secWrapper)->item(0);
        if (!$dataCell) return;

        $yearCells = $xpath->query('.//div[contains(@class, "layout-cell-3")]', $dataCell);
        $descCells = $xpath->query('.//div[contains(@class, "layout-cell-9")]', $dataCell);

        for ($i = 0; $i < $yearCells->length; $i++) {
            $yearText = trim($yearCells->item($i)->textContent);
            $descText = $descCells->item($i) ? trim($descCells->item($i)->textContent) : '';
            if ($descText === '') continue;

            $award = new Award();
            $award->setResearcher($researcher);
            if (preg_match('~\b(19\d{2}|20\d{2})\b~', $yearText, $ym)) {
                $award->setYear((int)$ym[1]);
            }

            $parts = explode(',', $descText);
            $award->setName($this->truncate(trim($parts[0]), 500));
            if (count($parts) > 1) {
                $award->setPromoterEntity($this->truncate(trim(end($parts)), 255));
            }

            $this->em->persist($award);
            $researcher->addAward($award);
        }
    }

    /**
     * Helper to classify section titles into standard Production Item Types.
     */
    private function classifyProductionSection(string $sectionTitle): ?string
    {
        $name = mb_strtolower($sectionTitle);

        // Skip non-production sections
        if (
            str_contains($name, 'formacao') || str_contains($name, 'formação') ||
            str_contains($name, 'atuacao') || str_contains($name, 'atuação') ||
            str_contains($name, 'projeto') || str_contains($name, 'premio') || str_contains($name, 'prêmio') ||
            str_contains($name, 'idioma') || str_contains($name, 'area') || str_contains($name, 'área') ||
            str_contains($name, 'orientac') || str_contains($name, 'orientaç') ||
            str_contains($name, 'banca') || str_contains($name, 'citac') || str_contains($name, 'citaç')
        ) {
            return null;
        }

        // Specific anchor / title matching for Books vs Chapters in Lattes
        if (str_contains($name, 'capituloslivros') || str_contains($name, 'capítulos de livros') || str_contains($name, 'capitulos de livros')) {
            return ProductionItem::TYPE_CAPITULO;
        }
        if (str_contains($name, 'livroscapitulos') || str_contains($name, 'livros publicados') || str_contains($name, 'livros organizados') || str_contains($name, 'livros/capitulos')) {
            return ProductionItem::TYPE_LIVRO;
        }
        if (str_contains($name, 'capitulo') || str_contains($name, 'capítulo')) return ProductionItem::TYPE_CAPITULO;
        if (str_contains($name, 'livro')) return ProductionItem::TYPE_LIVRO;

        // Articles
        if (str_contains($name, 'artigo')) return ProductionItem::TYPE_ARTIGO;

        // Newspaper / Magazine texts
        if (str_contains($name, 'jornal') || str_contains($name, 'revista') || str_contains($name, 'noticia') || str_contains($name, 'notícia')) return ProductionItem::TYPE_TEXTO_JORNAL;

        // Events / Congresses / Summaries / Presentations
        if (str_contains($name, 'trabalhopublicado') || str_contains($name, 'trabalhospublicados') || str_contains($name, 'congresso') || str_contains($name, 'anais') || str_contains($name, 'resumo') || str_contains($name, 'apresenta')) {
            if (str_contains($name, 'tecnico') || str_contains($name, 'técnico')) {
                return ProductionItem::TYPE_TRABALHO_TECNICO;
            }
            return ProductionItem::TYPE_EVENTO;
        }

        // Software
        if (str_contains($name, 'programa') && str_contains($name, 'computador') || str_contains($name, 'software')) return ProductionItem::TYPE_SOFTWARE;

        // Patents
        if (str_contains($name, 'patente') || str_contains($name, 'marca') || str_contains($name, 'cultivar')) return ProductionItem::TYPE_PATENTE;

        // Artistic
        if (str_contains($name, 'artistica') || str_contains($name, 'artística') || str_contains($name, 'cultural') || str_contains($name, 'musica') || str_contains($name, 'música') || str_contains($name, 'artes')) return ProductionItem::TYPE_ARTISTICA;

        // Technical productions
        if (str_contains($name, 'assessoria') || str_contains($name, 'consultoria') || str_contains($name, 'tecnico') || str_contains($name, 'técnico') || str_contains($name, 'tecnica') || str_contains($name, 'técnica') || str_contains($name, 'redes') || str_contains($name, 'website') || str_contains($name, 'blog') || str_contains($name, 'editoracao') || str_contains($name, 'editoração') || str_contains($name, 'relatorio') || str_contains($name, 'relatório') || str_contains($name, 'didatico') || str_contains($name, 'didático') || str_contains($name, 'curta duracao') || str_contains($name, 'curta duração') || str_contains($name, 'curso ministrado')) {
            return ProductionItem::TYPE_TRABALHO_TECNICO;
        }

        // Other
        if (str_contains($name, 'bibliografica') || str_contains($name, 'bibliográfica') || str_contains($name, 'outra')) return ProductionItem::TYPE_OUTRA;

        return null;
    }

    /**
     * Parse ALL Bibliographical, Technical and Artistic Productions comprehensively.
     */
    private function parseAllProductions(\DOMXPath $xpath, Researcher $researcher): void
    {
        $itemNodes = $xpath->query('//div[contains(@class, "artigo-completo")] | //div[contains(@class, "layout-cell-11")] | //div[contains(@class, "layout-cell-pad-5") and not(ancestor::div[contains(@class, "data-cell")]) and not(ancestor::div[@class="header"])]');

        $seenSignatures = [];

        foreach ($itemNodes as $node) {
            $rawText = trim($node->textContent);
            if ($rawText === '' || mb_strlen($rawText) < 10) continue;

            // Find the closest preceding section title
            $sectionNode = $xpath->query('(preceding::div[contains(@class, "cita-artigos")] | preceding::div[contains(@class, "title-wrapper")] | preceding::a[@name])[last()]', $node)->item(0);
            $sectionTitle = '';
            if ($sectionNode) {
                $sectionTitle = trim($sectionNode->textContent);
                if ($sectionTitle === '' && $sectionNode->hasAttribute('name')) {
                    $sectionTitle = $sectionNode->getAttribute('name');
                }
                if ($sectionTitle === '' && $sectionNode->parentNode) {
                    $sectionTitle = trim($sectionNode->parentNode->textContent);
                }
            }

            $itemType = $this->classifyProductionSection($sectionTitle);
            if (!$itemType) {
                // If under #artigos-completos, force TYPE_ARTIGO
                $ancestorArtigo = $xpath->query('ancestor::div[@id="artigos-completos"]', $node);
                if ($ancestorArtigo->length > 0) {
                    $itemType = ProductionItem::TYPE_ARTIGO;
                } else {
                    continue;
                }
            }

            // Deduplication signature
            $cleanForSig = preg_replace('~^\d+[\.\)]\s*~', '', $rawText);
            $sig = md5($itemType . '|' . mb_substr($cleanForSig, 0, 100));
            if (isset($seenSignatures[$sig])) continue;
            $seenSignatures[$sig] = true;

            $item = new ProductionItem();
            $item->setResearcher($researcher);
            $item->setItemType($itemType);

            // Year
            if (preg_match('~\b(19\d{2}|20\d{2})\b~', $rawText, $ym)) {
                $item->setYear((int)$ym[1]);
            }

            // DOI
            $doiNode = $xpath->query('.//a[contains(@href, "doi.org") or contains(@href, "dx.doi") or contains(@class, "icone-doi")]', $node)->item(0);
            if ($doiNode) {
                $href = $doiNode->getAttribute('href');
                if (preg_match('~(10\.\d{4,9}/[-._;()/:A-Za-z0-9]+)~', $href, $dm)) {
                    $item->setDoi($dm[1]);
                }
            }

            // Extract Authors, Title, Details
            $clean = preg_replace('~^\d+[\.\)]\s*~', '', trim($rawText));
            $clean = trim(preg_replace('~\s+~', ' ', $clean));

            $authorsStr = '';
            $title = '';
            $details = '';

            if (preg_match('~^(.*?)(?:\s+\.\s+|\.\s*\.\s+)(.*?)$~u', $clean, $pm)) {
                $authorsStr = trim($pm[1]);
                $rest = trim($pm[2]);
                $titleParts = preg_split('~\.\s+(?=[0-9A-Z]|In:|v\.|1\.|2\.|3\.|4\.|5\.|Ed\.|Editora|CADERNOS|REVISTA)~u', $rest, 2);
                $title = trim($titleParts[0]);
                $details = trim($titleParts[1] ?? '');
            } else {
                $title = $clean;
            }

            $title = preg_replace('~\.$~', '', $title);
            $item->setTitle($this->truncate($title ?: 'Produção Científica/Técnica', 500));

            // Extract specific details depending on Item Type
            if ($itemType === ProductionItem::TYPE_ARTIGO) {
                if ($details !== '') {
                    if (preg_match('~^([A-Za-zÀ-ÿ0-9\s\&\/\-\:\(\)\'\"]+?)(?:,|\.|\sv\.)~u', $details, $jm)) {
                        $item->setJournalName($this->truncate(trim($jm[1]), 255));
                    }
                }
                if (preg_match('~,\s*v\.\s*([A-Za-z0-9\-]+)~i', $rawText, $vm)) {
                    $item->setVolume($this->truncate($vm[1], 50));
                }
                if (preg_match('~,\s*n\.\s*([A-Za-z0-9\-]+)~i', $rawText, $im)) {
                    $item->setIssue($this->truncate($im[1], 50));
                }
                if (preg_match('~,\s*p\.\s*([0-9\-e\.]+)~i', $rawText, $pm)) {
                    $item->setPages($this->truncate($pm[1], 50));
                }
            } elseif ($itemType === ProductionItem::TYPE_LIVRO) {
                if (preg_match('~(?:\d+ed\.\s*)?([A-Za-zÀ-ÿ\s\.\-]+)\:\s*([A-Za-zÀ-ÿ0-9\s\.\&\-]+),\s*(19\d{2}|20\d{2})~u', $rawText, $bm)) {
                    $item->setPublisher($this->truncate(trim($bm[2]), 255));
                }
                if (preg_match('~v\.\s*([0-9\-]+)~i', $rawText, $vm)) {
                    $item->setVolume($this->truncate($vm[1], 50));
                }
                if (preg_match('~p\s*\.~i', $rawText, $pm)) {
                    $item->setPages($this->truncate(trim($pm[0]), 50));
                }
            } elseif ($itemType === ProductionItem::TYPE_CAPITULO) {
                if (preg_match('~In:\s*([^.]+)\.~u', $rawText, $cm)) {
                    $item->setJournalName($this->truncate(trim($cm[1]), 255));
                }
                if (preg_match('~(?:\d+ed\.\s*)?([A-Za-zÀ-ÿ\s\.\-]+)\:\s*([A-Za-zÀ-ÿ0-9\s\.\&\-]+),\s*(19\d{2}|20\d{2})~u', $rawText, $bm)) {
                    $item->setPublisher($this->truncate(trim($bm[2]), 255));
                }
                if (preg_match('~,\s*p\.\s*([0-9\-]+)~i', $rawText, $pm)) {
                    $item->setPages($this->truncate($pm[1], 50));
                }
            } elseif ($itemType === ProductionItem::TYPE_EVENTO) {
                if (preg_match('~In:\s*([^,]+)~u', $rawText, $em)) {
                    $item->setJournalName($this->truncate(trim($em[1]), 255));
                }
                if (str_contains(mb_strtolower($sectionTitle), 'expandido')) {
                    $item->setNature('RESUMO_EXPANDIDO');
                } elseif (str_contains(mb_strtolower($sectionTitle), 'resumo')) {
                    $item->setNature('RESUMO');
                } elseif (str_contains(mb_strtolower($sectionTitle), 'apresenta')) {
                    $item->setNature('APRESENTACAO');
                } else {
                    $item->setNature('COMPLETO');
                }
            } elseif ($itemType === ProductionItem::TYPE_TEXTO_JORNAL) {
                if ($details !== '') {
                    $parts = explode(',', $details);
                    $item->setJournalName($this->truncate(trim($parts[0]), 255));
                }
            } elseif ($itemType === ProductionItem::TYPE_TRABALHO_TECNICO) {
                if (str_contains(mb_strtolower($sectionTitle), 'assessoria') || str_contains(mb_strtolower($sectionTitle), 'consultoria')) {
                    $item->setNature('ASSESSORIA');
                } else {
                    $item->setNature('TRABALHO_TECNICO');
                }
            }

            // Parse authors
            if ($authorsStr !== '') {
                // Split by semicolon (ABNT standard delimiter between authors)
                $rawAuthors = preg_split('~;\s*~u', $authorsStr);
                $order = 1;
                foreach ($rawAuthors as $aName) {
                    $aName = trim(preg_replace('~\((?:Org|Ed|Coord)\.?\s*\)~iu', '', $aName));
                    $aName = trim(preg_replace('~^\d+[\.\)]\s*~', '', $aName));
                    if ($aName === '' || mb_strlen($aName) < 2) continue;

                    $pAuthor = new ProductionAuthor();
                    $pAuthor->setProductionItem($item);
                    $pAuthor->setAuthorName($this->truncate($aName, 255));
                    $pAuthor->setCitationName($this->truncate($aName, 255));
                    $pAuthor->setAuthorOrder($order++);
                    $item->addAuthor($pAuthor);
                    $this->em->persist($pAuthor);
                }
            }

            $this->em->persist($item);
            $researcher->addProduction($item);
        }
    }

    /**
     * Parse Orientações Concluídas e Em Andamento.
     */
    private function parseOrientations(\DOMXPath $xpath, Researcher $researcher): void
    {
        $orientSections = $xpath->query('//div[contains(@class, "title-wrapper") and (.//a[contains(@name, "Orientac") or contains(@name, "orientac")] or .//h1[contains(text(), "Orientaç") or contains(text(), "Orientac")])]');

        foreach ($orientSections as $secWrapper) {
            $secText = mb_strtolower($secWrapper->textContent);
            $isAndamento = str_contains($secText, 'andamento');
            
            $dataCell = $xpath->query('.//div[contains(@class, "data-cell")] | following-sibling::div[contains(@class, "data-cell")][1]', $secWrapper)->item(0);
            if (!$dataCell) continue;

            $itemNodes = $xpath->query('.//div[contains(@class, "layout-cell-11")] | .//div[contains(@class, "layout-cell-pad-5") and not(ancestor::div[contains(@class, "layout-cell-11")])]', $dataCell);

            foreach ($itemNodes as $node) {
                $text = trim($node->textContent);
                if ($text === '' || mb_strlen($text) < 10) continue;

                $orient = new Orientation();
                $orient->setResearcher($researcher);
                $orient->setNature($isAndamento ? Orientation::NATURE_EM_ANDAMENTO : Orientation::NATURE_CONCLUIDA);

                // Find closest preceding sub-header
                $closestSub = $xpath->query('(preceding::div[contains(@class, "cita-artigos")] | preceding::a[@name])[last()]', $node)->item(0);
                $subText = $closestSub ? mb_strtolower($closestSub->textContent . ' ' . $closestSub->getAttribute('name')) : '';
                $itemText = mb_strtolower($text);
                $combined = $subText . ' ' . $itemText;

                $type = Orientation::TYPE_OUTRA;
                if (str_contains($subText, 'doutorado') || str_contains($itemText, 'doutorado')) {
                    $type = Orientation::TYPE_DOUTORADO;
                } elseif (str_contains($subText, 'mestrado') || str_contains($itemText, 'dissertação (mestrado') || str_contains($itemText, '(mestrado em') || str_contains($itemText, 'mestrado')) {
                    $type = Orientation::TYPE_MESTRADO;
                } elseif (str_contains($subText, 'pós-doutorado') || str_contains($subText, 'pos-doutorado') || str_contains($itemText, 'pós-doutorado') || str_contains($itemText, 'pos-doutorado')) {
                    $type = Orientation::TYPE_POS_DOUTORADO;
                } elseif (str_contains($subText, 'iniciação') || str_contains($subText, 'iniciacao') || str_contains($subText, 'pibic') || str_contains($itemText, 'iniciação científica') || str_contains($itemText, 'iniciacao cientifica') || str_contains($itemText, '(iniciação científica')) {
                    $type = Orientation::TYPE_INICIACAO_CIENTIFICA;
                } elseif (str_contains($subText, 'graduação') || str_contains($subText, 'graduacao') || str_contains($subText, 'conclusão de curso') || str_contains($subText, 'tcc') || str_contains($itemText, 'trabalho de conclusão de curso') || str_contains($itemText, '(graduação em') || str_contains($itemText, 'graduação')) {
                    $type = Orientation::TYPE_TCC_GRADUACAO;
                } elseif (str_contains($subText, 'especialização') || str_contains($subText, 'especializacao') || str_contains($subText, 'aperfeiçoamento') || str_contains($itemText, 'especialização') || str_contains($itemText, 'aperfeiçoamento')) {
                    $type = Orientation::TYPE_ESPECIALIZACAO;
                }

                $orient->setOrientationType($type);

                if (preg_match('~\b(19\d{2}|20\d{2})\b~', $text, $ym)) {
                    $orient->setYear((int)$ym[1]);
                }

                $clean = preg_replace('~^\d+[\.\)]\s*~', '', $text);

                // Student name & Title
                $bNode = $xpath->query('.//b', $node)->item(0);
                if ($bNode) {
                    $orient->setTitle($this->truncate(trim($bNode->textContent), 500));
                    $parts = explode('<b>', $node->ownerDocument->saveHTML($node));
                    $studentRaw = strip_tags($parts[0] ?? '');
                    $orient->setStudentName($this->truncate(preg_replace('~^\d+[\.\)]\s*~', '', trim($studentRaw)) ?: 'Orientando', 255));
                } else {
                    $parts = explode('.', $clean, 2);
                    $orient->setStudentName($this->truncate(trim($parts[0]), 255));
                    $orient->setTitle($this->truncate(trim($parts[1] ?? $clean), 500));
                }

                // Institution
                if (preg_match('~-\s*([A-Za-zÀ-ÿ\s\.\/\-]+)(?:,|\.|$)~u', $text, $im)) {
                    $orient->setInstitutionName($this->truncate(trim($im[1]), 255));
                }

                $this->em->persist($orient);
                $researcher->addOrientation($orient);
            }
        }
    }

    /**
     * Parse Bancas Examinadoras.
     */
    private function parseExaminationBoards(\DOMXPath $xpath, Researcher $researcher): void
    {
        $boardSections = $xpath->query('//div[contains(@class, "title-wrapper") and (.//a[contains(@name, "Banca") or contains(@name, "banca")] or .//h1[contains(text(), "Banca") or contains(text(), "banca")])]');

        foreach ($boardSections as $secWrapper) {
            $secText = mb_strtolower($secWrapper->textContent);
            $dataCell = $xpath->query('.//div[contains(@class, "data-cell")] | following-sibling::div[contains(@class, "data-cell")][1]', $secWrapper)->item(0);
            if (!$dataCell) continue;

            $itemNodes = $xpath->query('.//div[contains(@class, "layout-cell-11")] | .//div[contains(@class, "layout-cell-pad-5") and not(ancestor::div[contains(@class, "layout-cell-11")])]', $dataCell);

            foreach ($itemNodes as $node) {
                $text = trim($node->textContent);
                if ($text === '' || mb_strlen($text) < 10) continue;

                $board = new ExaminationBoard();
                $board->setResearcher($researcher);

                if (preg_match('~\b(19\d{2}|20\d{2})\b~', $text, $ym)) {
                    $board->setYear((int)$ym[1]);
                }

                $type = 'MESTRADO';
                $lower = mb_strtolower($text . ' ' . $secText);
                if (str_contains($lower, 'doutorado')) $type = 'DOUTORADO';
                elseif (str_contains($lower, 'qualificação') || str_contains($lower, 'qualificacao')) $type = 'QUALIFICACAO';
                elseif (str_contains($lower, 'graduação') || str_contains($lower, 'graduacao') || str_contains($lower, 'tcc')) $type = 'GRADUACAO';
                elseif (str_contains($lower, 'concurso') || str_contains($lower, 'comissão julgadora') || str_contains($lower, 'comissoes julgadoras')) $type = 'CONCURSO';
                $board->setBoardType($type);

                if (preg_match('~banca\s+(?:examinadora\s+)?de\s+([^.]+?)(?:\.|\s*<b>)~iu', $text, $cm)) {
                    $board->setCandidateName($this->truncate(trim($cm[1]), 255));
                }

                $bNode = $xpath->query('.//b', $node)->item(0);
                if ($bNode && trim($bNode->textContent) !== '') {
                    $board->setTitle($this->truncate(trim($bNode->textContent), 500));
                } else {
                    $clean = preg_replace('~^\d+[\.\)]\s*~', '', $text);
                    $board->setTitle($this->truncate($clean, 500));
                }

                if (preg_match('~-\s*([A-Za-zÀ-ÿ\s\.\/\-]+)(?:,|\.|$)~u', $text, $im)) {
                    $board->setInstitutionName($this->truncate(trim($im[1]), 255));
                }

                $this->em->persist($board);
                $researcher->addExaminationBoard($board);
            }
        }
    }

    /**
     * Parse Eventos (Participação e Organização).
     */
    private function parseEventParticipations(\DOMXPath $xpath, Researcher $researcher): void
    {
        $eventSections = $xpath->query('//div[contains(@class, "title-wrapper") and (.//a[contains(@name, "Evento") or contains(@name, "evento")] or .//h1[contains(text(), "Evento") or contains(text(), "evento")])]');

        foreach ($eventSections as $secWrapper) {
            $secText = mb_strtolower($secWrapper->textContent);
            $isOrg = str_contains($secText, 'organiza') || str_contains($secText, 'organizou');

            $dataCell = $xpath->query('.//div[contains(@class, "data-cell")] | following-sibling::div[contains(@class, "data-cell")][1]', $secWrapper)->item(0);
            if (!$dataCell) continue;

            $itemNodes = $xpath->query('.//div[contains(@class, "layout-cell-11")] | .//div[contains(@class, "layout-cell-pad-5") and not(ancestor::div[contains(@class, "layout-cell-11")])]', $dataCell);

            foreach ($itemNodes as $node) {
                $text = trim($node->textContent);
                if ($text === '' || mb_strlen($text) < 10) continue;

                $ep = new EventParticipation();
                $ep->setResearcher($researcher);
                $ep->setParticipationType($isOrg ? 'ORGANIZACAO' : 'PARTICIPACAO');

                if (preg_match('~\b(19\d{2}|20\d{2})\b~', $text, $ym)) {
                    $ep->setYear((int)$ym[1]);
                }

                $clean = preg_replace('~^\d+[\.\)]\s*~', '', $text);
                $ep->setEventName($this->truncate($clean, 500));

                if (preg_match('~\((Congresso|Simpósio|Seminário|Encontro|Oficina|Outro)\)~iu', $text, $etm)) {
                    $ep->setEventType($this->truncate($etm[1], 100));
                }

                $this->em->persist($ep);
                $researcher->addEventParticipation($ep);
            }
        }
    }
}
