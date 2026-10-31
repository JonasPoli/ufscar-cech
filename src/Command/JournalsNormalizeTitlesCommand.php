<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Journal\Issn;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Preenche as colunas derivadas usadas pelo vínculo artigo→periódico (docs/evolutions/periodicos-por-base.md, A3 e T08):
 *  - qualis_journals.normalized_title        = forma do tesauro de `title`;
 *  - production_items.journal_name_normalized = forma do tesauro de `journal_name`.
 *
 * Forma do tesauro = StringNormalizer::normalizeString($s, true). Os dados brutos (`title`, `journal_name`) nunca são
 * alterados. O comando é idempotente: só grava onde o valor calculado difere do gravado.
 */
#[AsCommand(
    name: 'app:journals:normalize-titles',
    description: 'Preenche qualis_journals.normalized_title e production_items.journal_name_normalized (forma do tesauro)'
)]
class JournalsNormalizeTitlesCommand extends Command
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('only-missing', null, InputOption::VALUE_NONE, 'Só processa as linhas em que a coluna derivada está vazia (NULL)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @ini_set('memory_limit', '1024M');
        $io = new SymfonyStyle($input, $output);
        $io->title('Normalização de títulos e nomes de periódicos');

        $onlyMissing = (bool)$input->getOption('only-missing');
        $conn = $this->em->getConnection();
        $startedAt = microtime(true);

        $journals = $this->normalizeColumn($conn, 'qualis_journals', 'title', 'normalized_title', '', $onlyMissing);
        $repairedIssns = $this->repairGluedIssns($conn);
        $articles = $this->normalizeColumn($conn, 'production_items', 'journal_name', 'journal_name_normalized', 'journal_name IS NOT NULL', $onlyMissing);

        $io->table(
            ['Tabela.coluna', 'Linhas lidas', 'Linhas gravadas', 'Ficam sem valor (nome só com símbolos)'],
            [
                ['qualis_journals.normalized_title', $journals['read'], $journals['written'], $journals['empty']],
                ['production_items.journal_name_normalized', $articles['read'], $articles['written'], $articles['empty']],
            ]
        );
        $io->text(sprintf('Linhas com ISSNs colados corrigidas nas colunas de busca: %d.', $repairedIssns));
        $io->success(sprintf(
            'Normalização concluída em %s s%s.',
            number_format(microtime(true) - $startedAt, 1, ',', '.'),
            $onlyMissing ? ' (somente as linhas vazias)' : ''
        ));

        return Command::SUCCESS;
    }

    /**
     * Linhas com dois ISSNs no mesmo campo ("1984-6444, 0102-5473") têm os dois colados na coluna normalizada
     * ("1984644401025473"), e a busca por igualdade do resolver não acha nenhum deles. Aqui cada coluna normalizada passa
     * a ter um ISSN só; o texto original (`issn`, `issn_imp`...) não muda. Idempotente.
     *
     * @return int linhas corrigidas
     */
    private function repairGluedIssns(Connection $conn): int
    {
        $columns = ['normalized_issn', 'normalized_issn_imp', 'normalized_issn_e', 'normalized_issn_l'];
        $rows = $conn->fetchAllAssociative(
            'SELECT id, normalized_issn, normalized_issn_imp, normalized_issn_e, normalized_issn_l
               FROM qualis_journals
              WHERE CHAR_LENGTH(normalized_issn) > 8 OR CHAR_LENGTH(normalized_issn_imp) > 8
                 OR CHAR_LENGTH(normalized_issn_e) > 8 OR CHAR_LENGTH(normalized_issn_l) > 8'
        );

        foreach ($rows as $row) {
            $issns = [];
            $values = [];
            foreach ($columns as $column) {
                $value = (string) $row[$column];
                $values[$column] = strlen($value) === 8 ? $value : null;
                foreach (Issn::extractAll($value, false) as $issn) {
                    if (!in_array($issn, $issns, true)) {
                        $issns[] = $issn;
                    }
                }
            }

            $missing = array_values(array_diff($issns, array_filter($values)));
            foreach ($columns as $column) {
                if ($values[$column] === null && $missing !== []) {
                    $values[$column] = array_shift($missing);
                }
            }

            $conn->update('qualis_journals', $values, ['id' => $row['id']]);
        }

        return count($rows);
    }

    /**
     * Percorre a tabela por id, em lotes, e grava a forma do tesauro de $sourceColumn em $targetColumn.
     *
     * @return array{read: int, written: int, empty: int}
     */
    private function normalizeColumn(
        Connection $conn,
        string $table,
        string $sourceColumn,
        string $targetColumn,
        string $extraWhere,
        bool $onlyMissing
    ): array {
        $conditions = ['id > ?'];
        if ($extraWhere !== '') {
            $conditions[] = $extraWhere;
        }
        if ($onlyMissing) {
            $conditions[] = $targetColumn . ' IS NULL';
        }

        $select = sprintf(
            'SELECT id, %s AS source, %s AS target FROM %s WHERE %s ORDER BY id LIMIT %d',
            $sourceColumn,
            $targetColumn,
            $table,
            implode(' AND ', $conditions),
            self::BATCH_SIZE
        );
        $update = sprintf('UPDATE %s SET %s = ? WHERE id = ?', $table, $targetColumn);

        $stats = ['read' => 0, 'written' => 0, 'empty' => 0];
        $lastId = 0;

        while (true) {
            $rows = $conn->fetchAllAssociative($select, [$lastId]);
            if ($rows === []) {
                break;
            }

            $changes = [];
            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $stats['read']++;

                $normalized = StringNormalizer::normalizeString($row['source'], true);
                $normalized = $normalized !== '' ? $normalized : null;
                if ($normalized === null) {
                    $stats['empty']++;
                }

                // Comparação exata em PHP: a collation do MySQL ignora caixa e acento e esconderia valores antigos
                if ($normalized !== $row['target']) {
                    $changes[$lastId] = $normalized;
                }
            }

            if ($changes !== []) {
                $conn->transactional(static function (Connection $tx) use ($update, $changes): void {
                    $stmt = $tx->prepare($update);
                    foreach ($changes as $id => $value) {
                        $stmt->executeStatement([$value, $id]);
                    }
                });
                $stats['written'] += count($changes);
            }
        }

        return $stats;
    }
}
