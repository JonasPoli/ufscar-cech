<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Support\LattesText;
use App\Repository\QualisJournalRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Busca pública em três modos:
 *
 * - **Especialistas**: docentes cujos trabalhos casam com o filtro, cada um com os dois trabalhos de maior pontuação.
 *   A nota do docente é a do seu melhor trabalho; o desempate é pela quantidade de trabalhos e depois pelo nome.
 * - **Publicações**: trabalhos únicos (a mesma obra em vários currículos aparece uma vez), achados também pelo
 *   periódico, pelo evento, pelo DOI e por qualquer nome da família da revista.
 * - **Pesquisador**: docentes pelo nome, nomes de citação, ID Lattes ou ORCID.
 */
final class ExpertSearchService
{
    public const EXPERT_SORTS = [
        'relevancia' => 'Relevância',
        'recentes' => 'Mais recentes',
        'publicacoes' => 'Mais publicações relacionadas',
        'nome' => 'Nome',
    ];

    public const PUBLICATION_SORTS = [
        'relevancia' => 'Relevância',
        'recentes' => 'Mais recentes',
        'antigas' => 'Mais antigas',
        'titulo' => 'Título',
    ];

    private const TYPE_LABELS = [
        'ARTIGO' => 'Artigo',
        'LIVRO' => 'Livro',
        'CAPITULO' => 'Capítulo',
        'EVENTO' => 'Trabalho em evento',
        'TEXTO_JORNAL' => 'Texto em jornal ou revista',
        'TRABALHO_TECNICO' => 'Trabalho técnico',
        'OUTRA_TECNICA' => 'Produção técnica',
        'SOFTWARE' => 'Software',
        'PATENTE' => 'Patente',
        'MARCA' => 'Marca',
        'PRODUCAO_ARTISTICA' => 'Produção artística',
        'OUTRA' => 'Outra produção',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly QualisJournalRepository $journalRepository,
    ) {
    }

    public static function typeLabel(string $itemType): string
    {
        return self::TYPE_LABELS[$itemType] ?? ucfirst(mb_strtolower(str_replace('_', ' ', $itemType)));
    }

    /**
     * @return array{count: int, publications: int, page: int, pages: int, perPage: int, results: list<array<string, mixed>>}
     */
    public function experts(ResearchFilter $filter, string $sort = 'relevancia', int $page = 1, int $perPage = 12): array
    {
        $where = ResearchSql::where($filter);
        $params = $where['params'];
        $score = ResearchSql::score($filter, $params);
        $workKey = ResearchSql::workKey();

        $rows = $this->connection->fetchAllAssociative(
            "SELECT pi.id, pi.researcher_id, pi.year, {$workKey} AS wk, {$score} AS score, r.full_name
               FROM production_items pi
               JOIN researchers r ON r.id = pi.researcher_id
              WHERE {$where['sql']}",
            $params,
            $where['types']
        );

        $byResearcher = [];
        $workKeys = [];
        foreach ($rows as $row) {
            $rid = (int)$row['researcher_id'];
            $score = (float)$row['score'];
            $year = (int)$row['year'];
            $workKeys[$row['wk']] = true;

            if (!isset($byResearcher[$rid])) {
                $byResearcher[$rid] = ['id' => $rid, 'name' => (string)LattesText::display((string)$row['full_name']), 'score' => 0.0, 'latestYear' => 0, 'works' => [], 'items' => []];
            }
            $entry = &$byResearcher[$rid];
            $entry['score'] = max($entry['score'], $score);
            $entry['latestYear'] = max($entry['latestYear'], $year);
            $entry['works'][$row['wk']] = true;
            $entry['items'][] = ['id' => (int)$row['id'], 'score' => $score, 'year' => $year];
            unset($entry);
        }

        $list = array_values($byResearcher);
        foreach ($list as &$entry) {
            $entry['count'] = count($entry['works']);
        }
        unset($entry);

        $byName = static fn(array $a, array $b): int => strcoll(mb_strtolower($a['name']), mb_strtolower($b['name']));
        usort($list, match ($sort) {
            'recentes' => static fn($a, $b) => [$b['latestYear'], $b['score'], $b['count']] <=> [$a['latestYear'], $a['score'], $a['count']] ?: $byName($a, $b),
            'publicacoes' => static fn($a, $b) => [$b['count'], $b['score']] <=> [$a['count'], $a['score']] ?: $byName($a, $b),
            'nome' => $byName,
            default => static fn($a, $b) => [$b['score'], $b['count']] <=> [$a['score'], $a['count']] ?: $byName($a, $b),
        });

        $total = count($list);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $slice = array_slice($list, ($page - 1) * $perPage, $perPage);

        $itemIds = [];
        foreach ($slice as &$entry) {
            usort($entry['items'], static fn($a, $b) => [$b['score'], $b['year'], $b['id']] <=> [$a['score'], $a['year'], $a['id']]);
            $entry['topIds'] = array_column(array_slice($entry['items'], 0, 2), 'id');
            array_push($itemIds, ...$entry['topIds']);
        }
        unset($entry);

        $researchers = $this->loadResearchers(array_column($slice, 'id'));
        $works = $this->loadWorks($itemIds);

        $results = [];
        foreach ($slice as $entry) {
            if (!isset($researchers[$entry['id']])) {
                continue;
            }
            $results[] = [
                'researcher' => $researchers[$entry['id']],
                'count' => $entry['count'],
                'score' => round($entry['score'], 2),
                'latestYear' => $entry['latestYear'] ?: null,
                'publications' => array_values(array_filter(array_map(static fn($id) => $works[$id] ?? null, $entry['topIds']))),
            ];
        }

        return [
            'count' => $total,
            'publications' => count($workKeys),
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
            'results' => $results,
        ];
    }

    /**
     * @return array{count: int, page: int, pages: int, perPage: int, results: list<array<string, mixed>>}
     */
    public function publications(ResearchFilter $filter, string $sort = 'relevancia', int $page = 1, int $perPage = 15): array
    {
        $journalRowIds = $filter->q !== '' && mb_strlen($filter->q) >= 3
            ? $this->journalRepository->findFamilyRowIdsByName($filter->q)
            : [];
        $where = ResearchSql::where($filter, true, $journalRowIds);
        $params = $where['params'];
        $score = ResearchSql::score($filter, $params);
        $workKey = ResearchSql::workKey();

        $base = "FROM production_items pi JOIN researchers r ON r.id = pi.researcher_id WHERE {$where['sql']}";

        $total = (int)$this->connection->fetchOne("SELECT COUNT(DISTINCT {$workKey}) {$base}", $params, $where['types']);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $pages);

        $order = match ($sort) {
            'recentes' => 'year DESC, score DESC, rep DESC',
            'antigas' => 'year IS NULL, year ASC, score DESC, rep ASC',
            'titulo' => 'title ASC, rep ASC',
            default => 'score DESC, year DESC, rep DESC',
        };

        $offset = ($page - 1) * $perPage;
        $groups = $this->connection->fetchAllAssociative(
            "SELECT {$workKey} AS wk, MAX({$score}) AS score, MAX(pi.year) AS year, MIN(pi.id) AS rep,
                    MIN(LOWER(TRIM(pi.title))) AS title, GROUP_CONCAT(DISTINCT pi.researcher_id) AS owners
             {$base}
             GROUP BY wk
             ORDER BY {$order}
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
            $where['types']
        );

        $works = $this->loadWorks(array_map(static fn($g) => (int)$g['rep'], $groups));
        $ownerIds = [];
        foreach ($groups as $group) {
            foreach (explode(',', (string)$group['owners']) as $id) {
                $ownerIds[(int)$id] = true;
            }
        }
        $owners = $this->loadResearchers(array_keys($ownerIds));

        $results = [];
        foreach ($groups as $group) {
            $work = $works[(int)$group['rep']] ?? null;
            if ($work === null) {
                continue;
            }
            $work['owners'] = array_values(array_filter(array_map(
                static fn($id) => $owners[(int)$id] ?? null,
                explode(',', (string)$group['owners'])
            )));
            $results[] = $work;
        }

        return ['count' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage, 'results' => $results];
    }

    /**
     * Docentes pelo nome, com os filtros de departamento, vínculo e área (os de trabalho não se aplicam).
     *
     * @return array{count: int, page: int, pages: int, perPage: int, results: list<array<string, mixed>>}
     */
    public function researchers(ResearchFilter $filter, int $page = 1, int $perPage = 24): array
    {
        $byPerson = new ResearchFilter();
        $byPerson->department = $filter->department;
        $byPerson->affiliation = $filter->affiliation;
        $byPerson->majorArea = $filter->majorArea;
        $byPerson->area = $filter->area;
        $where = ResearchSql::where($byPerson, false, [], 'pi', 'r');

        $conditions = [$where['sql']];
        $params = $where['params'];
        if ($filter->q !== '') {
            $params['rs_name'] = ResearchSql::likePattern($filter->q);
            $conditions[] = '(r.full_name LIKE :rs_name OR r.citation_names LIKE :rs_name OR r.id_lattes LIKE :rs_name OR r.orcid LIKE :rs_name)';
        }
        $sqlWhere = implode(' AND ', $conditions);

        $total = (int)$this->connection->fetchOne("SELECT COUNT(*) FROM researchers r WHERE {$sqlWhere}", $params, $where['types']);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . ResearcherCard::columns() . ",
                    (SELECT COUNT(*) FROM production_items p WHERE p.researcher_id = r.id) AS productions,
                    (SELECT MAX(p.year) FROM production_items p WHERE p.researcher_id = r.id) AS latest_year
               FROM researchers r
              WHERE {$sqlWhere}
              ORDER BY r.status DESC, r.full_name ASC
              LIMIT {$perPage} OFFSET {$offset}",
            $params,
            $where['types']
        );

        $results = array_map(static function (array $row): array {
            $card = ResearcherCard::fromRow($row);
            $card['productions'] = (int)$row['productions'];
            $card['latestYear'] = $row['latest_year'] !== null ? (int)$row['latest_year'] : null;

            return $card;
        }, $rows);

        return ['count' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage, 'results' => $results];
    }

    /**
     * Docentes por id, prontos para exibição.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function loadResearchers(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . ResearcherCard::columns() . ' FROM researchers r WHERE r.id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['id']] = ResearcherCard::fromRow($row);
        }

        return $out;
    }

    /**
     * Trabalhos por id, com veículo, ISSN, DOI e autoria (docentes do CECH com link).
     *
     * @param list<int> $itemIds
     * @return array<int, array<string, mixed>>
     */
    public function loadWorks(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if ($itemIds === []) {
            return [];
        }

        $venue = ResearchSql::venue();
        $rows = $this->connection->fetchAllAssociative(
            "SELECT pi.id, pi.title, pi.year, pi.item_type, pi.doi, pi.issn, pi.isbn, pi.qualis, {$venue} AS venue
               FROM production_items pi
              WHERE pi.id IN (:ids)",
            ['ids' => $itemIds],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $authorRows = $this->connection->fetchAllAssociative(
            'SELECT pa.production_item_id, pa.author_name, pa.author_order, pa.matched_researcher_id,
                    r.full_name, r.slug, r.id_lattes
               FROM production_authors pa
               LEFT JOIN researchers r ON r.id = pa.matched_researcher_id
              WHERE pa.production_item_id IN (:ids)
              ORDER BY pa.production_item_id, COALESCE(pa.author_order, 9999), pa.id',
            ['ids' => $itemIds],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $authors = [];
        foreach ($authorRows as $a) {
            $itemId = (int)$a['production_item_id'];
            $matched = $a['matched_researcher_id'] !== null;
            $slug = trim((string)($a['slug'] ?? ''));
            $authors[$itemId][] = [
                'name' => (string)LattesText::display($matched ? (string)$a['full_name'] : trim((string)$a['author_name'])),
                'slug' => $matched ? ($slug !== '' ? $slug : (string)$a['id_lattes']) : null,
            ];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $doi = trim((string)($row['doi'] ?? ''));
            $doi = preg_replace('#^(https?://)?(dx\.)?doi\.org/#i', '', $doi) ?? $doi;
            $list = $authors[$id] ?? [];
            $out[$id] = [
                'id' => $id,
                // O banco guarda o texto do Lattes como veio, com entidades HTML; a exibição decodifica (App\Support\LattesText)
                'title' => (string)LattesText::display((string)$row['title']),
                'year' => $row['year'] !== null ? (int)$row['year'] : null,
                'type' => (string)$row['item_type'],
                'typeLabel' => self::typeLabel((string)$row['item_type']),
                'venue' => $row['venue'] !== null ? LattesText::display(trim((string)$row['venue'])) : null,
                'issn' => trim((string)($row['issn'] ?? '')) ?: null,
                'isbn' => trim((string)($row['isbn'] ?? '')) ?: null,
                'qualis' => trim((string)($row['qualis'] ?? '')) ?: null,
                'doi' => $doi !== '' ? $doi : null,
                'authors' => array_slice($list, 0, 10),
                'moreAuthors' => max(0, count($list) - 10),
            ];
        }

        return $out;
    }
}
