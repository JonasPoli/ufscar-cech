<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Journal\JournalEvidenceArtifactBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:journals:evidence:build',
    description: 'Constrói o artefato de evidências de área dos periódicos (WoS, Scopus, DOAJ, Latindex, OpenAlex, SciELO, PubMed) e os vocabulários'
)]
class JournalsEvidenceBuildCommand extends Command
{
    private const DEFAULT_SOURCES = 'docs/banco/lists-to-import';
    private const DEFAULT_DERIVED = 'docs/banco/derived';
    private const DEFAULT_OUT = 'docs/banco/derived/journal_evidence.jsonl.gz';
    private const DEFAULT_VOCAB_DIR = 'config/journal_areas/vocab';

    public function __construct(
        private readonly JournalEvidenceArtifactBuilder $builder,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sources', null, InputOption::VALUE_REQUIRED, 'Pasta com as listas de cada base (relativa à raiz do projeto ou absoluta)', self::DEFAULT_SOURCES)
            ->addOption('derived', null, InputOption::VALUE_REQUIRED, 'Pasta dos derivados (scielo_articlemeta.json)', self::DEFAULT_DERIVED)
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Artefato de saída (.jsonl.gz)', self::DEFAULT_OUT)
            ->addOption('vocab-dir', null, InputOption::VALUE_REQUIRED, 'Pasta dos vocabulários (um arquivo por base)', self::DEFAULT_VOCAB_DIR)
            ->addOption(
                'skip',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                sprintf('Fontes a não ler, separadas por vírgula (%s)', implode(', ', JournalEvidenceArtifactBuilder::SOURCES))
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '4G');

        $io = new SymfonyStyle($input, $output);
        $io->title('Artefato de evidências de área dos periódicos');

        $sources = $this->resolvePath((string) $input->getOption('sources'));
        $derived = $this->resolvePath((string) $input->getOption('derived'));
        $out = $this->resolvePath((string) $input->getOption('out'));
        $vocabDir = $this->resolvePath((string) $input->getOption('vocab-dir'));
        /** @var list<string> $skip */
        $skip = (array) $input->getOption('skip');

        $io->definitionList(
            ['Listas' => $sources],
            ['Derivados' => $derived],
            ['Artefato' => $out],
            ['Vocabulários' => $vocabDir],
            ['Fontes puladas' => $skip === [] ? '(nenhuma)' : implode(', ', $skip)]
        );

        try {
            $result = $this->builder->build(
                $sources,
                $derived,
                $out,
                $vocabDir,
                static function (string $message) use ($io): void {
                    $io->writeln(' ' . $message);
                },
                $skip
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->newLine();
        $rows = [];
        foreach ($result['sources'] as $source => $meta) {
            $rows[] = [
                $source,
                (string) $meta['file'] . (isset($meta['files']) ? sprintf(' (%d arquivos)', $meta['files']) : ''),
                number_format((int) $meta['records'], 0, ',', '.'),
                sprintf('%.1f s', $result['seconds'][$source] ?? 0),
            ];
        }
        $rows[] = ['<info>total</info>', '', '<info>' . number_format($result['records'], 0, ',', '.') . '</info>', sprintf('%.1f s', $result['total_seconds'])];
        $io->table(['Fonte', 'Arquivo', 'Registros', 'Tempo'], $rows);

        if ($result['vocab'] !== []) {
            $vocabRows = [];
            foreach ($result['vocab'] as $name => $vocab) {
                $vocabRows[] = [$name . '.txt', $vocab['lines'], $vocab['status']];
            }
            $io->table(['Vocabulário', 'Linhas', 'Situação'], $vocabRows);
        }

        foreach ($result['notes'] as $note) {
            $io->text($note);
        }
        if ($result['skipped'] !== []) {
            $io->text('Fontes puladas: ' . implode(', ', $result['skipped']));
        }
        if ($result['warnings'] !== []) {
            $io->warning($result['warnings']);
        }

        $io->success(sprintf(
            'Artefato gravado em %s (%s, %s registros).',
            $result['out'],
            Helper::formatMemory($result['bytes']),
            number_format($result['records'], 0, ',', '.')
        ));

        return Command::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        $absolute = str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;

        return rtrim($absolute, '/');
    }
}
