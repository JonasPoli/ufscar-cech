<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Journal\JournalAiBatchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exporta, em lotes JSONL, as famílias de periódicos para classificação de área por IA (docs/evolutions/periodicos-por-base.md, A7).
 *
 * Escopo `visible` (padrão): famílias com artigos cuja canônica não tem área ou tem confiança `low`
 * (`medium` com --include-medium). Escopo `unclassified`: todas as famílias sem nenhuma área, só com título e país
 * (segunda passada, lotes de 250).
 */
#[AsCommand(
    name: 'app:journals:ai:export',
    description: 'Exporta, em lotes JSONL, os periódicos para classificação de área por IA'
)]
class JournalsAiExportCommand extends Command
{
    private const SCOPE_VISIBLE = 'visible';
    private const SCOPE_UNCLASSIFIED = 'unclassified';

    public function __construct(
        private readonly JournalAiBatchService $batches,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Pasta de saída, relativa à raiz do projeto (padrão: docs/banco/derived/ai; ai_titles no escopo unclassified)')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'visible (padrão) ou unclassified', self::SCOPE_VISIBLE)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Periódicos por lote (padrão: 40; 250 no escopo unclassified)')
            ->addOption('include-medium', null, InputOption::VALUE_NONE, 'Inclui também as famílias com confiança medium (escopo visible)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');
        $io = new SymfonyStyle($input, $output);
        $io->title('Exportação de lotes para classificação por IA');

        $scope = (string) $input->getOption('scope');
        if (!in_array($scope, [self::SCOPE_VISIBLE, self::SCOPE_UNCLASSIFIED], true)) {
            $io->error(sprintf('Escopo inválido: "%s". Use visible ou unclassified.', $scope));

            return Command::FAILURE;
        }
        $unclassified = $scope === self::SCOPE_UNCLASSIFIED;

        $batchSize = $unclassified ? 250 : 40;
        if ($input->getOption('batch-size') !== null) {
            $raw = (string) $input->getOption('batch-size');
            if (!ctype_digit($raw) || (int) $raw < 1) {
                $io->error('--batch-size deve ser um inteiro maior que zero.');

                return Command::FAILURE;
            }
            $batchSize = (int) $raw;
        }

        $out = (string) $input->getOption('out');
        if ($out === '') {
            $out = $unclassified ? 'docs/banco/derived/ai_titles' : 'docs/banco/derived/ai';
        }
        $outDir = str_starts_with($out, '/') ? $out : $this->projectDir . '/' . $out;

        $includeMedium = (bool) $input->getOption('include-medium');
        if ($unclassified && $includeMedium) {
            $io->warning('--include-medium não se aplica ao escopo unclassified e foi ignorado.');
            $includeMedium = false;
        }

        $result = $unclassified
            ? $this->batches->exportUnclassified($outDir, $batchSize)
            : $this->batches->export($outDir, $batchSize, $includeMedium);

        $io->table(
            ['Escopo', 'Periódicos', 'Lotes', 'Tamanho do lote', 'Pasta'],
            [[$scope, $result['journals'], $result['batches'], $batchSize, $outDir]]
        );
        $io->success(sprintf(
            '%d periódicos em %d lotes. PROMPT.md gravado na pasta de saída.',
            $result['journals'],
            $result['batches']
        ));

        return Command::SUCCESS;
    }
}
