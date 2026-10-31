<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Journal\JournalAiBatchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa as respostas da IA (`.out.jsonl`) como evidência `ai` das famílias (docs/evolutions/periodicos-por-base.md, A7).
 *
 * Cada arquivo substitui as evidências `ai` que ele mesmo gravou antes, então reimportar é seguro. Linhas inválidas são
 * listadas e puladas. Depois da importação, rode app:journals:classify.
 */
#[AsCommand(
    name: 'app:journals:ai:import',
    description: 'Importa as respostas da IA (.out.jsonl) como evidência ai das famílias de periódicos'
)]
class JournalsAiImportCommand extends Command
{
    private const REASONS = [
        'invalid_json' => 'JSON inválido',
        'missing_key' => 'sem key',
        'unclassifiable' => 'sem área (primary null)',
        'invalid_primary' => 'área principal inválida',
        'invalid_secondary' => 'áreas secundárias inválidas (até 2)',
        'invalid_confidence' => 'confidence fora de [0,1]',
        'unknown_family' => 'família não encontrada',
        'ambiguous_family' => 'chave aponta para mais de uma família',
        'duplicate_family' => 'família repetida no arquivo',
    ];

    public function __construct(
        private readonly JournalAiBatchService $batches,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('files', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Arquivos .out.jsonl a importar')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Modelo gravado no payload das evidências', JournalAiBatchService::MODEL_DEFAULT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');
        $io = new SymfonyStyle($input, $output);
        $io->title('Importação das respostas de IA');

        $files = array_values(array_map('strval', (array) $input->getArgument('files')));
        $model = trim((string) $input->getOption('model'));
        if ($model === '') {
            $io->error('--model não pode ser vazio.');

            return Command::FAILURE;
        }

        // Dois arquivos com o mesmo nome (em pastas diferentes) gravariam no mesmo lote e um apagaria o outro.
        $names = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                $io->error('Arquivo não encontrado: ' . $file);

                return Command::FAILURE;
            }
            $name = basename($file);
            if (isset($names[$name])) {
                $io->error(sprintf('Dois arquivos se chamam "%s"; cada lote precisa de um nome único.', $name));

                return Command::FAILURE;
            }
            $names[$name] = true;
        }

        $lines = 0;
        $imported = 0;
        $reasons = [];
        foreach ($files as $file) {
            $result = $this->batches->import($file, $model);
            $lines += $result['lines'];
            $imported += $result['imported'];

            $io->section(sprintf(
                '%s: %d linhas, %d importadas, %d puladas',
                basename($file),
                $result['lines'],
                $result['imported'],
                count($result['skipped'])
            ));
            if ($result['skipped'] !== []) {
                $io->table(
                    ['Linha', 'Chave', 'Motivo'],
                    array_map(
                        static fn (array $skip): array => [$skip['line'], $skip['key'] ?? '-', self::REASONS[$skip['reason']] ?? $skip['reason']],
                        $result['skipped']
                    )
                );
            }
            foreach ($result['skipped'] as $skip) {
                $reasons[$skip['reason']] = ($reasons[$skip['reason']] ?? 0) + 1;
            }
        }

        if ($reasons !== []) {
            $io->table(
                ['Motivo', 'Linhas'],
                array_map(
                    static fn (string $reason, int $count): array => [self::REASONS[$reason] ?? $reason, $count],
                    array_keys($reasons),
                    array_values($reasons)
                )
            );
        }
        $io->success(sprintf(
            '%d linhas lidas; %d importadas como evidência ai (modelo %s). Rode app:journals:classify para aplicar.',
            $lines,
            $imported,
            $model
        ));

        return Command::SUCCESS;
    }
}
