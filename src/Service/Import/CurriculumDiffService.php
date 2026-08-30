<?php

namespace App\Service\Import;

use App\Entity\Award;
use App\Entity\Orientation;
use App\Entity\ProductionItem;
use App\Entity\Researcher;
use App\Entity\ResearchProject;

/**
 * Serviço responsável por capturar snapshots do currículo e calcular o relatório
 * detalhado de alterações (diff report) durante importação/sincronização Lattes.
 */
class CurriculumDiffService
{
    /**
     * Captura um snapshot do estado atual do pesquisador antes da limpeza/re-parseamento.
     */
    public function takeSnapshot(?Researcher $researcher): array
    {
        if (!$researcher || !$researcher->getId()) {
            return [
                'exists' => false,
                'productions' => [],
                'orientations' => [],
                'projects' => [],
                'awards' => [],
            ];
        }

        $productions = [];
        foreach ($researcher->getProductions() as $p) {
            $key = $this->makeProductionKey($p->getItemType(), $p->getTitle(), $p->getYear());
            $productions[$key] = true;
        }

        $orientations = [];
        foreach ($researcher->getOrientations() as $o) {
            $key = $this->makeOrientationKey($o->getOrientationType(), $o->getStudentName(), $o->getYear());
            $orientations[$key] = true;
        }

        $projects = [];
        foreach ($researcher->getResearchProjects() as $pr) {
            $key = $this->makeProjectKey($pr->getName(), $pr->getStartYear());
            $projects[$key] = true;
        }

        $awards = [];
        foreach ($researcher->getAwards() as $a) {
            $key = $this->makeAwardKey($a->getName(), $a->getYear());
            $awards[$key] = true;
        }

        return [
            'exists' => true,
            'productions' => $productions,
            'orientations' => $orientations,
            'projects' => $projects,
            'awards' => $awards,
        ];
    }

    /**
     * Compara o estado novo do pesquisador com o snapshot e gera o relatório detalhado.
     */
    public function computeReport(Researcher $researcher, array $snapshot): array
    {
        $isNew = !$snapshot['exists'];

        $newArticles = [];
        $newBooks = [];
        $newChapters = [];
        $newEvents = [];
        $newOtherProds = [];

        foreach ($researcher->getProductions() as $p) {
            $key = $this->makeProductionKey($p->getItemType(), $p->getTitle(), $p->getYear());
            if ($isNew || !isset($snapshot['productions'][$key])) {
                $itemData = [
                    'type' => $p->getItemType(),
                    'title' => $p->getTitle(),
                    'year' => $p->getYear(),
                    'journal' => $p->getJournalName(),
                    'publisher' => $p->getPublisher(),
                    'doi' => $p->getDoi(),
                    'qualis' => $p->getQualis(),
                ];

                match ($p->getItemType()) {
                    ProductionItem::TYPE_ARTIGO => $newArticles[] = $itemData,
                    ProductionItem::TYPE_LIVRO => $newBooks[] = $itemData,
                    ProductionItem::TYPE_CAPITULO => $newChapters[] = $itemData,
                    ProductionItem::TYPE_EVENTO => $newEvents[] = $itemData,
                    default => $newOtherProds[] = $itemData,
                };
            }
        }

        $newOrientations = [];
        foreach ($researcher->getOrientations() as $o) {
            $key = $this->makeOrientationKey($o->getOrientationType(), $o->getStudentName(), $o->getYear());
            if ($isNew || !isset($snapshot['orientations'][$key])) {
                $newOrientations[] = [
                    'type' => $o->getOrientationType(),
                    'nature' => $o->getNature(),
                    'student' => $o->getStudentName(),
                    'title' => $o->getTitle(),
                    'year' => $o->getYear(),
                    'institution' => $o->getInstitutionName(),
                ];
            }
        }

        $newProjects = [];
        foreach ($researcher->getResearchProjects() as $pr) {
            $key = $this->makeProjectKey($pr->getName(), $pr->getStartYear());
            if ($isNew || !isset($snapshot['projects'][$key])) {
                $newProjects[] = [
                    'name' => $pr->getName(),
                    'startYear' => $pr->getStartYear(),
                ];
            }
        }

        $newAwards = [];
        foreach ($researcher->getAwards() as $a) {
            $key = $this->makeAwardKey($a->getName(), $a->getYear());
            if ($isNew || !isset($snapshot['awards'][$key])) {
                $newAwards[] = [
                    'name' => $a->getName(),
                    'year' => $a->getYear(),
                ];
            }
        }

        $totalAdded = count($newArticles) + count($newBooks) + count($newChapters)
            + count($newEvents) + count($newOtherProds) + count($newOrientations)
            + count($newProjects) + count($newAwards);

        $summary = [
            'totalAdded' => $totalAdded,
            'articles' => count($newArticles),
            'books' => count($newBooks),
            'chapters' => count($newChapters),
            'events' => count($newEvents),
            'otherProductions' => count($newOtherProds),
            'orientations' => count($newOrientations),
            'projects' => count($newProjects),
            'awards' => count($newAwards),
        ];

        $summaryMessage = $this->buildSummaryMessage($isNew, $researcher->getFullName(), $summary);

        return [
            'isNewResearcher' => $isNew,
            'researcher' => [
                'id' => $researcher->getId(),
                'fullName' => $researcher->getFullName(),
                'idLattes' => $researcher->getIdLattes(),
            ],
            'totalProductions' => count($researcher->getProductions()),
            'totalOrientations' => count($researcher->getOrientations()),
            'summary' => $summary,
            'summaryMessage' => $summaryMessage,
            'addedItems' => [
                'articles' => $newArticles,
                'books' => $newBooks,
                'chapters' => $newChapters,
                'events' => $newEvents,
                'otherProductions' => $newOtherProds,
                'orientations' => $newOrientations,
                'projects' => $newProjects,
                'awards' => $newAwards,
            ],
        ];
    }

    private function makeProductionKey(string $type, string $title, ?int $year): string
    {
        $normTitle = mb_strtolower(trim(preg_replace('/\s+/', ' ', $title)));
        return "{$type}|{$normTitle}|" . ((int)$year);
    }

    private function makeOrientationKey(string $type, string $studentName, ?int $year): string
    {
        $normName = mb_strtolower(trim(preg_replace('/\s+/', ' ', $studentName)));
        return "{$type}|{$normName}|" . ((int)$year);
    }

    private function makeProjectKey(string $name, ?int $year): string
    {
        $normName = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        return "{$normName}|" . ((int)$year);
    }

    private function makeAwardKey(string $name, ?int $year): string
    {
        $normName = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        return "{$normName}|" . ((int)$year);
    }

    private function buildSummaryMessage(bool $isNew, string $name, array $summary): string
    {
        if ($isNew) {
            return "Docente {$name} cadastrado com sucesso! Total importado: {$summary['articles']} artigos, {$summary['books']} livros, {$summary['chapters']} capítulos, {$summary['events']} eventos e {$summary['orientations']} orientações.";
        }

        if ($summary['totalAdded'] === 0) {
            return "Currículo de {$name} sincronizado! Nenhuma nova produção ou orientação identificada (dados já atualizados).";
        }

        $parts = [];
        if ($summary['articles'] > 0) $parts[] = "{$summary['articles']} " . ($summary['articles'] === 1 ? 'artigo' : 'artigos');
        if ($summary['books'] > 0) $parts[] = "{$summary['books']} " . ($summary['books'] === 1 ? 'livro' : 'livros');
        if ($summary['chapters'] > 0) $parts[] = "{$summary['chapters']} " . ($summary['chapters'] === 1 ? 'capítulo' : 'capítulos');
        if ($summary['events'] > 0) $parts[] = "{$summary['events']} " . ($summary['events'] === 1 ? 'trabalho em evento' : 'trabalhos em eventos');
        if ($summary['otherProductions'] > 0) $parts[] = "{$summary['otherProductions']} " . ($summary['otherProductions'] === 1 ? 'outra produção' : 'outras produções');
        if ($summary['orientations'] > 0) $parts[] = "{$summary['orientations']} " . ($summary['orientations'] === 1 ? 'orientação' : 'orientações');
        if ($summary['projects'] > 0) $parts[] = "{$summary['projects']} " . ($summary['projects'] === 1 ? 'projeto de pesquisa' : 'projetos de pesquisa');
        if ($summary['awards'] > 0) $parts[] = "{$summary['awards']} " . ($summary['awards'] === 1 ? 'prêmio/título' : 'prêmios/títulos');

        return "Currículo de {$name} atualizado! Adicionado(s): " . implode(', ', $parts) . '.';
    }
}
