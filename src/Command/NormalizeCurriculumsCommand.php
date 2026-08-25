<?php

namespace App\Command;

use App\Repository\ResearcherRepository;
use App\Service\Indexing\CurriculumNormalizationService;
use App\Service\Thesaurus\AuthorResolverService;
use App\Service\Thesaurus\InstitutionResolverService;
use App\Service\Thesaurus\JournalResolverService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:curriculum:normalize',
    description: 'Normalizes and indexes all curriculum data (co-authors, journals, institutions) into dedicated index columns'
)]
class NormalizeCurriculumsCommand extends Command
{
    public function __construct(
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
        private readonly ResearcherRepository $researcherRepo,
        private readonly CurriculumNormalizationService $normalizationService,
        private readonly AuthorResolverService $authorResolver,
        private readonly JournalResolverService $journalResolver,
        private readonly InstitutionResolverService $institutionResolver
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dept', 'd', InputOption::VALUE_OPTIONAL, 'Filter by department code or name')
            ->addOption('only-pending', null, InputOption::VALUE_NONE, 'Process only researchers without lastIndexedAt')
            ->addOption('clear-cache', null, InputOption::VALUE_NONE, 'Clear thesaurus resolver caches before running');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');

        $io = new SymfonyStyle($input, $output);
        $io->title('Normalização e Indexação de Currículos Lattes CECH');

        // Disable query logging to prevent memory leaks in batch CLI
        $this->em->getConnection()->getConfiguration()->setMiddlewares([]);

        if ($input->getOption('clear-cache')) {
            $io->text('Limpando caches dos tesauros...');
            $this->authorResolver->clearCache();
            $this->journalResolver->clearCache();
            $this->institutionResolver->clearCache();
            $io->success('Caches limpos com sucesso!');
        }

        $dept = $input->getOption('dept');
        $onlyPending = (bool)$input->getOption('only-pending');

        $qb = $this->researcherRepo->createQueryBuilder('r')
            ->select('r.id, r.fullName')
            ->orderBy('r.fullName', 'ASC');

        if ($dept && $dept !== 'all') {
            $qb->andWhere('r.department = :dept OR r.departmentCode = :dept')
               ->setParameter('dept', $dept);
        }

        if ($onlyPending) {
            $qb->andWhere('r.lastIndexedAt IS NULL');
        }

        $researcherRows = $qb->getQuery()->getArrayResult();
        $total = count($researcherRows);

        if ($total === 0) {
            $io->info('Nenhum pesquisador encontrado para os critérios informados.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Processando %d docente(s)...', $total));

        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | Decorrido: %elapsed:6s% | Restante: %estimated:-6s% | Memória: %memory:6s% -- %message%');
        $progressBar->start();

        $totalProds = 0;
        $totalAuthors = 0;
        $totalCechMatched = 0;
        $totalQualis = 0;
        $totalInst = 0;

        foreach ($researcherRows as $row) {
            $id = (int)$row['id'];
            $name = (string)$row['fullName'];
            $progressBar->setMessage(sprintf('<info>%s</info>', mb_substr($name, 0, 30)));

            $researcher = $this->researcherRepo->findWithAllDetails($id);
            if ($researcher) {
                $stats = $this->normalizationService->normalizeResearcher($researcher);

                $totalProds += $stats['productionsProcessed'];
                $totalAuthors += $stats['authorsIndexed'];
                $totalCechMatched += $stats['authorsCechMatched'];
                $totalQualis += $stats['qualisResolved'];
                $totalInst += $stats['institutionsResolved'];
                unset($researcher);
            }

            $this->em->clear();
            gc_collect_cycles();
            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf(
            "Indexação concluída com sucesso!\n- Docentes processados: %d\n- Produções percorridas: %d\n- Autores indexados: %d (Coautores CECH vinculados: %d)\n- Qualis resolvidos: %d\n- Instituições vinculadas: %d",
            $total,
            $totalProds,
            $totalAuthors,
            $totalCechMatched,
            $totalQualis,
            $totalInst
        ));

        return Command::SUCCESS;
    }
}
