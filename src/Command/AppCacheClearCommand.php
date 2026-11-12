<?php

namespace App\Command;

use App\Service\PageCacheService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:cache:clear',
    description: 'Limpa o cache de páginas públicas (public_pages) e o da pesquisa; com --all, também o cache de dados da aplicação (cache.app)',
    aliases: ['app:clear:cache', 'app:page-cache:clear']
)]
class AppCacheClearCommand extends Command
{
    public function __construct(
        private readonly PageCacheService $pageCacheService,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $appCache
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Limpa também o cache de dados da aplicação (cache.app): índices dos tesauros, temas e catálogos')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Não limpa tudo: só descarta as páginas vencidas e, acima do teto de espaço (PAGE_CACHE_MAX_MB), as mais antigas')
            ->setHelp(<<<'HELP'
Remove as páginas HTML salvas em var/cache/public_pages e o cache da pesquisa (panorama, rede de coautoria).

Com --all, esvazia também o pool cache.app, onde ficam os índices dos tesauros (autores, instituições, periódicos), os
dados da Pesquisa Temática e os catálogos. O cache compilado do Symfony (container, rotas) não é tocado: para ele, use
`php bin/console cache:clear`.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clearAll = $input->getOption('all');

        if ($input->getOption('prune')) {
            $result = $this->pageCacheService->prune();
            $io->success(sprintf(
                'Descarte concluído: %d páginas removidas (vencidas ou acima do teto); restam %d páginas, %.1f MB.',
                $result['removed'],
                $result['remainingFiles'],
                $result['remainingBytes'] / 1048576
            ));

            return Command::SUCCESS;
        }

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
            if ($this->appCache->clear()) {
                $io->success('Cache de dados da aplicação (cache.app) limpo: índices dos tesauros, temas e catálogos serão refeitos.');
            } else {
                $io->warning('Não foi possível limpar o cache de dados da aplicação (cache.app).');
            }
            $io->note('O cache compilado do Symfony (container e rotas) é limpo com `php bin/console cache:clear`.');
        }

        return Command::SUCCESS;
    }
}
