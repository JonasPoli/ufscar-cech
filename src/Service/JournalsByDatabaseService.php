<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Journal\DisplayTitle;
use App\Service\Journal\Issn;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Dados da página pública `/periodicos` (docs/evolutions/periodicos-por-base.md, A1).
 *
 * Lista os periódicos em que os docentes do CECH publicaram artigos, agrupados pela base em que estão indexados
 * (Scopus, PubMed, SciELO, Web of Science e Latindex) e filtráveis pela área de avaliação da CAPES. Cada periódico traz
 * a quantidade de artigos distintos e de docentes; a ordem é apenas a da quantidade de artigos (decrescente), com
 * empate pela ordem alfabética do nome exibido.
 *
 * Um periódico é uma família: `COALESCE(canonical_journal_id, id)`. Com `canonical_journal_id` todo nulo, cada linha de
 * `qualis_journals` é a sua própria família; com `journal_areas` vazia, a lista não tem filtro de área nem chips.
 * Os artigos são contados com a mesma chave de deduplicação do restante do site (`StatisticsService::getGlobalSummary`):
 * DOI normalizado ou `TITLE:ano:título`, de modo que coautorias entre docentes do Centro contam uma vez.
 *
 * @phpstan-type Area array{id: int, slug: string, name: string, level: int, parent_id: int|null}
 * @phpstan-type Chip array{slug: string, name: string, is_primary: bool}
 * @phpstan-type BaseBadge array{name: string, acronym: string, logo: string|null}
 * @phpstan-type Row array{id: int, name: string, issns: list<string>, qualis: string|null, bases: list<BaseBadge>, n_articles: int, n_researchers: int, year_min: int|null, year_max: int|null, pct: float, areas: list<Chip>}
 * @phpstan-type Section array{db: array{id: int|null, name: string, acronym: string, url: string|null, logo: string|null}, note: string|null, total_journals: int, total_articles: int, rows: list<Row>}
 */
class JournalsByDatabaseService
{
    /** Bases exibidas, na ordem fixa da página (o acrônimo é o de `academic_database.acronym`). */
    public const BASES = ['scopus', 'pubmed', 'scielo', 'wos', 'latindex'];

    /** Quantidade de periódicos por base na visão geral; a lista completa fica em `/periodicos/{base}`. */
    public const PREVIEW_LIMIT = 25;

    /** Lista que reúne as cinco bases, em `/periodicos/geral`. Não é uma base: fica fora de BASES e de `academic_database`. */
    public const GENERAL = 'geral';

    private const GENERAL_NAME = 'Todas as bases somadas';

    private const GENERAL_NOTE = 'Periódicos presentes em pelo menos uma das cinco bases. Cada periódico e cada artigo contam uma vez.';

    /**
     * Data da lista de títulos de cada base (A1.1). A visão geral mostra a nota ao lado do nome da base; a nota do DOAJ
     * não aparece na página (o DOAJ só alimenta as áreas), mas fica registrada aqui junto das demais.
     */
    public const SOURCE_NOTES = [
        'scopus' => 'Lista de títulos de jul./2026',
        'pubmed' => 'Lista obtida em ago./2026 (arquivo de 25/08/2026)',
        'scielo' => 'Lista de 26/08/2026',
        'wos' => 'Lista obtida em ago./2026 (arquivo de 25/08/2026)',
        'latindex' => 'Lista obtida em ago./2026 (arquivo de 25/08/2026)',
        'doaj' => 'Lista de 26/07/2026',
    ];

    /** Nome usado quando a base ainda não tem linha em `academic_database`, para a página mostrar sempre as cinco seções. */
    private const BASE_NAMES = [
        'scopus' => 'Scopus',
        'pubmed' => 'PubMed',
        'scielo' => 'SciELO',
        'wos' => 'Web of Science',
        'latindex' => 'Latindex',
    ];

    /** Chave de deduplicação de artigos: DOI normalizado ou ano + título (iguais às de `StatisticsService`). */
    private const ARTICLE_KEY = "CASE WHEN pi.doi IS NOT NULL AND TRIM(pi.doi) <> '' "
        . "THEN CONCAT('DOI:', LOWER(TRIM(pi.doi))) "
        . "ELSE CONCAT('TITLE:', COALESCE(pi.year, ''), ':', LOWER(TRIM(pi.title))) END";

    /** Família de cada linha de periódico: a linha canônica ou, sem canônica, a própria linha. */
    private const FAMILIES = '(SELECT id, COALESCE(canonical_journal_id, id) AS fid FROM qualis_journals)';

    /** Famílias com alguma área na seleção: a própria área de avaliação ou qualquer área da grande área escolhida. */
    private const AREA_FAMILIES = 'SELECT ja.journal_id FROM journal_areas ja '
        . 'JOIN capes_areas ca ON ca.id = ja.area_id WHERE ca.id = :areaId OR ca.parent_id = :areaParentId';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Visão geral: a lista das bases somadas e as cinco bases na ordem fixa, cada uma com os primeiros PREVIEW_LIMIT
     * periódicos, mais os totais.
     *
     * Com `$areaId`, só entram periódicos que têm alguma área sob a seleção (a área de avaliação ou, quando é uma grande
     * área, qualquer uma das suas áreas) e os totais do cabeçalho passam a valer só para eles.
     *
     * @return array{general: Section, bases: array<string, Section>, totals: array{journals: int, articles: int, researchers: int}, updatedAt: \DateTimeImmutable}
     */
    public function getOverview(?int $areaId = null): array
    {
        $databases = $this->fetchDatabases();

        $fetched = [];
        $previewIds = [];
        foreach (self::BASES as $acronym) {
            $all = $this->fetchBaseRows([$acronym], $areaId);
            $preview = array_slice($all, 0, self::PREVIEW_LIMIT);
            foreach ($preview as $raw) {
                $previewIds[] = (int) $raw['id'];
            }
            $fetched[$acronym] = [$all, $preview];
        }

        $generalAll = $this->fetchBaseRows(self::BASES, $areaId);
        $generalPreview = array_slice($generalAll, 0, self::PREVIEW_LIMIT);
        foreach ($generalPreview as $raw) {
            $previewIds[] = (int) $raw['id'];
        }

        $chips = $this->fetchAreaChips($previewIds);
        $qualis = $this->fetchQualis($previewIds);

        $bases = [];
        foreach (self::BASES as $acronym) {
            [$all, $preview] = $fetched[$acronym];
            $bases[$acronym] = $this->buildSection($acronym, $databases[$acronym], $all, $preview, $chips, $qualis);
        }

        return [
            'general' => $this->buildGeneralSection($databases, $generalAll, $generalPreview, $chips, $qualis),
            'bases' => $bases,
            'totals' => $this->fetchTotals($areaId),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }

    /**
     * Lista completa de uma base (sem paginação), com a seção no mesmo formato da visão geral mais os docentes
     * distintos da base e a data de atualização.
     *
     * @return Section&array{total_researchers: int, updatedAt: \DateTimeImmutable}
     *
     * @throws \InvalidArgumentException se o acrônimo não for uma das BASES
     */
    public function getDatabaseList(string $acronym, ?int $areaId = null): array
    {
        if (!in_array($acronym, self::BASES, true)) {
            throw new \InvalidArgumentException(sprintf('Base de indexação desconhecida: "%s".', $acronym));
        }

        $all = $this->fetchBaseRows([$acronym], $areaId);
        $ids = array_map(static fn (array $raw): int => (int) $raw['id'], $all);
        $chips = $this->fetchAreaChips($ids);
        $qualis = $this->fetchQualis($ids);

        $section = $this->buildSection($acronym, $this->fetchDatabases()[$acronym], $all, $all, $chips, $qualis);
        $section['total_researchers'] = $this->fetchBaseResearchers([$acronym], $areaId);
        $section['updatedAt'] = $this->getUpdatedAt();

        return $section;
    }

    /**
     * Lista completa das bases somadas: cada família que está em pelo menos uma das cinco bases aparece uma vez, com as
     * bases em que está. Os artigos não são somados base a base, porque o mesmo artigo seria contado de novo em cada uma.
     *
     * @return Section&array{total_researchers: int, updatedAt: \DateTimeImmutable}
     */
    public function getGeneralList(?int $areaId = null): array
    {
        $all = $this->fetchBaseRows(self::BASES, $areaId);
        $ids = array_map(static fn (array $raw): int => (int) $raw['id'], $all);

        $section = $this->buildGeneralSection($this->fetchDatabases(), $all, $all, $this->fetchAreaChips($ids), $this->fetchQualis($ids));
        $section['total_researchers'] = $this->fetchBaseResearchers(self::BASES, $areaId);
        $section['updatedAt'] = $this->getUpdatedAt();

        return $section;
    }

    /**
     * Áreas para o filtro: cada grande área com as suas áreas de avaliação, na ordem de `capes_areas.sort_order`.
     *
     * @return list<array{area: array{id: int, slug: string, name: string}, children: list<array{id: int, slug: string, name: string}>}>
     */
    public function getAreaOptions(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, parent_id, level, slug, name FROM capes_areas ORDER BY sort_order ASC, id ASC'
        );

        $groups = [];
        foreach ($rows as $row) {
            if ((int) $row['level'] === 1) {
                $groups[(int) $row['id']] = ['area' => $this->areaOption($row), 'children' => []];
            }
        }
        foreach ($rows as $row) {
            $parentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            if ((int) $row['level'] === 2 && $parentId !== null && isset($groups[$parentId])) {
                $groups[$parentId]['children'][] = $this->areaOption($row);
            }
        }

        return array_values($groups);
    }

    /**
     * Procura uma área pelo slug. Nulo quando não existe.
     *
     * @return Area|null
     */
    public function resolveAreaSlug(string $slug): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, parent_id, level, slug, name FROM capes_areas WHERE slug = :slug',
            ['slug' => $slug]
        );
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'level' => (int) $row['level'],
            'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
        ];
    }

    /**
     * Data dos dados exibidos: a atualização mais recente de `journal_areas`; sem áreas gravadas, agora.
     */
    public function getUpdatedAt(): \DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT MAX(updated_at) FROM journal_areas');

        return is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : new \DateTimeImmutable();
    }

    /**
     * Todas as famílias com artigos que pertencem a alguma das bases, ordenadas por artigos (decrescente), nome exibido e id.
     *
     * @param list<string> $acronyms
     *
     * @return list<array<string, mixed>>
     */
    private function fetchBaseRows(array $acronyms, ?int $areaId): array
    {
        $params = ['acronyms' => $acronyms];
        $areaFilter = '';
        if ($areaId !== null) {
            $areaFilter = ' WHERE a.fid IN (' . self::AREA_FAMILIES . ')';
            $params['areaId'] = $areaId;
            $params['areaParentId'] = $areaId;
        }

        $key = self::ARTICLE_KEY;
        $families = self::FAMILIES;

        return $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT q.id, q.title, q.display_title, q.issn, q.issn_e, q.issn_imp,
                   a.n_articles, a.n_researchers, a.year_min, a.year_max
            FROM (
                SELECT f.fid,
                       COUNT(DISTINCT {$key}) AS n_articles,
                       COUNT(DISTINCT pi.researcher_id) AS n_researchers,
                       MIN(pi.year) AS year_min, MAX(pi.year) AS year_max
                FROM production_items pi
                JOIN {$families} f ON f.id = pi.qualis_journal_id
                WHERE pi.item_type = 'ARTIGO'
                GROUP BY f.fid
            ) a
            JOIN (
                SELECT DISTINCT f.fid
                FROM qualis_journal_academic_database qb
                JOIN academic_database ad ON ad.id = qb.academic_database_id AND ad.acronym IN (:acronyms)
                JOIN {$families} f ON f.id = qb.qualis_journal_id
            ) d ON d.fid = a.fid
            JOIN qualis_journals q ON q.id = a.fid
            {$areaFilter}
            ORDER BY a.n_articles DESC, COALESCE(NULLIF(q.display_title, ''), q.title) ASC, q.id ASC
            SQL,
            $params,
            ['acronyms' => ArrayParameterType::STRING]
        );
    }

    /**
     * Docentes distintos que publicaram em periódicos das bases (não é a soma das linhas: um docente publica em vários).
     *
     * @param list<string> $acronyms
     */
    private function fetchBaseResearchers(array $acronyms, ?int $areaId): int
    {
        $params = ['acronyms' => $acronyms];
        $areaFilter = '';
        if ($areaId !== null) {
            $areaFilter = ' WHERE x.fid IN (' . self::AREA_FAMILIES . ')';
            $params['areaId'] = $areaId;
            $params['areaParentId'] = $areaId;
        }

        $families = self::FAMILIES;

        // Junção entre duas tabelas derivadas, como na consulta principal: `fid IN (subconsulta)` sobre a família
        // calculada não usa índice e o MySQL a executa linha a linha (minutos), enquanto esta forma leva milissegundos
        $count = $this->connection->fetchOne(
            <<<SQL
            SELECT COUNT(DISTINCT x.researcher_id)
            FROM (
                SELECT DISTINCT pi.researcher_id, f.fid
                FROM production_items pi
                JOIN {$families} f ON f.id = pi.qualis_journal_id
                WHERE pi.item_type = 'ARTIGO'
            ) x
            JOIN (
                SELECT DISTINCT f.fid
                FROM qualis_journal_academic_database qb
                JOIN academic_database ad ON ad.id = qb.academic_database_id AND ad.acronym IN (:acronyms)
                JOIN {$families} f ON f.id = qb.qualis_journal_id
            ) d ON d.fid = x.fid{$areaFilter}
            SQL,
            $params,
            ['acronyms' => ArrayParameterType::STRING]
        );

        return (int) $count;
    }

    /**
     * Números do cabeçalho: famílias com artigos, artigos distintos e docentes distintos (toda a produção em
     * periódicos vinculados, estejam ou não em uma das cinco bases).
     *
     * @return array{journals: int, articles: int, researchers: int}
     */
    private function fetchTotals(?int $areaId): array
    {
        $params = [];
        $areaFilter = '';
        if ($areaId !== null) {
            $areaFilter = ' AND f.fid IN (' . self::AREA_FAMILIES . ')';
            $params = ['areaId' => $areaId, 'areaParentId' => $areaId];
        }

        $key = self::ARTICLE_KEY;
        $families = self::FAMILIES;

        $row = $this->connection->fetchAssociative(
            <<<SQL
            SELECT COUNT(DISTINCT f.fid) AS journals,
                   COUNT(DISTINCT {$key}) AS articles,
                   COUNT(DISTINCT pi.researcher_id) AS researchers
            FROM production_items pi
            JOIN {$families} f ON f.id = pi.qualis_journal_id
            WHERE pi.item_type = 'ARTIGO'{$areaFilter}
            SQL,
            $params
        );

        return [
            'journals' => (int) ($row['journals'] ?? 0),
            'articles' => (int) ($row['articles'] ?? 0),
            'researchers' => (int) ($row['researchers'] ?? 0),
        ];
    }

    /**
     * Áreas CAPES dos periódicos, a principal primeiro. Chave: id da família.
     *
     * @param list<int> $journalIds
     *
     * @return array<int, list<Chip>>
     */
    private function fetchAreaChips(array $journalIds): array
    {
        $journalIds = array_values(array_unique($journalIds));
        if ($journalIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ja.journal_id, ca.slug, ca.name, ja.is_primary '
            . 'FROM journal_areas ja JOIN capes_areas ca ON ca.id = ja.area_id '
            . 'WHERE ja.journal_id IN (:ids) '
            . 'ORDER BY ja.is_primary DESC, ja.score DESC, ca.sort_order ASC',
            ['ids' => $journalIds],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $chips = [];
        foreach ($rows as $row) {
            $chips[(int) $row['journal_id']][] = [
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'is_primary' => (bool) $row['is_primary'],
            ];
        }

        return $chips;
    }

    /**
     * Estrato Qualis de cada família, o mesmo que o site mostra nos artigos dela (`production_items.qualis`). Linhas da
     * família com estratos diferentes (impresso e online, por exemplo): vale o de mais artigos distintos e, no empate, o
     * mais alto. Chave: id da família; família sem estrato nos artigos não entra.
     *
     * @param list<int> $journalIds
     *
     * @return array<int, string>
     */
    private function fetchQualis(array $journalIds): array
    {
        $journalIds = array_values(array_unique($journalIds));
        if ($journalIds === []) {
            return [];
        }

        $key = self::ARTICLE_KEY;

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT COALESCE(j.canonical_journal_id, j.id) AS fid, pi.qualis, COUNT(DISTINCT {$key}) AS n_articles
            FROM production_items pi
            JOIN qualis_journals j ON j.id = pi.qualis_journal_id
            WHERE pi.item_type = 'ARTIGO' AND pi.qualis IS NOT NULL AND pi.qualis <> ''
              AND COALESCE(j.canonical_journal_id, j.id) IN (:ids)
            GROUP BY fid, pi.qualis
            ORDER BY n_articles DESC, pi.qualis ASC
            SQL,
            ['ids' => $journalIds],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $qualis = [];
        foreach ($rows as $row) {
            $qualis[(int) $row['fid']] ??= (string) $row['qualis'];
        }

        return $qualis;
    }

    /**
     * Bases de cada família, na ordem fixa de BASES. Chave: id da família.
     *
     * @param list<int>                                                                                         $journalIds
     * @param array<string, array{id: int|null, name: string, acronym: string, url: string|null, logo: string|null}> $databases
     *
     * @return array<int, list<BaseBadge>>
     */
    private function fetchFamilyBases(array $journalIds, array $databases): array
    {
        $journalIds = array_values(array_unique($journalIds));
        if ($journalIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT COALESCE(j.canonical_journal_id, j.id) AS fid, LOWER(ad.acronym) AS acronym '
            . 'FROM qualis_journal_academic_database qb '
            . 'JOIN academic_database ad ON ad.id = qb.academic_database_id AND ad.acronym IN (:acronyms) '
            . 'JOIN qualis_journals j ON j.id = qb.qualis_journal_id '
            . 'WHERE COALESCE(j.canonical_journal_id, j.id) IN (:ids)',
            ['acronyms' => self::BASES, 'ids' => $journalIds],
            ['acronyms' => ArrayParameterType::STRING, 'ids' => ArrayParameterType::INTEGER]
        );

        $found = [];
        foreach ($rows as $row) {
            $found[(int) $row['fid']][(string) $row['acronym']] = true;
        }

        $bases = [];
        foreach ($found as $familyId => $acronyms) {
            foreach (self::BASES as $acronym) {
                if (isset($acronyms[$acronym])) {
                    $bases[$familyId][] = [
                        'name' => $databases[$acronym]['name'],
                        'acronym' => $acronym,
                        'logo' => $databases[$acronym]['logo'],
                    ];
                }
            }
        }

        return $bases;
    }

    /**
     * Uma linha de `academic_database` por base, com valores padrão para as que ainda não existem na tabela.
     *
     * @return array<string, array{id: int|null, name: string, acronym: string, url: string|null, logo: string|null}>
     */
    private function fetchDatabases(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, acronym, url, logo FROM academic_database WHERE acronym IN (:acronyms)',
            ['acronyms' => self::BASES],
            ['acronyms' => ArrayParameterType::STRING]
        );

        $found = [];
        foreach ($rows as $row) {
            $found[strtolower((string) $row['acronym'])] = [
                'id' => (int) $row['id'],
                'name' => trim((string) $row['name']) !== '' ? (string) $row['name'] : self::BASE_NAMES[strtolower((string) $row['acronym'])],
                'acronym' => strtolower((string) $row['acronym']),
                'url' => ($row['url'] ?? '') !== '' ? (string) $row['url'] : null,
                'logo' => ($row['logo'] ?? '') !== '' ? (string) $row['logo'] : null,
            ];
        }

        $databases = [];
        foreach (self::BASES as $acronym) {
            $databases[$acronym] = $found[$acronym] ?? [
                'id' => null,
                'name' => self::BASE_NAMES[$acronym],
                'acronym' => $acronym,
                'url' => null,
                'logo' => null,
            ];
        }

        return $databases;
    }

    /**
     * @param array{id: int|null, name: string, acronym: string, url: string|null, logo: string|null} $database
     * @param list<array<string, mixed>>                                                              $all      todas as linhas da base
     * @param list<array<string, mixed>>                                                              $shown    as linhas que a página exibe
     * @param array<int, list<Chip>>                                                                  $chips
     * @param array<int, string>                                                                      $qualis
     * @param array<int, list<BaseBadge>>                                                             $bases    vazio nas listas de uma base só
     *
     * @return Section
     */
    private function buildSection(string $acronym, array $database, array $all, array $shown, array $chips, array $qualis, array $bases = []): array
    {
        // A lista vem ordenada por artigos: a primeira linha é o maior valor da seção, referência das barras
        $max = $all === [] ? 0 : (int) $all[0]['n_articles'];

        $articles = 0;
        foreach ($all as $raw) {
            $articles += (int) $raw['n_articles'];
        }

        $rows = [];
        foreach ($shown as $raw) {
            $rows[] = $this->buildRow($raw, $max, $chips[(int) $raw['id']] ?? [], $qualis[(int) $raw['id']] ?? null, $bases[(int) $raw['id']] ?? []);
        }

        return [
            'db' => $database,
            'note' => self::SOURCE_NOTES[$acronym] ?? null,
            'total_journals' => count($all),
            'total_articles' => $articles,
            'rows' => $rows,
        ];
    }

    /**
     * Seção das bases somadas: mesmo formato de uma base, com as bases de cada periódico nas linhas.
     *
     * @param array<string, array{id: int|null, name: string, acronym: string, url: string|null, logo: string|null}> $databases
     * @param list<array<string, mixed>>                                                                             $all
     * @param list<array<string, mixed>>                                                                             $shown
     * @param array<int, list<Chip>>                                                                                 $chips
     * @param array<int, string>                                                                                     $qualis
     *
     * @return Section
     */
    private function buildGeneralSection(array $databases, array $all, array $shown, array $chips, array $qualis): array
    {
        $ids = array_map(static fn (array $raw): int => (int) $raw['id'], $shown);
        $database = ['id' => null, 'name' => self::GENERAL_NAME, 'acronym' => self::GENERAL, 'url' => null, 'logo' => null];

        $section = $this->buildSection(self::GENERAL, $database, $all, $shown, $chips, $qualis, $this->fetchFamilyBases($ids, $databases));
        $section['note'] = self::GENERAL_NOTE;

        return $section;
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<Chip>           $areas
     * @param list<BaseBadge>      $bases
     *
     * @return Row
     */
    private function buildRow(array $raw, int $max, array $areas, ?string $qualis, array $bases = []): array
    {
        $articles = (int) $raw['n_articles'];

        return [
            'id' => (int) $raw['id'],
            'name' => $this->displayName($raw['display_title'] ?? null, (string) $raw['title']),
            'issns' => $this->issnsOf($raw),
            'qualis' => $qualis,
            'bases' => $bases,
            'n_articles' => $articles,
            'n_researchers' => (int) $raw['n_researchers'],
            'year_min' => $raw['year_min'] === null ? null : (int) $raw['year_min'],
            'year_max' => $raw['year_max'] === null ? null : (int) $raw['year_max'],
            // Largura da barra em % do maior valor da seção; no mínimo 1% para que todo periódico tenha uma barra visível
            'pct' => $max > 0 ? max(1.0, round($articles / $max * 100, 1)) : 0.0,
            'areas' => $areas,
        ];
    }

    /**
     * Nome exibido: `display_title` (gravado pelas famílias) ou, enquanto ele não existe, o título da linha; um título
     * todo em maiúsculas (ou todo em minúsculas) ganha a caixa de título, como `DisplayTitle` faz no último passo.
     */
    private function displayName(mixed $displayTitle, string $title): string
    {
        $displayTitle = trim((string) $displayTitle);
        if ($displayTitle !== '') {
            return $displayTitle;
        }

        $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $hasOwnCase = preg_match('/\p{Lu}/u', $title) === 1 && preg_match('/\p{Ll}/u', $title) === 1;

        return $hasOwnCase ? $title : DisplayTitle::titleCase($title);
    }

    /**
     * ISSNs válidos do periódico, formatados (`0103-863X`), sem repetição: impresso, genérico e eletrônico, nessa ordem.
     *
     * @param array<string, mixed> $raw
     *
     * @return list<string>
     */
    private function issnsOf(array $raw): array
    {
        // Lista, e não mapa: um ISSN só de dígitos (`16784626`) viraria chave inteira
        $found = [];
        foreach (['issn_imp', 'issn', 'issn_e'] as $column) {
            foreach (Issn::extractAll(isset($raw[$column]) ? (string) $raw[$column] : null) as $n8) {
                if (!in_array($n8, $found, true)) {
                    $found[] = $n8;
                }
            }
        }

        return array_map(static fn (string $n8): string => Issn::format($n8), $found);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, slug: string, name: string}
     */
    private function areaOption(array $row): array
    {
        return ['id' => (int) $row['id'], 'slug' => (string) $row['slug'], 'name' => (string) $row['name']];
    }
}
