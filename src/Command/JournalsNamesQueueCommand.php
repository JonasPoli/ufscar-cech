<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\JournalNameCandidate;
use App\Service\Journal\JournalNameQueueService;
use App\Service\Thesaurus\JournalResolverService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fila de revisão de nomes de periódicos (journal_name_candidates, docs/evolutions/periodicos-por-base.md, A5.4):
 * reconstrói a partir dos artigos sem vínculo e/ou mostra as contagens. Sem opções, mostra as contagens.
 */
#[AsCommand(
    name: 'app:journals:names:queue',
    description: 'Fila de revisão de nomes de periódicos: reconstrói a partir dos artigos sem vínculo (--rebuild) e mostra as contagens (--stats)'
)]
class JournalsNamesQueueCommand extends Command
{
    private const STATUS_LABELS = [
        JournalNameCandidate::STATUS_PENDING => 'Pendentes',
        JournalNameCandidate::STATUS_AMBIGUOUS => 'Ambíguos',
        JournalNameCandidate::STATUS_ACCEPTED => 'Aceitos',
        JournalNameCandidate::STATUS_REJECTED => 'Rejeitados',
    ];

    private const METHOD_LABELS = [
        JournalNameCandidate::SUGGESTION_ISSN => 'ISSN do artigo (aprendizado de grafias)',
        JournalNameCandidate::SUGGESTION_KEYWORDS => 'palavras-chave',
        JournalNameCandidate::SUGGESTION_NONE => 'sem sugestão',
    ];

    public function __construct(
        private readonly JournalNameQueueService $queue,
        private readonly JournalResolverService $resolver,
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('rebuild', null, InputOption::VALUE_NONE, 'Recalcula a fila a partir dos artigos sem vínculo (preserva nomes aceitos/rejeitados e sugestões por ISSN)')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Mostra as contagens por status (é o padrão quando nenhuma opção é informada)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');

        $io = new SymfonyStyle($input, $output);
        $io->title('Fila de revisão de nomes de periódicos');

        $rebuild = (bool)$input->getOption('rebuild');
        $stats = (bool)$input->getOption('stats') || !$rebuild;

        if ($rebuild) {
            $missing = $this->articlesWithoutNormalizedName();
            if ($missing > 0) {
                $io->warning(sprintf(
                    '%s artigo(s) sem journal_name_normalized: a fila só considera artigos com a coluna preenchida. Rode app:journals:normalize-titles antes.',
                    number_format($missing, 0, ',', '.')
                ));
            }

            $startedAt = microtime(true);
            try {
                // Modo lote: carrega o cadastro uma vez em vez de uma consulta por nome (o resultado é o mesmo)
                $this->resolver->loadFullMaps();
                $result = $this->queue->rebuild();
            } catch (\Throwable $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            $io->success(sprintf(
                'Fila reconstruída em %s s: %s pendente(s), %s ambíguo(s); %s linha(s) sem artigos removida(s).',
                number_format(microtime(true) - $startedAt, 1, ',', '.'),
                number_format($result['pending'], 0, ',', '.'),
                number_format($result['ambiguous'], 0, ',', '.'),
                number_format($result['removed'], 0, ',', '.')
            ));
        }

        if ($stats) {
            $this->printStats($io);
        }

        return Command::SUCCESS;
    }

    private function printStats(SymfonyStyle $io): void
    {
        $conn = $this->em->getConnection();

        $byStatus = [];
        foreach ($conn->fetchAllAssociative('SELECT status, COUNT(*) AS names, COALESCE(SUM(articles_count), 0) AS articles FROM journal_name_candidates GROUP BY status') as $row) {
            $byStatus[(string)$row['status']] = ['names' => (int)$row['names'], 'articles' => (int)$row['articles']];
        }

        $rows = [];
        $totalNames = 0;
        $totalArticles = 0;
        foreach (self::STATUS_LABELS as $status => $label) {
            $names = $byStatus[$status]['names'] ?? 0;
            $articles = $byStatus[$status]['articles'] ?? 0;
            $rows[] = [$label, number_format($names, 0, ',', '.'), number_format($articles, 0, ',', '.')];
            $totalNames += $names;
            $totalArticles += $articles;
        }
        foreach ($byStatus as $status => $counts) {
            if (!isset(self::STATUS_LABELS[$status])) {
                $rows[] = [$status, number_format($counts['names'], 0, ',', '.'), number_format($counts['articles'], 0, ',', '.')];
                $totalNames += $counts['names'];
                $totalArticles += $counts['articles'];
            }
        }
        $rows[] = ['Total', number_format($totalNames, 0, ',', '.'), number_format($totalArticles, 0, ',', '.')];

        $io->section('Contagens por status');
        $io->table(['Status', 'Nomes', 'Artigos'], $rows);

        $byMethod = [];
        foreach ($conn->fetchAllAssociative(
            "SELECT COALESCE(suggestion_method, 'none') AS method, COUNT(*) AS names
               FROM journal_name_candidates
              WHERE status IN ('pending', 'ambiguous')
              GROUP BY method"
        ) as $row) {
            $byMethod[(string)$row['method']] = (int)$row['names'];
        }
        $methodRows = [];
        foreach (self::METHOD_LABELS as $method => $label) {
            $methodRows[] = [$label, number_format($byMethod[$method] ?? 0, 0, ',', '.')];
        }

        $io->section('Em aberto (pendentes e ambíguos) por tipo de sugestão');
        $io->table(['Sugestão', 'Nomes'], $methodRows);

        $multi = (int)$conn->fetchOne(
            "SELECT COUNT(*) FROM journal_name_candidates WHERE status = 'pending' AND articles_count >= 2"
        );
        $io->writeln(sprintf('Pendentes com 2 ou mais artigos: <info>%s</info>', number_format($multi, 0, ',', '.')));
        $io->newLine();
    }

    /**
     * Artigos com nome de periódico (com letras ou números) e sem a coluna derivada preenchida.
     */
    private function articlesWithoutNormalizedName(): int
    {
        return (int)$this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM production_items
              WHERE item_type = 'ARTIGO'
                AND journal_name REGEXP '[[:alnum:]]'
                AND (journal_name_normalized IS NULL OR journal_name_normalized = '')"
        );
    }
}
