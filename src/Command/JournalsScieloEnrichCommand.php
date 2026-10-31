<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Journal\ScieloIssnEnricher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:journals:scielo:enrich',
    description: 'Preenche ISSNs dos periódicos vinculados à base scielo que não têm ISSN válido, casando o título com o cadastro do SciELO ArticleMeta'
)]
class JournalsScieloEnrichCommand extends Command
{
    private const DEFAULT_FILE = 'docs/banco/derived/scielo_articlemeta.json';

    public function __construct(
        private readonly ScieloIssnEnricher $enricher,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'JSON do SciELO ArticleMeta (relativo à raiz do projeto ou absoluto)', self::DEFAULT_FILE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula o enriquecimento sem gravar no banco');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Enriquecimento de ISSN pelo cadastro do SciELO');

        $dryRun = (bool) $input->getOption('dry-run');
        $file = $this->resolvePath((string) $input->getArgument('file'));

        try {
            $result = $this->enricher->enrich($file, $dryRun);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->table(['Indicador', 'Valor'], [
            ['Casados (título igual ao de exatamente um registro do SciELO)', $result['matched']],
            [$dryRun ? 'Seriam atualizados' : 'Atualizados', $result['updated']],
            ['Não casados', count($result['unmatched'])],
            ['Ambíguos (título igual ao de mais de um registro do SciELO)', count($result['ambiguous'])],
        ]);

        if ($result['unmatched'] !== []) {
            $io->section('Não casados');
            $io->table(['ID', 'Título'], array_map(
                static fn (array $row): array => [$row['id'], $row['title']],
                $result['unmatched']
            ));
        }

        if ($result['ambiguous'] !== []) {
            $io->section('Ambíguos');
            $io->table(['ID', 'Título', 'Códigos SciELO'], array_map(
                static fn (array $row): array => [$row['id'], $row['title'], implode(', ', $row['codes'])],
                $result['ambiguous']
            ));
        }

        if ($dryRun) {
            $io->warning('Modo simulação (--dry-run): nenhuma alteração foi gravada no banco.');
        } else {
            $io->success(sprintf('%d periódico(s) atualizado(s).', $result['updated']));
        }

        return Command::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;
    }
}
