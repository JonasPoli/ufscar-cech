<?php

namespace App\Command;

use App\Service\Import\LattesXmlParserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:import:lattes',
    description: 'Importa currículos Lattes em formato XML para o banco de dados do CECH (Upsert)'
)]
class ImportLattesCommand extends Command
{
    public function __construct(
        private readonly LattesXmlParserService $parserService,
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_OPTIONAL, 'Diretório com os arquivos XML', 'docs/banco/CECH')
            ->addOption('file', null, InputOption::VALUE_OPTIONAL, 'Arquivo XML específico para importar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Importador de Currículos Lattes XML — CECH');

        // Disable SQL logging to avoid memory exhaustion during bulk imports
        $this->em->getConnection()->getConfiguration()->setMiddlewares([]);

        $singleFile = $input->getOption('file');
        $dir = $input->getOption('dir');

        $files = [];
        if ($singleFile) {
            if (!file_exists($singleFile)) {
                $io->error("Arquivo especificado não existe: {$singleFile}");
                return Command::FAILURE;
            }
            $files = [new \SplFileInfo($singleFile)];
        } else {
            if (!is_dir($dir)) {
                $io->error("Diretório especificado não existe: {$dir}");
                return Command::FAILURE;
            }
            $finder = new Finder();
            $finder->files()->in($dir)->name('*.xml')->sortByName();
            $files = iterator_to_array($finder, false);
        }

        $totalFiles = count($files);
        if ($totalFiles === 0) {
            $io->warning("Nenhum arquivo XML encontrado para importar.");
            return Command::SUCCESS;
        }

        $io->text(sprintf('Iniciando processamento de <info>%d</info> arquivos XML...', $totalFiles));
        $progressBar = new ProgressBar($output, $totalFiles);
        $progressBar->start();

        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($files as $index => $file) {
            try {
                $this->parserService->parseAndSave($file->getRealPath());
                $this->em->clear();
                $successCount++;
            } catch (\Throwable $e) {
                $this->em->clear();
                $errorCount++;
                $errors[] = sprintf("%s: %s", $file->getFilename(), $e->getMessage());
            }
            $progressBar->advance();

            if ($index % 10 === 0) {
                gc_collect_cycles();
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf('Importação finalizada! %d currículos importados com sucesso.', $successCount));

        if ($errorCount > 0) {
            $io->warning(sprintf('%d arquivos apresentaram erros durante a importação:', $errorCount));
            $io->listing(array_slice($errors, 0, 10));
        }

        return Command::SUCCESS;
    }
}
