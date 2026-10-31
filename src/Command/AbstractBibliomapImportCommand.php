<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base dos comandos que copiam dados do banco do BiblioMap para o CECH (`app:import:bibliomap`,
 * `app:import-bibliomap-journals`, `app:import-bibliomap-databases`).
 *
 * Os três limpam as tabelas de destino com `TRUNCATE` (e as chaves estrangeiras desligadas) antes de copiar, então rodar um
 * deles num banco que já tem dados apagaria o trabalho feito depois da primeira importação: famílias de periódicos, áreas
 * CAPES, tesauro de grafias, identidades de autores e curadoria manual. Por isso nenhum apaga nada sem trava:
 *  - com dados nas tabelas de destino, o comando recusa (`Command::FAILURE`) e nada é alterado;
 *  - `--force` libera a substituição, mas ainda pede confirmação; no modo não interativo (`--no-interaction`) a resposta
 *    é "não", então `--force` sozinho nunca apaga.
 * Com as tabelas de destino vazias o comando segue sem perguntar: não há o que perder.
 */
abstract class AbstractBibliomapImportCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Substitui as tabelas de destino mesmo que já tenham dados: APAGA o que há nelas e pede confirmação (sem confirmação, nada é apagado)'
        );
    }

    /**
     * Confere as tabelas de destino antes de qualquer alteração.
     *
     * @param list<string> $tables tabelas que o comando vai limpar
     * @param string $consequence o que se perde com a substituição, para a mensagem
     *
     * @return int|null null quando pode seguir (tabelas vazias, ou `--force` confirmado); senão o código de saída que o
     *         comando deve devolver
     */
    protected function guardDestination(SymfonyStyle $io, InputInterface $input, Connection $conn, array $tables, string $consequence): ?int
    {
        $filled = self::tablesWithData($conn, $tables);
        if ($filled === []) {
            return null;
        }

        $summary = implode('; ', array_map(
            static fn (string $table, int $rows): string => sprintf('%s: %s linhas', $table, number_format($rows, 0, ',', '.')),
            array_keys($filled),
            $filled
        ));

        if (!$input->getOption('force')) {
            $io->error([
                'Importação recusada: as tabelas de destino já têm dados (' . $summary . ').',
                'Esta importação APAGA (TRUNCATE) essas tabelas antes de copiar. ' . $consequence,
                'Nada foi alterado. Para substituí-las mesmo assim, rode de novo com --force: será pedida uma confirmação.',
            ]);

            return Command::FAILURE;
        }

        $io->warning([
            'Atenção: as tabelas de destino têm dados (' . $summary . ').',
            'Continuar APAGA essas tabelas. ' . $consequence,
        ]);
        if (!$io->confirm('Apagar as tabelas de destino e importar do BiblioMap?', false)) {
            $io->error('Cancelado: sem confirmação nada foi apagado (no modo não interativo, --force não basta).');

            return Command::FAILURE;
        }

        return null;
    }

    /**
     * Tabelas que têm pelo menos uma linha, com a quantidade de linhas de cada uma.
     *
     * @param list<string> $tables nomes de tabela fixos do código (entram no SQL entre crases)
     *
     * @return array<string, int> tabela => linhas, só as que não estão vazias
     */
    public static function tablesWithData(Connection $conn, array $tables): array
    {
        $filled = [];
        foreach ($tables as $table) {
            $rows = (int) $conn->fetchOne('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`');
            if ($rows > 0) {
                $filled[$table] = $rows;
            }
        }

        return $filled;
    }
}
