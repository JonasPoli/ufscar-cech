<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Indexing\ThematicTermIndexService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:index:topics',
    description: 'Indexa palavras-chave e sintagmas temáticos consolidados (Lattes + Repositório UFSCar)',
    aliases: ['app:topics:index', 'app:index:thematic-terms']
)]
class ThematicTermIndexCommand extends Command
{
    public function __construct(
        private readonly ThematicTermIndexService $indexService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Indexação Temática por Palavras-Chave (Lattes + Repositório UFSCar)');

        $progressBar = null;

        $stats = $this->indexService->indexAll(function (int $processed, int $total) use ($output, &$progressBar) {
            if ($progressBar === null) {
                $progressBar = new ProgressBar($output, $total);
                $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
                $progressBar->setMessage('Analisando docentes e minerando termos...');
                $progressBar->start();
            }
            $progressBar->setProgress($processed);
        });

        if ($progressBar !== null) {
            $progressBar->finish();
            $io->newLine(2);
        }

        $io->success(sprintf(
            'Indexação concluída com sucesso em %.2f segundos! Total de termos: %s | Total de vínculos com docentes: %s',
            $stats['executionTime'],
            number_format($stats['totalTerms'], 0, ',', '.'),
            number_format($stats['totalLinks'], 0, ',', '.')
        ));

        return Command::SUCCESS;
    }
}
