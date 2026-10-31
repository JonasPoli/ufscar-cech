<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tesauro de categorias de revistas: cria capes_area_variants.
 *
 * Gerada por doctrine:migrations:diff; o diff trazia também a remoção das chaves estrangeiras FK_QJ_DATABASE e
 * FK_QJ_JOURNAL de qualis_journal_academic_database (divergência antiga entre o banco e o mapeamento, que não é desta
 * tarefa), por isso ficaram de fora. A tabela nasce vazia: quem a semeia é o comando app:journals:areas:thesaurus.
 */
final class Version20261031035455 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria capes_area_variants (tesauro de categorias de revistas: rótulo, vocabulário e área CAPES)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE capes_area_variants (id INT AUTO_INCREMENT NOT NULL, area_id SMALLINT UNSIGNED DEFAULT NULL, vocabulary VARCHAR(20) NOT NULL, variation_name VARCHAR(255) NOT NULL, normalized_name VARCHAR(255) NOT NULL, origin VARCHAR(10) NOT NULL, status VARCHAR(10) NOT NULL, occurrences INT UNSIGNED DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_24BAB1BCBD0F409C (area_id), INDEX idx_capes_variant_norm (normalized_name), INDEX idx_capes_variant_status (status, occurrences), UNIQUE INDEX uniq_capes_variant (vocabulary, normalized_name, area_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE capes_area_variants ADD CONSTRAINT FK_24BAB1BCBD0F409C FOREIGN KEY (area_id) REFERENCES capes_areas (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE capes_area_variants DROP FOREIGN KEY FK_24BAB1BCBD0F409C');
        $this->addSql('DROP TABLE capes_area_variants');
    }
}
