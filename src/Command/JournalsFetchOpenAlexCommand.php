<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:journals:evidence:fetch-openalex',
    description: 'Baixa o snapshot de periódicos (sources) do OpenAlex, arquivo a arquivo, a partir do manifesto (CC0, sem chave)'
)]
class JournalsFetchOpenAlexCommand extends Command
{
    private const DEFAULT_DIR = 'docs/banco/lists-to-import/openalex/snapshot';
    private const DEFAULT_MANIFEST = 'https://openalex.s3.amazonaws.com/data/jsonl/sources/manifest.json';
    private const S3_PREFIX = 's3://openalex/';
    private const HTTP_PREFIX = 'https://openalex.s3.amazonaws.com/';
    private const TIMEOUT = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Pasta de destino (relativa à raiz do projeto ou absoluta)', self::DEFAULT_DIR)
            ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'URL ou caminho local do manifesto', self::DEFAULT_MANIFEST)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Baixar apenas os N primeiros arquivos do manifesto (teste)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Snapshot OpenAlex: periódicos (sources)');

        $limit = null;
        if ($input->getOption('limit') !== null) {
            $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($limit === false) {
                $io->error('--limit deve ser um inteiro maior que zero.');
                return Command::FAILURE;
            }
        }

        $dir = $this->resolvePath((string) $input->getOption('dir'));

        try {
            [$rawManifest, $manifest] = $this->readManifest((string) $input->getOption('manifest'));
        } catch (\Throwable $e) {
            $io->error('Não foi possível ler o manifesto: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $entries = $manifest['entries'] ?? $manifest['files'] ?? null;
        if (!is_array($entries)) {
            $io->error('Manifesto sem lista de arquivos (esperado "files" ou "entries").');
            return Command::FAILURE;
        }

        $io->text(sprintf(
            'Release <info>%s</info>: %d arquivos, %s registros, %s no total.',
            $manifest['date'] ?? '?',
            count($entries),
            number_format((int) ($manifest['record_count'] ?? 0), 0, ',', '.'),
            Helper::formatMemory((int) ($manifest['content_length'] ?? 0))
        ));

        $this->ensureDirectory($dir);
        $this->writeFile($dir . '/manifest.json', $rawManifest);

        if ($limit !== null) {
            $entries = array_slice($entries, 0, $limit);
        }

        $tasks = [];
        $errors = [];
        foreach ($entries as $entry) {
            try {
                $tasks[] = $this->describeEntry($entry);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        // Pula arquivos já completos: mesmo tamanho que o manifesto informa.
        $pending = [];
        $skipped = 0;
        foreach ($tasks as $task) {
            $path = $dir . '/' . $task['relative'];
            if (is_file($path) && filesize($path) === $task['size']) {
                $skipped++;
                if ($io->isVerbose()) {
                    $io->writeln('  já existe: ' . $task['relative']);
                }
                continue;
            }
            $pending[] = $task + ['path' => $path];
        }

        $downloaded = 0;
        $downloadedBytes = 0;
        if ($pending !== []) {
            $progress = $io->createProgressBar(array_sum(array_column($pending, 'size')));
            $progress->setFormat(' %percent:3s%% [%bar%] %message%');
            $progress->setMessage('iniciando...');
            $progress->start();

            foreach ($pending as $i => $task) {
                $progress->setMessage(sprintf(
                    '[%d/%d] %s (%s)',
                    $i + 1,
                    count($pending),
                    $task['relative'],
                    Helper::formatMemory($task['size'])
                ));
                try {
                    $this->download($task['url'], $task['path'], $task['size'], $progress);
                    $downloaded++;
                    $downloadedBytes += $task['size'];
                } catch (\Throwable $e) {
                    $errors[] = sprintf('%s: %s', $task['relative'], $e->getMessage());
                }
            }

            $progress->finish();
            $io->newLine(2);
        }

        $io->table(['Métrica', 'Valor'], [
            ['Arquivos processados', count($tasks)],
            ['Registros nesses arquivos', number_format(array_sum(array_column($tasks, 'records')), 0, ',', '.')],
            ['Já existiam completos (pulados)', $skipped],
            ['Baixados agora', $downloaded],
            ['Bytes baixados agora', Helper::formatMemory($downloadedBytes)],
            ['Erros', count($errors)],
            ['Pasta', $dir],
        ]);

        if ($errors !== []) {
            $io->error(sprintf('%d erro(s). Rode o comando de novo: os arquivos completos são pulados.', count($errors)));
            $io->listing($errors);
            return Command::FAILURE;
        }

        $io->success('Snapshot OpenAlex pronto em ' . $dir);
        return Command::SUCCESS;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>} conteúdo bruto do manifesto e seu JSON decodificado
     */
    private function readManifest(string $source): array
    {
        if (preg_match('#^https?://#i', $source) === 1) {
            $raw = $this->httpClient->request('GET', $source, ['timeout' => self::TIMEOUT])->getContent();
        } else {
            $path = $this->resolvePath($source);
            if (!is_file($path)) {
                throw new \RuntimeException('arquivo não encontrado: ' . $path);
            }
            $raw = (string) file_get_contents($path);
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('o manifesto não é um objeto JSON');
        }

        return [$raw, $data];
    }

    /**
     * Converte uma entrada do manifesto em URL HTTPS e caminho relativo (<updated_date=...>/<part>.gz).
     *
     * @return array{url: string, relative: string, size: int, records: int}
     */
    private function describeEntry(mixed $entry): array
    {
        $url = is_array($entry) && is_string($entry['url'] ?? null) ? $entry['url'] : null;
        $size = is_array($entry) ? ($entry['meta']['content_length'] ?? null) : null;
        $records = is_array($entry) ? ($entry['meta']['record_count'] ?? 0) : 0;

        if ($url === null || !is_int($size) || $size < 0) {
            throw new \InvalidArgumentException('entrada inválida no manifesto: ' . (string) json_encode($entry));
        }

        if (str_starts_with($url, self::S3_PREFIX)) {
            $url = self::HTTP_PREFIX . substr($url, strlen(self::S3_PREFIX));
        } elseif (!str_starts_with($url, self::HTTP_PREFIX)) {
            throw new \InvalidArgumentException('URL fora do bucket do OpenAlex: ' . $url);
        }

        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH)), 'strlen'));
        $segments = array_slice($segments, -2);
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || preg_match('/^[\w.=-]+$/', $segment) !== 1) {
                throw new \InvalidArgumentException('nome de arquivo inválido no manifesto: ' . $url);
            }
        }
        if ($segments === []) {
            throw new \InvalidArgumentException('URL sem nome de arquivo: ' . $url);
        }

        return [
            'url' => $url,
            'relative' => implode('/', $segments),
            'size' => $size,
            'records' => (int) $records,
        ];
    }

    private function download(string $url, string $path, int $size, ProgressBar $progress): void
    {
        $this->ensureDirectory(dirname($path));

        // Grava em .part e só renomeia ao final: arquivo interrompido nunca parece completo.
        $partial = $path . '.part';
        $handle = fopen($partial, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('não foi possível gravar ' . $partial);
        }

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => self::TIMEOUT]);
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('HTTP ' . $response->getStatusCode());
            }

            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new \RuntimeException('tempo esgotado sem receber dados');
                }
                if (($error = $chunk->getError()) !== null) {
                    throw new \RuntimeException($error);
                }
                $content = $chunk->getContent();
                if ($content === '') {
                    continue;
                }
                if (fwrite($handle, $content) !== strlen($content)) {
                    throw new \RuntimeException('falha ao gravar em disco');
                }
                $progress->advance(strlen($content));
            }
            fclose($handle);

            $written = (int) filesize($partial);
            if ($written !== $size) {
                throw new \RuntimeException(sprintf('tamanho recebido %d bytes, esperado %d', $written, $size));
            }
            if (!rename($partial, $path)) {
                throw new \RuntimeException('não foi possível renomear para ' . $path);
            }
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($partial);
            throw $e;
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('não foi possível criar a pasta ' . $dir);
        }
    }

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('não foi possível gravar ' . $path);
        }
    }

    private function resolvePath(string $path): string
    {
        $absolute = str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;

        return rtrim($absolute, '/');
    }
}
