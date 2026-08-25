<?php

namespace App\Service\Export;

use App\Entity\ProductionItem;
use App\Entity\Researcher;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Serviço de exportação de dados curriculares e produções científicas.
 *
 * Suporta a geração de arquivos nos formatos:
 * - JSON (.json): Estrutura completa de dados biográficos, formações, produções e orientações.
 * - CSV (.csv): Resumo tabular formatado com delimitador ponto-e-vírgula e UTF-8 BOM.
 * - BibTeX (.bib): Citações bibliográficas padronizadas de artigos, livros e capítulos.
 * - HTML / PDF: Template estilizado para impressão e download de currículos individuais.
 */
class CurriculumExporterService
{
    /**
     * @param Environment $twig Motor de templates Twig para renderização do layout de impressão
     */
    public function __construct(
        private readonly Environment $twig
    ) {}

    /**
     * Exporta um array de pesquisadores para resposta HTTP em formato JSON.
     *
     * @param array<Researcher> $researchers Lista de pesquisadores
     * @return Response Resposta com cabeçalhos de download JSON
     */
    public function exportJson(array $researchers): Response
    {
        $data = [];
        foreach ($researchers as $r) {
            $data[] = $this->serializeResearcher($r);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = new Response($json);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="curriculos_cech.json"');
        return $response;
    }

    public function exportCsv(array $researchers): Response
    {
        $csv = Writer::fromString('');
        $csv->setOutputBOM(Writer::BOM_UTF8);
        $csv->setDelimiter(';');
        $csv->insertOne([
            'id_lattes',
            'full_name',
            'citation_names',
            'department',
            'unit',
            'orcid',
            'email',
            'birth_city',
            'birth_state',
            'birth_country',
            'admission_year',
            'leave_year',
            'total_productions',
            'total_orientations',
            'last_lattes_update',
        ]);

        foreach ($researchers as $r) {
            $csv->insertOne([
                $r->getIdLattes(),
                $r->getFullName(),
                $r->getCitationNames(),
                $r->getDepartment(),
                $r->getUnit(),
                $r->getOrcid(),
                $r->getEmail(),
                $r->getBirthCity(),
                $r->getBirthState(),
                $r->getBirthCountry(),
                $r->getAdmissionYear(),
                $r->getLeaveYear(),
                $r->getProductions()->count(),
                $r->getOrientations()->count(),
                $r->getLastLattesUpdate() ? $r->getLastLattesUpdate()->format('Y-m-d') : '',
            ]);
        }

        $response = new Response("\xEF\xBB\xBF" . $csv->toString());
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="curriculos_cech.csv"');
        return $response;
    }

    public function exportXml(array $researchers): Response
    {
        $xml = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\"?><curriculos></curriculos>");

        foreach ($researchers as $r) {
            $node = $xml->addChild('curriculo');
            $node->addChild('id_lattes', htmlspecialchars($r->getIdLattes()));
            $node->addChild('nome_completo', htmlspecialchars($r->getFullName()));
            $node->addChild('nomes_citacao', htmlspecialchars($r->getCitationNames() ?? ''));
            $node->addChild('departamento', htmlspecialchars($r->getDepartment() ?? ''));
            $node->addChild('orcid', htmlspecialchars($r->getOrcid() ?? ''));
            $node->addChild('email', htmlspecialchars($r->getEmail() ?? ''));
            $node->addChild('resumo', htmlspecialchars($r->getAbstractResume() ?? ''));

            $prodsNode = $node->addChild('producoes');
            foreach ($r->getProductions() as $p) {
                $pNode = $prodsNode->addChild('producao');
                $pNode->addChild('tipo', htmlspecialchars($p->getItemType()));
                $pNode->addChild('titulo', htmlspecialchars($p->getTitle()));
                $pNode->addChild('ano', (string)$p->getYear());
                $pNode->addChild('doi', htmlspecialchars($p->getDoi() ?? ''));
            }

            $orientsNode = $node->addChild('orientacoes');
            foreach ($r->getOrientations() as $o) {
                $oNode = $orientsNode->addChild('orientacao');
                $oNode->addChild('tipo', htmlspecialchars($o->getOrientationType()));
                $oNode->addChild('natureza', htmlspecialchars($o->getNature()));
                $oNode->addChild('aluno', htmlspecialchars($o->getStudentName()));
                $oNode->addChild('titulo', htmlspecialchars($o->getTitle() ?? ''));
                $oNode->addChild('ano', (string)$o->getYear());
            }
        }

        $response = new Response($xml->asXML());
        $response->headers->set('Content-Type', 'application/xml; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="curriculos_cech.xml"');
        return $response;
    }

    public function exportPdf(array $researchers): Response
    {
        $html = $this->twig->render('admin/curriculum/export_pdf.html.twig', [
            'researchers' => $researchers,
            'generated_at' => new \DateTimeImmutable(),
        ]);

        if (class_exists(\Dompdf\Dompdf::class)) {
            $options = new \Dompdf\Options();
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('isRemoteEnabled', true);

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return new Response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="curriculos_cech.pdf"',
            ]);
        }

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function exportBibtex(Researcher $r): Response
    {
        $bibtex = "% =========================================================\n";
        $bibtex .= "% Acervo de Produções Científicas e Acadêmicas\n";
        $bibtex .= "% Pesquisador: " . $r->getFullName() . "\n";
        $bibtex .= "% ID Lattes: " . $r->getIdLattes() . "\n";
        $bibtex .= "% Exportado de: Portal CECH - UFSCar\n";
        $bibtex .= "% =========================================================\n\n";

        $count = 1;
        foreach ($r->getProductions() as $p) {
            $year = $p->getYear() ?: 's.d.';
            $authors = [];
            foreach ($p->getAuthors() as $a) {
                $authors[] = $a->getCitationName() ?: $a->getAuthorName();
            }
            if (empty($authors)) {
                $authors[] = $r->getFullName();
            }
            $authorStr = implode(' and ', $authors);
            $key = preg_replace('/[^a-zA-Z0-9]/', '', substr($r->getFullName(), 0, 8)) . $year . '_' . $count++;

            $title = addcslashes($p->getTitle(), '"{}\\$');

            match ($p->getItemType()) {
                ProductionItem::TYPE_ARTIGO => (function() use (&$bibtex, $key, $authorStr, $title, $p, $year) {
                    $bibtex .= "@article{{$key},\n";
                    $bibtex .= "  author = {{$authorStr}},\n";
                    $bibtex .= "  title = {{$title}},\n";
                    if ($p->getJournalName()) $bibtex .= "  journal = {" . addcslashes($p->getJournalName(), '"{}\\$') . "},\n";
                    $bibtex .= "  year = {{$year}},\n";
                    if ($p->getVolume()) $bibtex .= "  volume = {{$p->getVolume()}},\n";
                    if ($p->getIssue()) $bibtex .= "  number = {{$p->getIssue()}},\n";
                    if ($p->getPages()) $bibtex .= "  pages = {" . str_replace('-', '--', $p->getPages()) . "},\n";
                    if ($p->getDoi()) $bibtex .= "  doi = {{$p->getDoi()}},\n";
                    if ($p->getIssn()) $bibtex .= "  issn = {{$p->getIssn()}},\n";
                    $bibtex .= "}\n\n";
                })(),

                ProductionItem::TYPE_LIVRO => (function() use (&$bibtex, $key, $authorStr, $title, $p, $year) {
                    $bibtex .= "@book{{$key},\n";
                    $bibtex .= "  author = {{$authorStr}},\n";
                    $bibtex .= "  title = {{$title}},\n";
                    if ($p->getPublisher()) $bibtex .= "  publisher = {" . addcslashes($p->getPublisher(), '"{}\\$') . "},\n";
                    $bibtex .= "  year = {{$year}},\n";
                    if ($p->getIsbn()) $bibtex .= "  isbn = {{$p->getIsbn()}},\n";
                    if ($p->getDoi()) $bibtex .= "  doi = {{$p->getDoi()}},\n";
                    $bibtex .= "}\n\n";
                })(),

                ProductionItem::TYPE_CAPITULO => (function() use (&$bibtex, $key, $authorStr, $title, $p, $year) {
                    $bibtex .= "@incollection{{$key},\n";
                    $bibtex .= "  author = {{$authorStr}},\n";
                    $bibtex .= "  title = {{$title}},\n";
                    if ($p->getJournalName()) $bibtex .= "  booktitle = {" . addcslashes($p->getJournalName(), '"{}\\$') . "},\n";
                    if ($p->getPublisher()) $bibtex .= "  publisher = {" . addcslashes($p->getPublisher(), '"{}\\$') . "},\n";
                    $bibtex .= "  year = {{$year}},\n";
                    if ($p->getPages()) $bibtex .= "  pages = {" . str_replace('-', '--', $p->getPages()) . "},\n";
                    if ($p->getDoi()) $bibtex .= "  doi = {{$p->getDoi()}},\n";
                    $bibtex .= "}\n\n";
                })(),

                ProductionItem::TYPE_EVENTO => (function() use (&$bibtex, $key, $authorStr, $title, $p, $year) {
                    $bibtex .= "@inproceedings{{$key},\n";
                    $bibtex .= "  author = {{$authorStr}},\n";
                    $bibtex .= "  title = {{$title}},\n";
                    if ($p->getEventName() ?: $p->getJournalName()) $bibtex .= "  booktitle = {" . addcslashes($p->getEventName() ?: $p->getJournalName(), '"{}\\$') . "},\n";
                    $bibtex .= "  year = {{$year}},\n";
                    if ($p->getDoi()) $bibtex .= "  doi = {{$p->getDoi()}},\n";
                    $bibtex .= "}\n\n";
                })(),

                default => (function() use (&$bibtex, $key, $authorStr, $title, $p, $year) {
                    $bibtex .= "@misc{{$key},\n";
                    $bibtex .= "  author = {{$authorStr}},\n";
                    $bibtex .= "  title = {{$title}},\n";
                    $bibtex .= "  year = {{$year}},\n";
                    $bibtex .= "  note = {" . addcslashes($p->getItemType() . ($p->getNature() ? ' - ' . $p->getNature() : ''), '"{}\\$') . "},\n";
                    if ($p->getDoi()) $bibtex .= "  doi = {{$p->getDoi()}},\n";
                    $bibtex .= "}\n\n";
                })(),
            };
        }

        $filename = 'producoes_' . ($r->getSlug() ?: $r->getIdLattes()) . '.bib';
        $response = new Response($bibtex);
        $response->headers->set('Content-Type', 'application/x-bibtex; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $response;
    }

    public function exportSingleJson(Researcher $r): Response
    {
        $data = $this->serializeResearcher($r);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $filename = 'curriculo_' . ($r->getSlug() ?: $r->getIdLattes()) . '.json';
        $response = new Response($json);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $response;
    }

    public function exportSingleCsv(Researcher $r): Response
    {
        $csv = Writer::fromString('');
        $csv->setOutputBOM(Writer::BOM_UTF8);
        $csv->setDelimiter(';');
        $csv->insertOne([
            'id_producao',
            'tipo',
            'natureza',
            'titulo',
            'ano',
            'periodico_livro_evento',
            'volume',
            'numero',
            'paginas',
            'doi',
            'qualis',
            'autores',
        ]);

        foreach ($r->getProductions() as $p) {
            $authors = [];
            foreach ($p->getAuthors() as $a) {
                $authors[] = $a->getCitationName() ?: $a->getAuthorName();
            }
            $csv->insertOne([
                $p->getId(),
                $p->getItemType(),
                $p->getNature(),
                $p->getTitle(),
                $p->getYear(),
                $p->getJournalName() ?: $p->getPublisher() ?: $p->getEventName(),
                $p->getVolume(),
                $p->getIssue(),
                $p->getPages(),
                $p->getDoi(),
                $p->getQualis(),
                implode('; ', $authors),
            ]);
        }

        $filename = 'producoes_' . ($r->getSlug() ?: $r->getIdLattes()) . '.csv';
        $response = new Response("\xEF\xBB\xBF" . $csv->toString());
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $response;
    }

    private function serializeResearcher(Researcher $r): array
    {
        $productions = [];
        foreach ($r->getProductions() as $p) {
            $authors = [];
            foreach ($p->getAuthors() as $a) {
                $authors[] = [
                    'name' => $a->getAuthorName(),
                    'citation' => $a->getCitationName(),
                    'order' => $a->getAuthorOrder(),
                ];
            }

            $productions[] = [
                'type' => $p->getItemType(),
                'title' => $p->getTitle(),
                'year' => $p->getYear(),
                'doi' => $p->getDoi(),
                'doi_url' => $p->getDoiUrl(),
                'journal' => $p->getJournalName(),
                'volume' => $p->getVolume(),
                'issue' => $p->getIssue(),
                'pages' => $p->getPages(),
                'issn' => $p->getIssn(),
                'qualis' => $p->getQualis(),
                'authors' => $authors,
                'publisher' => $p->getPublisher(),
                'isbn' => $p->getIsbn(),
                'event_name' => $p->getEventName(),
                'event_city' => $p->getEventCity(),
                'nature' => $p->getNature(),
            ];
        }

        $educations = [];
        foreach ($r->getEducations() as $e) {
            $educations[] = [
                'level' => $e->getLevel(),
                'course' => $e->getCourseName(),
                'institution' => $e->getInstitutionName(),
                'start_year' => $e->getStartYear(),
                'end_year' => $e->getEndYear(),
                'title' => $e->getMonographTitle(),
                'advisor' => $e->getAdvisorName(),
            ];
        }

        $orientations = [];
        foreach ($r->getOrientations() as $o) {
            $orientations[] = [
                'type' => $o->getOrientationType(),
                'nature' => $o->getNature(),
                'student' => $o->getStudentName(),
                'title' => $o->getTitle(),
                'year' => $o->getYear(),
                'institution' => $o->getInstitutionName(),
            ];
        }

        return [
            'id_lattes' => $r->getIdLattes(),
            'full_name' => $r->getFullName(),
            'citation_names' => $r->getCitationNames(),
            'slug' => $r->getSlug(),
            'orcid' => $r->getOrcid(),
            'email' => $r->getEmail(),
            'department' => $r->getDepartment(),
            'department_code' => $r->getDepartmentCode(),
            'unit' => $r->getUnit(),
            'admission_year' => $r->getAdmissionYear(),
            'leave_year' => $r->getLeaveYear(),
            'nationality' => $r->getNationality(),
            'birth_city' => $r->getBirthCity(),
            'birth_state' => $r->getBirthState(),
            'birth_country' => $r->getBirthCountry(),
            'abstract_resume' => $r->getAbstractResume(),
            'last_lattes_update' => $r->getLastLattesUpdate() ? $r->getLastLattesUpdate()->format('Y-m-d') : null,
            'productions' => $productions,
            'educations' => $educations,
            'orientations' => $orientations,
        ];
    }
}
