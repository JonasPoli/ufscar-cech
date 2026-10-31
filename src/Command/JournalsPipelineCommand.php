<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sequência de produção dos periódicos (docs/evolutions/periodicos-por-base.md, A8) em um comando só. Roda os passos na
 * ordem, cada um pelo comando correspondente, e para no primeiro erro. As migrations não entram aqui: rode-as antes, à mão.
 *
 * Passos pulados com aviso (arquivo de entrada ausente): overrides-names, overrides-areas e ai-import. Antes de começar,
 * confere os artefatos obrigatórios dos passos escolhidos (scielo_articlemeta.json e journal_evidence.jsonl.gz) e para
 * sem tocar no banco se faltar algum.
 *
 * Auditoria: cada execução grava `var/audit/pipeline-<AAAAMMDD-HHMMSS>-before.json` (nunca `var/audit/before.json`).
 * audit-after compara com o snapshot da mesma execução; se audit-before não rodou nela (--from, --only, --skip), usa o
 * mais recente `pipeline-*-before.json` e avisa; sem nenhum, pula.
 *
 * Exemplos: `app:journals:pipeline --derived=docs/banco/derived --env=prod`;
 * `app:journals:pipeline --only=normalize-titles,audit-before`; `app:journals:pipeline --from=classify`.
 */
#[AsCommand(
    name: 'app:journals:pipeline',
    description: 'Executa em ordem os passos de produção dos periódicos (A8), parando no primeiro erro.'
)]
class JournalsPipelineCommand extends Command
{
    /** @var list<string> passos na ordem de execução (A8, passos 2 a 17, com o families dividido em antes e depois do index) */
    public const STEPS = [
        'normalize-titles',
        'audit-before',
        'scielo-enrich',
        'evidence-load',
        'families-first',
        'index',
        'families',
        'learn',
        'overrides-names',
        'families-final',
        'index-again',
        'audit-after',
        'title-rules',
        'classify',
        'ai-import',
        'overrides-areas',
        'classify-again',
        'report',
        'cache-clear',
    ];

    /** Modelo gravado no payload das respostas de IA (`app:journals:ai:import --model`). */
    private const AI_MODEL = 'claude-haiku';

    private const SCIELO_FILE = 'scielo_articlemeta.json';
    private const EVIDENCE_FILE = 'journal_evidence.jsonl.gz';

    private const STATUS_OK = 'ok';
    private const STATUS_SKIPPED = 'pulado';
    private const STATUS_FAILED = 'falhou';

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'derived',
                null,
                InputOption::VALUE_REQUIRED,
                'Pasta dos artefatos derivados (relativa à raiz do projeto ou absoluta)',
                'docs/banco/derived'
            )
            ->addOption(
                'only',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Roda só estes passos (separados por vírgula ou repetindo a opção)'
            )
            ->addOption(
                'skip',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Pula estes passos (separados por vírgula ou repetindo a opção)'
            )
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Começa por este passo e segue até o fim');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');

        $io = new SymfonyStyle($input, $output);
        $io->title('Pipeline de periódicos (A8)');

        try {
            $selected = $this->selectSteps($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $derived = $this->resolvePath((string) $input->getOption('derived'));
        $missing = $this->missingRequiredInputs($selected, $derived);
        if ($missing !== []) {
            $io->error(array_merge(['Artefatos obrigatórios ausentes; nenhum passo foi executado:'], $missing));

            return Command::FAILURE;
        }

        $stamp = (new \DateTimeImmutable())->format('Ymd-His');
        $state = [
            'derived' => $derived,
            'audit' => sprintf('%s/var/audit/pipeline-%s-before.json', $this->projectDir, $stamp),
            'auditTaken' => false,
        ];

        $io->definitionList(
            ['Pasta derivada' => $derived],
            ['Snapshot desta execução' => $state['audit']],
            ['Passos' => implode(', ', $selected)],
        );

        $results = [];
        $failed = false;
        $total = count($selected);
        foreach ($selected as $index => $step) {
            $io->section(sprintf('[%d/%d] %s', $index + 1, $total, $step));

            $started = microtime(true);
            try {
                [$status, $note, $code] = $this->runStep($step, $state, $io, $output);
            } catch (\Throwable $e) {
                [$status, $note, $code] = $this->fail(Command::FAILURE, $e->getMessage());
            }
            $duration = $this->duration(microtime(true) - $started);

            if ($status === self::STATUS_SKIPPED) {
                $io->warning(sprintf('Passo "%s" pulado: %s', $step, $note));
            }
            $io->writeln(sprintf('Tempo do passo "%s": %s', $step, $duration));
            $results[] = [$step, $status, $duration, $note];

            if ($code !== Command::SUCCESS) {
                $failed = true;
                $io->error(sprintf('Passo "%s" falhou (%s). Os passos seguintes não foram executados.', $step, $note));
                break;
            }
        }

        $io->section('Resumo');
        $io->table(['Passo', 'Situação', 'Tempo', 'Detalhe'], $results);

        if ($failed) {
            return Command::FAILURE;
        }
        $io->success('Pipeline concluído.');

        return Command::SUCCESS;
    }

    /**
     * Executa um passo e devolve [situação, detalhe, código de saída].
     *
     * @param array{derived: string, audit: string, auditTaken: bool} $state
     *
     * @return array{string, string, int}
     */
    private function runStep(string $step, array &$state, SymfonyStyle $io, OutputInterface $output): array
    {
        $derived = $state['derived'];

        return match ($step) {
            'normalize-titles' => $this->call('app:journals:normalize-titles', [], $output),
            'audit-before' => $this->auditBefore($state, $output),
            'scielo-enrich' => $this->call('app:journals:scielo:enrich', ['file' => $derived . '/' . self::SCIELO_FILE], $output),
            'evidence-load' => $this->call('app:journals:evidence:load', [
                'artifact' => $derived . '/' . self::EVIDENCE_FILE,
                '--create-missing-for-articles' => true,
                '--legacy' => true,
            ], $output),
            'families-first', 'families', 'families-final' => $this->call(
                'app:journals:families',
                ['--artifact' => $derived . '/' . self::EVIDENCE_FILE],
                $output
            ),
            'index', 'index-again' => $this->call('app:index:journals', [], $output),
            'learn' => $this->call('app:journals:names:learn', [], $output),
            'overrides-names' => $this->overrides($derived . '/overrides/names.jsonl', $output),
            'audit-after' => $this->auditAfter($state, $io, $output),
            'title-rules' => $this->call('app:journals:evidence:load', ['--title-rules' => true], $output),
            'classify', 'classify-again' => $this->call('app:journals:classify', [], $output),
            'ai-import' => $this->aiImport($derived, $output),
            'overrides-areas' => $this->overrides($derived . '/overrides/areas.jsonl', $output),
            'report' => $this->call('app:journals:report', [], $output),
            'cache-clear' => $this->call('app:cache:clear', [], $output),
        };
    }

    /**
     * @param array{derived: string, audit: string, auditTaken: bool} $state
     *
     * @return array{string, string, int}
     */
    private function auditBefore(array &$state, OutputInterface $output): array
    {
        if (is_file($state['audit'])) {
            return $this->fail(Command::FAILURE, 'o arquivo de auditoria desta execução já existe: ' . $state['audit']);
        }

        $state['auditTaken'] = true;

        return $this->call('app:journals:links:audit', ['--snapshot' => $state['audit']], $output);
    }

    /**
     * @param array{derived: string, audit: string, auditTaken: bool} $state
     *
     * @return array{string, string, int}
     */
    private function auditAfter(array $state, SymfonyStyle $io, OutputInterface $output): array
    {
        if ($state['auditTaken']) {
            $file = $state['audit'];
        } else {
            $file = $this->latestAuditSnapshot();
            if ($file === null) {
                return $this->skip('nenhum pipeline-*-before.json em var/audit; comparação pulada');
            }
            $io->warning(sprintf('audit-before não rodou nesta execução; comparando com o snapshot mais recente: %s', $file));
        }

        return $this->call('app:journals:links:audit', ['--compare' => $file], $output);
    }

    /**
     * @return array{string, string, int}
     */
    private function aiImport(string $derived, OutputInterface $output): array
    {
        $files = $this->globAll([$derived . '/ai/*.out.jsonl', $derived . '/ai_titles/*.out.jsonl']);
        if ($files === []) {
            return $this->skip('sem ai/*.out.jsonl nem ai_titles/*.out.jsonl em ' . $derived);
        }

        return $this->call('app:journals:ai:import', ['files' => $files, '--model' => self::AI_MODEL], $output);
    }

    /**
     * @return array{string, string, int}
     */
    private function overrides(string $file, OutputInterface $output): array
    {
        if (!is_file($file)) {
            return $this->skip('arquivo ausente: ' . $file);
        }

        return $this->call('app:journals:overrides:import', ['file' => $file], $output);
    }

    /**
     * Roda outro comando da aplicação, em modo não interativo, com a mesma saída.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array{string, string, int}
     */
    private function call(string $name, array $parameters, OutputInterface $output): array
    {
        $application = $this->getApplication();
        if ($application === null) {
            throw new \LogicException('O pipeline precisa rodar dentro da aplicação de console.');
        }

        $input = new ArrayInput($parameters);
        $input->setInteractive(false);
        $code = $application->find($name)->run($input, $output);

        return $code === Command::SUCCESS
            ? [self::STATUS_OK, '', Command::SUCCESS]
            : $this->fail($code, sprintf('%s terminou com código %d', $name, $code));
    }

    /**
     * @return array{string, string, int}
     */
    private function skip(string $note): array
    {
        return [self::STATUS_SKIPPED, $note, Command::SUCCESS];
    }

    /**
     * @return array{string, string, int}
     */
    private function fail(int $code, string $note): array
    {
        return [self::STATUS_FAILED, $note, $code];
    }

    /**
     * Passos escolhidos pelas opções, na ordem do A8.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException nome de passo desconhecido ou nenhum passo selecionado
     */
    private function selectSteps(InputInterface $input): array
    {
        $only = $this->splitNames($input->getOption('only'));
        $skip = $this->splitNames($input->getOption('skip'));
        $from = trim((string) $input->getOption('from'));

        $named = array_merge($only, $skip, $from === '' ? [] : [$from]);
        foreach ($named as $name) {
            if (!in_array($name, self::STEPS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Passo desconhecido: "%s". Passos: %s',
                    $name,
                    implode(', ', self::STEPS)
                ));
            }
        }

        $start = $from === '' ? 0 : (int) array_search($from, self::STEPS, true);
        $selected = [];
        foreach (self::STEPS as $position => $step) {
            if ($position < $start) {
                continue;
            }
            if ($only !== [] && !in_array($step, $only, true)) {
                continue;
            }
            if (in_array($step, $skip, true)) {
                continue;
            }
            $selected[] = $step;
        }

        if ($selected === []) {
            throw new \InvalidArgumentException('Nenhum passo selecionado com estas opções.');
        }

        return $selected;
    }

    /**
     * @return list<string> artefatos obrigatórios que faltam para os passos escolhidos
     */
    private function missingRequiredInputs(array $selected, string $derived): array
    {
        $missing = [];
        if (in_array('scielo-enrich', $selected, true) && !is_file($derived . '/' . self::SCIELO_FILE)) {
            $missing[] = sprintf('%s (passo scielo-enrich)', $derived . '/' . self::SCIELO_FILE);
        }
        $usesEvidence = array_intersect(['evidence-load', 'families-first', 'families', 'families-final'], $selected) !== [];
        if ($usesEvidence && !is_file($derived . '/' . self::EVIDENCE_FILE)) {
            $missing[] = sprintf('%s (passos evidence-load e families)', $derived . '/' . self::EVIDENCE_FILE);
        }

        return $missing;
    }

    /**
     * Snapshot de audit-before mais recente (o nome traz a data, então a ordem alfabética é a cronológica).
     */
    private function latestAuditSnapshot(): ?string
    {
        $files = $this->globAll([$this->projectDir . '/var/audit/pipeline-*-before.json']);

        return $files === [] ? null : $files[array_key_last($files)];
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<string> caminhos em ordem alfabética
     */
    private function globAll(array $patterns): array
    {
        $files = [];
        foreach ($patterns as $pattern) {
            $found = glob($pattern) ?: [];
            sort($found, SORT_STRING);
            array_push($files, ...$found);
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function splitNames(mixed $value): array
    {
        $names = [];
        foreach ((array) $value as $item) {
            foreach (explode(',', (string) $item) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function duration(float $seconds): string
    {
        return number_format($seconds, 1, ',', '.') . ' s';
    }

    private function resolvePath(string $path): string
    {
        $path = rtrim($path, '/');

        return str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;
    }
}
