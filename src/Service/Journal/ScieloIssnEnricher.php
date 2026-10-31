<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Entity\QualisJournal;
use App\Service\Thesaurus\JournalResolverService;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use RuntimeException;

/**
 * Preenche ISSNs de periódicos vinculados à base `scielo` que não têm nenhum ISSN válido.
 *
 * O casamento é pelo título: a forma do resolver do título do periódico (JournalResolverService::normalizeString)
 * precisa ser igual à de exatamente um título do cadastro do SciELO (campo `title`, o v100).
 * Só as colunas de ISSN e a exibição (`display_title`) são gravadas; `title` e os demais dados ficam intactos.
 */
final class ScieloIssnEnricher
{
    private const ACRONYM = 'scielo';
    private const DISPLAY_TITLE_SOURCE = 'scielo';
    private const BATCH_SIZE = 100;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{
     *     matched: int,
     *     updated: int,
     *     unmatched: list<array{id: int, title: string}>,
     *     ambiguous: list<array{id: int, title: string, codes: list<string>}>
     * }
     */
    public function enrich(string $jsonFile, bool $dryRun): array
    {
        $records = $this->readRecords($jsonFile);
        $index = $this->indexByTitle($records);

        $matched = 0;
        $unmatched = [];
        $ambiguous = [];
        $plans = [];

        foreach ($this->targetRows() as $row) {
            $key = JournalResolverService::normalizeString($row['title']);
            $codes = $key === '' ? [] : ($index[$key] ?? []);

            if ($codes === []) {
                $unmatched[] = ['id' => $row['id'], 'title' => $row['title']];
                continue;
            }
            if (count($codes) > 1) {
                $ambiguous[] = ['id' => $row['id'], 'title' => $row['title'], 'codes' => $codes];
                continue;
            }

            ++$matched;
            $plan = $this->planFor($row, $codes[0], $records[$codes[0]]);
            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        if (!$dryRun) {
            $this->writePlans($plans);
        }

        return [
            'matched' => $matched,
            'updated' => count($plans),
            'unmatched' => $unmatched,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * Periódicos vinculados à base scielo em que nenhuma das quatro colunas de ISSN tem um ISSN válido.
     *
     * @return list<array{id: int, title: string, displayTitleSource: ?string}>
     */
    private function targetRows(): array
    {
        $rows = $this->em->createQuery(
            'SELECT DISTINCT j.id AS id, j.title AS title, j.displayTitleSource AS displayTitleSource,
                    j.issn AS issn, j.issnImp AS issnImp, j.issnE AS issnE, j.issnL AS issnL
             FROM App\Entity\QualisJournal j
             JOIN j.academicDatabases d
             WHERE LOWER(d.acronym) = :acronym
             ORDER BY j.id'
        )->setParameter('acronym', self::ACRONYM)->getArrayResult();

        $targets = [];
        foreach ($rows as $row) {
            $withValidIssn = array_filter(
                [$row['issn'], $row['issnImp'], $row['issnE'], $row['issnL']],
                static fn (?string $raw): bool => Issn::extractAll($raw) !== []
            );
            if ($withValidIssn !== []) {
                continue;
            }

            $targets[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'displayTitleSource' => $row['displayTitleSource'],
            ];
        }

        return $targets;
    }

    /**
     * @return array<int|string, array<string, mixed>> registros do JSON, indexados pelo código do periódico
     */
    private function readRecords(string $jsonFile): array
    {
        if (!is_file($jsonFile)) {
            throw new RuntimeException(sprintf('Arquivo não encontrado: %s', $jsonFile));
        }

        try {
            $data = json_decode((string) file_get_contents($jsonFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('JSON inválido (%s): %s', $jsonFile, $e->getMessage()), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException(sprintf('O JSON (%s) deve ser um objeto indexado pelo código do periódico.', $jsonFile));
        }

        return array_filter($data, 'is_array');
    }

    /**
     * @param array<int|string, array<string, mixed>> $records
     * @return array<string, list<string>> forma do resolver do título => códigos do SciELO com esse título
     */
    private function indexByTitle(array $records): array
    {
        $index = [];
        foreach ($records as $code => $record) {
            if (!is_string($record['title'] ?? null)) {
                continue;
            }

            $key = JournalResolverService::normalizeString($record['title']);
            if ($key !== '') {
                $index[$key][] = (string) $code;
            }
        }

        return $index;
    }

    /**
     * Calcula o que gravar numa linha casada. Retorna null quando não há nada a gravar.
     *
     * Tipo PRINT vai para issn_imp, ONLIN para issn_e; sem tipo (ou tipo desconhecido) o ISSN é geral e não
     * é presumido impresso nem online. A coluna issn recebe o código do SciELO, quando ele é um ISSN válido.
     *
     * @param array{id: int, title: string, displayTitleSource: ?string} $row
     * @param array<string, mixed> $record
     * @return array{id: int, print: ?string, online: ?string, general: ?string, displayTitle: ?string}|null
     */
    private function planFor(array $row, string $code, array $record): ?array
    {
        $print = null;
        $online = null;
        $typeless = null;

        $issns = is_array($record['issns'] ?? null) ? $record['issns'] : [];
        foreach ($issns as $item) {
            if (!is_array($item) || !is_string($item['issn'] ?? null)) {
                continue;
            }

            $valid = Issn::extractAll($item['issn']);
            if ($valid === []) {
                continue;
            }

            $type = strtoupper((string) ($item['type'] ?? ''));
            if ($type === 'PRINT') {
                $print ??= $valid[0];
            } elseif ($type === 'ONLIN') {
                $online ??= $valid[0];
            } else {
                $typeless ??= $valid[0];
            }
        }

        $general = Issn::extractAll($code)[0] ?? $typeless;

        // Só troca a exibição quando ela ainda é automática (sem fonte ou vinda do próprio título)
        $displayTitle = in_array($row['displayTitleSource'], [null, 'title'], true)
            ? trim((string) $record['title'])
            : null;

        $plan = [
            'id' => $row['id'],
            'print' => $print !== null ? Issn::format($print) : null,
            'online' => $online !== null ? Issn::format($online) : null,
            'general' => $general !== null ? Issn::format($general) : null,
            'displayTitle' => $displayTitle,
        ];

        $hasChange = $plan['print'] !== null
            || $plan['online'] !== null
            || $plan['general'] !== null
            || $plan['displayTitle'] !== null;

        return $hasChange ? $plan : null;
    }

    /**
     * @param list<array{id: int, print: ?string, online: ?string, general: ?string, displayTitle: ?string}> $plans
     */
    private function writePlans(array $plans): void
    {
        $pending = 0;
        foreach ($plans as $plan) {
            $journal = $this->em->find(QualisJournal::class, $plan['id']);
            if ($journal === null) {
                continue;
            }

            $this->applyPlan($journal, $plan);

            if (++$pending === self::BATCH_SIZE) {
                $this->em->flush();
                $this->em->clear();
                $pending = 0;
            }
        }

        $this->em->flush();
        $this->em->clear();
    }

    /**
     * @param array{id: int, print: ?string, online: ?string, general: ?string, displayTitle: ?string} $plan
     */
    private function applyPlan(QualisJournal $journal, array $plan): void
    {
        if ($plan['print'] !== null) {
            $journal->setIssnImp($plan['print']);
        }
        if ($plan['online'] !== null) {
            $journal->setIssnE($plan['online']);
        }
        if ($plan['general'] !== null) {
            $printBefore = $journal->getIssnImp();
            $journal->setIssn($plan['general']);
            if ($plan['print'] === null) {
                // setIssn() copia o valor para issn_imp quando nenhum outro ISSN existe: sem tipo PRINT, desfaz
                $journal->setIssnImp($printBefore);
            }
        }
        if ($plan['displayTitle'] !== null) {
            $journal->setDisplayTitle($plan['displayTitle']);
            $journal->setDisplayTitleSource(self::DISPLAY_TITLE_SOURCE);
        }
    }
}
