<?php

namespace App\Command;

use App\Service\Thesaurus\AuthorThesaurusService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:thesaurus:sync-authors',
    description: 'Limpa e sincroniza o Tesauro de Autores com os pesquisadores e coautores do CECH'
)]
class SyncAuthorThesaurusCommand extends Command
{
    public function __construct(
        private readonly AuthorThesaurusService $authorThesaurusService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('clean', 'c', InputOption::VALUE_NONE, 'Apaga todo o conteúdo das tabelas do tesauro de autores antes de sincronizar (Passo 01)')
            ->addOption('researchers-only', 'r', InputOption::VALUE_NONE, 'Sincroniza apenas os pesquisadores cadastrados em researchers (Passo 02)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sincronização e Manutenção do Tesauro de Autores — CECH');

        $clean = $input->getOption('clean');
        $researchersOnly = $input->getOption('researchers-only');

        if ($clean) {
            $io->section('Passo 01: Limpando tabelas do tesauro de autores...');
            $this->authorThesaurusService->truncateAuthorThesaurus();
            $io->success('Tabelas author_identities, author_name_variants e author_external_identifiers esvaziadas com sucesso.');
        }

        if ($researchersOnly) {
            $io->section('Passo 02: Sincronizando nomes e variações de Researchers...');
            $stats = $this->authorThesaurusService->syncAllResearchers();
            $io->success(sprintf(
                "Sincronização de pesquisadores finalizada com sucesso!\n- Pesquisadores analisados: %d\n- Identidades criadas: %d\n- Variações adicionadas: %d\n- Variações verificadas: %d",
                $stats['researchersProcessed'],
                $stats['identitiesCreated'],
                $stats['variantsAdded'],
                $stats['variantsChecked']
            ));
        } else {
            $io->section('Sincronizando Tesauro completo (Pesquisadores + Coautores)...');
            $stats = $this->authorThesaurusService->rebuildAuthorThesaurus();
            $io->success(sprintf(
                "Tesauro de autores reconstruído com sucesso!\n- Pesquisadores CECH processados: %d\n- Autores/Coautores processados: %d\n- Identidades de Autor criadas: %d\n- Variações de Nome registradas: %d",
                $stats['researchersProcessed'],
                $stats['coauthorsProcessed'],
                $stats['identitiesCreated'],
                $stats['variantsCreated']
            ));
        }

        return Command::SUCCESS;
    }
}

