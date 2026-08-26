<?php

namespace App\Command;

use App\Service\Indexing\CurriculumNormalizationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:index:journals',
    description: 'Indexa periódicos Qualis e Bases de Indexação Internacional (Scopus, WoS, etc.) em todas as produções',
    aliases: ['app:indexing:journals']
)]
class IndexJournalsCommand extends Command
{
    public function __construct(
        private readonly CurriculumNormalizationService $normalizationService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Indexação em Lote de Periódicos e Bases Científicas Internacionais');

        $progressBar = new ProgressBar($output);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $progressBar->setMessage('Iniciando...');

        $started = false;
        $progressCallback = function(int $current, int $total, int $percent, array $stats) use ($progressBar, &$started) {
            if (!$started) {
                $progressBar->setMaxSteps($total);
                $progressBar->start();
                $started = true;
            }
            $progressBar->setProgress($current);
            $progressBar->setMessage(sprintf(
                'Periódicos: %d | Qualis: %d | Bases: %d',
                $stats['journalsMatched'],
                $stats['qualisResolved'],
                $stats['databasesLinked']
            ));
        };

        $stats = $this->normalizationService->indexAllProductionsJournalAndDatabases($progressCallback);

        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf('Indexação concluída em %s segundos!', $stats['durationSec']));
        $io->table(
            ['Métrica', 'Total'],
            [
                ['Artigos Processados', number_format($stats['totalProcessed'], 0, ',', '.')],
                ['Periódicos Canônicos Vinculados', number_format($stats['journalsMatched'], 0, ',', '.')],
                ['Estratos Qualis Atribuídos', number_format($stats['qualisResolved'], 0, ',', '.')],
                ['Artigos com Bases Indexadoras (Scopus, WoS, etc.)', number_format($stats['databasesLinked'], 0, ',', '.')],
                ['Tempo de Execução', $stats['durationSec'] . ' s'],
            ]
        );

        return Command::SUCCESS;
    }
}
