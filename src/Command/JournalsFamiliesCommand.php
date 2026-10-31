<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Journal\JournalFamilyBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:journals:families',
    description: 'Agrupa as linhas do mesmo periódico em famílias (canonical_journal_id) e grava o nome exibido, sem mesclar nem apagar nada'
)]
class JournalsFamiliesCommand extends Command
{
    private const DEFAULT_ARTIFACT = 'docs/banco/derived/journal_evidence.jsonl.gz';

    /** Acima disto a família é suspeita (o esperado é no máximo 6 linhas, ver A9). */
    private const LARGE_FAMILY = 6;

    private const BASE_NAMES = [
        'scopus' => 'Scopus',
        'pubmed' => 'PubMed',
        'scielo' => 'SciELO',
        'wos' => 'Web of Science',
        'latindex' => 'Latindex',
    ];

    public function __construct(
        private readonly JournalFamilyBuilder $builder,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcula e mostra o relatório sem gravar no banco')
            ->addOption('artifact', null, InputOption::VALUE_REQUIRED, 'Artefato de evidências (relativo à raiz do projeto ou absoluto)', self::DEFAULT_ARTIFACT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');

        $io = new SymfonyStyle($input, $output);
        $io->title('Famílias de periódicos e nome exibido');

        $dryRun = (bool) $input->getOption('dry-run');
        $artifact = $this->resolvePath((string) $input->getOption('artifact'));

        if (!is_file($artifact)) {
            $io->warning(sprintf(
                "Artefato de evidências não encontrado: %s\n"
                . 'As famílias serão calculadas só com os ISSNs do cadastro (sem os pares impresso/online das bases) e o nome '
                . 'exibido virá das grafias do Lattes ou do título. Gere o artefato com app:journals:evidence:build.',
                $artifact
            ));
        }

        $bar = null;
        $stage = null;
        $progress = static function (string $name, int $done, int $total) use ($output, &$bar, &$stage): void {
            if ($stage !== $name) {
                if ($bar !== null) {
                    $bar->finish();
                    $output->writeln('');
                }
                $bar = new ProgressBar($output, $total);
                $bar->setFormat($total > 0 ? " $name %current%/%max% [%bar%] %percent:3s%%" : " $name %current%");
                $bar->start();
                $stage = $name;
            }
            $bar->setProgress($done);
        };

        try {
            $result = $this->builder->build($artifact, $dryRun, $progress);
        } catch (\Throwable $e) {
            $bar?->finish();
            $io->newLine();
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $bar?->finish();
        $io->newLine(2);

        $this->printSummary($io, $result, $dryRun);
        $this->printArticlesByBase($io, $result['by_db']);
        $this->printDisplaySources($io, $result['display_sources']);
        $this->printLargestFamilies($io, $result['largest_families']);
        $this->printConflicts($io, $result['conflict_rows']);
        $this->printAmbiguous($io, $result['ambiguous_titles']);

        if ($result['rows_without_normalized_title'] > 0) {
            $io->note(sprintf(
                '%d linha(s) estão sem normalized_title no banco; a forma do tesauro foi calculada em memória. '
                . 'Rode app:journals:normalize-titles para gravá-la.',
                $result['rows_without_normalized_title']
            ));
        }
        if ($result['manual_conflicts'] > 0) {
            $io->warning(sprintf(
                '%d grupo(s) contêm linhas de famílias manuais diferentes: as linhas manuais não foram alteradas e as demais seguiram a melhor canônica manual.',
                $result['manual_conflicts']
            ));
        }

        if ($dryRun) {
            $io->warning('Modo simulação (--dry-run): nenhuma alteração foi gravada no banco.');
        } else {
            $io->success(sprintf(
                'Famílias gravadas: %d linha(s) com canônica alterada e %d com nome exibido alterado.',
                $result['updated']['canonical'],
                $result['updated']['display_title']
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function printSummary(SymfonyStyle $io, array $result, bool $dryRun): void
    {
        $ambiguousRows = 0;
        foreach ($result['ambiguous_titles'] as $ambiguous) {
            $ambiguousRows += count($ambiguous['ids']);
        }
        $artifact = $result['artifact'];

        $io->table(['Indicador', 'Valor'], [
            ['Linhas do cadastro', $this->number($result['rows'])],
            ['Linhas sem ISSN válido', $this->number($result['rows_without_issn'])],
            ['Famílias', $this->number($result['families'])],
            ['Famílias com mais de 1 linha', $this->number($result['multi'])],
            ['Linhas sem ISSN unidas por título', $this->number($result['joined_by_title'])],
            ['Títulos ambíguos (linhas sem ISSN que ficam isoladas)', sprintf('%s (%s linhas)', $this->number(count($result['ambiguous_titles'])), $this->number($ambiguousRows))],
            ['Maior família (linhas)', $this->number($result['largest'])],
            [
                'Linhas em conflito (ISSNs de revistas diferentes)',
                sprintf(
                    '%s (%s com artigos, %s artigos)',
                    $this->number(count($result['conflict_rows'])),
                    $this->number(count(array_filter($result['conflict_rows'], static fn (array $row): bool => $row['articles'] > 0))),
                    $this->number(array_sum(array_column($result['conflict_rows'], 'articles')))
                ),
            ],
            ['Limiar de semelhança entre títulos', number_format($result['similarity_threshold'], 2, ',', '.')],
            ['Linhas manuais preservadas', $this->number($result['manual_rows'])],
            ['Vínculos com bases estendidos à família', $this->number($result['base_links_added'] ?? 0)],
            [
                'Artefato',
                $artifact['found']
                    ? sprintf(
                        '%s registros lidos; %s usados; %s ignorados por terem mais de %d ISSNs; %s de %d ISSNs ignorados por contradição entre fontes; %s sem ISSN válido',
                        $this->number($artifact['records']),
                        $this->number($artifact['used']),
                        $this->number($artifact['skipped_many_issns']),
                        JournalFamilyBuilder::MAX_RECORD_ISSNS,
                        $this->number($artifact['skipped_contradicted']),
                        JournalFamilyBuilder::MAX_RECORD_ISSNS,
                        $this->number($artifact['without_issn'])
                    )
                    : 'ausente',
            ],
            [
                $dryRun ? 'Seriam alteradas: canônica / nome exibido' : 'Alteradas: canônica / nome exibido',
                sprintf('%s / %s', $this->number($result['updated']['canonical']), $this->number($result['updated']['display_title'])),
            ],
        ]);
    }

    /**
     * @param array<string, array{row: int, family: int, row_items: int, family_items: int}> $byDb
     */
    private function printArticlesByBase(SymfonyStyle $io, array $byDb): void
    {
        $rows = [];
        foreach ($byDb as $acronym => $counts) {
            $rows[] = [
                self::BASE_NAMES[$acronym] ?? $acronym,
                $this->number($counts['row_items']),
                $this->number($counts['family_items']),
                $this->number($counts['row']),
                $this->number($counts['family']),
                sprintf('%+d', $counts['family'] - $counts['row']),
            ];
        }

        $io->section('Artigos por base, por linha e por família');
        $io->table(
            ['Base', 'Itens por linha', 'Itens por família', 'Distintos por linha', 'Distintos por família', 'Ganho (distintos)'],
            $rows
        );
        $io->text(
            'Itens = artigos do Lattes vinculados, sem juntar coautorias: é a medida dos números esperados em A4 '
            . '(SciELO 1.101 para ≈ 2.277). Distintos = como a página pública conta (coautorias entre docentes valem uma).'
        );
    }

    /**
     * @param array<string, int> $sources
     */
    private function printDisplaySources(SymfonyStyle $io, array $sources): void
    {
        if ($sources === []) {
            return;
        }

        $rows = [];
        foreach ($sources as $source => $count) {
            $rows[] = [$source, $this->number($count)];
        }

        $io->section('Nome exibido por fonte');
        $io->table(['Fonte', 'Linhas'], $rows);
    }

    /**
     * @param list<array{id: int, rows: int, title: string}> $families
     */
    private function printLargestFamilies(SymfonyStyle $io, array $families): void
    {
        $large = array_filter($families, static fn (array $family): bool => $family['rows'] > self::LARGE_FAMILY);
        if ($large === []) {
            return;
        }

        $io->warning(sprintf('Há famílias com mais de %d linhas; confira se não juntaram revistas diferentes:', self::LARGE_FAMILY));
        $io->table(['Canônica', 'Linhas', 'Título'], array_map(
            static fn (array $family): array => [$family['id'], $family['rows'], $family['title']],
            $large
        ));
    }

    /**
     * Linhas cujos ISSNs caem em grupos incompatíveis (títulos que não se parecem ou países que se excluem): não unem nada
     * e vão para o grupo de título mais parecido, ou ficam sós.
     *
     * @param list<array{id: int, title: string, issns: list<string>, reason: string, groups: list<array{issns: list<string>, title: string, countries: list<string>, family: ?int}>, destination: ?array{id: int, title: string}, tie: bool, similarity: float, articles: int}> $conflicts
     */
    private function printConflicts(SymfonyStyle $io, array $conflicts): void
    {
        if ($conflicts === []) {
            return;
        }

        $rows = [];
        foreach ($conflicts as $conflict) {
            $groups = array_map(
                static fn (array $group): string => sprintf(
                    '%s%s: %s%s',
                    implode(' + ', $group['issns']),
                    $group['countries'] !== [] ? ' [' . implode('/', $group['countries']) . ']' : '',
                    $group['title'],
                    $group['family'] !== null ? sprintf(' (#%d)', $group['family']) : ''
                ),
                $conflict['groups']
            );
            $similarity = number_format($conflict['similarity'], 2, ',', '.');
            $rows[] = [
                $conflict['id'],
                $conflict['title'],
                implode(', ', $conflict['issns']),
                $conflict['reason'] === 'country' ? 'país' : 'título',
                implode("\n", $groups),
                $conflict['destination'] !== null
                    ? sprintf('#%d %s (%s)', $conflict['destination']['id'], $conflict['destination']['title'], $similarity)
                    : sprintf('isolada (%s%s)', $conflict['tie'] ? 'empate ' : '', $similarity),
                $conflict['articles'],
            ];
        }

        $io->section('Linhas em conflito (por número de artigos)');
        $io->table(['ID', 'Título', 'ISSNs', 'Motivo', 'Grupos (ISSN [países]: título externo)', 'Destino (semelhança)', 'Artigos'], $rows);
    }

    /**
     * @param list<array{title: string, ids: list<int>, families: list<array{id: int, title: string}>}> $ambiguous
     */
    private function printAmbiguous(SymfonyStyle $io, array $ambiguous): void
    {
        if ($ambiguous === []) {
            return;
        }

        $rows = [];
        foreach ($ambiguous as $item) {
            $rows[] = [
                $item['title'],
                implode(', ', $item['ids']),
                implode(' | ', array_map(static fn (array $family): string => sprintf('#%d %s', $family['id'], $family['title']), $item['families'])),
            ];
        }

        $io->section('Títulos ambíguos');
        $io->table(['Título normalizado', 'Linhas sem ISSN', 'Famílias candidatas'], $rows);
    }

    private function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;
    }
}
