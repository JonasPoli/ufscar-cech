<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Service\Thesaurus\JournalResolverService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Famílias de periódicos (agrupar sem mesclar) e nome exibido: docs/evolutions/periodicos-por-base.md, A4 passos 1 a 7,
 * com a regra de linhas em conflito no lugar do passo 2.
 *
 * Nada é apagado nem movido: `canonical_journal_id` aponta para a linha canônica e `display_title` recebe o nome
 * exibido da família. Para desfazer: `UPDATE qualis_journals SET canonical_journal_id = NULL WHERE canonical_method <> 'manual'`.
 *
 * Algoritmo (determinístico e idempotente):
 *  1. ISSNs válidos (dígito verificador) das 4 colunas de cada linha, com Issn::extractAll (um campo pode ter dois ISSNs).
 *  2. Grupos externos: union-find de ISSNs só com os registros do artefato de evidências. Não unem os registros com mais
 *     de MAX_RECORD_ISSNS ISSNs nem os de 3 ISSNs que duas ou mais outras fontes contradizem (ver `contradictedRecords`):
 *     costumam juntar revistas diferentes. Um ISSN do cadastro não une grupos por si: a linha do cadastro olha em que
 *     grupos externos caem os seus ISSNs.
 *      - um grupo só: a linha entra nele, mesmo com título diferente (ISSN certo e título errado);
 *      - dois ou mais grupos compatíveis, isto é, de títulos parecidos (ver `titleScore`) e sem países que se excluam
 *        (países conhecidos dos dois lados e disjuntos): o par impresso/online que nenhuma fonte listou junto; os grupos
 *        se unem;
 *      - dois ou mais grupos incompatíveis: linha em conflito. Nenhuma união passa por ela; ela entra só no grupo de
 *        título mais parecido com o dela, se passar do limiar e sem empate, senão fica isolada, e vai para o relatório;
 *      - ISSN que o artefato não tem não tem título externo e acompanha a linha que o traz, como sempre. As linhas
 *        são tratadas na ordem do id, então um ISSN compartilhado por duas linhas também não encadeia grupos incompatíveis.
 *  3. Linhas que caem no mesmo grupo formam uma família (`canonical_method = 'issn'`).
 *  4. Linha sem ISSN válido: se o `normalized_title` (exato, sem remover parênteses) é igual ao de linhas com ISSN ou ao
 *     título normalizado de um registro do artefato e isso aponta para exatamente um grupo de ISSN com linha no cadastro,
 *     ela entra nesse grupo (`canonical_method = 'title'`); com mais de um grupo é ambígua e fica isolada.
 *  5. Canônica: mais artigos, depois mais ISSNs válidos, depois menor id. Linhas `canonical_method = 'manual'` nunca são
 *     alteradas, valem como pontes sem checar títulos nem países (decisão humana), e a família que contém uma delas usa a
 *     canônica manual.
 *  6. Nome exibido em todas as linhas da família, menos nas de `display_title_source = 'manual'` (ver DisplayTitle).
 *  7. Relatório, com os artigos por base contados por linha e por família e as linhas em conflito.
 *
 * O cálculo (`plan`) é puro: recebe os dados em arrays e não toca no banco.
 */
final class JournalFamilyBuilder
{
    public const METHOD_ISSN = 'issn';
    public const METHOD_TITLE = 'title';
    public const METHOD_MANUAL = 'manual';

    /** Valor de `display_title_source` que a curadoria manual grava; essas linhas nunca recebem nome automático. */
    public const SOURCE_MANUAL = 'manual';

    /** Bases da página pública, na ordem fixa. */
    public const BASES = ['scopus', 'pubmed', 'scielo', 'wos', 'latindex'];

    /** Registro do artefato com mais ISSNs que isto não une nada nem empresta o título (junta revistas diferentes). */
    public const MAX_RECORD_ISSNS = 3;

    /**
     * Semelhança mínima entre títulos para valerem como a mesma revista (ver `titleScore`). Nos dados reais as semelhanças
     * entre grupos de uma mesma linha se separam em dois blocos, até 0,6 e a partir de 0,9: qualquer limiar entre 0,65 e
     * 0,9 decide igual.
     */
    public const TITLE_SIMILARITY_THRESHOLD = 0.7;

    /**
     * Semelhança dada a títulos em que a chave compacta de um está contida na do outro (2 palavras ou mais). Abaixo do
     * limiar, a contenção deixa de bastar para tornar dois títulos compatíveis.
     */
    public const CONTAINMENT_SCORE = 0.9;

    /** Tamanho máximo de `qualis_journals.display_title`. */
    private const DISPLAY_TITLE_MAX = 500;

    private const WRITE_CHUNK = 1000;
    private const PROGRESS_EVERY = 5000;

    /**
     * País de um registro (DOAJ e Latindex dão o nome; OpenAlex, o código): código ISO 3166-1 alfa-2 e nomes aceitos, um
     * país por linha, nomes separados por ponto e vírgula. Nome que não está aqui (como "Iberoamericanistas-Europa", do
     * Latindex) não vale como país.
     */
    private const COUNTRIES = <<<'TXT'
AE: United Arab Emirates
AF: Afghanistan
AL: Albania
AM: Armenia
AO: Angola
AR: Argentina
AT: Austria
AU: Australia
AX: Åland Islands
AZ: Azerbaijan
BA: Bosnia and Herzegovina
BB: Barbados
BD: Bangladesh
BE: Belgium
BF: Burkina Faso
BG: Bulgaria
BH: Bahrain
BN: Brunei Darussalam
BO: Bolivia, Plurinational State of; Bolivia
BR: Brazil; Brasil
BT: Bhutan
BY: Belarus
CA: Canada
CD: Congo, The Democratic Republic of the
CH: Switzerland
CI: Côte d'Ivoire
CL: Chile
CM: Cameroon
CN: China
CO: Colombia
CR: Costa Rica
CU: Cuba
CY: Cyprus
CZ: Czechia
DE: Germany
DK: Denmark
DO: Dominican Republic; República Dominicana
DZ: Algeria
EC: Ecuador
EE: Estonia
EG: Egypt
ES: Spain; España
ET: Ethiopia
FI: Finland
FR: France
GA: Gabon
GB: United Kingdom
GE: Georgia
GH: Ghana
GM: Gambia
GR: Greece
GT: Guatemala
GU: Guam
HK: Hong Kong
HN: Honduras
HR: Croatia
HU: Hungary
ID: Indonesia
IE: Ireland
IL: Israel
IN: India
IQ: Iraq
IR: Iran, Islamic Republic of
IS: Iceland
IT: Italy
JO: Jordan
JP: Japan
KE: Kenya
KG: Kyrgyzstan
KR: Korea, Republic of
KW: Kuwait
KZ: Kazakhstan
LB: Lebanon
LK: Sri Lanka
LT: Lithuania
LU: Luxembourg
LV: Latvia
LY: Libya
MA: Morocco
MD: Moldova, Republic of
ME: Montenegro
MK: North Macedonia
ML: Mali
MN: Mongolia
MT: Malta
MU: Mauritius
MW: Malawi
MX: Mexico; México
MY: Malaysia
MZ: Mozambique
NA: Namibia
NE: Niger
NG: Nigeria
NI: Nicaragua
NL: Netherlands
NO: Norway
NP: Nepal
NZ: New Zealand
OM: Oman
PA: Panama; Panamá
PE: Peru; Perú
PH: Philippines
PK: Pakistan
PL: Poland
PR: Puerto Rico
PS: Palestine, State of
PT: Portugal
PY: Paraguay
QA: Qatar
RO: Romania
RS: Serbia
RU: Russian Federation
RW: Rwanda
SA: Saudi Arabia
SE: Sweden
SG: Singapore
SI: Slovenia
SK: Slovakia
SN: Senegal
SS: South Sudan
SV: El Salvador
SY: Syrian Arab Republic
TG: Togo
TH: Thailand
TJ: Tajikistan
TL: Timor-Leste
TN: Tunisia
TR: Türkiye
TT: Trinidad and Tobago
TW: Taiwan, Province of China
TZ: Tanzania, United Republic of
UA: Ukraine
UG: Uganda
US: United States
UY: Uruguay
VE: Venezuela, Bolivarian Republic of; Venezuela
VN: Viet Nam
XK: Kosovo
YE: Yemen
ZA: South Africa
ZW: Zimbabwe
TXT;

    /**
     * Códigos MARC que o OpenAlex grava no lugar do ISO e que só querem dizer o país da direita (conferido com o país que
     * DOAJ e Latindex dão para os mesmos periódicos).
     */
    private const COUNTRY_MARC = [
        'AA' => 'AL', 'AG' => 'AR', 'AJ' => 'AZ', 'BL' => 'BR', 'BU' => 'BG', 'CC' => 'CN', 'CE' => 'LK', 'CK' => 'CO',
        'DR' => 'DO', 'EM' => 'TL', 'GW' => 'DE', 'HO' => 'HN', 'II' => 'IN', 'IO' => 'ID', 'JA' => 'JP', 'KO' => 'KR',
        'LE' => 'LB', 'NE' => 'NL', 'NQ' => 'NI', 'NR' => 'NG', 'PN' => 'PA', 'PO' => 'PT', 'RB' => 'RS', 'RM' => 'RO',
        'SP' => 'ES', 'SW' => 'SE', 'SZ' => 'CH', 'TS' => 'AE', 'TU' => 'TR', 'UN' => 'UA', 'VM' => 'VN', 'XN' => 'MK',
        'XO' => 'SK', 'XR' => 'CZ', 'XV' => 'SI',
    ];

    /** Códigos de 2 letras que o OpenAlex usa tanto como ISO quanto como MARC, com países diferentes: não valem. */
    private const COUNTRY_AMBIGUOUS = ['AE', 'BN', 'CI', 'LA', 'LI', 'LS', 'MO', 'SA', 'SD', 'SI', 'ST'];

    /** Subdivisões MARC de 3 letras (estado, província): o país é dado pela última letra. */
    private const MARC_SUBDIVISIONS = ['U' => 'US', 'C' => 'CA', 'K' => 'GB', 'A' => 'AU'];

    /** Para dobrar caixa e acentos na chave de artigo (A1.3 compara com a collation utf8mb4_unicode_ci). */
    private static ?\Transliterator $folder = null;

    /** @var array{names: array<string, string>, codes: array<string, true>}|null */
    private static ?array $countryTable = null;

    public function __construct(
        private readonly Connection $conn,
        private readonly JournalEvidenceArtifactReader $reader
    ) {
    }

    /**
     * Calcula as famílias e o nome exibido e, fora do `--dry-run`, grava o resultado.
     *
     * Sem artefato (arquivo ausente) roda só com os ISSNs do cadastro; o relatório traz `artifact.found = false`.
     * `$progress` recebe (etapa, itens feitos, total estimado); o total é 0 quando não se sabe.
     *
     * @param (callable(string, int, int): void)|null $progress
     *
     * @return array{
     *     families: int,
     *     multi: int,
     *     joined_by_title: int,
     *     ambiguous_titles: list<array{title: string, ids: list<int>, families: list<array{id: int, title: string}>}>,
     *     largest: int,
     *     by_db: array<string, array{row: int, family: int, row_items: int, family_items: int}>,
     *     rows: int,
     *     rows_without_issn: int,
     *     rows_without_normalized_title: int,
     *     manual_rows: int,
     *     manual_conflicts: int,
     *     conflict_rows: list<array{id: int, title: string, issns: list<string>, reason: string, groups: list<array{issns: list<string>, title: string, countries: list<string>, family: ?int}>, destination: ?array{id: int, title: string}, tie: bool, similarity: float, articles: int}>,
     *     similarity_threshold: float,
     *     artifact: array{file: string, found: bool, records: int, used: int, skipped_many_issns: int, skipped_contradicted: int, without_issn: int},
     *     display_sources: array<string, int>,
     *     largest_families: list<array{id: int, rows: int, title: string}>,
     *     updated: array{canonical: int, display_title: int},
     *     dry_run: bool
     * }
     *
     * @throws \RuntimeException artefato ilegível ou inválido
     */
    public function build(string $artifact, bool $dryRun, ?callable $progress = null): array
    {
        $found = is_file($artifact);

        [$rows, $withoutNormalizedTitle] = $this->loadRows();
        [$articles, $lattes] = $this->loadArticles();
        $bases = $this->loadBases();

        $records = $found
            ? fn (): iterable => $this->reader->read($artifact)
            : static fn (): iterable => [];

        $plan = $this->plan($rows, $articles, $lattes, $bases, $records, $progress, $found ? $this->recordsTotal($artifact) : 0);

        $canonicalChanges = [];
        foreach ($plan['assign'] as $id => [$canonical, $method]) {
            if ($rows[$id]['canonical'] !== $canonical || $rows[$id]['method'] !== $method) {
                $canonicalChanges[$id] = [$canonical, $method];
            }
        }

        $titleChanges = [];
        foreach ($plan['titles'] as $id => $chosen) {
            if ($rows[$id]['displayTitle'] !== $chosen['title'] || $rows[$id]['displayTitleSource'] !== $chosen['source']) {
                $titleChanges[$id] = $chosen;
            }
        }

        $baseLinksAdded = 0;
        if (!$dryRun) {
            $this->apply($canonicalChanges, $titleChanges, $progress);
            $baseLinksAdded = $this->propagateBaseLinks();
        }

        $report = $plan['report'];
        $report['base_links_added'] = $baseLinksAdded;
        $report['rows_without_normalized_title'] = $withoutNormalizedTitle;
        $report['artifact']['file'] = $artifact;
        $report['artifact']['found'] = $found;
        $report['updated'] = ['canonical' => count($canonicalChanges), 'display_title' => count($titleChanges)];
        $report['dry_run'] = $dryRun;

        return $report;
    }

    /**
     * Cálculo puro das famílias. Não acessa o banco.
     *
     * @param array<int, array{title: string, normalizedTitle: ?string, issns: list<string>, canonical: ?int, method: ?string, displayTitle: ?string, displayTitleSource: ?string}> $rows
     *        linhas de `qualis_journals` indexadas pelo id; `issns` = ISSNs válidos normalizados das 4 colunas
     * @param array<int, list<string>> $articles chaves dos artigos (ARTIGO) vinculados a cada linha, com repetição
     * @param array<int, array<array-key, int>> $lattes por linha: grafia de `journal_name` do Lattes => ocorrências
     * @param array<int, list<string>> $bases por linha: acrônimos das bases a que está vinculada
     * @param callable(): iterable<array<string, mixed>> $records registros do artefato; chamada uma vez por passada (duas)
     * @param (callable(string, int, int): void)|null $progress
     * @param float $similarityThreshold limiar de compatibilidade entre títulos (ver `titleScore`)
     *
     * @return array{
     *     assign: array<int, array{0: ?int, 1: ?string}>,
     *     heads: array<int, int>,
     *     titles: array<int, array{title: string, source: string}>,
     *     report: array<string, mixed>
     * } `assign`: canonical_journal_id e canonical_method finais das linhas que o algoritmo pode alterar (as manuais
     *   ficam de fora); `heads`: canônica final de toda linha; `titles`: nome exibido das linhas que aceitam (as de
     *   `display_title_source = 'manual'` ficam de fora)
     */
    public function plan(
        array $rows,
        array $articles,
        array $lattes,
        array $bases,
        callable $records,
        ?callable $progress = null,
        int $recordsTotal = 0,
        float $similarityThreshold = self::TITLE_SIMILARITY_THRESHOLD
    ): array {
        ksort($rows);

        // Linhas que o algoritmo não altera: as manuais e as cabeças de famílias manuais
        $manualHead = [];
        $fixed = [];
        foreach ($rows as $id => $row) {
            if ($row['method'] !== self::METHOD_MANUAL) {
                continue;
            }
            $head = ($row['canonical'] !== null && $row['canonical'] !== $id && isset($rows[$row['canonical']])) ? $row['canonical'] : $id;
            $manualHead[$id] = $head;
            $fixed[$id] = true;
            $fixed[$head] = true;
        }
        $protectedHeads = array_flip($manualHead);

        // Passo 1: cada ISSN do cadastro vira um nó do union-find. As linhas ainda não unem nada
        $nodeOf = [];
        $parent = [];
        $rowNodes = [];
        $rowsWithoutIssn = 0;
        foreach ($rows as $id => $row) {
            if ($row['issns'] === []) {
                ++$rowsWithoutIssn;
                continue;
            }
            foreach ($row['issns'] as $issn) {
                $rowNodes[$id][] = self::node($nodeOf, $parent, $issn);
            }
        }

        // Títulos das linhas sem ISSN que ainda podem entrar numa família por título (passo 4)
        $targetTitles = [];
        foreach ($rows as $id => $row) {
            if ($row['normalizedTitle'] !== null && $row['normalizedTitle'] !== '' && !isset($rowNodes[$id]) && !isset($fixed[$id])) {
                $targetTitles[$row['normalizedTitle']] = true;
            }
        }

        // Registro de 3 ISSNs que duas ou mais outras fontes contradizem não une nada nem empresta o título
        $contradicted = self::contradictedRecords($records, $progress, $recordsTotal);

        // Passo 2a, primeira passada no artefato: os grupos externos. Só registro com no máximo 3 ISSNs une ISSNs
        $artifactStats = [
            'file' => '',
            'found' => true,
            'records' => 0,
            'used' => 0,
            'skipped_many_issns' => 0,
            'skipped_contradicted' => 0,
            'without_issn' => 0,
        ];
        $titleNodes = [];
        foreach ($records() as $record) {
            ++$artifactStats['records'];
            if ($progress !== null && $artifactStats['records'] % self::PROGRESS_EVERY === 0) {
                $progress('artefato 3/4: ISSNs e títulos', $artifactStats['records'], $recordsTotal);
            }

            $issns = self::recordIssns($record);
            $count = count($issns);
            if ($count === 0) {
                ++$artifactStats['without_issn'];
                continue;
            }
            if ($count > self::MAX_RECORD_ISSNS) {
                ++$artifactStats['skipped_many_issns'];
                continue;
            }
            if ($count === self::MAX_RECORD_ISSNS && isset($contradicted[self::recordKey($record, $issns)])) {
                ++$artifactStats['skipped_contradicted'];
                continue;
            }

            ++$artifactStats['used'];
            $first = self::node($nodeOf, $parent, $issns[0]);
            for ($i = 1; $i < $count; ++$i) {
                self::union($parent, $first, self::node($nodeOf, $parent, $issns[$i]));
            }

            if ($targetTitles !== [] && is_string($record['title'] ?? null)) {
                $normalized = StringNormalizer::normalizeString($record['title'], true);
                if ($normalized !== '' && isset($targetTitles[$normalized])) {
                    $titleNodes[$normalized][] = $first;
                }
            }
        }
        if ($progress !== null && $artifactStats['records'] > 0) {
            $progress('artefato 3/4: ISSNs e títulos', $artifactStats['records'], $recordsTotal);
        }

        // Grupos externos que têm algum ISSN do cadastro: só deles interessam títulos, países e nomes das fontes
        $catalogRoots = [];
        foreach ($rowNodes as $nodes) {
            foreach ($nodes as $node) {
                $catalogRoots[self::find($parent, $node)] = true;
            }
        }

        // Passo 2b, segunda passada: títulos e países de cada grupo externo (para decidir se duas linhas do mesmo ISSN são
        // a mesma revista) e título de cada fonte (para o nome exibido). `$live` são os grupos que o cadastro vai unindo:
        // começam iguais aos externos e trazem os títulos, os países e os grupos externos de origem.
        $live = [];
        $candidates = [];
        if ($catalogRoots !== []) {
            $done = 0;
            foreach ($records() as $record) {
                if ($progress !== null && ++$done % self::PROGRESS_EVERY === 0) {
                    $progress('artefato 4/4: títulos e países dos grupos', $done, $recordsTotal);
                }

                $title = $record['title'] ?? null;
                if (!is_string($title) || trim($title) === '') {
                    continue;
                }

                $issns = self::recordIssns($record);
                $count = count($issns);
                if ($count === 0 || $count > self::MAX_RECORD_ISSNS) {
                    continue;
                }
                if ($count === self::MAX_RECORD_ISSNS && isset($contradicted[self::recordKey($record, $issns)])) {
                    continue;
                }
                $node = $nodeOf[$issns[0]] ?? null;
                if ($node === null) {
                    continue;
                }

                $root = self::find($parent, $node);
                if (!isset($catalogRoots[$root])) {
                    continue;
                }

                $source = self::recordSource($record);
                $live[$root] ??= ['titles' => [], 'countries' => [], 'ext' => [$root]];

                $key = self::titleKey($title);
                if ($key !== '') {
                    $live[$root]['titles'][$key] ??= $title;
                }
                $country = self::recordCountry($source, $record);
                if ($country !== null) {
                    $live[$root]['countries'][$country] = true;
                }
                if (in_array($source, DisplayTitle::SOURCE_ORDER, true)) {
                    $candidates[$root][$source] ??= $title;
                }
            }
            if ($progress !== null && $done > 0) {
                $progress('artefato 4/4: títulos e países dos grupos', $done, $recordsTotal);
            }
        }

        // Família manual vale como uma unidade (decisão humana, sem checar títulos nem países)
        foreach ($manualHead as $id => $head) {
            if ($head !== $id && isset($rowNodes[$id], $rowNodes[$head])) {
                self::unite($parent, $live, $rowNodes[$id][0], $rowNodes[$head][0]);
            }
        }

        // Passo 2c: o cadastro só une grupos externos quando os títulos deles são compatíveis e, havendo país conhecido
        // nos dois lados, os países se cruzam. Uma linha cujos ISSNs caem em grupos incompatíveis é "linha em conflito":
        // não une nada (nenhuma união passa por ela) e entra só no grupo de título mais parecido com o dela, se passar do
        // limiar e sem empate; senão fica isolada. As linhas seguem na ordem do id; um ISSN que nenhuma fonte lista (sem
        // título externo) acompanha a linha que o traz.
        $conflicts = [];
        foreach ($rows as $id => $row) {
            $nodes = $rowNodes[$id] ?? [];
            if (count($nodes) < 2) {
                continue;
            }

            if (isset($manualHead[$id])) {
                for ($i = 1, $n = count($nodes); $i < $n; ++$i) {
                    self::unite($parent, $live, $nodes[0], $nodes[$i]);
                }
                continue;
            }

            $roots = [];
            foreach ($nodes as $node) {
                $roots[self::find($parent, $node)] = true;
            }
            $roots = array_keys($roots);
            if (count($roots) === 1) {
                continue;
            }
            sort($roots);

            $titled = [];
            foreach ($roots as $root) {
                if (($live[$root]['titles'] ?? []) !== []) {
                    $titled[] = $root;
                }
            }

            if (count($titled) >= 2) {
                $reason = self::incompatibility($titled, $live, $similarityThreshold);
                if ($reason !== null) {
                    $conflicts[$id] = self::conflictOf($row, $nodes, $titled, $live, $parent, $similarityThreshold, $reason);
                    continue;
                }
            }

            foreach ($roots as $root) {
                self::unite($parent, $live, $roots[0], $root);
            }
        }

        // Passo 3: grupos de linhas com ISSN. A linha em conflito entra só no grupo de destino (ou fica sozinha)
        $groupRows = [];
        $rowGroup = [];
        $titleRowRoots = [];
        foreach ($rowNodes as $id => $nodes) {
            if (isset($conflicts[$id])) {
                $destination = $conflicts[$id]['destination'];
                $key = $destination !== null ? self::find($parent, $destination) : -$id;
            } else {
                $key = self::find($parent, $nodes[0]);
            }
            $rowGroup[$id] = $key;
            $groupRows[$key][] = $id;

            $normalized = $rows[$id]['normalizedTitle'];
            if ($normalized !== null && $normalized !== '' && isset($targetTitles[$normalized])) {
                $titleRowRoots[$normalized][$key] = true;
            }
        }

        // Passo 4: linha sem ISSN entra no único grupo apontado pelo seu título; vários grupos = ambígua
        $resolution = [];
        $attached = [];
        $ambiguous = [];
        foreach ($rows as $id => $row) {
            $normalized = $row['normalizedTitle'];
            if (isset($rowNodes[$id]) || isset($fixed[$id]) || $normalized === null || $normalized === '') {
                continue;
            }

            if (!isset($resolution[$normalized])) {
                $roots = $titleRowRoots[$normalized] ?? [];
                foreach ($titleNodes[$normalized] ?? [] as $node) {
                    $root = self::find($parent, $node);
                    if (isset($groupRows[$root])) {
                        $roots[$root] = true;
                    }
                }
                $resolution[$normalized] = array_keys($roots);
                sort($resolution[$normalized]);
            }

            $roots = $resolution[$normalized];
            if (count($roots) === 1) {
                $attached[$id] = $roots[0];
            } elseif (count($roots) > 1) {
                $ambiguous[$normalized][] = $id;
            }
        }

        // Passo 5: canônica de cada grupo
        $articleCount = [];
        foreach ($articles as $id => $keys) {
            $articleCount[$id] = count($keys);
        }

        $groups = $groupRows;
        foreach ($attached as $id => $root) {
            $groups[$root][] = $id;
        }
        ksort($groups);

        $assign = [];
        $groupHead = [];
        $manualConflicts = 0;
        foreach ($groups as $root => $ids) {
            sort($ids);

            // Canônicas impostas pela curadoria: a cabeça de cada linha manual do grupo e qualquer cabeça de família manual
            $manualHeads = [];
            foreach ($ids as $id) {
                if (isset($manualHead[$id])) {
                    $manualHeads[$manualHead[$id]] = true;
                }
                if (isset($protectedHeads[$id])) {
                    $manualHeads[$id] = true;
                }
            }
            $manualHeads = array_keys($manualHeads);
            if (count($manualHeads) > 1) {
                ++$manualConflicts;
            }

            $head = self::bestCanonical($manualHeads !== [] ? $manualHeads : $ids, $articleCount, $rows, $conflicts);
            $groupHead[$root] = $head;

            foreach ($ids as $id) {
                if (isset($fixed[$id])) {
                    continue;
                }
                $assign[$id] = $id === $head
                    ? [null, null]
                    : [$head, isset($attached[$id]) ? self::METHOD_TITLE : self::METHOD_ISSN];
            }
        }
        foreach ($rows as $id => $row) {
            if (!isset($assign[$id]) && !isset($fixed[$id])) {
                $assign[$id] = [null, null];
            }
        }

        $heads = [];
        foreach ($rows as $id => $row) {
            $heads[$id] = isset($assign[$id])
                ? ($assign[$id][0] ?? $id)
                : ($manualHead[$id] ?? ($row['canonical'] !== null && isset($rows[$row['canonical']]) ? $row['canonical'] : $id));
        }

        $familyMembers = [];
        foreach ($heads as $id => $head) {
            $familyMembers[$head][] = $id;
        }
        ksort($familyMembers);

        // Passo 6: nome exibido por família
        $titles = [];
        $displaySources = [];
        foreach ($familyMembers as $head => $ids) {
            $keys = [];
            $names = [];
            foreach ($ids as $id) {
                if (isset($rowGroup[$id])) {
                    $keys[$rowGroup[$id]] = true;
                }
                foreach ($lattes[$id] ?? [] as $name => $times) {
                    $names[$name] = ($names[$name] ?? 0) + $times;
                }
            }

            $bySource = [];
            $keys = array_keys($keys);
            sort($keys);
            foreach ($keys as $key) {
                $externals = $live[$key]['ext'] ?? [];
                sort($externals);
                foreach ($externals as $external) {
                    foreach ($candidates[$external] ?? [] as $source => $title) {
                        $bySource[$source][] = $title;
                    }
                }
            }

            $chosen = DisplayTitle::choose($bySource, $names, $rows[$head]['title'], array_map(static fn (int|string $id): string => (string)($rows[$id]['title'] ?? ''), $ids));
            if ($chosen['title'] === '') {
                continue;
            }
            $chosen['title'] = mb_substr($chosen['title'], 0, self::DISPLAY_TITLE_MAX, 'UTF-8');

            foreach ($ids as $id) {
                if ($rows[$id]['displayTitleSource'] === self::SOURCE_MANUAL) {
                    continue;
                }
                $titles[$id] = $chosen;
                $displaySources[$chosen['source']] = ($displaySources[$chosen['source']] ?? 0) + 1;
            }
        }
        ksort($displaySources);

        // Passo 7: relatório
        $sizes = array_map('count', $familyMembers);
        arsort($sizes);
        $largestFamilies = [];
        foreach (array_slice($sizes, 0, 5, true) as $head => $size) {
            $largestFamilies[] = ['id' => $head, 'rows' => $size, 'title' => $rows[$head]['title']];
        }

        $ambiguousTitles = [];
        foreach ($ambiguous as $normalized => $ids) {
            $families = [];
            foreach ($resolution[$normalized] as $root) {
                $head = $groupHead[$root];
                $families[$head] = ['id' => $head, 'title' => $rows[$head]['title']];
            }
            ksort($families);
            $ambiguousTitles[] = ['title' => (string) $normalized, 'ids' => $ids, 'families' => array_values($families)];
        }
        usort($ambiguousTitles, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        $conflictRows = [];
        foreach ($conflicts as $id => $conflict) {
            $conflictGroups = [];
            foreach ($conflict['groups'] as $group) {
                $head = $groupHead[self::find($parent, $group['root'])] ?? null;
                $conflictGroups[] = [
                    'issns' => array_map(Issn::format(...), $group['issns']),
                    'title' => $group['title'],
                    'countries' => $group['countries'],
                    'family' => $head,
                ];
            }

            $destination = null;
            if ($conflict['destination'] !== null) {
                $head = $groupHead[self::find($parent, $conflict['destination'])];
                $destination = ['id' => $head, 'title' => $rows[$head]['title']];
            }

            $conflictRows[] = [
                'id' => $id,
                'title' => $rows[$id]['title'],
                'issns' => array_map(Issn::format(...), $rows[$id]['issns']),
                'reason' => $conflict['reason'],
                'groups' => $conflictGroups,
                'destination' => $destination,
                'tie' => $conflict['tie'],
                'similarity' => round($conflict['score'], 2),
                'articles' => $articleCount[$id] ?? 0,
            ];
        }
        usort($conflictRows, static fn (array $a, array $b): int => $b['articles'] <=> $a['articles'] ?: $a['id'] <=> $b['id']);

        $joinedByTitle = 0;
        foreach ($assign as [, $method]) {
            if ($method === self::METHOD_TITLE) {
                ++$joinedByTitle;
            }
        }

        return [
            'assign' => $assign,
            'heads' => $heads,
            'titles' => $titles,
            'report' => [
                'families' => count($familyMembers),
                'multi' => count(array_filter($sizes, static fn (int $size): bool => $size > 1)),
                'joined_by_title' => $joinedByTitle,
                'ambiguous_titles' => $ambiguousTitles,
                'largest' => $sizes === [] ? 0 : max($sizes),
                'by_db' => self::articlesByBase($heads, $familyMembers, $articles, $bases),
                'rows' => count($rows),
                'rows_without_issn' => $rowsWithoutIssn,
                'manual_rows' => count($manualHead),
                'manual_conflicts' => $manualConflicts,
                'conflict_rows' => $conflictRows,
                'similarity_threshold' => $similarityThreshold,
                'artifact' => $artifactStats,
                'display_sources' => $displaySources,
                'largest_families' => $largestFamilies,
            ],
        ];
    }

    /**
     * Artigos por base, somados por linha (cada linha vinculada à base conta os seus) e por família (cada família com
     * alguma linha vinculada à base conta os de todas as suas linhas).
     *
     * `row` e `family` contam artigos distintos, com a chave de A1.3 (coautorias entre docentes contam uma vez): é o
     * que a página pública mostra. `row_items` e `family_items` contam os itens ARTIGO sem deduplicar, que é como os
     * números do cabeçalho de A0 foram medidos (SciELO 1.101, Scopus 3.036...).
     *
     * @param array<int, int> $heads
     * @param array<int, list<int>> $familyMembers
     * @param array<int, list<string>> $articles
     * @param array<int, list<string>> $bases
     *
     * @return array<string, array{row: int, family: int, row_items: int, family_items: int}>
     */
    private static function articlesByBase(array $heads, array $familyMembers, array $articles, array $bases): array
    {
        $byDb = [];
        foreach (self::BASES as $acronym) {
            $byDb[$acronym] = ['row' => 0, 'family' => 0, 'row_items' => 0, 'family_items' => 0];
        }

        $familyBases = [];
        foreach ($bases as $id => $acronyms) {
            if (!isset($heads[$id])) {
                continue;
            }

            $items = count($articles[$id] ?? []);
            $distinct = count(array_unique($articles[$id] ?? []));
            foreach ($acronyms as $acronym) {
                if (isset($byDb[$acronym])) {
                    $byDb[$acronym]['row'] += $distinct;
                    $byDb[$acronym]['row_items'] += $items;
                    $familyBases[$heads[$id]][$acronym] = true;
                }
            }
        }

        foreach ($familyBases as $head => $acronyms) {
            $keys = [];
            $items = 0;
            foreach ($familyMembers[$head] as $id) {
                foreach ($articles[$id] ?? [] as $key) {
                    $keys[$key] = true;
                    ++$items;
                }
            }
            foreach (array_keys($acronyms) as $acronym) {
                $byDb[$acronym]['family'] += count($keys);
                $byDb[$acronym]['family_items'] += $items;
            }
        }

        return $byDb;
    }

    /**
     * Escolhe a canônica: mais artigos, depois mais ISSNs válidos, depois menor id. Uma linha em conflito só é canônica
     * quando o grupo não tem outra: os ISSNs dela não são confiáveis e apareceriam na página como os da revista.
     *
     * @param list<int> $candidates
     * @param array<int, int> $articleCount
     * @param array<int, array{issns: list<string>}> $rows
     * @param array<int, mixed> $conflicts linhas em conflito, indexadas pelo id
     */
    private static function bestCanonical(array $candidates, array $articleCount, array $rows, array $conflicts): int
    {
        usort($candidates, static function (int $a, int $b) use ($articleCount, $rows, $conflicts): int {
            return isset($conflicts[$a]) <=> isset($conflicts[$b])
                ?: ($articleCount[$b] ?? 0) <=> ($articleCount[$a] ?? 0)
                ?: count($rows[$b]['issns']) <=> count($rows[$a]['issns'])
                ?: $a <=> $b;
        });

        return $candidates[0];
    }

    /**
     * ISSNs válidos de um registro do artefato, normalizados (8 caracteres), sem repetição.
     *
     * @param array<string, mixed> $record
     *
     * @return list<string>
     */
    private static function recordIssns(array $record): array
    {
        $raw = $record['issns'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $issns = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }
            foreach (Issn::extractAll($value) as $issn) {
                if (!in_array($issn, $issns, true)) {
                    $issns[] = $issn;
                }
            }
        }

        return $issns;
    }

    // ---- union-find de ISSNs: nós inteiros em arrays empacotados; a raiz é sempre o menor nó (determinístico)

    /**
     * @param array<array-key, int> $nodeOf ISSN => nó
     * @param list<int> $parent
     */
    private static function node(array &$nodeOf, array &$parent, string $issn): int
    {
        $node = $nodeOf[$issn] ?? null;
        if ($node === null) {
            $node = count($parent);
            $nodeOf[$issn] = $node;
            $parent[] = $node;
        }

        return $node;
    }

    /** @param list<int> $parent */
    private static function find(array &$parent, int $node): int
    {
        while ($parent[$node] !== $node) {
            $parent[$node] = $parent[$parent[$node]];
            $node = $parent[$node];
        }

        return $node;
    }

    /** @param list<int> $parent */
    private static function union(array &$parent, int $a, int $b): void
    {
        $a = self::find($parent, $a);
        $b = self::find($parent, $b);
        if ($a < $b) {
            $parent[$b] = $a;
        } elseif ($b < $a) {
            $parent[$a] = $b;
        }
    }

    /**
     * Une dois grupos vivos e junta os dados que andam com eles (títulos externos, países e grupos externos de origem).
     *
     * @param list<int> $parent
     * @param array<int, array{titles: array<array-key, string>, countries: array<string, true>, ext: list<int>}> $live
     */
    private static function unite(array &$parent, array &$live, int $a, int $b): void
    {
        $a = self::find($parent, $a);
        $b = self::find($parent, $b);
        if ($a === $b) {
            return;
        }

        [$keep, $drop] = $a < $b ? [$a, $b] : [$b, $a];
        $parent[$drop] = $keep;

        if (isset($live[$drop])) {
            if (isset($live[$keep])) {
                $live[$keep]['titles'] += $live[$drop]['titles'];
                $live[$keep]['countries'] += $live[$drop]['countries'];
                $live[$keep]['ext'] = array_merge($live[$keep]['ext'], $live[$drop]['ext']);
            } else {
                $live[$keep] = $live[$drop];
            }
            unset($live[$drop]);
        }
    }

    // ---- compatibilidade de títulos

    /**
     * Chave de comparação de um título: minúsculas, sem acento nem pontuação, entidades HTML decodificadas. Usa o
     * normalizador do tesauro, que serve a qualquer alfabeto (a forma do resolver apagaria títulos em cirílico).
     */
    public static function titleKey(string $title): string
    {
        return StringNormalizer::normalizeString(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false);
    }

    /**
     * Semelhança entre dois títulos, de 0 a 1: 1 quando a chave compacta (sem conectivos, `JournalResolverService::compactKey`)
     * é igual; CONTAINMENT_SCORE quando uma está contida na outra com 2 palavras ou mais; nos demais casos, a similaridade
     * de Jaccard das palavras (sem os conectivos). Vale a melhor combinação entre o título inteiro e o título sem os
     * trechos entre parênteses, porque o cadastro qualifica ("Perspectiva (Florianópolis)") e as fontes não.
     */
    public static function titleScore(string $a, string $b): float
    {
        $keyA = self::titleKey($a);
        $keyB = self::titleKey($b);

        return $keyA !== '' && $keyB !== '' ? self::pairScore($a, $keyA, $b, $keyB) : 0.0;
    }

    /** Dois títulos são compatíveis quando `titleScore` chega ao limiar. */
    public static function titlesCompatible(string $a, string $b, float $threshold = self::TITLE_SIMILARITY_THRESHOLD): bool
    {
        return self::titleScore($a, $b) >= $threshold;
    }

    /** @return list<string> a chave do título e, se mudar, a chave do título sem os parênteses */
    private static function keyVariants(string $title, string $key): array
    {
        if (!str_contains($title, '(')) {
            return [$key];
        }

        $stripped = self::titleKey(JournalResolverService::stripParentheses($title));

        return $stripped !== '' && $stripped !== $key ? [$key, $stripped] : [$key];
    }

    private static function pairScore(string $titleA, string $keyA, string $titleB, string $keyB): float
    {
        $best = self::keyScore($keyA, $keyB);
        if ($best >= 1.0) {
            return $best;
        }

        $variantsA = self::keyVariants($titleA, $keyA);
        $variantsB = self::keyVariants($titleB, $keyB);
        if (count($variantsA) === 1 && count($variantsB) === 1) {
            return $best;
        }

        foreach ($variantsA as $variantA) {
            foreach ($variantsB as $variantB) {
                $best = max($best, self::keyScore($variantA, $variantB));
            }
        }

        return $best;
    }

    private static function keyScore(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $compactA = JournalResolverService::compactKey($a);
        $compactB = JournalResolverService::compactKey($b);

        $floor = 0.0;
        if ($compactA !== null && $compactB !== null) {
            if ($compactA === $compactB) {
                return 1.0;
            }
            if (self::containsTokens($compactA, $compactB) || self::containsTokens($compactB, $compactA)) {
                $floor = self::CONTAINMENT_SCORE;
            }
        }

        $tokensA = array_unique(explode(' ', $compactA ?? $a));
        $tokensB = array_unique(explode(' ', $compactB ?? $b));
        $union = count(array_unique(array_merge($tokensA, $tokensB)));
        $jaccard = $union > 0 ? count(array_intersect($tokensA, $tokensB)) / $union : 0.0;

        return max($floor, $jaccard);
    }

    /** `$shorter` aparece em `$longer` como sequência de palavras inteiras. */
    private static function containsTokens(string $longer, string $shorter): bool
    {
        return str_contains(' ' . $longer . ' ', ' ' . $shorter . ' ');
    }

    /**
     * Maior semelhança entre um título de cada lado.
     *
     * @param array<array-key, string> $a chave do título => título original
     * @param array<array-key, string> $b
     */
    private static function groupScore(array $a, array $b): float
    {
        $best = 0.0;
        foreach ($a as $keyA => $titleA) {
            foreach ($b as $keyB => $titleB) {
                $best = max($best, self::pairScore($titleA, (string) $keyA, $titleB, (string) $keyB));
                if ($best >= 1.0) {
                    return $best;
                }
            }
        }

        return $best;
    }

    /**
     * Por que a linha não pode unir os grupos dela, ou null se pode. Os grupos só se unem se todos os pares forem
     * compatíveis (nunca une incompatíveis por encadeamento): títulos de semelhança abaixo do limiar ("title") ou países
     * conhecidos nos dois lados que não se cruzam ("country"); grupo sem país conhecido não dispara a segunda regra. Se
     * os dois motivos aparecem, vale "title".
     *
     * @param list<int> $roots raízes dos grupos com título externo
     * @param array<int, array{titles: array<array-key, string>, countries: array<string, true>, ext: list<int>}> $live
     *
     * @return 'title'|'country'|null
     */
    private static function incompatibility(array $roots, array $live, float $threshold): ?string
    {
        $countries = false;
        for ($i = 0, $n = count($roots); $i < $n; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                if (self::groupScore($live[$roots[$i]]['titles'], $live[$roots[$j]]['titles']) < $threshold) {
                    return 'title';
                }
                if (!self::countriesIntersect($live[$roots[$i]]['countries'], $live[$roots[$j]]['countries'])) {
                    $countries = true;
                }
            }
        }

        return $countries ? 'country' : null;
    }

    /**
     * Os países de dois grupos não se opõem: algum lado não tem país conhecido ou os conjuntos se cruzam.
     *
     * @param array<string, true> $a
     * @param array<string, true> $b
     */
    private static function countriesIntersect(array $a, array $b): bool
    {
        return $a === [] || $b === [] || array_intersect_key($a, $b) !== [];
    }

    /**
     * Descreve uma linha em conflito: motivo, grupos envolvidos (ISSNs da linha em cada um, um título externo e os países)
     * e o destino, que é o grupo de título mais parecido com o da linha, desde que passe do limiar e que nenhum outro
     * grupo empate com ele; num empate o título não diz de qual revista a linha é, e ela fica isolada.
     *
     * @param array{title: string, issns: list<string>} $row
     * @param list<int> $nodes nó de cada ISSN da linha, na mesma ordem de `$row['issns']`
     * @param list<int> $titled raízes dos grupos com título externo, em ordem crescente
     * @param array<int, array{titles: array<array-key, string>, countries: array<string, true>, ext: list<int>}> $live
     * @param list<int> $parent
     *
     * @return array{destination: ?int, score: float, tie: bool, reason: string, groups: list<array{root: int, issns: list<string>, title: string, countries: list<string>}>}
     */
    private static function conflictOf(array $row, array $nodes, array $titled, array $live, array &$parent, float $threshold, string $reason): array
    {
        $rowKey = self::titleKey($row['title']);

        $scores = [];
        $best = 0.0;
        $groups = [];
        foreach ($titled as $root) {
            $scores[$root] = $rowKey !== '' ? self::groupScore([$rowKey => $row['title']], $live[$root]['titles']) : 0.0;
            $best = max($best, $scores[$root]);

            $titles = $live[$root]['titles'];
            $first = reset($titles);
            $countries = array_keys($live[$root]['countries']);
            sort($countries);
            $groups[$root] = ['root' => $root, 'issns' => [], 'title' => $first !== false ? $first : '', 'countries' => $countries];
        }

        $destination = null;
        $tie = false;
        if ($best >= $threshold) {
            $winners = array_keys(array_filter($scores, static fn (float $score): bool => $score >= $best - 1e-9));
            if (count($winners) === 1) {
                $destination = $winners[0];
            } else {
                $tie = true;
            }
        }

        foreach ($nodes as $index => $node) {
            $root = self::find($parent, $node);
            if (isset($groups[$root])) {
                $groups[$root]['issns'][] = $row['issns'][$index];
            }
        }

        return [
            'destination' => $destination,
            'score' => $best,
            'tie' => $tie,
            'reason' => $reason,
            'groups' => array_values($groups),
        ];
    }

    // ---- registros do artefato: contradição entre fontes e país

    /**
     * Registros de exatamente 3 ISSNs que duas ou mais outras fontes contradizem. Um registro é contradito quando existem
     * dois registros, de duas fontes diferentes entre si e da fonte dele, que trazem cada um só parte dos 3 ISSNs (um ou
     * dois) e sem nenhum ISSN em comum: as duas fontes tratam esses ISSNs como revistas separadas. O Latindex junta "Íconos"
     * (1390-1249, 1390-8065) com "Foro" (1390-2466) num registro só, enquanto DOAJ, Scopus, OpenAlex e WoS os listam à parte.
     * Qualquer registro de qualquer tamanho vale como contraprova.
     *
     * @param callable(): iterable<array<string, mixed>> $records
     * @param (callable(string, int, int): void)|null $progress
     *
     * @return array<string, true> chaves de `recordKey`
     */
    private static function contradictedRecords(callable $records, ?callable $progress, int $recordsTotal): array
    {
        // Primeira passada: os registros de 3 ISSNs e os ISSNs que eles trazem
        $triples = [];
        $indexOf = [];
        $watch = [];
        $read = 0;
        foreach ($records() as $record) {
            if ($progress !== null && ++$read % self::PROGRESS_EVERY === 0) {
                $progress('artefato 1/4: registros de 3 ISSNs', $read, $recordsTotal);
            }

            $raw = $record['issns'] ?? null;
            if (!is_array($raw) || count($raw) < self::MAX_RECORD_ISSNS) {
                continue;
            }

            $issns = self::recordIssns($record);
            if (count($issns) !== self::MAX_RECORD_ISSNS) {
                continue;
            }

            $key = self::recordKey($record, $issns);
            if (isset($indexOf[$key])) {
                continue;
            }

            $indexOf[$key] = count($triples);
            $triples[] = [self::recordSource($record), $issns, $key];
            foreach ($issns as $issn) {
                $watch[$issn][] = $indexOf[$key];
            }
        }
        if ($progress !== null && $read > 0) {
            $progress('artefato 1/4: registros de 3 ISSNs', $read, $recordsTotal);
        }
        if ($triples === []) {
            return [];
        }

        // Segunda passada: o que cada outra fonte diz sobre os ISSNs de cada registro de 3 (máscara de bits dos que ela traz)
        $evidence = [];
        $done = 0;
        foreach ($records() as $record) {
            if ($progress !== null && ++$done % self::PROGRESS_EVERY === 0) {
                $progress('artefato 2/4: registros contraditos', $done, $recordsTotal);
            }

            $issns = self::recordIssns($record);
            $touched = [];
            foreach ($issns as $issn) {
                foreach ($watch[$issn] ?? [] as $index) {
                    $touched[$index] = true;
                }
            }
            if ($touched === []) {
                continue;
            }

            $source = self::recordSource($record);
            foreach (array_keys($touched) as $index) {
                [$tripleSource, $tripleIssns] = $triples[$index];
                if ($tripleSource === $source) {
                    continue;
                }

                $mask = 0;
                foreach ($tripleIssns as $bit => $issn) {
                    if (in_array($issn, $issns, true)) {
                        $mask |= 1 << $bit;
                    }
                }
                if ($mask !== 7) {
                    $evidence[$index][$source . ':' . $mask] = [$source, $mask];
                }
            }
        }
        if ($progress !== null && $done > 0) {
            $progress('artefato 2/4: registros contraditos', $done, $recordsTotal);
        }

        $contradicted = [];
        foreach ($evidence as $index => $entries) {
            $entries = array_values($entries);
            for ($i = 0, $n = count($entries); $i < $n; ++$i) {
                for ($j = $i + 1; $j < $n; ++$j) {
                    if ($entries[$i][0] !== $entries[$j][0] && ($entries[$i][1] & $entries[$j][1]) === 0) {
                        $contradicted[$triples[$index][2]] = true;
                        break 2;
                    }
                }
            }
        }

        return $contradicted;
    }

    /**
     * Chave de um registro para saber se foi contradito: a fonte e os ISSNs em ordem crescente.
     *
     * @param array<string, mixed> $record
     * @param list<string> $issns
     */
    private static function recordKey(array $record, array $issns): string
    {
        sort($issns);

        return self::recordSource($record) . '|' . implode(',', $issns);
    }

    /** @param array<string, mixed> $record */
    private static function recordSource(array $record): string
    {
        return is_string($record['src'] ?? null) ? $record['src'] : '';
    }

    /**
     * País do registro, normalizado para o código ISO 3166-1 alfa-2, ou null. Só DOAJ, Latindex e OpenAlex trazem país.
     *
     * @param array<string, mixed> $record
     */
    private static function recordCountry(string $source, array $record): ?string
    {
        $extra = $record['extra'] ?? null;
        $raw = is_array($extra) ? ($extra['country'] ?? null) : null;

        return is_string($raw) ? self::countryCode($source, $raw) : null;
    }

    /**
     * Código ISO 3166-1 alfa-2 do país como cada fonte o grava, ou null quando não dá para saber:
     *  - DOAJ (nome em inglês, "Brazil", "Korea, Republic of") e Latindex (nome em espanhol, "Brasil", "México");
     *  - OpenAlex (`country_code`): quase sempre ISO ("BR", "GB"), mas parte vem em código MARC ("BL" é o Brasil, "IO" a
     *    Indonésia, "XXK" o Reino Unido, "CAU" a Califórnia). Subdivisão MARC de 3 letras vale pelo país da última letra;
     *    código de 2 letras que é MARC em alguns registros e ISO em outros ("SA": África do Sul ou Arábia Saudita) não vale.
     */
    public static function countryCode(string $source, string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $table = self::countryTable();
        if ($source === 'doaj' || $source === 'latindex') {
            return $table['names'][StringNormalizer::normalizeString($raw, false)] ?? null;
        }
        if ($source !== 'openalex') {
            return null;
        }

        $code = strtoupper($raw);
        if (preg_match('/\A[A-Z]{3}\z/', $code) === 1) {
            return self::MARC_SUBDIVISIONS[$code[2]] ?? null;
        }
        if (preg_match('/\A[A-Z]{2}\z/', $code) !== 1 || in_array($code, self::COUNTRY_AMBIGUOUS, true)) {
            return null;
        }

        $code = self::COUNTRY_MARC[$code] ?? $code;

        return isset($table['codes'][$code]) ? $code : null;
    }

    /**
     * Tabela de países lida de COUNTRIES: nome normalizado => código, e os códigos conhecidos.
     *
     * @return array{names: array<string, string>, codes: array<string, true>}
     */
    private static function countryTable(): array
    {
        if (self::$countryTable !== null) {
            return self::$countryTable;
        }

        $names = [];
        $codes = [];
        foreach (explode("\n", self::COUNTRIES) as $line) {
            [$code, $list] = array_pad(explode(':', $line, 2), 2, '');
            $code = trim($code);
            if ($code === '') {
                continue;
            }

            $codes[$code] = true;
            foreach (explode(';', $list) as $name) {
                $key = StringNormalizer::normalizeString($name, false);
                if ($key !== '') {
                    $names[$key] = $code;
                }
            }
        }

        return self::$countryTable = ['names' => $names, 'codes' => $codes];
    }

    // ---- leitura do banco

    /**
     * Linhas de `qualis_journals` e quantas estão com `normalized_title` vazio no banco (nelas a forma do tesauro é
     * calculada em memória, de modo que o resultado não depende de `app:journals:normalize-titles` ter rodado).
     *
     * @return array{0: array<int, array{title: string, normalizedTitle: ?string, issns: list<string>, canonical: ?int, method: ?string, displayTitle: ?string, displayTitleSource: ?string}>, 1: int}
     */
    private function loadRows(): array
    {
        $rows = [];
        $withoutNormalizedTitle = 0;
        $result = $this->conn->iterateAssociative(
            'SELECT id, title, normalized_title, issn, issn_e, issn_l, issn_imp, canonical_journal_id, canonical_method, display_title, display_title_source
             FROM qualis_journals
             ORDER BY id'
        );

        foreach ($result as $row) {
            $issns = [];
            foreach (['issn', 'issn_imp', 'issn_e', 'issn_l'] as $column) {
                foreach (Issn::extractAll($row[$column] !== null ? (string) $row[$column] : null) as $issn) {
                    if (!in_array($issn, $issns, true)) {
                        $issns[] = $issn;
                    }
                }
            }

            $title = (string) $row['title'];
            if ($row['normalized_title'] !== null && $row['normalized_title'] !== '') {
                $normalized = (string) $row['normalized_title'];
            } else {
                ++$withoutNormalizedTitle;
                $normalized = StringNormalizer::normalizeString($title, true) ?: null;
            }

            $rows[(int) $row['id']] = [
                'title' => $title,
                'normalizedTitle' => $normalized,
                'issns' => $issns,
                'canonical' => $row['canonical_journal_id'] !== null ? (int) $row['canonical_journal_id'] : null,
                'method' => $row['canonical_method'] !== null && $row['canonical_method'] !== '' ? (string) $row['canonical_method'] : null,
                'displayTitle' => $row['display_title'] !== null ? (string) $row['display_title'] : null,
                'displayTitleSource' => $row['display_title_source'] !== null && $row['display_title_source'] !== '' ? (string) $row['display_title_source'] : null,
            ];
        }

        return [$rows, $withoutNormalizedTitle];
    }

    /**
     * Chaves dos artigos (ARTIGO) por linha e grafias de `journal_name` do Lattes por linha.
     *
     * A chave é a de A1.3: DOI em minúsculas ou, sem DOI, ano + título sem caixa nem acento.
     *
     * @return array{0: array<int, list<string>>, 1: array<int, array<array-key, int>>}
     */
    private function loadArticles(): array
    {
        $articles = [];
        $lattes = [];

        $result = $this->conn->iterateAssociative(
            "SELECT qualis_journal_id, doi, year, title, journal_name
             FROM production_items
             WHERE item_type = 'ARTIGO' AND qualis_journal_id IS NOT NULL"
        );
        foreach ($result as $row) {
            $id = (int) $row['qualis_journal_id'];

            $doi = trim((string) $row['doi']);
            $articles[$id][] = $doi !== ''
                ? 'DOI:' . mb_strtolower($doi, 'UTF-8')
                : 'TITLE:' . (string) ($row['year'] ?? '') . ':' . self::fold(trim((string) $row['title']));

            $name = trim((string) $row['journal_name']);
            if ($name !== '') {
                $lattes[$id][$name] = ($lattes[$id][$name] ?? 0) + 1;
            }
        }

        return [$articles, $lattes];
    }

    /**
     * Acrônimos das bases da página a que cada linha está vinculada.
     *
     * @return array<int, list<string>>
     */
    private function loadBases(): array
    {
        $bases = [];
        $result = $this->conn->iterateAssociative(
            'SELECT qb.qualis_journal_id AS id, LOWER(ad.acronym) AS acronym
             FROM qualis_journal_academic_database qb
             JOIN academic_database ad ON ad.id = qb.academic_database_id
             WHERE LOWER(ad.acronym) IN (?, ?, ?, ?, ?)
             ORDER BY qb.qualis_journal_id',
            self::BASES
        );
        foreach ($result as $row) {
            $bases[(int) $row['id']][] = (string) $row['acronym'];
        }

        return $bases;
    }

    /** Soma de `_meta.sources[*].records`, só para a barra de progresso (0 se o cabeçalho não ajudar). */
    private function recordsTotal(string $artifact): int
    {
        try {
            $meta = $this->reader->meta($artifact);
        } catch (\RuntimeException) {
            return 0;
        }

        $total = 0;
        foreach (is_array($meta['sources'] ?? null) ? $meta['sources'] : [] as $source) {
            $total += is_array($source) ? (int) ($source['records'] ?? 0) : 0;
        }

        return $total;
    }

    /** Minúsculas e sem acento: aproxima a comparação da collation utf8mb4_unicode_ci que A1.3 usa no SQL. */
    private static function fold(string $s): string
    {
        self::$folder ??= \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC; Lower');

        return self::$folder !== null
            ? (self::$folder->transliterate($s) ?: mb_strtolower($s, 'UTF-8'))
            : mb_strtolower($s, 'UTF-8');
    }

    // ---- gravação

    /**
     * A família é uma só revista: a presença numa base vale para todas as suas linhas. Assim, os indicadores que contam
     * artigos por base em cada linha (StatisticsService) e a página /periodicos dão o mesmo resultado. A canônica recebe
     * os vínculos dos membros e, depois, os membros recebem os da canônica. Idempotente.
     */
    private function propagateBaseLinks(): int
    {
        $added = (int)$this->conn->executeStatement(
            'INSERT IGNORE INTO qualis_journal_academic_database (qualis_journal_id, academic_database_id)
             SELECT DISTINCT q.canonical_journal_id, l.academic_database_id
               FROM qualis_journal_academic_database l
               JOIN qualis_journals q ON q.id = l.qualis_journal_id
              WHERE q.canonical_journal_id IS NOT NULL'
        );

        return $added + (int)$this->conn->executeStatement(
            'INSERT IGNORE INTO qualis_journal_academic_database (qualis_journal_id, academic_database_id)
             SELECT DISTINCT q.id, l.academic_database_id
               FROM qualis_journals q
               JOIN qualis_journal_academic_database l ON l.qualis_journal_id = q.canonical_journal_id
              WHERE q.canonical_journal_id IS NOT NULL'
        );
    }

    /**
     * UPDATE em lote por família, tudo numa transação: ou grava tudo ou nada.
     *
     * @param array<int, array{0: ?int, 1: ?string}> $canonicalChanges
     * @param array<int, array{title: string, source: string}> $titleChanges
     * @param (callable(string, int, int): void)|null $progress
     */
    private function apply(array $canonicalChanges, array $titleChanges, ?callable $progress): void
    {
        $byCanonical = [];
        foreach ($canonicalChanges as $id => [$canonical, $method]) {
            $key = ($canonical ?? 'null') . '|' . ($method ?? 'null');
            $byCanonical[$key]['canonical'] = $canonical;
            $byCanonical[$key]['method'] = $method;
            $byCanonical[$key]['ids'][] = $id;
        }

        $byTitle = [];
        foreach ($titleChanges as $id => $chosen) {
            $key = $chosen['source'] . "\0" . $chosen['title'];
            $byTitle[$key]['title'] = $chosen['title'];
            $byTitle[$key]['source'] = $chosen['source'];
            $byTitle[$key]['ids'][] = $id;
        }

        $total = count($byCanonical) + count($byTitle);
        $this->conn->transactional(function () use ($byCanonical, $byTitle, $progress, $total): void {
            $done = 0;

            foreach ($byCanonical as $group) {
                foreach (array_chunk($group['ids'], self::WRITE_CHUNK) as $chunk) {
                    if ($group['canonical'] === null) {
                        $this->conn->executeStatement(
                            'UPDATE qualis_journals SET canonical_journal_id = NULL, canonical_method = NULL WHERE id IN (?)',
                            [$chunk],
                            [ArrayParameterType::INTEGER]
                        );
                    } else {
                        $this->conn->executeStatement(
                            'UPDATE qualis_journals SET canonical_journal_id = ?, canonical_method = ? WHERE id IN (?)',
                            [$group['canonical'], $group['method'], $chunk],
                            [ParameterType::INTEGER, ParameterType::STRING, ArrayParameterType::INTEGER]
                        );
                    }
                }
                if ($progress !== null && ++$done % self::PROGRESS_EVERY === 0) {
                    $progress('gravação', $done, $total);
                }
            }

            foreach ($byTitle as $group) {
                foreach (array_chunk($group['ids'], self::WRITE_CHUNK) as $chunk) {
                    // A cláusula final repete a regra do passo 6 para o caso de a linha ter virado manual no meio do caminho
                    $this->conn->executeStatement(
                        "UPDATE qualis_journals SET display_title = ?, display_title_source = ?
                         WHERE id IN (?) AND (display_title_source IS NULL OR display_title_source <> 'manual')",
                        [$group['title'], $group['source'], $chunk],
                        [ParameterType::STRING, ParameterType::STRING, ArrayParameterType::INTEGER]
                    );
                }
                if ($progress !== null && ++$done % self::PROGRESS_EVERY === 0) {
                    $progress('gravação', $done, $total);
                }
            }

            if ($progress !== null && $total > 0) {
                $progress('gravação', $total, $total);
            }
        });
    }
}
