<?php

declare(strict_types=1);

namespace App\Service\Project;

use Doctrine\DBAL\Connection;

/**
 * Colunas calculadas do catálogo de projetos:
 *
 * - `research_projects.project_group_key` ({@see ProjectGroupKey}): une o mesmo projeto declarado em vários currículos;
 * - `research_project_members.matched_researcher_id`: o docente do banco com o mesmo ID Lattes do integrante.
 *
 * A importação já grava as duas para o currículo importado; isto refaz tudo (ou um docente) depois de mudar a regra,
 * de preencher os projetos antigos ou de incluir/excluir docentes. Só escreve onde o valor muda, então rodar de novo
 * não altera nada. Nenhum dado bruto é tocado.
 */
class ProjectIndexService
{
    private const BATCH = 500;

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @param string|null $idLattes Só os projetos deste docente e os integrantes que têm o ID dele
     *
     * @return array{group_keys_updated: int, members_relinked: int}
     */
    public function index(?string $idLattes = null): array
    {
        $researcherId = null;
        if ($idLattes !== null) {
            $researcherId = $this->conn->fetchOne('SELECT id FROM researchers WHERE id_lattes = ?', [$idLattes]);
            if ($researcherId === false) {
                throw new \InvalidArgumentException(sprintf('Docente com ID Lattes %s não encontrado.', $idLattes));
            }
            $researcherId = (int)$researcherId;
        }

        return [
            'group_keys_updated' => $this->refreshGroupKeys($researcherId),
            'members_relinked' => $this->refreshMatches($researcherId, $idLattes),
        ];
    }

    /**
     * @return array{records: int, groups: int, shared_groups: int, members: int, members_with_lattes: int, members_matched: int, funders: int, records_without_key: int}
     */
    public function stats(): array
    {
        $projects = $this->conn->fetchAssociative(
            'SELECT COUNT(*) AS records,
                    COUNT(DISTINCT project_group_key) AS groups_,
                    SUM(project_group_key IS NULL) AS without_key
               FROM research_projects'
        ) ?: [];
        $shared = (int)$this->conn->fetchOne(
            'SELECT COUNT(*) FROM (SELECT project_group_key FROM research_projects WHERE project_group_key IS NOT NULL
               GROUP BY project_group_key HAVING COUNT(DISTINCT researcher_id) > 1) t'
        );
        $members = $this->conn->fetchAssociative(
            'SELECT COUNT(*) AS total, SUM(id_lattes IS NOT NULL) AS with_lattes, SUM(matched_researcher_id IS NOT NULL) AS matched
               FROM research_project_members'
        ) ?: [];

        return [
            'records' => (int)($projects['records'] ?? 0),
            'groups' => (int)($projects['groups_'] ?? 0) + (int)($projects['without_key'] ?? 0),
            'shared_groups' => $shared,
            'members' => (int)($members['total'] ?? 0),
            'members_with_lattes' => (int)($members['with_lattes'] ?? 0),
            'members_matched' => (int)($members['matched'] ?? 0),
            'funders' => (int)$this->conn->fetchOne('SELECT COUNT(*) FROM research_project_funders'),
            'records_without_key' => (int)($projects['without_key'] ?? 0),
        ];
    }

    private function refreshGroupKeys(?int $researcherId): int
    {
        $sql = 'SELECT id, name, start_year, project_group_key FROM research_projects';
        $rows = $researcherId === null
            ? $this->conn->fetchAllAssociative($sql)
            : $this->conn->fetchAllAssociative($sql . ' WHERE researcher_id = ?', [$researcherId]);

        $changes = [];
        foreach ($rows as $row) {
            $key = ProjectGroupKey::compute($row['name'], $row['start_year'] !== null ? (int)$row['start_year'] : null);
            if ($key !== $row['project_group_key']) {
                $changes[(int)$row['id']] = $key;
            }
        }

        foreach (array_chunk($changes, self::BATCH, true) as $chunk) {
            $this->conn->transactional(function (Connection $conn) use ($chunk): void {
                foreach ($chunk as $id => $key) {
                    $conn->executeStatement('UPDATE research_projects SET project_group_key = ? WHERE id = ?', [$key, $id]);
                }
            });
        }

        return count($changes);
    }

    private function refreshMatches(?int $researcherId, ?string $idLattes): int
    {
        $sql = 'UPDATE research_project_members m
                  JOIN research_projects p ON p.id = m.research_project_id
             LEFT JOIN researchers r ON r.id_lattes = m.id_lattes
                   SET m.matched_researcher_id = r.id
                 WHERE NOT (m.matched_researcher_id <=> r.id)';

        if ($researcherId === null) {
            return (int)$this->conn->executeStatement($sql);
        }

        return (int)$this->conn->executeStatement($sql . ' AND (p.researcher_id = ? OR m.id_lattes = ?)', [$researcherId, $idLattes]);
    }
}
