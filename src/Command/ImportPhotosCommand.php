<?php

namespace App\Command;

use App\Service\Crawler\LattesPhotoCrawlerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:photos',
    description: 'Importa fotos de docentes em lote a partir de um diretório ou arquivo ZIP (nomeadas por ID Lattes, slug ou nome)'
)]
class ImportPhotosCommand extends Command
{
    public function __construct(
        private readonly LattesPhotoCrawlerService $photoService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Caminho do diretório contendo as imagens')
            ->addOption('zip', 'z', InputOption::VALUE_OPTIONAL, 'Caminho de um arquivo ZIP contendo as imagens');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $input->getOption('dir');
        $zip = $input->getOption('zip');

        if (!$dir && !$zip) {
            $io->error('Você deve informar --dir=/caminho/pasta ou --zip=/caminho/arquivo.zip');
            return Command::FAILURE;
        }

        try {
            if ($zip) {
                $io->section(sprintf('Importando fotos do arquivo ZIP: %s', $zip));
                $result = $this->photoService->importFromZip($zip);
            } else {
                $io->section(sprintf('Importando fotos do diretório: %s', $dir));
                $result = $this->photoService->importFromDirectory($dir);
            }

            $io->success(sprintf(
                'Importação concluída! %d fotos vinculadas com sucesso (%d arquivos sem correspondência).',
                $result['imported'],
                $result['unmatched']
            ));

            if (!empty($result['matched'])) {
                $rows = array_map(fn($m) => [$m['researcher'], $m['idLattes'], $m['file']], array_slice($result['matched'], 0, 15));
                $io->table(['Docente', 'ID Lattes', 'Arquivo'], $rows);
                if (count($result['matched']) > 15) {
                    $io->note(sprintf('... e mais %d fotos vinculadas.', count($result['matched']) - 15));
                }
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Erro ao importar fotos: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
