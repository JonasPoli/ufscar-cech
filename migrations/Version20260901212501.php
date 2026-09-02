<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260901212501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE orientations ADD alternative_title LONGTEXT DEFAULT NULL, ADD handle_url VARCHAR(255) DEFAULT NULL, ADD handle VARCHAR(100) DEFAULT NULL, ADD repository_uuid VARCHAR(64) DEFAULT NULL, ADD source VARCHAR(50) DEFAULT \'lattes\' NOT NULL, ADD is_coadvising TINYINT(1) DEFAULT 0 NOT NULL, ADD defense_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', ADD abstract_text LONGTEXT DEFAULT NULL, ADD keywords LONGTEXT DEFAULT NULL, ADD doi VARCHAR(100) DEFAULT NULL, ADD center_name VARCHAR(255) DEFAULT NULL, ADD campus VARCHAR(100) DEFAULT NULL, ADD student_orcid VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_orient_handle ON orientations (handle)');
        $this->addSql('CREATE INDEX idx_orient_repo_uuid ON orientations (repository_uuid)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_orient_handle ON orientations');
        $this->addSql('DROP INDEX idx_orient_repo_uuid ON orientations');
        $this->addSql('ALTER TABLE orientations DROP alternative_title, DROP handle_url, DROP handle, DROP repository_uuid, DROP source, DROP is_coadvising, DROP defense_date, DROP abstract_text, DROP keywords, DROP doi, DROP center_name, DROP campus, DROP student_orcid');
    }
}
