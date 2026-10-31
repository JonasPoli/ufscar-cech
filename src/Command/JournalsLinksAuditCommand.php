<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Journal\JournalLinkAuditService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:journals:links:audit',
    description: 'Audita os vínculos artigo→periódico: grava um snapshot (--snapshot) ou compara o estado atual com um (--compare).'
)]
class JournalsLinksAuditCommand extends Command
{
    public function __construct(private readonly JournalLinkAuditService $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'snapshot',
                null,
                InputOption::VALUE_REQUIRED,
                'Grava o estado atual dos vínculos neste arquivo JSON (rodar antes da reindexação)'
            )
            ->addOption(
                'compare',
                null,
                InputOption::VALUE_REQUIRED,
                'Compara o estado atual com o snapshot deste arquivo JSON (rodar depois da reindexação)'
            )
            ->addOption('json', null, InputOption::VALUE_NONE, 'Imprime o resultado bruto em JSON, sem as tabelas');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');
        $io = new SymfonyStyle($input, $output);

        $snapshotFile = (string) $input->getOption('snapshot');
        $compareFile = (string) $input->getOption('compare');
        if (($snapshotFile === '') === ($compareFile === '')) {
            $io->error('Informe um, e somente um, dos parâmetros: --snapshot=<arquivo> ou --compare=<arquivo>.');

            return Command::FAILURE;
        }

        $asJson = (bool) $input->getOption('json');

        try {
            if ($snapshotFile !== '') {
                $state = $this->audit->snapshot($snapshotFile);
                if ($asJson) {
                    $this->writeJson($output, $state);
                } else {
                    $this->printSnapshot($io, $snapshotFile, $state);
                }

                return Command::SUCCESS;
            }

            $result = $this->audit->compare($compareFile);
            if ($asJson) {
                $this->writeJson($output, $result);
            } else {
                $this->printComparison($io, $result);
            }
        } catch (\RuntimeException | \JsonException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $state */
    private function printSnapshot(SymfonyStyle $io, string $file, array $state): void
    {
        $io->title('Auditoria de vínculos artigo→periódico: snapshot');
        $io->success(sprintf(
            'Snapshot gravado em %s: %s artigos, %s vinculados a periódico.',
            OutputFormatter::escape($file),
            $this->number($state['totals']['articles']),
            $this->number($state['totals']['linked'])
        ));

        $dbRows = [];
        arsort($state['totals']['by_db']);
        foreach ($state['totals']['by_db'] as $acronym => $count) {
            $dbRows[] = [$acronym, $this->number($count)];
        }
        $io->section('Artigos por base de indexação');
        $io->table(['Base', 'Artigos'], $dbRows);

        $stratumRows = [];
        foreach ($state['totals']['by_qualis'] as $stratum => $count) {
            $stratumRows[] = [$this->stratumLabel((string) $stratum), $this->number($count)];
        }
        $io->section('Artigos por estrato Qualis');
        $io->table(['Estrato', 'Artigos'], $stratumRows);

        $checkRows = [];
        foreach ($state['checks'] as $check) {
            $checkRows[] = [OutputFormatter::escape($check['label']), $this->number($check['count'])];
        }
        $io->section('Casos conhecidos de A5.6 neste snapshot');
        $io->table(['Caso', 'Violações'], $checkRows);
    }

    /** @param array<string, mixed> $r */
    private function printComparison(SymfonyStyle $io, array $r): void
    {
        $io->title('Auditoria de vínculos artigo→periódico: antes x depois');
        $io->text(sprintf(
            'Snapshot: %s (gravado em %s). Estado atual lido em %s.',
            OutputFormatter::escape($r['snapshot']['file']),
            $r['snapshot']['taken_at'],
            $r['compared_at']
        ));

        $io->section('Artigos');
        $io->table(['Situação', 'Artigos'], [
            ['Iguais (mesmo periódico, inclusive sem vínculo nas duas leituras)', $this->number($r['same'])],
            ['   dentre os iguais: Qualis ou bases diferentes', $this->number($r['same_journal_other_changes'])],
            ['Mudaram de periódico', $this->number($r['changed'])],
            ['Ganharam vínculo', $this->number($r['gained'])],
            ['Perderam vínculo', $this->number($r['lost'])],
            ['Novos (só no estado atual)', $this->number($r['articles']['new'])],
            ['Removidos (só no snapshot)', $this->number($r['articles']['removed'])],
            ['Vinculados: antes → depois', sprintf('%s → %s', $this->number($r['linked']['before']), $this->number($r['linked']['after']))],
        ]);

        $io->section('Variação por estrato Qualis');
        $io->table(['Estrato', 'Antes', 'Depois', 'Variação'], $this->variationRows($r['by_qualis'], true));

        $io->section('Variação por base de indexação');
        $io->table(['Base', 'Antes', 'Depois', 'Variação'], $this->variationRows($r['by_db']));

        $pairRows = [];
        foreach ($r['pairs'] as $pair) {
            $pairRows[] = [OutputFormatter::escape($pair['from']), OutputFormatter::escape($pair['to']), $this->number($pair['n'])];
        }
        $io->section(sprintf('Maiores mudanças por par, até %d (antes → depois)', JournalLinkAuditService::TOP_PAIRS));
        $io->table(['Antes', 'Depois', 'Artigos'], $pairRows);

        $checkRows = [];
        $pending = false;
        foreach ($r['checks'] as $check) {
            $pending = $pending || $check['after'] > 0;
            $checkRows[] = [
                OutputFormatter::escape($check['label']),
                $check['before'] === null ? '-' : $this->number($check['before']),
                $this->number($check['after']),
                $check['after'] === 0 ? 'OK' : 'PENDENTE',
            ];
        }
        $io->section('Casos conhecidos de A5.6 (meta: 0)');
        $io->table(['Caso', 'Antes', 'Depois', 'Situação'], $checkRows);

        foreach ($r['checks'] as $check) {
            if ($check['after'] === 0) {
                continue;
            }
            $exampleRows = [];
            foreach ($check['examples'] as $example) {
                $exampleRows[] = [
                    $example['id'],
                    OutputFormatter::escape((string) $example['journal_name']),
                    OutputFormatter::escape((string) ($example['journal_title'] ?? '')),
                ];
            }
            $io->text(sprintf('Exemplos (até 5) de: %s', OutputFormatter::escape($check['label'])));
            $io->table(['Artigo', 'Nome no Lattes', 'Periódico vinculado'], $exampleRows);
        }

        if ($pending) {
            $io->warning('Há artigos vinculados a periódico errado nos casos de A5.6. Veja os exemplos acima.');
        } else {
            $io->success('Nenhum caso conhecido de A5.6 está vinculado indevidamente.');
        }
    }

    /**
     * @param array<string, array{before: int, after: int, delta: int}> $variation
     *
     * @return list<list<string>>
     */
    private function variationRows(array $variation, bool $stratum = false): array
    {
        $rows = [];
        foreach ($variation as $key => $values) {
            $rows[] = [
                $stratum ? $this->stratumLabel((string) $key) : OutputFormatter::escape((string) $key),
                $this->number($values['before']),
                $this->number($values['after']),
                $this->signed($values['delta']),
            ];
        }

        return $rows;
    }

    private function stratumLabel(string $stratum): string
    {
        return $stratum === JournalLinkAuditService::NO_QUALIS ? '(sem Qualis)' : $stratum;
    }

    private function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function signed(int $value): string
    {
        return ($value > 0 ? '+' : '') . $this->number($value);
    }

    private function writeJson(OutputInterface $output, array $data): void
    {
        $output->writeln(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            OutputInterface::OUTPUT_RAW
        );
    }
}
