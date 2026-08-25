<?php

namespace App\Command;

use App\Repository\ResearcherRepository;
use App\Service\Crawler\LattesPhotoCrawlerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:crawl:lattes-photos',
    description: 'Executa crawler para buscar fotos dos pesquisadores diretamente da plataforma Lattes/CNPq'
)]
class CrawlLattesPhotosCommand extends Command
{
    public function __construct(
        private readonly ResearcherRepository $researcherRepo,
        private readonly LattesPhotoCrawlerService $photoService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'ID Lattes ou Slug de um pesquisador específico')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limite de pesquisadores a consultar', 50)
            ->addOption('all', null, InputOption::VALUE_NONE, 'Buscar fotos para todos os pesquisadores sem foto');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $specificId = $input->getOption('id');
        $isAll = $input->getOption('all');
        $limit = $isAll ? null : (int)$input->getOption('limit');

        if ($specificId) {
            $researcher = $this->researcherRepo->findOneBy(['idLattes' => $specificId])
                ?: $this->researcherRepo->findOneBy(['slug' => $specificId]);

            if (!$researcher) {
                $io->error("Pesquisador não encontrado para o ID/slug: $specificId");
                return Command::FAILURE;
            }

            $io->info("Buscando foto para: {$researcher->getFullName()} (Lattes: {$researcher->getIdLattes()})...");
            $url = $this->photoService->crawlPhoto($researcher);

            if ($url) {
                $io->success("Foto obtida com sucesso: $url");
            } else {
                $io->warning("Não foi possível obter a foto pública no Lattes para este ID (pesquisador pode não ter foto cadastrada no CNPq).");
            }

            return Command::SUCCESS;
        }

        // Batch crawl for researchers without photos
        $researchers = $this->researcherRepo->findBy(['photoUrl' => null], ['id' => 'ASC'], $limit);
        $total = count($researchers);

        if ($total === 0) {
            $io->success('Todos os pesquisadores já possuem fotos cadastradas!');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Iniciando crawler de fotos para %d pesquisadores sem foto...', $total));
        $progressBar = $io->createProgressBar($total);
        $successCount = 0;

        foreach ($researchers as $r) {
            $url = $this->photoService->crawlPhoto($r);
            if ($url) {
                $successCount++;
            }
            $progressBar->advance();
            usleep(200000); // 200ms pause to prevent rate limiting
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf(
            'Processamento finalizado! %d fotos baixadas com sucesso de %d pesquisadores processados.',
            $successCount,
            $total
        ));

        return Command::SUCCESS;
    }
}
