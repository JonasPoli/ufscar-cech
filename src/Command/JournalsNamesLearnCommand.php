<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Journal\JournalNameLearner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Aprendizado de grafias do Lattes com confirmação por ISSN (docs/evolutions/periodicos-por-base.md, A5.3).
 * A regra está em JournalNameLearner; aqui só ficam as opções e o relatório.
 */
#[AsCommand(
    name: 'app:journals:names:learn',
    description: 'Aprende grafias de periódicos do Lattes confirmadas pelo ISSN: cria variantes "lattes" ou enfileira para revisão'
)]
class JournalsNamesLearnCommand extends Command
{
    private const ACTION_LABELS = [
        JournalNameLearner::ACTION_LEARNED => 'aprendido',
        JournalNameLearner::ACTION_QUEUED => 'fila (1 artigo)',
        JournalNameLearner::ACTION_CONFLICT => 'conflito',
        JournalNameLearner::ACTION_SKIPPED => 'ignorado',
    ];

    public function __construct(
        private readonly JournalNameLearner $learner
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcula e mostra o relatório sem gravar variantes nem linhas da fila')
            ->addOption('min-articles', null, InputOption::VALUE_REQUIRED, 'Mínimo de artigos confirmados pelo ISSN para criar a variante direto; com menos, o nome vai para a fila', '2')
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restringe a execução a este nome de periódico (qualquer grafia; repita a opção para mais de um). Sem ela, processa todos');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');

        $io = new SymfonyStyle($input, $output);
        $io->title('Aprendizado de grafias do Lattes (confirmação por ISSN)');

        $dryRun = (bool)$input->getOption('dry-run');
        $minOption = (string)$input->getOption('min-articles');
        if (!ctype_digit($minOption) || (int)$minOption < 1) {
            $io->error('--min-articles deve ser um número inteiro maior ou igual a 1.');

            return Command::FAILURE;
        }

        $only = array_values(array_filter(array_map('strval', (array)$input->getOption('only')), static fn (string $name): bool => trim($name) !== ''));

        $startedAt = microtime(true);
        try {
            $result = $this->learner->learn((int)$minOption, $dryRun, $only !== [] ? $only : null);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->table(
            ['Resultado', 'Nomes'],
            [
                ['Nomes candidatos (artigos com vínculo confirmado por ISSN e nome ainda desconhecido)', $this->format($result['candidates'])],
                [$dryRun ? 'Variantes "lattes" a criar' : 'Variantes "lattes" criadas', $this->format($result['learned'])],
                ['Enfileirados para revisão, com sugestão por ISSN (artigos abaixo do mínimo de ' . $minOption . ')', $this->format($result['queued'])],
                ['Conflitos: nome em mais de uma família (fila, como ambíguos)', $this->format($result['conflicts'])],
                ['Ignorados (já decididos na fila ou já conhecidos pela família)', $this->format($result['skipped'])],
                [$dryRun ? 'Linhas obsoletas da fila por ISSN a remover' : 'Linhas obsoletas da fila por ISSN removidas', $this->format($result['removed'])],
            ]
        );

        if ($result['examples'] !== []) {
            $rows = [];
            foreach ($result['examples'] as $example) {
                $rows[] = [
                    self::ACTION_LABELS[$example['action']] ?? $example['action'],
                    $example['name'],
                    $example['journal'] . (isset($example['detail']) ? ' (' . $example['detail'] . ')' : ''),
                    $example['articles'],
                ];
            }
            $io->section('Exemplos');
            $io->table(['Ação', 'Nome no Lattes', 'Periódico', 'Artigos'], $rows);
        }

        if ($result['unnormalized_titles'] > 0 || $result['unnormalized_articles'] > 0) {
            $io->note(sprintf(
                '%s linha(s) de qualis_journals sem normalized_title e %s artigo(s) vinculados com ISSN sem journal_name_normalized: '
                . 'a forma do tesauro foi calculada em memória. Rode app:journals:normalize-titles para gravá-la.',
                $this->format($result['unnormalized_titles']),
                $this->format($result['unnormalized_articles'])
            ));
        }

        $seconds = number_format(microtime(true) - $startedAt, 1, ',', '.');
        if ($dryRun) {
            $io->warning(sprintf('Modo simulação (--dry-run): nada foi gravado no banco (%s s).', $seconds));
        } else {
            $io->success(sprintf(
                '%s variante(s) criada(s) e %s nome(s) enfileirado(s) para revisão (%s s).',
                $this->format($result['learned']),
                $this->format($result['queued'] + $result['conflicts']),
                $seconds
            ));
        }

        return Command::SUCCESS;
    }

    private function format(int $number): string
    {
        return number_format($number, 0, ',', '.');
    }
}
