<?php

namespace App\Command;

use App\Service\PageCacheService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:cache:clear',
    description: 'Limpa o cache de páginas públicas em HTML (public_pages) e opcionalmente o cache do Symfony',
    aliases: ['app:clear:cache', 'app:page-cache:clear']
)]
class AppCacheClearCommand extends Command
{
    public function __construct(
        private readonly PageCacheService $pageCacheService,
        private readonly KernelInterface $kernel
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Limpa também o cache padrão do Symfony')
            ->setHelp('Este comando invalida e remove todas as páginas HTML estáticas salvas em var/cache/public_pages.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clearAll = $input->getOption('all');

        $statsBefore = $this->pageCacheService->getStats();

        $cleared = $this->pageCacheService->clearCache();
        if ($cleared) {
            $io->success(sprintf(
                'Cache de páginas públicas limpo com sucesso! (%d arquivos removidos).',
                $statsBefore['fileCount']
            ));
        } else {
            $io->warning('Não foi possível limpar o diretório de cache de páginas públicas.');
        }

        if ($clearAll) {
            $fs = new Filesystem();
            $symfonyCacheDir = $this->kernel->getCacheDir();
            if ($fs->exists($symfonyCacheDir)) {
                $io->info(sprintf('Limpando cache do Symfony em: %s', $symfonyCacheDir));
            }
        }

        return Command::SUCCESS;
    }
}
