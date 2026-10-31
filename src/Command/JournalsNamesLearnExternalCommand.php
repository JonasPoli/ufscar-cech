<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Journal\JournalExternalNameLearner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Alimenta o tesauro de periódicos com os títulos das listas externas. A regra está em JournalExternalNameLearner.
 */
#[AsCommand(
    name: 'app:journals:names:learn-external',
    description: 'Cria variantes "external" a partir dos títulos das listas externas, confirmados pelo ISSN'
)]
class JournalsNamesLearnExternalCommand extends Command
{
    public function __construct(
        private readonly JournalExternalNameLearner $learner
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Artefato de evidências', 'docs/banco/derived/journal_evidence.jsonl.gz')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcula e mostra o relatório sem gravar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');

        $io = new SymfonyStyle($input, $output);
        $io->title('Títulos das listas externas no tesauro de periódicos');

        $dryRun = (bool)$input->getOption('dry-run');
        try {
            $result = $this->learner->learn((string)$input->getOption('file'), $dryRun);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->table(
            ['Resultado', 'Quantidade'],
            [
                ['Registros das listas ligados a uma família pelo ISSN', $this->format($result['records'])],
                ['Nomes que ainda não eram título nem variante', $this->format($result['candidates'])],
                [$dryRun ? 'Variantes "external" a criar' : 'Variantes "external" criadas', $this->format($result['learned'])],
                ['Fora: nome em mais de uma família', $this->format($result['ambiguous'])],
                ['Fora: nome sem coerência com os títulos da família', $this->format($result['incoherent'])],
                ['Fora: conflito apontado pela trava do tesauro', $this->format($result['conflicts'])],
            ]
        );

        if ($result['by_source'] !== []) {
            arsort($result['by_source']);
            $io->section('Variantes por lista');
            $io->table(['Lista', 'Nomes'], array_map(
                fn (string $source, int $count): array => [$source, $this->format($count)],
                array_keys($result['by_source']),
                array_values($result['by_source'])
            ));
        }

        if ($result['examples'] !== []) {
            $io->section('Exemplos aceitos');
            $io->table(['Nome na lista', 'Título no cadastro'], array_map(static fn (array $e): array => [$e['name'], $e['journal']], $result['examples']));
        }
        if ($result['rejected_examples'] !== []) {
            $io->section('Exemplos recusados');
            $io->table(['Nome na lista', 'Título no cadastro', 'Nota'], array_map(static fn (array $e): array => [$e['name'], $e['journal'], $e['score']], $result['rejected_examples']));
        }

        if ($dryRun) {
            $io->warning('Modo simulação (--dry-run): nada foi gravado no banco.');
        } else {
            $io->success(sprintf('%s variante(s) "external" criada(s).', $this->format($result['learned'])));
        }

        return Command::SUCCESS;
    }

    private function format(int $number): string
    {
        return number_format($number, 0, ',', '.');
    }
}
