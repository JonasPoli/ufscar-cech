<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260825005742 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE event_participations (id INT AUTO_INCREMENT NOT NULL, researcher_id INT NOT NULL, event_name VARCHAR(500) NOT NULL, event_type VARCHAR(100) DEFAULT NULL, participation_type VARCHAR(100) DEFAULT NULL, presentation_title LONGTEXT DEFAULT NULL, year INT DEFAULT NULL, INDEX IDX_2282709BC7533BDE (researcher_id), INDEX idx_event_type (event_type), INDEX idx_event_year (year), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE examination_boards (id INT AUTO_INCREMENT NOT NULL, researcher_id INT NOT NULL, board_type VARCHAR(100) NOT NULL, candidate_name VARCHAR(255) DEFAULT NULL, title LONGTEXT DEFAULT NULL, institution_name VARCHAR(255) DEFAULT NULL, year INT DEFAULT NULL, INDEX IDX_18FDCDD7C7533BDE (researcher_id), INDEX idx_board_type (board_type), INDEX idx_board_year (year), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE language_proficiencies (id INT AUTO_INCREMENT NOT NULL, researcher_id INT NOT NULL, language VARCHAR(100) NOT NULL, reading VARCHAR(50) DEFAULT NULL, writing VARCHAR(50) DEFAULT NULL, speaking VARCHAR(50) DEFAULT NULL, comprehension VARCHAR(50) DEFAULT NULL, INDEX IDX_E51AC019C7533BDE (researcher_id), INDEX idx_lang_name (language), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE research_projects (id INT AUTO_INCREMENT NOT NULL, researcher_id INT NOT NULL, name VARCHAR(500) NOT NULL, nature VARCHAR(50) DEFAULT \'PESQUISA\' NOT NULL, status VARCHAR(50) DEFAULT \'EM_ANDAMENTO\' NOT NULL, start_year INT DEFAULT NULL, end_year INT DEFAULT NULL, description LONGTEXT DEFAULT NULL, agency_financier VARCHAR(255) DEFAULT NULL, is_coordinator TINYINT(1) DEFAULT 0 NOT NULL, INDEX IDX_A168E46EC7533BDE (researcher_id), INDEX idx_proj_nature (nature), INDEX idx_proj_status (status), INDEX idx_proj_year (start_year), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE event_participations ADD CONSTRAINT FK_2282709BC7533BDE FOREIGN KEY (researcher_id) REFERENCES researchers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE examination_boards ADD CONSTRAINT FK_18FDCDD7C7533BDE FOREIGN KEY (researcher_id) REFERENCES researchers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE language_proficiencies ADD CONSTRAINT FK_E51AC019C7533BDE FOREIGN KEY (researcher_id) REFERENCES researchers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE research_projects ADD CONSTRAINT FK_A168E46EC7533BDE FOREIGN KEY (researcher_id) REFERENCES researchers (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event_participations DROP FOREIGN KEY FK_2282709BC7533BDE');
        $this->addSql('ALTER TABLE examination_boards DROP FOREIGN KEY FK_18FDCDD7C7533BDE');
        $this->addSql('ALTER TABLE language_proficiencies DROP FOREIGN KEY FK_E51AC019C7533BDE');
        $this->addSql('ALTER TABLE research_projects DROP FOREIGN KEY FK_A168E46EC7533BDE');
        $this->addSql('DROP TABLE event_participations');
        $this->addSql('DROP TABLE examination_boards');
        $this->addSql('DROP TABLE language_proficiencies');
        $this->addSql('DROP TABLE research_projects');
    }
}
