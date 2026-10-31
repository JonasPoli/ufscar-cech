<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:journals:evidence:fetch-scielo',
    description: 'Baixa do SciELO ArticleMeta o cadastro de periódicos da coleção scl (título, ISSN, áreas, WoS, descritores, status)'
)]
class JournalsFetchScieloCommand extends Command
{
    private const API_BASE = 'https://articlemeta.scielo.org/api/v1/journal/';
    private const COLLECTION = 'scl';
    private const PAGE_SIZE = 1000;
    private const ATTEMPTS = 3;
    private const DEFAULT_OUT = 'docs/banco/derived/scielo_articlemeta.json';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Arquivo JSON de saída (relativo à raiz do projeto ou absoluto)', self::DEFAULT_OUT)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Processar apenas os N primeiros códigos (teste)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Cadastro de periódicos do SciELO ArticleMeta (coleção scl)');

        $limit = null;
        if ($input->getOption('limit') !== null) {
            $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($limit === false) {
                $io->error('--limit deve ser um inteiro maior que zero.');
                return Command::FAILURE;
            }
        }

        $outPath = $this->resolvePath((string) $input->getOption('out'));

        try {
            $codes = $this->fetchCodes();
        } catch (\Throwable $e) {
            $io->error('Não foi possível obter a lista de códigos: ' . $e->getMessage());
            return Command::FAILURE;
        }
        if ($limit !== null) {
            $codes = array_slice($codes, 0, $limit);
        }
        $io->text(sprintf('%d código(s) a processar.', count($codes)));

        $journals = [];
        $errors = [];
        $progress = $io->createProgressBar(count($codes));
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progress->setMessage('iniciando...');
        $progress->start();
        foreach ($codes as $code) {
            $progress->setMessage($code);
            try {
                $journals[$code] = $this->fetchJournal($code);
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s: %s', $code, $e->getMessage());
            }
            usleep(100000);
            $progress->advance();
        }
        $progress->finish();
        $io->newLine(2);

        try {
            $this->writeJson($outPath, $journals);
        } catch (\Throwable $e) {
            $io->error('Não foi possível gravar o arquivo: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->table(['Métrica', 'Valor'], [
            ['Códigos processados', count($codes)],
            ['Periódicos gravados', count($journals)],
            ['Erros', count($errors)],
            ['Arquivo', $outPath],
        ]);

        if ($errors !== []) {
            $io->error(sprintf(
                '%d código(s) falharam e ficaram de fora do arquivo (até %d tentativas cada).',
                count($errors),
                self::ATTEMPTS
            ));
            $io->listing($errors);
            return Command::FAILURE;
        }

        $io->success(sprintf('%d periódicos gravados em %s.', count($journals), $outPath));
        return Command::SUCCESS;
    }

    /**
     * @return list<string> códigos SciELO da coleção
     */
    private function fetchCodes(): array
    {
        $codes = [];
        $offset = 0;
        do {
            $page = $this->httpClient->request('GET', self::API_BASE . 'identifiers/', [
                'query' => ['collection' => self::COLLECTION, 'limit' => self::PAGE_SIZE, 'offset' => $offset],
                'timeout' => 30,
            ])->toArray();

            $objects = $page['objects'] ?? [];
            foreach ($objects as $object) {
                if (is_string($object['code'] ?? null)) {
                    $codes[] = $object['code'];
                }
            }

            $offset += self::PAGE_SIZE;
            $total = (int) ($page['meta']['total'] ?? 0);
        } while ($objects !== [] && $offset < $total);

        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            throw new \RuntimeException('a API não retornou nenhum código');
        }

        return $codes;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJournal(string $code): array
    {
        $lastError = '';
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                return $this->normalize($code, $this->requestRecord($code));
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempt < self::ATTEMPTS) {
                    sleep(1);
                }
            }
        }

        throw new \RuntimeException(sprintf('%s (após %d tentativas)', $lastError, self::ATTEMPTS));
    }

    /**
     * @return array<string, mixed> registro bruto do ArticleMeta para o código
     */
    private function requestRecord(string $code): array
    {
        $data = $this->httpClient->request('GET', self::API_BASE, [
            'query' => ['collection' => self::COLLECTION, 'issn' => $code],
            'timeout' => 30,
        ])->toArray();

        // A API responde com uma lista de um objeto (ou com o próprio objeto).
        $record = array_is_list($data) ? ($data[0] ?? null) : $data;
        if (!is_array($record)) {
            throw new \RuntimeException('resposta sem registro');
        }
        if (isset($record['code']) && $record['code'] !== $code) {
            throw new \RuntimeException('código divergente na resposta');
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function normalize(string $code, array $record): array
    {
        $issns = [];
        foreach ($this->items($record, 'v435') as $item) {
            if (is_array($item) && is_string($item['_'] ?? null) && $item['_'] !== '') {
                $issns[] = ['issn' => $item['_'], 'type' => $item['t'] ?? null];
            }
        }
        if ($issns === []) {
            // Sem v435 no registro: o código do SciELO serve como ISSN, sem tipo informado.
            $issns[] = ['issn' => $code, 'type' => null];
        }

        return [
            'title' => $this->values($record, 'v100')[0] ?? null,
            'short' => $this->values($record, 'v150')[0] ?? null,
            'issns' => $issns,
            'area' => $this->values($record, 'v441'),
            'wos' => $this->values($record, 'v854'),
            'desc' => $this->values($record, 'v440'),
            'status' => $this->values($record, 'v50')[0] ?? null,
        ];
    }

    /**
     * Campo ISIS do ArticleMeta: lista de objetos (ou um objeto isolado) em que o valor fica em "_".
     *
     * @param array<string, mixed> $record
     * @return list<string>
     */
    private function values(array $record, string $field): array
    {
        $values = [];
        foreach ($this->items($record, $field) as $item) {
            $value = is_array($item) ? ($item['_'] ?? null) : $item;
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $record
     * @return list<mixed>
     */
    private function items(array $record, string $field): array
    {
        $value = $record[$field] ?? null;
        if ($value === null) {
            return [];
        }

        return is_array($value) && array_is_list($value) ? $value : [$value];
    }

    /**
     * @param array<string, array<string, mixed>> $journals
     */
    private function writeJson(string $path, array $journals): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('não foi possível criar a pasta ' . $dir);
        }

        $json = $journals === []
            ? '{}'
            : json_encode($journals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Grava em arquivo temporário e renomeia: falha no meio não corrompe um arquivo anterior.
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $path)) {
            throw new \RuntimeException('não foi possível gravar ' . $path);
        }
    }

    private function resolvePath(string $path): string
    {
        $absolute = str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;

        return rtrim($absolute, '/');
    }
}
