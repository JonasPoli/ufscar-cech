<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903152715 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove índice único estrito da coluna term em thematic_terms';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_8F1EEBCA50FE78D ON thematic_terms');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8F1EEBCA50FE78D ON thematic_terms (term)');
    }
}
