<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Corrige a divergência entre a entidade AcademicDatabase e o banco.
 *
 * A Version20260830015144 criou a tabela com `CREATE TABLE IF NOT EXISTS`, de modo que
 * instalações onde `academic_database` já existia nunca receberam a coluna
 * `list_download_url`. Como a entidade mapeia essa coluna, qualquer consulta a
 * AcademicDatabase falha com "Unknown column" (telas /admin/academic-databases e
 * /admin/journals retornam 500).
 */
final class Version20260911120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona a coluna list_download_url em academic_database quando ausente';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($this->columnExists(), 'A coluna list_download_url já existe em academic_database.');

        $this->addSql('ALTER TABLE academic_database ADD list_download_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$this->columnExists(), 'A coluna list_download_url não existe em academic_database.');

        $this->addSql('ALTER TABLE academic_database DROP list_download_url');
    }

    private function columnExists(): bool
    {
        return (bool)$this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'academic_database'
               AND column_name = 'list_download_url'"
        );
    }
}
