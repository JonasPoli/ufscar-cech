<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903152327 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabelas thematic_terms e thematic_term_researchers para pesquisa temática';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE thematic_term_researchers (id INT AUTO_INCREMENT NOT NULL, term_id INT NOT NULL, researcher_id INT NOT NULL, occurrences INT DEFAULT 1 NOT NULL, sample_titles JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_49F94465E2C35FC (term_id), INDEX idx_term_occurrences (term_id, occurrences), INDEX idx_researcher_term (researcher_id), UNIQUE INDEX uniq_term_researcher (term_id, researcher_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE thematic_terms (id INT AUTO_INCREMENT NOT NULL, term VARCHAR(190) NOT NULL, slug VARCHAR(190) NOT NULL, normalized_term VARCHAR(190) NOT NULL, total_occurrences INT DEFAULT 0 NOT NULL, researcher_count INT DEFAULT 0 NOT NULL, source_type VARCHAR(30) DEFAULT \'all\' NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_8F1EEBCA50FE78D (term), UNIQUE INDEX UNIQ_8F1EEBC989D9B62 (slug), INDEX idx_thematic_term_normalized (normalized_term), INDEX idx_thematic_term_occurrences (total_occurrences), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE thematic_term_researchers ADD CONSTRAINT FK_49F94465E2C35FC FOREIGN KEY (term_id) REFERENCES thematic_terms (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE thematic_term_researchers ADD CONSTRAINT FK_49F94465C7533BDE FOREIGN KEY (researcher_id) REFERENCES researchers (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE thematic_term_researchers DROP FOREIGN KEY FK_49F94465E2C35FC');
        $this->addSql('ALTER TABLE thematic_term_researchers DROP FOREIGN KEY FK_49F94465C7533BDE');
        $this->addSql('DROP TABLE thematic_term_researchers');
        $this->addSql('DROP TABLE thematic_terms');
    }
}
