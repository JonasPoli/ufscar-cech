<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260825015708 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE journal_name_variants (id INT AUTO_INCREMENT NOT NULL, journal_id INT NOT NULL, variation_name VARCHAR(500) NOT NULL, normalized_name VARCHAR(500) NOT NULL, variation_type VARCHAR(50) DEFAULT \'alternative\' NOT NULL, status TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_79F9369D478E8802 (journal_id), INDEX idx_journal_var_norm_name (normalized_name), INDEX idx_journal_var_name (variation_name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE qualis_journals (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(500) NOT NULL, issn VARCHAR(50) DEFAULT NULL, normalized_issn VARCHAR(50) DEFAULT NULL, issn_e VARCHAR(50) DEFAULT NULL, normalized_issn_e VARCHAR(50) DEFAULT NULL, issn_l VARCHAR(50) DEFAULT NULL, normalized_issn_l VARCHAR(50) DEFAULT NULL, issn_imp VARCHAR(50) DEFAULT NULL, normalized_issn_imp VARCHAR(50) DEFAULT NULL, qualis VARCHAR(10) DEFAULT NULL, area LONGTEXT DEFAULT NULL, INDEX idx_journal_norm_issn (normalized_issn), INDEX idx_journal_norm_issn_e (normalized_issn_e), INDEX idx_journal_norm_issn_l (normalized_issn_l), INDEX idx_journal_norm_issn_imp (normalized_issn_imp), INDEX idx_journal_qualis (qualis), INDEX idx_journal_title (title), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE journal_name_variants ADD CONSTRAINT FK_79F9369D478E8802 FOREIGN KEY (journal_id) REFERENCES qualis_journals (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE journal_name_variants DROP FOREIGN KEY FK_79F9369D478E8802');
        $this->addSql('DROP TABLE journal_name_variants');
        $this->addSql('DROP TABLE qualis_journals');
    }
}
