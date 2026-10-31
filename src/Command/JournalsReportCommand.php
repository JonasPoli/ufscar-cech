<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ProductionItem;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Relatório dos periódicos (docs/evolutions/periodicos-por-base.md, A8 e A9). Só lê: não grava nada.
 *
 * "Família visível" é a que tem artigo ligado (a mesma definição do classificador); "classificada" é a que tem área
 * principal em `journal_areas`.
 */
#[AsCommand(
    name: 'app:journals:report',
    description: 'Relatório só de leitura dos periódicos: famílias, áreas, bases, variantes e fila de nomes.'
)]
class JournalsReportCommand extends Command
{
    /** Slug da área CAPES interdisciplinar (a que o classificador usa para o espalhamento). */
    private const INTERDISCIPLINARY = 'interdisciplinar';

    /** Quantas duplicatas listar (A8, passo 1). */
    private const DUPLICATE_LIMIT = 20;

    private const CONFIDENCES = ['high', 'medium', 'low', 'manual'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');

        $io = new SymfonyStyle($input, $output);
        $io->title('Relatório dos periódicos');

        $conn = $this->em->getConnection();

        // Cadastro e famílias
        $totals = $conn->fetchAssociative(
            "SELECT COUNT(*) AS linhas,
                    SUM(CASE WHEN canonical_journal_id IS NULL THEN 1 ELSE 0 END) AS familias,
                    SUM(CASE WHEN area IS NOT NULL AND TRIM(area) <> '' THEN 1 ELSE 0 END) AS com_area
               FROM qualis_journals"
        );
        $rows = (int) $totals['linhas'];
        $families = (int) $totals['familias'];
        $withArea = (int) $totals['com_area'];
        $withEvidence = (int) $conn->fetchOne('SELECT COUNT(DISTINCT journal_id) FROM journal_area_evidence');

        // Área principal de cada família (a manual antes da automática), e a família de cada artigo
        $primary = $this->primaryAreas($conn);
        $interdisciplinary = count(array_filter($primary, static fn (array $area): bool => $area['slug'] === self::INTERDISCIPLINARY));

        $visible = array_flip(array_map('intval', $conn->fetchFirstColumn(
            'SELECT DISTINCT COALESCE(q.canonical_journal_id, q.id)
               FROM production_items pi
               JOIN qualis_journals q ON q.id = pi.qualis_journal_id
              WHERE pi.item_type = ?',
            [ProductionItem::TYPE_ARTIGO]
        )));
        $visibleClassified = array_intersect_key($primary, $visible);

        $io->section('Cadastro e famílias');
        $io->table(['Indicador', 'Valor'], [
            ['Linhas do cadastro', $this->n($rows)],
            ['Famílias (linhas canônicas)', $this->n($families)],
            ['Linhas com área preenchida (area)', $this->share($withArea, $rows)],
            ['Linhas com evidência de área', $this->share($withEvidence, $rows)],
            ['Famílias com área principal interdisciplinar', $this->n($interdisciplinary)],
        ]);

        $io->section('Famílias visíveis (com artigos)');
        $io->table(['Indicador', 'Valor'], [
            ['Famílias visíveis', $this->n(count($visible))],
            ['Classificadas (com área principal)', $this->share(count($visibleClassified), count($visible))],
        ]);

        $byConfidence = array_fill_keys(self::CONFIDENCES, 0);
        foreach ($visibleClassified as $area) {
            $byConfidence[$area['confidence']] = ($byConfidence[$area['confidence']] ?? 0) + 1;
        }
        $confidenceRows = [];
        foreach ($byConfidence as $confidence => $count) {
            $confidenceRows[] = [$confidence, $this->n($count), $this->pct($count, count($visibleClassified))];
        }
        $io->section('Famílias visíveis classificadas, por confiança da área principal');
        $io->table(['Confiança', 'Famílias', '%'], $confidenceRows);

        // Artigos por base
        $baseRows = [];
        foreach ($conn->fetchAllAssociative(
            'SELECT ad.acronym AS base,
                    COUNT(DISTINCT pi.id) AS artigos,
                    COUNT(DISTINCT COALESCE(q.canonical_journal_id, q.id)) AS familias
               FROM production_items pi
               JOIN qualis_journals q ON q.id = pi.qualis_journal_id
               JOIN qualis_journal_academic_database qjd ON qjd.qualis_journal_id = q.id
               JOIN academic_database ad ON ad.id = qjd.academic_database_id
              WHERE pi.item_type = ?
              GROUP BY ad.acronym
              ORDER BY artigos DESC',
            [ProductionItem::TYPE_ARTIGO]
        ) as $row) {
            $baseRows[] = [(string) $row['base'], $this->n((int) $row['familias']), $this->n((int) $row['artigos'])];
        }
        $io->section('Artigos por base (famílias e artigos com periódico indexado naquela base)');
        $io->table(['Base', 'Famílias', 'Artigos'], $baseRows);

        // Variantes por tipo
        $variantRows = [];
        foreach ($conn->fetchAllAssociative(
            'SELECT variation_type, COUNT(*) AS total, SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS ativas
               FROM journal_name_variants
              GROUP BY variation_type
              ORDER BY total DESC'
        ) as $row) {
            $variantRows[] = [(string) $row['variation_type'], $this->n((int) $row['total']), $this->n((int) $row['ativas'])];
        }
        $io->section('Variantes de nome, por tipo');
        $io->table(['Tipo', 'Total', 'Ativas'], $variantRows);

        // Fila de nomes por status
        $queueRows = [];
        foreach ($conn->fetchAllAssociative(
            'SELECT status, COUNT(*) AS total FROM journal_name_candidates GROUP BY status ORDER BY total DESC'
        ) as $row) {
            $queueRows[] = [(string) $row['status'], $this->n((int) $row['total'])];
        }
        $io->section('Fila de nomes, por status');
        $io->table(['Status', 'Nomes'], $queueRows);

        // Duplicatas (a migração aborta se houver; listadas aqui para revisão)
        $duplicates = $conn->fetchAllAssociative(
            'SELECT normalized_name, COUNT(*) AS total FROM journal_name_variants
              GROUP BY normalized_name HAVING COUNT(*) > 1 ORDER BY total DESC LIMIT ' . self::DUPLICATE_LIMIT
        );
        $io->section('normalized_name duplicado em journal_name_variants');
        if ($duplicates === []) {
            $io->writeln('Nenhum.');
        } else {
            $io->table(['normalized_name', 'Ocorrências'], array_map(
                static fn (array $row): array => [(string) $row['normalized_name'], (string) $row['total']],
                $duplicates
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Área principal de cada família (chave: id da canônica), com a manual preferida.
     *
     * @return array<int, array{slug: string, confidence: string}>
     */
    private function primaryAreas(Connection $conn): array
    {
        $primary = [];
        $result = $conn->executeQuery(
            'SELECT COALESCE(q.canonical_journal_id, q.id) AS fam, ja.confidence, a.slug
               FROM journal_areas ja
               JOIN qualis_journals q ON q.id = ja.journal_id
               JOIN capes_areas a ON a.id = ja.area_id
              WHERE ja.is_primary = 1
              ORDER BY fam, (ja.method = \'manual\') DESC, ja.id'
        );
        while (($row = $result->fetchAssociative()) !== false) {
            $primary[(int) $row['fam']] ??= ['slug' => (string) $row['slug'], 'confidence' => (string) $row['confidence']];
        }

        return $primary;
    }

    private function n(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    /** "parte de total (percentual)", ou só a parte quando não há total. */
    private function share(int $part, int $total): string
    {
        return $total > 0
            ? sprintf('%s de %s (%s)', $this->n($part), $this->n($total), $this->pct($part, $total))
            : $this->n($part);
    }

    private function pct(int $part, int $total): string
    {
        return $total > 0 ? number_format(100 * $part / $total, 1, ',', '.') . '%' : '-';
    }
}
