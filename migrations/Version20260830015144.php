<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260830015144 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS academic_database (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, acronym VARCHAR(100) NOT NULL, url VARCHAR(500) DEFAULT NULL, list_download_url VARCHAR(500) DEFAULT NULL, logo VARCHAR(500) DEFAULT NULL, file_formats JSON DEFAULT NULL, signature_columns JSON DEFAULT NULL, description LONGTEXT DEFAULT NULL, import_instructions LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_8B7E2CE9E648F133 (acronym), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE IF NOT EXISTS qualis_journal_academic_database (qualis_journal_id INT NOT NULL, academic_database_id INT NOT NULL, INDEX IDX_QJ_JOURNAL (qualis_journal_id), INDEX IDX_QJ_DATABASE (academic_database_id), PRIMARY KEY(qualis_journal_id, academic_database_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE qualis_journal_academic_database ADD CONSTRAINT FK_QJ_JOURNAL FOREIGN KEY (qualis_journal_id) REFERENCES qualis_journals (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE qualis_journal_academic_database ADD CONSTRAINT FK_QJ_DATABASE FOREIGN KEY (academic_database_id) REFERENCES academic_database (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS qualis_journal_academic_database');
        $this->addSql('DROP TABLE IF EXISTS academic_database');
    }
}
