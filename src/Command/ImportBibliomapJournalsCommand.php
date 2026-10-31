<?php

namespace App\Command;

use App\Entity\QualisJournal;
use App\Service\Journal\JournalVariantConflictException;
use App\Service\Journal\JournalVariantGuard;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-bibliomap-journals',
    description: 'Import all Qualis Journals and Thesaurus Variations from the bibliomap database into CECH. Erases qualis_journals and journal_name_variants: refuses to run if they have data, unless --force (and a confirmation).'
)]
class ImportBibliomapJournalsCommand extends AbstractBibliomapImportCommand
{
    /** Tabelas que a importação limpa antes de copiar. */
    private const DESTINATION_TABLES = ['qualis_journals', 'journal_name_variants'];

    /** Variantes novas acumuladas antes de gravar: a trava confere cada nome contra elas, então o lote precisa ser curto. */
    private const VARIANT_FLUSH_EVERY = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalVariantGuard $variantGuard
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Importando Periódicos e Tesauros do BiblioMap');

        $conn = $this->em->getConnection();

        // Antes de ler ou apagar qualquer coisa: com dados no destino, recusa; com --force, só depois de confirmar
        $refused = $this->guardDestination(
            $io,
            $input,
            $conn,
            self::DESTINATION_TABLES,
            'Perderiam-se as famílias de periódicos, as áreas CAPES, o tesauro de grafias e a curadoria manual, e as áreas, '
            . 'as evidências e os artigos que apontavam para esses periódicos ficariam sem eles.'
        );
        if ($refused !== null) {
            return $refused;
        }

        // 1. Fetch from bibliomap.qualis_journal
        $io->section('Lendo dados de bibliomap.qualis_journal...');
        try {
            $stmt = $conn->executeQuery('SELECT id, issn, normalized_issn, title, qualis FROM bibliomap.qualis_journal ORDER BY id ASC');
            $rawJournals = $stmt->fetchAllAssociative();
        } catch (\Throwable $e) {
            $io->error('Erro ao conectar ou consultar bibliomap: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->text(sprintf('Encontrados %d periódicos no BiblioMap.', count($rawJournals)));

        // Clear existing qualis_journals and variants for fresh sync
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $conn->executeStatement('TRUNCATE TABLE journal_name_variants');
        $conn->executeStatement('TRUNCATE TABLE qualis_journals');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->em->clear();

        $idMap = [];
        $batchSize = 500;
        $count = 0;

        // `normalized_title` é a chave pela qual a trava de variantes e o resolver acham o título
        $insertSql = 'INSERT INTO qualis_journals (id, title, normalized_title, issn, normalized_issn, qualis) VALUES ';
        $values = [];
        $params = [];

        foreach ($rawJournals as $row) {
            $record = self::journalRecord($row);

            $values[] = '(?, ?, ?, ?, ?, ?)';
            array_push($params, $record['id'], $record['title'], $record['normalized_title'], $record['issn'], $record['normalized_issn'], $record['qualis']);
            $idMap[$record['id']] = $record['id'];
            $count++;

            if (count($values) >= $batchSize) {
                $conn->executeStatement($insertSql . implode(', ', $values), $params);
                $values = [];
                $params = [];
            }
        }

        if (count($values) > 0) {
            $conn->executeStatement($insertSql . implode(', ', $values), $params);
        }

        $io->success(sprintf('%d periódicos importados com sucesso.', $count));

        // 2. Fetch and import variations from bibliomap.qualis_journal_variacoes_nome
        $io->section('Lendo variações de nome do tesauro...');
        try {
            $vStmt = $conn->executeQuery('SELECT id, journal_id, variation_name, variation_type, status, created_at, updated_at FROM bibliomap.qualis_journal_variacoes_nome ORDER BY id ASC');
            $rawVars = $vStmt->fetchAllAssociative();
        } catch (\Throwable $e) {
            $io->warning('Não foi possível ler variações de bibliomap: ' . $e->getMessage());
            $rawVars = [];
        }

        $io->text(sprintf('Encontradas %d variações de periódicos.', count($rawVars)));

        // Toda variante entra pela trava do tesauro: ela recusa o que é código (uma página de erro já entrou aqui como 411
        // "variantes" do periódico 22013), o nome que já é de outra família e o que a família já tinha
        ['added' => $added, 'skipped' => $skipped, 'conflicts' => $conflicts] = $this->importVariants($rawVars, $idMap);

        $io->success(sprintf('%d variações/sinônimos de periódicos importados com sucesso.', $added));
        if ($skipped > 0 || $conflicts > 0) {
            $io->text(sprintf(
                'Recusadas pela trava do tesauro: %d (código ou nome que a família já tinha) e %d que já pertenciam a outro periódico.',
                $skipped,
                $conflicts
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Linha de `bibliomap.qualis_journal` como entra em `qualis_journals`: o título sem o mojibake (ISO-8859-1 lido como UTF-8) e
     * cortado no tamanho da coluna, o `normalized_title` (forma do tesauro, a chave pela qual a trava de variantes e o resolver
     * acham o título), o ISSN e o Qualis em maiúsculas.
     *
     * @param array<string, mixed> $row
     *
     * @return array{id: int, title: string, normalized_title: ?string, issn: ?string, normalized_issn: ?string, qualis: ?string}
     */
    public static function journalRecord(array $row): array
    {
        $title = trim((string)$row['title']);
        // Fix encoding mojibake if any
        if (!mb_check_encoding($title, 'UTF-8')) {
            $title = mb_convert_encoding($title, 'UTF-8', 'ISO-8859-1');
        }
        $title = mb_substr($title, 0, 500, 'UTF-8');

        $issn = $row['issn'] ? trim((string)$row['issn']) : null;

        return [
            'id' => (int)$row['id'],
            'title' => $title,
            'normalized_title' => mb_substr(StringNormalizer::normalizeString($title, true), 0, 500, 'UTF-8') ?: null,
            'issn' => $issn,
            'normalized_issn' => $row['normalized_issn'] ? trim((string)$row['normalized_issn']) : ($issn ? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $issn)) : null),
            'qualis' => $row['qualis'] ? strtoupper(trim((string)$row['qualis'])) : null,
        ];
    }

    /**
     * Grava as variantes de nome de `bibliomap.qualis_journal_variacoes_nome` pela trava do tesauro
     * ({@see JournalVariantGuard::addVariant()}): só entra nome que nenhuma família conhece, na canônica da família do
     * periódico. O que é código (HTML, pilha de erro) ou que a família já tinha é contado em `skipped`, e o que já era de
     * outro periódico, em `conflicts`. O estado (`status`) e as datas que vieram do BiblioMap valem sobre os que a trava dá.
     * Os periódicos precisam estar gravados, com `normalized_title`, para a trava enxergar os títulos.
     *
     * @param list<array<string, mixed>> $rawVars linhas da origem (journal_id, variation_name, variation_type, status, created_at, updated_at)
     * @param array<int, int> $idMap ids de periódicos importados; variante de outro id é ignorada
     *
     * @return array{added: int, skipped: int, conflicts: int}
     */
    public function importVariants(array $rawVars, array $idMap): array
    {
        $added = 0;
        $skipped = 0;
        $conflicts = 0;
        $pending = 0;

        foreach ($rawVars as $vRow) {
            $jId = (int)$vRow['journal_id'];
            if (!isset($idMap[$jId])) continue;

            $varName = trim((string)$vRow['variation_name']);
            if (!mb_check_encoding($varName, 'UTF-8')) {
                $varName = mb_convert_encoding($varName, 'UTF-8', 'ISO-8859-1');
            }
            $varName = mb_substr($varName, 0, 500, 'UTF-8');

            $journal = $this->em->find(QualisJournal::class, $jId);
            if ($journal === null) {
                continue;
            }

            try {
                $variation = $this->variantGuard->addVariant($journal, $varName, $vRow['variation_type'] ?: 'alternative');
            } catch (JournalVariantConflictException) {
                $conflicts++;
                continue;
            }

            if ($variation === null) {
                $skipped++;
                continue;
            }

            // A trava cria a variante ativa e com a data de agora; o que veio do BiblioMap vale
            $variation->setStatus((int)($vRow['status'] ?? 1) === 1);
            foreach (['created_at' => 'setCreatedAt', 'updated_at' => 'setUpdatedAt'] as $column => $setter) {
                if (!empty($vRow[$column])) {
                    try {
                        $variation->$setter(new \DateTimeImmutable((string)$vRow[$column]));
                    } catch (\Exception) {
                        // data ilegível: fica a de agora
                    }
                }
            }

            $added++;
            if (++$pending >= self::VARIANT_FLUSH_EVERY) {
                $this->em->flush();
                $this->em->clear();
                $pending = 0;
            }
        }

        $this->em->flush();
        $this->em->clear();

        return ['added' => $added, 'skipped' => $skipped, 'conflicts' => $conflicts];
    }
}
