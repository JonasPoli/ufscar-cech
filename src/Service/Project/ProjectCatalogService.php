<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Service\Thesaurus\StringNormalizer;
use App\Support\LattesText;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Leitura do catálogo institucional de projetos (`/projetos`, `/projeto/{id}` e a API JSON).
 *
 * Cada docente declara o projeto no próprio currículo, então o mesmo projeto pode ter vários registros em
 * `research_projects`. O catálogo mostra um item por grupo (`project_group_key`; registro sem chave é um grupo sozinho).
 * Os dados do grupo vêm do registro representante: o do docente que é o responsável (`is_coordinator`), senão o de
 * descrição mais longa, senão o mais antigo. Situação, natureza e período filtram pelo representante; departamento,
 * pelos docentes do CECH do grupo (quem declara o projeto e os integrantes ligados a um docente). A equipe mostrada é
 * a do representante: cada currículo escreve os nomes de um jeito, e juntar as listas repetiria pessoas.
 *
 * Os textos saem decodificados para exibição ({@see LattesText}); o banco guarda como veio do Lattes.
 */
class ProjectCatalogService
{
    public const PER_PAGE = 12;
    private const SUMMARY_LENGTH = 240;

    private const GROUP_KEY = "COALESCE(rp.project_group_key, CONCAT('#', rp.id))";
    private const REPRESENTATIVE_ORDER = "rp.is_coordinator DESC, CHAR_LENGTH(COALESCE(rp.description, '')) DESC, rp.id ASC";

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @return array{count: int, page: int, pages: int, per_page: int, results: list<array<string, mixed>>}
     */
    public function search(ProjectFilter $filter, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        [$with, $from, $where, $params] = $this->filterSql($filter);

        $count = (int)$this->conn->fetchOne("$with SELECT COUNT(*) $from WHERE g.rn = 1 $where", $params);
        $pages = max(1, (int)ceil($count / $perPage));
        $page = max(1, $page);

        $rows = $count === 0 ? [] : $this->conn->fetchAllAssociative(
            "$with SELECT g.id, g.gk, g.name, g.status, g.nature, g.start_year, g.end_year, g.n_records,
                    LEFT(rp.description, 600) AS description
               $from JOIN research_projects rp ON rp.id = g.id
              WHERE g.rn = 1 $where
           ORDER BY g.start_year IS NULL, g.start_year DESC, g.id DESC
              LIMIT " . (int)$perPage . ' OFFSET ' . (int)(($page - 1) * $perPage),
            $params
        );

        $people = $this->peopleByGroup(array_column($rows, 'gk'));
        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->presentListItem($row, $people[$row['gk']] ?? []);
        }

        return ['count' => $count, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'results' => $results];
    }

    /**
     * O projeto do registro `$id`, com todos os registros do mesmo grupo unidos. Null se o id não existe.
     *
     * @return array<string, mixed>|null
     */
    public function findGroup(int $id): ?array
    {
        $key = $this->conn->fetchAssociative('SELECT id, project_group_key FROM research_projects WHERE id = ?', [$id]);
        if ($key === false) {
            return null;
        }

        $select = 'SELECT rp.id, rp.name, rp.status, rp.nature, rp.start_year, rp.end_year, rp.description, rp.agency_financier,
                          rp.is_coordinator, r.id AS researcher_id, r.full_name, r.slug, r.id_lattes, r.department, r.department_code, r.photo_url
                     FROM research_projects rp JOIN researchers r ON r.id = rp.researcher_id';
        $records = $key['project_group_key'] !== null
            ? $this->conn->fetchAllAssociative("$select WHERE rp.project_group_key = ? ORDER BY " . self::REPRESENTATIVE_ORDER, [$key['project_group_key']])
            : $this->conn->fetchAllAssociative("$select WHERE rp.id = ?", [$id]);
        if ($records === []) {
            return null;
        }

        $recordIds = array_map(static fn (array $r): int => (int)$r['id'], $records);
        $rank = array_flip($recordIds);

        $members = $this->conn->fetchAllAssociative(
            'SELECT m.research_project_id, m.full_name, m.member_order, m.is_responsible, m.id_lattes,
                    r.id AS researcher_id, r.full_name AS researcher_name, r.slug, r.id_lattes AS researcher_lattes, r.department_code, r.photo_url
               FROM research_project_members m LEFT JOIN researchers r ON r.id = m.matched_researcher_id
              WHERE m.research_project_id IN (?) ORDER BY m.id',
            [$recordIds],
            [ArrayParameterType::INTEGER]
        );
        usort($members, static fn (array $a, array $b): int => [$rank[(int)$a['research_project_id']], $a['member_order'] ?? PHP_INT_MAX]
            <=> [$rank[(int)$b['research_project_id']], $b['member_order'] ?? PHP_INT_MAX]);

        $funders = $this->conn->fetchAllAssociative(
            'SELECT research_project_id, name, nature, funder_order FROM research_project_funders WHERE research_project_id IN (?) ORDER BY id',
            [$recordIds],
            [ArrayParameterType::INTEGER]
        );
        usort($funders, static fn (array $a, array $b): int => [$rank[(int)$a['research_project_id']], $a['funder_order'] ?? PHP_INT_MAX]
            <=> [$rank[(int)$b['research_project_id']], $b['funder_order'] ?? PHP_INT_MAX]);

        return $this->presentGroup($records, $members, $funders);
    }

    /**
     * Departamentos com docentes, para o filtro.
     *
     * @return list<array{code: string, name: string}>
     */
    public function departmentOptions(): array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT department_code AS code, MAX(department) AS name FROM researchers
              WHERE department_code IS NOT NULL AND department_code <> ''
           GROUP BY department_code ORDER BY MAX(department), department_code"
        );

        return array_map(static fn (array $r): array => ['code' => (string)$r['code'], 'name' => (string)($r['name'] ?: $r['code'])], $rows);
    }

    /**
     * @return list<string>
     */
    public function departmentCodes(): array
    {
        return array_column($this->departmentOptions(), 'code');
    }

    /**
     * Totais do catálogo inteiro, para o cabeçalho.
     *
     * @return array{projects: int, ongoing: int, researchers: int, min_year: int|null}
     */
    public function totals(): array
    {
        $row = $this->conn->fetchAssociative(
            $this->groupsCte() . " SELECT COUNT(*) AS projects, SUM(g.status = 'EM_ANDAMENTO') AS ongoing, MIN(g.start_year) AS min_year FROM grp g WHERE g.rn = 1"
        ) ?: [];

        return [
            'projects' => (int)($row['projects'] ?? 0),
            'ongoing' => (int)($row['ongoing'] ?? 0),
            'researchers' => (int)$this->conn->fetchOne('SELECT COUNT(DISTINCT researcher_id) FROM research_projects'),
            'min_year' => isset($row['min_year']) ? (int)$row['min_year'] : null,
        ];
    }

    private function groupsCte(): string
    {
        return 'WITH grp AS (
            SELECT rp.id, ' . self::GROUP_KEY . ' AS gk, rp.name, rp.status, rp.nature, rp.start_year, rp.end_year,
                   ROW_NUMBER() OVER (PARTITION BY ' . self::GROUP_KEY . ' ORDER BY ' . self::REPRESENTATIVE_ORDER . ') AS rn,
                   COUNT(*) OVER (PARTITION BY ' . self::GROUP_KEY . ') AS n_records
              FROM research_projects rp
        )';
    }

    /**
     * Partes da consulta do catálogo para o filtro: o WITH (grupos e, com departamento, os grupos do departamento), o
     * FROM e as condições sobre o representante `g`. O departamento entra por JOIN com uma CTE: como subconsulta no
     * WHERE, o MySQL a repetia para cada grupo (13 s em vez de 0,3 s).
     *
     * @return array{0: string, 1: string, 2: string, 3: array<string, mixed>}
     */
    private function filterSql(ProjectFilter $filter): array
    {
        $with = $this->groupsCte();
        $from = 'FROM grp g';
        $where = '';
        $params = [];

        if ($filter->department !== null) {
            $with .= ', dept AS (
                SELECT ' . self::GROUP_KEY . ' AS gk FROM research_projects rp JOIN researchers r ON r.id = rp.researcher_id WHERE r.department_code = :dept
                UNION
                SELECT ' . self::GROUP_KEY . ' FROM research_project_members m
                  JOIN research_projects rp ON rp.id = m.research_project_id
                  JOIN researchers r ON r.id = m.matched_researcher_id
                 WHERE r.department_code = :dept
            )';
            $from .= ' JOIN dept d ON d.gk = g.gk';
            $params['dept'] = $filter->department;
        }
        if ($filter->q !== '') {
            $where .= ' AND g.name LIKE :q';
            $params['q'] = '%' . addcslashes($filter->q, '%_\\') . '%';
        }
        if ($filter->status !== null) {
            $where .= ' AND g.status = :status';
            $params['status'] = $filter->status;
        }
        if ($filter->nature !== null) {
            $where .= ' AND g.nature = :nature';
            $params['nature'] = $filter->nature;
        }
        if ($filter->toYear !== null) {
            $where .= ' AND g.start_year IS NOT NULL AND g.start_year <= :to_year';
            $params['to_year'] = $filter->toYear;
        }
        if ($filter->fromYear !== null) {
            $where .= " AND COALESCE(g.end_year, CASE WHEN g.status = 'EM_ANDAMENTO' THEN :current_year ELSE g.start_year END) >= :from_year";
            $params['from_year'] = $filter->fromYear;
            $params['current_year'] = (int)date('Y');
        }

        return [$with, $from, $where, $params];
    }

    /**
     * Docentes do CECH de cada grupo: quem declara o projeto e os integrantes ligados a um docente.
     *
     * @param list<string> $groupKeys
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function peopleByGroup(array $groupKeys): array
    {
        if ($groupKeys === []) {
            return [];
        }
        $keys = array_values(array_filter($groupKeys, static fn (string $k): bool => !str_starts_with($k, '#')));
        $ids = array_values(array_map(static fn (string $k): int => (int)substr($k, 1), array_filter($groupKeys, static fn (string $k): bool => str_starts_with($k, '#'))));
        $match = '(rp.project_group_key IN (:keys) OR (rp.project_group_key IS NULL AND rp.id IN (:ids)))';
        $params = ['keys' => $keys ?: ['-'], 'ids' => $ids ?: [0]];
        $types = ['keys' => ArrayParameterType::STRING, 'ids' => ArrayParameterType::INTEGER];
        $columns = self::GROUP_KEY . ' AS gk, r.id, r.full_name, r.slug, r.id_lattes, r.department_code, r.photo_url';

        $rows = $this->conn->fetchAllAssociative(
            "SELECT $columns, 1 AS declares, rp.is_coordinator AS responsible
               FROM research_projects rp JOIN researchers r ON r.id = rp.researcher_id WHERE $match
             UNION ALL
             SELECT $columns, 0 AS declares, m.is_responsible AS responsible
               FROM research_project_members m
               JOIN research_projects rp ON rp.id = m.research_project_id
               JOIN researchers r ON r.id = m.matched_researcher_id
              WHERE $match",
            $params,
            $types
        );

        $byGroup = [];
        foreach ($rows as $row) {
            $person = &$byGroup[$row['gk']][(int)$row['id']];
            $person ??= $this->presentResearcher($row) + ['declares' => false, 'is_responsible' => false];
            $person['declares'] = $person['declares'] || (bool)$row['declares'];
            $person['is_responsible'] = $person['is_responsible'] || (bool)$row['responsible'];
            unset($person);
        }

        $sorted = [];
        foreach ($byGroup as $gk => $people) {
            $people = array_values($people);
            usort($people, static fn (array $a, array $b): int => [!$a['is_responsible'], $a['name']] <=> [!$b['is_responsible'], $b['name']]);
            $sorted[(string)$gk] = $people;
        }

        return $sorted;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $people
     *
     * @return array<string, mixed>
     */
    private function presentListItem(array $row, array $people): array
    {
        $startYear = $row['start_year'] !== null ? (int)$row['start_year'] : null;
        $endYear = $row['end_year'] !== null ? (int)$row['end_year'] : null;

        return [
            'id' => (int)$row['id'],
            'title' => (string)LattesText::display($row['name']),
            'status' => $row['status'],
            'status_label' => ProjectLabels::status($row['status']),
            'nature' => $row['nature'],
            'nature_label' => ProjectLabels::nature($row['nature']),
            'start_year' => $startYear,
            'end_year' => $endYear,
            'period' => ProjectLabels::period($startYear, $endYear, $row['status']),
            'summary' => $this->summary($row['description']),
            'records' => (int)$row['n_records'],
            'researchers' => $people,
        ];
    }

    /**
     * @param list<array<string, mixed>> $records Registros do grupo, o representante primeiro
     * @param list<array<string, mixed>> $members
     * @param list<array<string, mixed>> $funders
     *
     * @return array<string, mixed>
     */
    private function presentGroup(array $records, array $members, array $funders): array
    {
        $main = $records[0];
        $startYear = $main['start_year'] !== null ? (int)$main['start_year'] : null;
        $endYear = $main['end_year'] !== null ? (int)$main['end_year'] : null;

        $description = null;
        foreach ($records as $record) {
            if (trim((string)$record['description']) !== '') {
                $description = (string)LattesText::display(trim((string)$record['description']));
                break;
            }
        }

        // Quem declara: um item por docente, coordenador se algum registro dele diz que é o responsável
        $declarers = [];
        foreach ($records as $record) {
            $id = (int)$record['researcher_id'];
            $declarers[$id] ??= $this->presentResearcher(['id' => $id] + $record) + ['department' => $record['department'], 'is_coordinator' => false];
            $declarers[$id]['is_coordinator'] = $declarers[$id]['is_coordinator'] || (bool)$record['is_coordinator'];
        }
        $declarers = array_values($declarers);
        usort($declarers, static fn (array $a, array $b): int => [!$a['is_coordinator'], $a['name']] <=> [!$b['is_coordinator'], $b['name']]);

        // Equipe: a do registro representante. Cada currículo escreve os nomes do seu jeito ("Carmen Passos",
        // "CARMEN LUCIA BRANCAGLION PASSOS", quase sempre sem ID Lattes), e juntar as listas repetiria as pessoas. Se o
        // representante não informa a equipe, vale o registro com a maior.
        $membersByRecord = [];
        foreach ($members as $member) {
            $membersByRecord[(int)$member['research_project_id']][] = $member;
        }
        $teamRecord = $main;
        if (empty($membersByRecord[(int)$main['id']])) {
            foreach ($records as $record) {
                if (count($membersByRecord[(int)$record['id']] ?? []) > count($membersByRecord[(int)$teamRecord['id']] ?? [])) {
                    $teamRecord = $record;
                }
            }
        }
        // Integrante sem ID Lattes com o nome completo igual ao de um docente que declara o projeto também ganha o link
        $declarerByName = [];
        foreach ($declarers as $declarer) {
            $declarerByName[StringNormalizer::normalizeString($declarer['name'], true)] = $declarer;
        }
        $team = [];
        foreach ($membersByRecord[(int)$teamRecord['id']] ?? [] as $member) {
            $name = (string)LattesText::display($member['full_name']);
            $researcher = $member['researcher_id'] === null ? null : $this->presentResearcher([
                'id' => $member['researcher_id'],
                'full_name' => $member['researcher_name'],
                'slug' => $member['slug'],
                'id_lattes' => $member['researcher_lattes'],
                'department_code' => $member['department_code'],
                'photo_url' => $member['photo_url'] ?? null,
            ]);
            if ($researcher === null && ($declarer = $declarerByName[StringNormalizer::normalizeString($name, true)] ?? null) !== null) {
                $researcher = array_intersect_key($declarer, array_flip(['id', 'name', 'slug_or_id', 'department_code', 'photo_url']));
            }
            $team[] = ['name' => $name, 'is_responsible' => (bool)$member['is_responsible'], 'researcher' => $researcher];
        }
        usort($team, static fn (array $a, array $b): int => $b['is_responsible'] <=> $a['is_responsible']);

        // Financiadores de todos os registros (cada docente informa o próprio apoio), um por instituição com as naturezas
        // somadas; sem a tabela preenchida, o primeiro financiador de cada registro
        $funding = [];
        foreach ($funders as $funder) {
            $name = trim((string)LattesText::display($funder['name']));
            if ($name === '') {
                continue;
            }
            $key = StringNormalizer::normalizeString($name, true);
            $funding[$key] ??= ['name' => $name, 'natures' => []];
            $nature = ProjectLabels::funderNature($funder['nature']);
            if ($nature !== null && !in_array($nature, $funding[$key]['natures'], true)) {
                $funding[$key]['natures'][] = $nature;
            }
        }
        if ($funders === []) {
            foreach ($records as $record) {
                $name = trim((string)LattesText::display($record['agency_financier']));
                if ($name !== '') {
                    $funding[StringNormalizer::normalizeString($name, true)] ??= ['name' => $name, 'natures' => []];
                }
            }
        }

        $statuses = array_values(array_unique(array_map(static fn (array $r): string => (string)$r['status'], $records)));

        return [
            'id' => (int)$main['id'],
            'record_ids' => array_map(static fn (array $r): int => (int)$r['id'], $records),
            'title' => (string)LattesText::display($main['name']),
            'status' => $main['status'],
            'status_label' => ProjectLabels::status($main['status']),
            'other_status_labels' => array_values(array_map(ProjectLabels::status(...), array_diff($statuses, [(string)$main['status']]))),
            'nature' => $main['nature'],
            'nature_label' => ProjectLabels::nature($main['nature']),
            'start_year' => $startYear,
            'end_year' => $endYear,
            'period' => ProjectLabels::period($startYear, $endYear, $main['status']),
            'description' => $description,
            'summary' => $this->summary($description, 300),
            'funders' => array_values($funding),
            'members' => $team,
            'team_source' => count($records) > 1 ? $this->presentResearcher(['id' => $teamRecord['researcher_id']] + $teamRecord) : null,
            'declared_by' => $declarers,
        ];
    }

    /**
     * @param array<string, mixed> $row id, full_name, slug, id_lattes, department_code
     *
     * @return array{id: int, name: string, slug_or_id: string, department_code: ?string, photo_url: ?string}
     */
    private function presentResearcher(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['full_name'],
            'slug_or_id' => (string)($row['slug'] ?: $row['id_lattes']),
            'department_code' => $row['department_code'] ?: null,
            'photo_url' => $row['photo_url'] ?? null,
        ];
    }

    private function summary(?string $description, int $length = self::SUMMARY_LENGTH): ?string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', (string)LattesText::display($description)));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $length * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-–") . '…';
    }
}
