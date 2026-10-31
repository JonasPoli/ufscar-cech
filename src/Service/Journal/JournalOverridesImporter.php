<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Entity\JournalNameCandidate;
use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Repository\JournalAreaRepository;
use App\Repository\JournalNameCandidateRepository;
use App\Service\Indexing\CurriculumNormalizationService;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importador dos overrides curados de periódicos (docs/evolutions/periodicos-por-base.md, A6.8).
 *
 * Um objeto JSON por linha, portátil para produção (`app:journals:overrides:import`). Tipos:
 *  - `area`: curadoria manual das áreas CAPES da família ({@see JournalAreaRepository::replaceManual()}); trava por padrão;
 *  - `name`: grafia curada: variante `curated` na família, revinculação dos artigos e candidato da fila `accepted`;
 *  - `family`: junta as linhas que têm os ISSNs dados numa família de canônica manual (`canonical_method = 'manual'`);
 *  - `display_title`: nome exibido da família, com `display_title_source = 'manual'`.
 *
 * Cada linha é tudo ou nada: os dados são conferidos (só leitura) antes de qualquer gravação. Linha que não encontra o
 * periódico, que encontra mais de uma família ou que tem dado inválido é pulada e reportada, sem alterar o banco. Erro
 * de banco não é pulado: aborta a importação. Repetir o arquivo não muda o resultado (idempotente).
 *
 * Localizar o periódico (`journal`): `{"issn": ...}` (qualquer linha com o ISSN, em `issn`, `issn_e`, `issn_l` ou
 * `issn_imp`) ou `{"title": ...}` (título exato na forma do tesauro). Família = COALESCE(canonical_journal_id, id).
 *
 * Sinal de linha pulada: `\InvalidArgumentException` com a mensagem para o relatório.
 */
final class JournalOverridesImporter
{
    public const TYPE_AREA = 'area';
    public const TYPE_NAME = 'name';
    public const TYPE_FAMILY = 'family';
    public const TYPE_DISPLAY_TITLE = 'display_title';

    /** @var list<string> */
    public const TYPES = [self::TYPE_AREA, self::TYPE_NAME, self::TYPE_FAMILY, self::TYPE_DISPLAY_TITLE];

    public const STATUS_APPLIED = 'aplicado';
    public const STATUS_SKIPPED = 'pulado';

    /** Tamanho de `qualis_journals.display_title`. */
    private const MAX_DISPLAY_TITLE = 500;

    /** Valor de `journal_name_candidates.reviewed_by` nas revisões feitas por override. */
    private const REVIEWER = 'overrides:import';

    /** Colunas de ISSN de `qualis_journals`; a família usa os ISSNs válidos das quatro. */
    private const ISSN_COLUMNS = ['issn', 'issn_e', 'issn_l', 'issn_imp'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalAreaRepository $areas,
        private readonly JournalVariantGuard $variants,
        private readonly CurriculumNormalizationService $normalization,
        private readonly JournalNameCandidateRepository $candidates,
    ) {
    }

    /**
     * Importa o arquivo JSONL. Com `$dryRun`, aplica tudo numa transação e a desfaz no fim.
     *
     * @return array{lines: list<array{line: int, type: string, status: string, message: string}>, applied: int, skipped: int}
     *
     * @throws \RuntimeException arquivo ilegível
     */
    public function importFile(string $file, bool $dryRun = false): array
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException(sprintf('Não foi possível ler o arquivo de overrides: %s', $file));
        }

        return $this->importLines($lines, $dryRun);
    }

    /**
     * Importa linhas JSONL (a numeração começa em 1; linhas em branco são ignoradas). Ver {@see importFile()}.
     *
     * @param iterable<int, string> $lines
     *
     * @return array{lines: list<array{line: int, type: string, status: string, message: string}>, applied: int, skipped: int}
     */
    public function importLines(iterable $lines, bool $dryRun = false): array
    {
        $conn = $this->em->getConnection();
        $report = ['lines' => [], 'applied' => 0, 'skipped' => 0];
        $number = 0;

        if ($dryRun) {
            $conn->beginTransaction();
        }
        try {
            foreach ($lines as $raw) {
                ++$number;
                // O BOM de arquivos salvos por planilha não pode grudar na primeira linha
                $raw = trim(str_replace("\xEF\xBB\xBF", '', (string) $raw));
                if ($raw === '') {
                    continue;
                }

                $line = $this->processLine($number, $raw);
                $report['lines'][] = $line;
                ++$report[$line['status'] === self::STATUS_APPLIED ? 'applied' : 'skipped'];
            }
        } finally {
            if ($dryRun && $conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->em->clear();
        }

        return $report;
    }

    /**
     * @return array{line: int, type: string, status: string, message: string}
     */
    private function processLine(int $number, string $raw): array
    {
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->skipped($number, '', 'JSON inválido: ' . json_last_error_msg());
        }
        if (!is_array($data) || array_is_list($data)) {
            return $this->skipped($number, '', 'a linha não é um objeto JSON');
        }

        $type = is_string($data['type'] ?? null) ? $data['type'] : '';
        if (!in_array($type, self::TYPES, true)) {
            return $this->skipped($number, $type, sprintf('tipo desconhecido: "%s"', $type));
        }

        try {
            $message = match ($type) {
                self::TYPE_AREA => $this->applyArea($data),
                self::TYPE_NAME => $this->applyName($data),
                self::TYPE_FAMILY => $this->applyFamily($data),
                self::TYPE_DISPLAY_TITLE => $this->applyDisplayTitle($data),
            };
        } catch (\InvalidArgumentException $e) {
            return $this->skipped($number, $type, $e->getMessage());
        } finally {
            // Cada linha parte de entidades lidas do banco, sem estado de linhas anteriores
            $this->em->clear();
        }

        return ['line' => $number, 'type' => $type, 'status' => self::STATUS_APPLIED, 'message' => $message];
    }

    /**
     * @return array{line: int, type: string, status: string, message: string}
     */
    private function skipped(int $number, string $type, string $message): array
    {
        return ['line' => $number, 'type' => $type, 'status' => self::STATUS_SKIPPED, 'message' => $message];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyArea(array $data): string
    {
        $familyId = $this->familyOf($data['journal'] ?? null);
        $primary = $this->singleArea($this->requiredText($data, 'primary', 'área principal'));

        $secondary = $data['secondary'] ?? [];
        if (!is_array($secondary) || !array_is_list($secondary)) {
            throw new \InvalidArgumentException('"secondary" deve ser uma lista de áreas');
        }
        $slugs = [$primary];
        foreach ($secondary as $text) {
            if (!is_string($text)) {
                throw new \InvalidArgumentException('área secundária inválida (deve ser texto)');
            }
            $resolved = $this->areas->resolveSlugs($text);
            if ($resolved['unknown'] !== [] || $resolved['slugs'] === []) {
                throw new \InvalidArgumentException(sprintf('área secundária desconhecida: "%s"', $text));
            }
            array_push($slugs, ...$resolved['slugs']);
        }
        $slugs = array_values(array_unique($slugs));

        $lock = $data['lock'] ?? true;
        if (!is_bool($lock)) {
            throw new \InvalidArgumentException('"lock" deve ser true ou false');
        }

        $canonical = $this->loadFamily($familyId);
        $this->em->wrapInTransaction(function () use ($canonical, $slugs, $primary, $lock): void {
            $this->em->flush();
            $this->areas->replaceManual($canonical, $slugs, $primary);
            if (!$lock) {
                // replaceManual trava sempre; `lock: false` desfaz a trava, para a classificação automática voltar a valer
                $this->em->getConnection()->executeStatement(
                    'UPDATE qualis_journals SET areas_locked = 0 WHERE id = ?',
                    [$canonical->getId()]
                );
            }
        });

        return sprintf(
            'áreas da família %d: principal %s, %d no total%s',
            $familyId,
            $primary,
            count($slugs),
            $lock ? '' : ', sem trava'
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyName(array $data): string
    {
        $name = $this->requiredText($data, 'name', 'nome');
        $key = StringNormalizer::normalizeString($name, true);
        if ($key === '') {
            throw new \InvalidArgumentException('o nome não tem letras nem números');
        }

        $familyId = $this->familyOf($data['journal'] ?? null);
        $conflict = $this->variants->findConflict($key, $familyId);
        if ($conflict !== null) {
            throw new \InvalidArgumentException(sprintf(
                'o nome já pertence a outra família (%s na linha %d: "%s")',
                $conflict['kind'] === JournalVariantGuard::KIND_TITLE ? 'título' : 'variante',
                $conflict['journalId'],
                $conflict['title']
            ));
        }

        $canonical = $this->loadFamily($familyId);
        $candidate = $this->candidates->findOneBy(['normalizedName' => $key]);

        [$created, $relinked, $accepted] = $this->em->wrapInTransaction(
            function () use ($canonical, $name, $key, $candidate): array {
                // A variante é gravada antes da revinculação: o resolver lê o cadastro pelo banco
                $created = $this->variants->addVariant($canonical, $name, 'curated') !== null;
                $this->em->flush();

                $relinked = $this->normalization->reindexArticlesByNormalizedJournalName($key);

                $accepted = false;
                if ($candidate !== null && $candidate->getStatus() !== JournalNameCandidate::STATUS_ACCEPTED) {
                    $candidate
                        ->setStatus(JournalNameCandidate::STATUS_ACCEPTED)
                        ->setResolvedJournal($canonical)
                        ->setReviewedAt(new \DateTimeImmutable())
                        ->setReviewedBy(self::REVIEWER);
                    $this->em->flush();
                    $accepted = true;
                }

                return [$created, $relinked, $accepted];
            }
        );

        $parts = [
            $created
                ? sprintf('variante curada criada na família %d', $familyId)
                : sprintf('a família %d já conhecia o nome', $familyId),
            sprintf('%d artigo(s) revinculado(s)', $relinked),
        ];
        if ($accepted) {
            $parts[] = 'candidato da fila aceito';
        }

        return implode('; ', $parts);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyFamily(array $data): string
    {
        $issns = $data['issns'] ?? null;
        if (!is_array($issns) || !array_is_list($issns) || $issns === []) {
            throw new \InvalidArgumentException('"issns" deve ser uma lista com ao menos um ISSN');
        }
        $n8s = [];
        foreach ($issns as $raw) {
            $found = is_string($raw) ? Issn::extractAll($raw, true) : [];
            if ($found === []) {
                throw new \InvalidArgumentException(sprintf('ISSN inválido: %s', (string) json_encode($raw, JSON_UNESCAPED_UNICODE)));
            }
            array_push($n8s, ...$found);
        }
        $n8s = array_values(array_unique($n8s));

        $canonicalIssn = Issn::extractAll($this->requiredText($data, 'canonical_issn', 'ISSN da canônica'), true);
        if (count($canonicalIssn) !== 1 || !in_array($canonicalIssn[0], $n8s, true)) {
            throw new \InvalidArgumentException('"canonical_issn" precisa ser um ISSN válido e estar em "issns"');
        }
        $canonicalIssn = $canonicalIssn[0];

        $rows = $this->rowsWithIssns($n8s);
        if ($rows === []) {
            throw new \InvalidArgumentException('nenhuma linha do cadastro tem estes ISSNs');
        }

        // Canônica: a linha com o ISSN canônico; em empate, a que tem mais artigos; depois, a de menor id
        $articles = $this->articleCounts(array_column($rows, 'id'));
        $heads = array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array($canonicalIssn, $row['issns'], true)
        ));
        if ($heads === []) {
            throw new \InvalidArgumentException(sprintf(
                'o ISSN da canônica (%s) não está no cadastro',
                Issn::format($canonicalIssn)
            ));
        }
        usort(
            $heads,
            static fn (array $a, array $b): int => [$articles[$b['id']] ?? 0, $a['id']] <=> [$articles[$a['id']] ?? 0, $b['id']]
        );
        $canonicalId = $heads[0]['id'];
        $rowIds = array_column($rows, 'id');
        $others = array_values(array_diff($rowIds, [$canonicalId]));

        $conn = $this->em->getConnection();
        $this->em->wrapInTransaction(function () use ($conn, $canonicalId, $others): void {
            // A canônica deixa de ser membro, se era, e fica marcada como manual
            $conn->executeStatement(
                "UPDATE qualis_journals SET canonical_journal_id = NULL, canonical_method = 'manual' WHERE id = ?",
                [$canonicalId]
            );
            if ($others !== []) {
                $conn->executeStatement(
                    "UPDATE qualis_journals SET canonical_journal_id = ?, canonical_method = 'manual' WHERE id IN (?)",
                    [$canonicalId, $others],
                    [ParameterType::INTEGER, ArrayParameterType::INTEGER]
                );
                // Membros de linhas que deixaram de ser canônicas passam a apontar para a nova canônica
                $conn->executeStatement(
                    'UPDATE qualis_journals SET canonical_journal_id = ? WHERE canonical_journal_id IN (?)',
                    [$canonicalId, $others],
                    [ParameterType::INTEGER, ArrayParameterType::INTEGER]
                );
            }

            // Nome exibido da família a partir da canônica, sem tocar nas linhas com nome manual
            $canonicalRow = $conn->fetchAssociative(
                'SELECT display_title, display_title_source FROM qualis_journals WHERE id = ?',
                [$canonicalId]
            );
            if (is_array($canonicalRow) && trim((string) ($canonicalRow['display_title'] ?? '')) !== '') {
                $conn->executeStatement(
                    "UPDATE qualis_journals SET display_title = ?, display_title_source = ?
                      WHERE (id = ? OR canonical_journal_id = ?) AND (display_title_source IS NULL OR display_title_source <> 'manual')",
                    [$canonicalRow['display_title'], $canonicalRow['display_title_source'], $canonicalId, $canonicalId]
                );
            }
        });

        return sprintf(
            'família de %d linha(s), canônica %d (ISSN %s)',
            $this->familySize($canonicalId),
            $canonicalId,
            Issn::format($canonicalIssn)
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyDisplayTitle(array $data): string
    {
        $title = $this->requiredText($data, 'title', 'nome exibido');
        if (mb_strlen($title, 'UTF-8') > self::MAX_DISPLAY_TITLE) {
            throw new \InvalidArgumentException(sprintf('nome exibido com mais de %d caracteres', self::MAX_DISPLAY_TITLE));
        }

        $familyId = $this->familyOf($data['journal'] ?? null);
        $this->em->getConnection()->executeStatement(
            "UPDATE qualis_journals SET display_title = ?, display_title_source = 'manual' WHERE id = ? OR canonical_journal_id = ?",
            [$title, $familyId, $familyId]
        );

        return sprintf('nome exibido gravado em %d linha(s) da família %d', $this->familySize($familyId), $familyId);
    }

    /**
     * Família (id da canônica) do periódico indicado em `journal`.
     *
     * @throws \InvalidArgumentException periódico não encontrado, sem ISSN nem título, ou em mais de uma família
     */
    private function familyOf(mixed $spec): int
    {
        if (!is_array($spec)) {
            throw new \InvalidArgumentException('campo "journal" ausente (use {"issn": ...} ou {"title": ...})');
        }

        $issn = $spec['issn'] ?? null;
        $title = $spec['title'] ?? null;
        if (is_string($issn) && trim($issn) !== '') {
            $n8s = Issn::extractAll($issn, true);
            if ($n8s === []) {
                throw new \InvalidArgumentException(sprintf('ISSN inválido: "%s"', $issn));
            }
            $families = [];
            foreach ($this->rowsWithIssns($n8s) as $row) {
                $families[] = $row['canonical'] ?? $row['id'];
            }
            $label = 'ISSN ' . implode(', ', array_map([Issn::class, 'format'], $n8s));
        } elseif (is_string($title) && trim($title) !== '') {
            $families = array_map('intval', $this->em->getConnection()->fetchFirstColumn(
                'SELECT DISTINCT COALESCE(canonical_journal_id, id) FROM qualis_journals WHERE normalized_title = ?',
                [StringNormalizer::normalizeString($title, true)]
            ));
            $label = sprintf('título "%s"', trim($title));
        } else {
            throw new \InvalidArgumentException('campo "journal" sem "issn" nem "title"');
        }

        $families = array_values(array_unique($families));
        if ($families === []) {
            throw new \InvalidArgumentException(sprintf('periódico não encontrado (%s)', $label));
        }
        if (count($families) > 1) {
            throw new \InvalidArgumentException(sprintf(
                '%s corresponde a %d famílias (linhas %s): pulado',
                $label,
                count($families),
                implode(', ', $families)
            ));
        }

        return $families[0];
    }

    /**
     * Linhas do cadastro com algum dos ISSNs dados (já normalizados). O LIKE nas colunas só pré-filtra; a conferência é
     * exata, pelos ISSNs válidos de cada linha (como em JournalFamilyBuilder).
     *
     * @param list<string> $n8s
     *
     * @return list<array{id: int, canonical: ?int, issns: list<string>}>
     */
    private function rowsWithIssns(array $n8s): array
    {
        if ($n8s === []) {
            return [];
        }

        $conditions = [];
        $params = [];
        foreach ($n8s as $n8) {
            foreach (self::ISSN_COLUMNS as $column) {
                $conditions[] = sprintf("REPLACE(COALESCE(%s, ''), '-', '') LIKE ?", $column);
                $params[] = '%' . strtoupper($n8) . '%';
            }
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, canonical_journal_id, issn, issn_e, issn_l, issn_imp FROM qualis_journals WHERE '
            . implode(' OR ', $conditions) . ' ORDER BY id',
            $params
        );

        $found = [];
        foreach ($rows as $row) {
            $issns = [];
            foreach (self::ISSN_COLUMNS as $column) {
                array_push($issns, ...Issn::extractAll($row[$column] ?? null, true));
            }
            $issns = array_values(array_unique($issns));
            if (array_intersect($issns, $n8s) === []) {
                continue;
            }

            $found[] = [
                'id' => (int) $row['id'],
                'canonical' => $row['canonical_journal_id'] !== null ? (int) $row['canonical_journal_id'] : null,
                'issns' => $issns,
            ];
        }

        return $found;
    }

    /**
     * Artigos por linha do cadastro (`production_items` do tipo artigo), para escolher a canônica.
     *
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    private function articleCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_map('intval', $this->em->getConnection()->fetchAllKeyValue(
            'SELECT qualis_journal_id, COUNT(*) FROM production_items
              WHERE item_type = ? AND qualis_journal_id IN (?)
              GROUP BY qualis_journal_id',
            [ProductionItem::TYPE_ARTIGO, $ids],
            [ParameterType::STRING, ArrayParameterType::INTEGER]
        ));
    }

    private function familySize(int $familyId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM qualis_journals WHERE id = ? OR canonical_journal_id = ?',
            [$familyId, $familyId]
        );
    }

    private function loadFamily(int $familyId): QualisJournal
    {
        $journal = $this->em->find(QualisJournal::class, $familyId);
        if ($journal === null) {
            throw new \InvalidArgumentException(sprintf('periódico %d não existe mais', $familyId));
        }

        return $journal;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredText(array $data, string $key, string $what): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s ausente (campo "%s")', $what, $key));
        }

        return trim($value);
    }

    /**
     * Uma única área CAPES (slug, nome ou rótulo); texto que não se resolve a exatamente uma área é recusado.
     */
    private function singleArea(string $text): string
    {
        $resolved = $this->areas->resolveSlugs($text);
        if ($resolved['unknown'] !== [] || count($resolved['slugs']) !== 1) {
            throw new \InvalidArgumentException(sprintf('área principal desconhecida ou ambígua: "%s"', $text));
        }

        return $resolved['slugs'][0];
    }
}
