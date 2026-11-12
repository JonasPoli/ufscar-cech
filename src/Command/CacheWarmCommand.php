<?php

namespace App\Command;

use App\Repository\ResearcherRepository;
use App\Service\JournalsByDatabaseService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:cache:warm',
    description: 'Aquece o cache das páginas públicas principais, uma de cada vez, com pausa entre elas'
)]
class CacheWarmCommand extends Command
{
    use LockableTrait;

    /** Abas de /indicadores carregadas sob demanda (MainController::indicatorFragment). */
    private const INDICATOR_TABS = ['faculty', 'training', 'production', 'network'];

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly HttpClientInterface $httpClient,
        private readonly ResearcherRepository $researcherRepo,
        #[Autowire('%env(default::DEFAULT_URI)%')]
        private readonly ?string $defaultUri = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'URL base do site (ex.: https://site.exemplo.br); padrão: DEFAULT_URI, se existir')
            ->addOption('em-processo', null, InputOption::VALUE_NONE, 'Sem URL: chama o kernel da própria aplicação, sem rede')
            ->addOption('pausa', null, InputOption::VALUE_REQUIRED, 'Segundos de pausa entre uma página e a próxima', '1')
            ->addOption('apenas', null, InputOption::VALUE_REQUIRED, 'Só as páginas cujo caminho contém este texto (ex.: /periodicos)')
            ->setHelp(<<<'HELP'
Percorre as páginas principais (início, indicadores e suas abas, periódicos e bases, departamentos, coautorias, explorar,
temas, projetos, sobre e novidades) uma por vez, para que a primeira visita real já encontre o cache pronto. Mostra o tempo
e o status de cada uma e não para quando uma falha.

Sem --url (nem DEFAULT_URI), é preciso informar --em-processo.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('O comando app:cache:warm já está em execução em outro processo. Esta execução foi cancelada.');

            return Command::FAILURE;
        }

        try {
            return $this->warm($input, $io);
        } finally {
            $this->release();
        }
    }

    private function warm(InputInterface $input, SymfonyStyle $io): int
    {
        $base = (string)($input->getOption('url') ?: $this->defaultUri);
        $inProcess = (bool)$input->getOption('em-processo');
        if ($base === '' && !$inProcess) {
            $io->error('Informe a URL base com --url=https://... (ou defina DEFAULT_URI), ou use --em-processo.');

            return Command::FAILURE;
        }
        $base = rtrim($base, '/');

        $pause = max(0.0, (float)str_replace(',', '.', (string)$input->getOption('pausa')));
        $only = (string)$input->getOption('apenas');

        $paths = $this->paths();
        if ($only !== '') {
            $paths = array_values(array_filter($paths, static fn(string $p) => str_contains($p, $only)));
        }
        if ($paths === []) {
            $io->warning('Nenhuma página corresponde ao filtro informado.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Aquecendo %d páginas (%s)', count($paths), $inProcess && $base === '' ? 'em processo' : $base));

        $failures = 0;
        $started = microtime(true);
        foreach ($paths as $i => $path) {
            $t = microtime(true);
            try {
                $status = $base === '' ? $this->fetchInProcess($path) : $this->fetchHttp($base . $path);
            } catch (\Throwable $e) {
                $status = 0;
                $io->writeln(sprintf('  <error>erro</error>  %s: %s', $path, $e->getMessage()));
            }
            $ok = $status >= 200 && $status < 400;
            if (!$ok) {
                ++$failures;
            }
            $io->writeln(sprintf(
                '  <%1$s>%2$s</%1$s>  %3$6.2fs  %4$s',
                $ok ? 'info' : 'error',
                $status ?: '---',
                microtime(true) - $t,
                $path
            ));

            if ($pause > 0 && $i < count($paths) - 1) {
                usleep((int)($pause * 1_000_000));
            }
        }

        $summary = sprintf('%d páginas em %.1fs; %d com falha.', count($paths), microtime(true) - $started, $failures);
        $failures === 0 ? $io->success($summary) : $io->warning($summary);

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return list<string> */
    private function paths(): array
    {
        $paths = ['/', '/indicadores'];
        foreach (self::INDICATOR_TABS as $tab) {
            $paths[] = '/indicadores/fragment/' . $tab;
        }
        $paths[] = '/periodicos';
        $paths[] = '/periodicos/geral';
        foreach (JournalsByDatabaseService::BASES as $base) {
            $paths[] = '/periodicos/' . $base;
        }
        $paths[] = '/departamentos';
        foreach ($this->researcherRepo->findTopDepartments(100) as $dept) {
            $code = $dept['departmentCode'] ?: $dept['department'];
            if ($code) {
                $paths[] = '/departamento/' . rawurlencode((string)$code);
            }
        }
        array_push(
            $paths,
            '/coautorias',
            '/explorar',
            '/explorar/areas',
            '/explorar/grandes-areas',
            '/temas',
            '/projetos',
            '/sobre',
            '/sobre-os-dados',
            '/novidades'
        );

        return array_values(array_unique($paths));
    }

    private function fetchHttp(string $url): int
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 120, 'max_duration' => 300]);
        $status = $response->getStatusCode();
        $response->getContent(false); // lê o corpo inteiro: a página só é gravada no cache quando a resposta termina

        return $status;
    }

    private function fetchInProcess(string $path): int
    {
        $request = Request::create($path, 'GET', [], [], [], ['HTTP_HOST' => 'localhost']);
        $response = $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
        $status = $response->getStatusCode();
        if ($this->kernel instanceof \Symfony\Component\HttpKernel\TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }

        return $status;
    }
}
