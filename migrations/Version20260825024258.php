<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260825024258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE educations ADD institution_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE educations ADD CONSTRAINT FK_730876AD10405986 FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_730876AD10405986 ON educations (institution_id)');
        $this->addSql('ALTER TABLE production_authors ADD matched_researcher_id INT DEFAULT NULL, ADD author_identity_id INT DEFAULT NULL, ADD is_cech_researcher TINYINT(1) DEFAULT 0 NOT NULL, ADD is_indexed TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE production_authors ADD CONSTRAINT FK_9B19A78CFB633BFA FOREIGN KEY (matched_researcher_id) REFERENCES researchers (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE production_authors ADD CONSTRAINT FK_9B19A78CA3CFB7D0 FOREIGN KEY (author_identity_id) REFERENCES author_identities (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9B19A78CFB633BFA ON production_authors (matched_researcher_id)');
        $this->addSql('CREATE INDEX IDX_9B19A78CA3CFB7D0 ON production_authors (author_identity_id)');
        $this->addSql('ALTER TABLE production_items ADD qualis_journal_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE production_items ADD CONSTRAINT FK_FB1F2D7B47DF50A9 FOREIGN KEY (qualis_journal_id) REFERENCES qualis_journals (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_FB1F2D7B47DF50A9 ON production_items (qualis_journal_id)');
        $this->addSql('ALTER TABLE professional_experiences ADD institution_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE professional_experiences ADD CONSTRAINT FK_3088682010405986 FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_3088682010405986 ON professional_experiences (institution_id)');
        $this->addSql('ALTER TABLE researchers ADD last_indexed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE educations DROP FOREIGN KEY FK_730876AD10405986');
        $this->addSql('DROP INDEX IDX_730876AD10405986 ON educations');
        $this->addSql('ALTER TABLE educations DROP institution_id');
        $this->addSql('ALTER TABLE production_authors DROP FOREIGN KEY FK_9B19A78CFB633BFA');
        $this->addSql('ALTER TABLE production_authors DROP FOREIGN KEY FK_9B19A78CA3CFB7D0');
        $this->addSql('DROP INDEX IDX_9B19A78CFB633BFA ON production_authors');
        $this->addSql('DROP INDEX IDX_9B19A78CA3CFB7D0 ON production_authors');
        $this->addSql('ALTER TABLE production_authors DROP matched_researcher_id, DROP author_identity_id, DROP is_cech_researcher, DROP is_indexed');
        $this->addSql('ALTER TABLE production_items DROP FOREIGN KEY FK_FB1F2D7B47DF50A9');
        $this->addSql('DROP INDEX IDX_FB1F2D7B47DF50A9 ON production_items');
        $this->addSql('ALTER TABLE production_items DROP qualis_journal_id');
        $this->addSql('ALTER TABLE professional_experiences DROP FOREIGN KEY FK_3088682010405986');
        $this->addSql('DROP INDEX IDX_3088682010405986 ON professional_experiences');
        $this->addSql('ALTER TABLE professional_experiences DROP institution_id');
        $this->addSql('ALTER TABLE researchers DROP last_indexed_at');
    }
}
