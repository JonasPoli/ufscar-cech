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
    description: 'Completa o Tesauro de Autores com os pesquisadores e coautores do CECH (não apaga nada; --clean apaga e refaz)'
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
            ->addOption('clean', 'c', InputOption::VALUE_NONE, 'Apaga todo o tesauro de autores, inclusive fusões e correções manuais, antes de sincronizar (pede confirmação; com --no-interaction a resposta é "não")')
            ->addOption('researchers-only', 'r', InputOption::VALUE_NONE, 'Sincroniza apenas os pesquisadores cadastrados em researchers (Passo 02)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sincronização e Manutenção do Tesauro de Autores — CECH');

        $clean = $input->getOption('clean');
        $researchersOnly = $input->getOption('researchers-only');

        if ($clean) {
            $io->warning([
                'A opção --clean apaga author_identities, author_name_variants e author_external_identifiers.',
                'As fusões e correções feitas à mão no tesauro se perdem, e production_authors.author_identity_id volta a NULL.',
            ]);
            // Com --no-interaction o confirm() devolve o padrão, que aqui é "não": nada é apagado sem alguém responder.
            if (!$io->confirm('Apagar todo o tesauro de autores e refazê-lo do zero?', false)) {
                $io->note('Nada foi apagado nem sincronizado. Sem --clean, o comando só acrescenta o que falta.');

                return Command::FAILURE;
            }

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
            $stats = $this->authorThesaurusService->syncAuthorThesaurus();
            $io->success(sprintf(
                "Tesauro de autores %s com sucesso!\n- Pesquisadores CECH processados: %d\n- Autores/Coautores processados: %d\n- Identidades de Autor criadas: %d\n- Variações de Nome registradas: %d",
                $clean ? 'reconstruído' : 'completado',
                $stats['researchersProcessed'],
                $stats['coauthorsProcessed'],
                $stats['identitiesCreated'],
                $stats['variantsCreated']
            ));
        }

        if ($clean) {
            // A limpeza zerou as ligações das produções com as identidades, e o tesauro não as refaz: quem liga é a normalização.
            $io->warning([
                'As produções ainda não apontam para as identidades novas (production_authors.author_identity_id está vazio).',
                'Rode agora: php bin/console app:curriculum:normalize',
            ]);
        } elseif (($stats['identitiesCreated'] ?? 0) > 0) {
            $io->note('As produções passam a apontar para as identidades novas na próxima normalização: php bin/console app:curriculum:normalize');
        }

        return Command::SUCCESS;
    }
}
