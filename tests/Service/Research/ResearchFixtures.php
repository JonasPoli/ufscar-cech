<?php

declare(strict_types=1);

namespace App\Tests\Service\Research;

use Doctrine\DBAL\Connection;

/**
 * Dados mínimos para os testes da pesquisa pública. Tudo é gravado dentro da transação do teste e revertido no
 * tearDown; os nomes levam um sufixo único ($t) e um departamento próprio, para não se misturar com o banco real.
 */
trait ResearchFixtures
{
    private Connection $conn;
    private string $t;

    private function newToken(): string
    {
        return 'zq' . strtr(bin2hex(random_bytes(5)), '0123456789', 'ghijklmnop');
    }

    private function dept(): string
    {
        return 'Z' . strtoupper(substr($this->t, 2, 6));
    }

    private function insertResearcher(string $name, ?string $dept = null, int $status = 1, ?int $start = 2010, ?int $end = 2026): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->insert('researchers', [
            'id_lattes' => '97' . str_pad((string)random_int(0, 99999999999999), 14, '0', STR_PAD_LEFT),
            'full_name' => $name,
            'slug' => 'teste-' . strtolower($this->t) . '-' . substr(md5($name), 0, 8),
            'department_code' => $dept ?? $this->dept(),
            'department' => 'Departamento Teste ' . $this->t,
            'status' => $status,
            'admission_year' => $start,
            'leave_year' => $end,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    /** @param list<string> $keywords */
    private function insertItem(int $researcherId, string $title, int $year = 2020, string $type = 'ARTIGO', ?string $doi = null, array $keywords = [], ?string $journal = null): int
    {
        $now = date('Y-m-d H:i:s');
        $this->conn->insert('production_items', [
            'researcher_id' => $researcherId,
            'item_type' => $type,
            'title' => $title,
            'year' => $year,
            'doi' => $doi,
            'journal_name' => $journal,
            'extra_data' => $keywords !== [] ? json_encode(['keywords' => $keywords], JSON_UNESCAPED_UNICODE) : null,
            'is_scientific_dissemination' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    private function insertAuthor(int $itemId, string $name, ?int $matchedResearcherId, int $order = 1): void
    {
        $this->conn->insert('production_authors', [
            'production_item_id' => $itemId,
            'author_name' => $name,
            'author_order' => $order,
            'matched_researcher_id' => $matchedResearcherId,
            'is_cech_researcher' => $matchedResearcherId !== null ? 1 : 0,
            'is_indexed' => 0,
        ]);
    }

    private function insertKnowledgeArea(int $researcherId, string $majorArea, string $area): void
    {
        $this->conn->insert('knowledge_areas', [
            'researcher_id' => $researcherId,
            'major_area' => $majorArea,
            'area' => $area,
        ]);
    }

    /**
     * Uma publicação em coautoria: um registro no currículo de cada docente, com os dois como autores casados.
     *
     * @param list<int> $researcherIds
     * @return list<int> ids dos registros criados
     */
    private function insertSharedWork(array $researcherIds, string $title, int $year = 2021, ?string $doi = null): array
    {
        $ids = [];
        foreach ($researcherIds as $owner) {
            $itemId = $this->insertItem($owner, $title, $year, 'ARTIGO', $doi);
            foreach ($researcherIds as $order => $author) {
                $this->insertAuthor($itemId, 'Autor ' . $author, $author, $order + 1);
            }
            $ids[] = $itemId;
        }

        return $ids;
    }
}
