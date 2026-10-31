<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Limpeza de dados do tesauro de periódicos: 411 linhas de uma página de erro HTTP 500 do Bibliomap (CSS, HTML e pilha de
 * chamadas) foram gravadas como variantes do periódico "JOURNAL OF ENVIRONMENTAL PROTECTION AND ECOLOGY", todas no mesmo
 * instante. Nenhuma é nome de revista e nenhuma veio do Lattes. Ver docs/evolutions/periodicos-como-descobrimos.md, 3.11.
 */
final class Version20261031040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove de journal_name_variants as 411 linhas de uma página de erro gravadas como variantes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "DELETE v FROM journal_name_variants v
               JOIN qualis_journals q ON q.id = v.journal_id
              WHERE q.title = 'JOURNAL OF ENVIRONMENTAL PROTECTION AND ECOLOGY'
                AND v.variation_type = 'alternative'
                AND v.created_at = '2026-08-21 15:46:29'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('As linhas removidas não eram nomes de revista.');
    }
}
