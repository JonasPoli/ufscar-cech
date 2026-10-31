<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JournalArea;
use App\Entity\QualisJournal;
use App\Service\Journal\CapesTaxonomy;
use App\Service\Journal\JournalAreaThesaurus;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositório das áreas CAPES atribuídas aos periódicos (tabela journal_areas).
 *
 * Além das consultas, é o dono da curadoria manual: {@see self::replaceManual()} e {@see self::clearManual()} gravam por
 * SQL, numa transação, as linhas de `journal_areas` de toda a família do periódico (família = canônica e as linhas que
 * apontam para ela) e, no primeiro caso, o rótulo canônico em `qualis_journals.area` (docs/evolutions/periodicos-por-base.md,
 * A3 e A6.8). Como a gravação é por SQL, as entidades da família que já estavam carregadas no EntityManager são
 * recarregadas (`refresh`) ao fim: faça o `flush()` de qualquer alteração pendente nelas ANTES de chamar.
 *
 * @extends ServiceEntityRepository<JournalArea>
 */
class JournalAreaRepository extends ServiceEntityRepository
{
    /** Proteção contra cadeias de canônicas e ciclos. */
    private const MAX_CHAIN_DEPTH = 10;

    /** Linhas (tuplas) por INSERT em `journal_areas`. */
    private const INSERT_CHUNK = 500;

    /** @var array<string, string>|null forma do tesauro do slug/nome/rótulo de cada área => slug (resolveSlugs) */
    private ?array $areaLookup = null;

    /**
     * O tesauro de categorias é opcional: sem ele, {@see self::resolveSlugs()} só entende slug, nome e rótulo canônico.
     * O serviço do tesauro depende só da conexão, da taxonomia e do crosswalk (nunca deste repositório), então não há
     * dependência circular.
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly CapesTaxonomy $taxonomy,
        private readonly ?JournalAreaThesaurus $thesaurus = null,
    ) {
        parent::__construct($registry, JournalArea::class);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Curadoria manual
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Substitui as áreas da família por uma seleção manual.
     *
     * Na canônica e em cada linha membro da família: apaga todas as linhas de `journal_areas` (automáticas e manuais) e
     * grava uma por área com `method = 'manual'`, `confidence = 'manual'`, `score = 1` e `is_primary` só na principal.
     * Na canônica: `areas_locked = 1`, para o classificador automático não mexer mais. Em todas as linhas da família:
     * `qualis_journals.area` recebe o rótulo canônico da principal ({@see CapesTaxonomy::areaLabel()}), e o texto que
     * estava em `area` e não é rótulo canônico passa para `area_raw`, como na classificação automática.
     *
     * @param QualisJournal $canonical linha canônica da família; se for uma linha membro, vale a canônica dela. Precisa
     *        estar gravada (ter id)
     * @param list<string> $slugs áreas escolhidas (slugs da taxonomia CAPES, grande área ou área de avaliação); a ordem
     *        não importa e repetidos contam uma vez
     * @param string $primarySlug a principal; se vazia ou fora de `$slugs`, vale a primeira de `$slugs`
     *
     * @throws \InvalidArgumentException lista vazia, slug desconhecido ou periódico ainda sem id (nada é gravado)
     */
    public function replaceManual(QualisJournal $canonical, array $slugs, string $primarySlug): void
    {
        $canonical = self::canonicalOf($canonical);
        $canonicalId = $canonical->getId();
        if ($canonicalId === null) {
            throw new \InvalidArgumentException('O periódico precisa estar gravado (flush) antes de receber áreas CAPES.');
        }

        [$slugs, $primarySlug] = $this->normalizeSelection($slugs, $primarySlug);
        $label = $this->taxonomy->areaLabel($primarySlug);
        $conn = $this->getEntityManager()->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $conn->beginTransaction();
        try {
            $rowIds = $this->familyRowIds($canonicalId);
            $in = self::inList($rowIds);

            $conn->executeStatement('DELETE FROM journal_areas WHERE journal_id IN (' . $in . ')');

            $tuples = [];
            foreach ($rowIds as $rowId) {
                foreach ($slugs as $slug) {
                    $tuples[] = [
                        $rowId,
                        $this->taxonomy->bySlug($slug)['id'] ?? throw new \LogicException(sprintf('Slug sem área: %s', $slug)),
                        $slug === $primarySlug ? 1 : 0,
                    ];
                }
            }
            foreach (array_chunk($tuples, self::INSERT_CHUNK) as $chunk) {
                $placeholders = [];
                $params = [];
                foreach ($chunk as [$rowId, $areaId, $primary]) {
                    $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                    array_push($params, $rowId, $areaId, $primary, '1.0000', JournalArea::CONFIDENCE_MANUAL, JournalArea::METHOD_MANUAL, null, $now);
                }
                $conn->executeStatement(
                    'INSERT INTO journal_areas (journal_id, area_id, is_primary, score, confidence, method, run_id, updated_at) VALUES '
                    . implode(', ', $placeholders),
                    $params
                );
            }

            $conn->executeStatement('UPDATE qualis_journals SET areas_locked = 1 WHERE id = ?', [$canonicalId]);

            foreach ($conn->fetchAllAssociative('SELECT id, area, area_raw FROM qualis_journals WHERE id IN (' . $in . ')') as $row) {
                $raw = $row['area_raw'];
                if ($row['area'] !== null && trim((string) $row['area']) !== '' && !$this->taxonomy->isCanonicalLabel((string) $row['area'])) {
                    $raw = $row['area'];
                }
                if ($row['area'] === $label && $row['area_raw'] === $raw) {
                    continue;
                }
                $conn->executeStatement('UPDATE qualis_journals SET area = ?, area_raw = ? WHERE id = ?', [$label, $raw, $row['id']]);
            }

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }

        $this->refreshManaged($rowIds);
    }

    /**
     * Desfaz a curadoria manual da família: apaga as linhas de `journal_areas` que não são automáticas (em todas as
     * linhas da família) e zera `areas_locked` na canônica. Não reclassifica: quem chama roda o classificador
     * ({@see \App\Service\Journal\JournalAreaClassifier::classifyFamily()}), que também refaz `area`.
     *
     * @param QualisJournal $canonical linha canônica da família (uma linha membro vale pela canônica dela)
     *
     * @return int linhas de `journal_areas` apagadas
     *
     * @throws \InvalidArgumentException periódico ainda sem id
     */
    public function clearManual(QualisJournal $canonical): int
    {
        $canonical = self::canonicalOf($canonical);
        $canonicalId = $canonical->getId();
        if ($canonicalId === null) {
            throw new \InvalidArgumentException('O periódico precisa estar gravado (flush) antes de voltar ao automático.');
        }

        $conn = $this->getEntityManager()->getConnection();
        $conn->beginTransaction();
        try {
            $rowIds = $this->familyRowIds($canonicalId);
            $deleted = (int) $conn->executeStatement(
                "DELETE FROM journal_areas WHERE method <> 'auto' AND journal_id IN (" . self::inList($rowIds) . ')'
            );
            $conn->executeStatement('UPDATE qualis_journals SET areas_locked = 0 WHERE id = ?', [$canonicalId]);
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }

        $this->refreshManaged($rowIds);

        return $deleted;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Consultas
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Ids da família: a canônica e as linhas que apontam para ela.
     *
     * @return list<int>
     */
    public function familyRowIds(int $canonicalId): array
    {
        return array_map(
            'intval',
            $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT id FROM qualis_journals WHERE id = ? OR canonical_journal_id = ? ORDER BY id',
                [$canonicalId, $canonicalId]
            )
        );
    }

    /**
     * Áreas de uma linha, com a principal primeiro e depois por parcela e ordem da taxonomia.
     *
     * @return list<JournalArea>
     */
    public function findOrdered(int $journalId): array
    {
        return $this->createQueryBuilder('ja')
            ->addSelect('a')
            ->join('ja.area', 'a')
            ->andWhere('IDENTITY(ja.journal) = :journal')
            ->setParameter('journal', $journalId)
            ->addOrderBy('ja.isPrimary', 'DESC')
            ->addOrderBy('ja.score', 'DESC')
            ->addOrderBy('a.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Área principal de cada linha (para o índice do admin): `confidence` = high | medium | low | manual. Linha sem
     * principal fica fora do resultado. Se houver duas principais (uma manual e uma automática), vale a manual.
     *
     * @param list<int> $journalIds
     *
     * @return array<int, array{slug: string, name: string, level: int, confidence: string, method: string}>
     */
    public function primaryByJournal(array $journalIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $journalIds))));
        if ($ids === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT ja.journal_id, ja.confidence, ja.method, a.slug, a.name, a.level
               FROM journal_areas ja
               JOIN capes_areas a ON a.id = ja.area_id
              WHERE ja.is_primary = 1 AND ja.journal_id IN (' . self::inList($ids) . ")
              ORDER BY ja.journal_id, (ja.method = 'manual') ASC, ja.id ASC"
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['journal_id']] = [
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'level' => (int) $row['level'],
                'confidence' => (string) $row['confidence'],
                'method' => (string) $row['method'],
            ];
        }

        return $map;
    }

    /**
     * Nomes das áreas CAPES de cada linha, para as exportações: a principal e todas (a principal primeiro, depois por
     * parcela e ordem da taxonomia). Linha sem área fica fora do resultado.
     *
     * @return array<int, array{primary: ?string, all: list<string>}>
     */
    public function namesByJournal(): array
    {
        $result = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT ja.journal_id, a.name, ja.is_primary
               FROM journal_areas ja
               JOIN capes_areas a ON a.id = ja.area_id
              ORDER BY ja.journal_id, ja.is_primary DESC, ja.score DESC, a.sort_order ASC'
        );

        $map = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $id = (int) $row['journal_id'];
            $map[$id] ??= ['primary' => null, 'all' => []];
            if ($map[$id]['primary'] === null && (int) $row['is_primary'] === 1) {
                $map[$id]['primary'] = (string) $row['name'];
            }
            $map[$id]['all'][] = (string) $row['name'];
        }

        return $map;
    }

    /**
     * Entende uma lista de áreas escrita à mão (coluna `areas_capes` do CSV): slugs, nomes (`Educação`) ou rótulos
     * canônicos (`EDUCAÇÃO / CIÊNCIAS HUMANAS`) separados por `;`, sem diferença de caixa nem de acentos. O trecho que
     * não é nenhum dos três ainda é procurado no tesauro de categorias de revistas (só linhas `active`: `Lingüística`,
     * `Mathematics, Applied`, um sinônimo corrigido no admin), que pode dar mais de uma área. A primeira área reconhecida
     * é a que o chamador deve tratar como principal.
     *
     * @return array{slugs: list<string>, unknown: list<string>} slugs únicos na ordem escrita e os trechos não reconhecidos
     */
    public function resolveSlugs(string $text): array
    {
        $lookup = $this->areaLookup();

        $slugs = [];
        $unknown = [];
        foreach (explode(';', $text) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $slug = $lookup[StringNormalizer::normalizeString($token, true)] ?? null;
            if ($slug !== null) {
                $slugs[$slug] = $slug;
                continue;
            }

            // Rótulo "sem área" (`ignored`) volta como lista vazia e não vale como área: continua desconhecido
            $found = $this->thesaurus?->lookup($token) ?? [];
            if ($found === []) {
                $unknown[] = $token;
                continue;
            }
            foreach ($found as $foundSlug) {
                $slugs[$foundSlug] = $foundSlug;
            }
        }

        return ['slugs' => array_values($slugs), 'unknown' => $unknown];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Infraestrutura
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Índice "forma do tesauro" do slug, do nome e do rótulo de cada área => slug (o primeiro que reivindica uma chave
     * fica com ela: slug, depois nome, depois rótulo).
     *
     * @return array<string, string>
     */
    private function areaLookup(): array
    {
        if ($this->areaLookup !== null) {
            return $this->areaLookup;
        }

        $lookup = [];
        foreach ($this->taxonomy->all() as $area) {
            foreach ([$area['slug'], $area['name'], $this->taxonomy->areaLabel($area['slug'])] as $text) {
                $lookup[StringNormalizer::normalizeString($text, true)] ??= $area['slug'];
            }
        }

        return $this->areaLookup = $lookup;
    }

    /**
     * @param list<string> $slugs
     *
     * @return array{0: list<string>, 1: string} slugs únicos e válidos, na ordem recebida, e a principal (que está neles)
     */
    private function normalizeSelection(array $slugs, string $primarySlug): array
    {
        $clean = [];
        foreach ($slugs as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }
            if (!$this->taxonomy->isValidSlug($slug)) {
                throw new \InvalidArgumentException(sprintf('Área CAPES desconhecida: "%s".', $slug));
            }
            $clean[$slug] = $slug;
        }
        if ($clean === []) {
            throw new \InvalidArgumentException('Informe ao menos uma área CAPES.');
        }

        $clean = array_values($clean);
        $primarySlug = trim($primarySlug);
        if ($primarySlug === '' || !in_array($primarySlug, $clean, true)) {
            $primarySlug = $clean[0];
        }

        return [$clean, $primarySlug];
    }

    /**
     * Recarrega as linhas da família que já estão gerenciadas (a gravação foi por SQL, e o `refresh` também zera as
     * coleções delas, como `areas`). Proxies ainda não inicializados carregam o estado novo quando forem usados.
     *
     * @param list<int> $rowIds
     */
    private function refreshManaged(array $rowIds): void
    {
        $em = $this->getEntityManager();
        $uow = $em->getUnitOfWork();

        foreach ($rowIds as $rowId) {
            $entity = $uow->tryGetById($rowId, QualisJournal::class);
            if ($entity instanceof QualisJournal && !$em->isUninitializedObject($entity)) {
                $em->refresh($entity);
            }
        }
    }

    /**
     * Linha canônica da família de $journal (a primeira da cadeia `canonical` sem canônica).
     */
    private static function canonicalOf(QualisJournal $journal): QualisJournal
    {
        $current = $journal;
        for ($depth = 0; $depth < self::MAX_CHAIN_DEPTH; ++$depth) {
            $next = $current->getCanonical();
            if ($next === null || $next === $current) {
                break;
            }
            $current = $next;
        }

        return $current;
    }

    /**
     * Lista de ids inteiros para um `IN (...)`: o cast garante que nada além de números entra no SQL.
     *
     * @param list<int> $ids
     */
    private static function inList(array $ids): string
    {
        return implode(',', array_map('intval', $ids));
    }
}
