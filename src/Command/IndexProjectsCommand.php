<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Project\ProjectIndexService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recalcula as colunas do catálogo de projetos: a chave que une o mesmo projeto declarado em vários currículos e o
 * vínculo de cada integrante com o docente do CECH de mesmo ID Lattes. A importação já faz isso para o currículo
 * importado; este comando serve para os dados antigos, para depois de incluir ou excluir docentes e para conferir.
 */
#[AsCommand(
    name: 'app:index:projects',
    description: 'Agrupa os projetos declarados em vários currículos e liga os integrantes aos docentes do CECH'
)]
class IndexProjectsCommand extends Command
{
    use LockableTrait;

    public function __construct(private readonly ProjectIndexService $indexService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('researcher', null, InputOption::VALUE_REQUIRED, 'Só os projetos deste docente (ID Lattes) e os integrantes com o ID dele');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Uma execução por vez: o comando grava em lote, e duas execuções simultâneas se atropelariam. A trava é
        // por nome de comando, então quem chama outros comandos em sequência não trava a si mesmo.
        if (!$this->lock()) {
            (new SymfonyStyle($input, $output))->warning(sprintf(
                'O comando %s já está em execução em outro processo. Esta execução foi cancelada: aguarde a outra terminar e rode de novo.',
                $this->getName()
            ));

            return Command::FAILURE;
        }

        try {
            return $this->indexProjects($input, $output);
        } finally {
            $this->release();
        }
    }

    private function indexProjects(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Indexação dos projetos');

        $idLattes = $input->getOption('researcher');
        try {
            $result = $this->indexService->index(is_string($idLattes) && $idLattes !== '' ? $idLattes : null);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $stats = $this->indexService->stats();
        $n = static fn (int $v): string => number_format($v, 0, ',', '.');
        $io->definitionList(
            ['Chaves de agrupamento alteradas' => $n($result['group_keys_updated'])],
            ['Integrantes religados' => $n($result['members_relinked'])],
            new TableSeparator(),
            ['Registros de projeto' => $n($stats['records'])],
            ['Projetos (grupos)' => $n($stats['groups'])],
            ['Projetos declarados por mais de um docente' => $n($stats['shared_groups'])],
            ['Integrantes' => $n($stats['members'])],
            ['Integrantes com ID Lattes' => $n($stats['members_with_lattes'])],
            ['Integrantes ligados a um docente do CECH' => $n($stats['members_matched'])],
            ['Financiadores' => $n($stats['funders'])],
        );

        if ($stats['records_without_key'] > 0) {
            $io->note(sprintf('%s registros sem nome não entram em nenhum grupo.', $n($stats['records_without_key'])));
        }
        $io->success('Projetos indexados. Limpe o cache das páginas (app:cache:clear) para o catálogo mostrar o resultado.');

        return Command::SUCCESS;
    }
}
