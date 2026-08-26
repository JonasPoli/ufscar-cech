<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona coluna indexed_databases (JSON) em production_items para persistência de bases internacionais indexadas
 */
final class Version20260826033000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona coluna indexed_databases (JSON) em production_items para persistência de bases internacionais indexadas (Scopus, WoS, PubMed, etc.)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_items ADD indexed_databases JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_items DROP indexed_databases');
    }
}
