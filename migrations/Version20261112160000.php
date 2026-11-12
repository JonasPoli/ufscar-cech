<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove as tabelas do CRUD de teste do gerador (scaffolding) que ficava exposto no painel.
 *
 * As entidades TestDatabase e SuperTestFields, seus controllers (/admin/test/database e /admin/super/test/fields),
 * formulários e templates saíram do código: eram só exemplos do maker, sem uso no portal. As tabelas `test_database` e
 * `super_test_fields` não guardam dado do Lattes nem do portal. O `down()` recria as duas tabelas vazias, com a
 * estrutura que tinham (SHOW CREATE TABLE).
 */
final class Version20261112160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove as tabelas de teste do scaffolding: super_test_fields e test_database';
    }

    public function up(Schema $schema): void
    {
        // super_test_fields primeiro: a chave estrangeira dela aponta para test_database
        $this->addSql('DROP TABLE IF EXISTS super_test_fields');
        $this->addSql('DROP TABLE IF EXISTS test_database');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE test_database (
              id INT NOT NULL AUTO_INCREMENT,
              name VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE super_test_fields (
              id INT NOT NULL AUTO_INCREMENT,
              choice_type_from_entity_id INT DEFAULT NULL,
              simple_input_text VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
              edit_text_with_editor LONGTEXT COLLATE utf8mb4_unicode_ci NOT NULL,
              date_field DATE NOT NULL,
              date_and_time_field DATETIME NOT NULL,
              choice_type_from_list VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
              sin_nao_int INT NOT NULL,
              boolean_true_false TINYINT(1) NOT NULL,
              image VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
              img_updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
              select_enum VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
              email_field VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
              numero_simples INT NOT NULL,
              PRIMARY KEY (id),
              KEY IDX_43206855B736F1D0 (choice_type_from_entity_id),
              CONSTRAINT FK_43206855B736F1D0 FOREIGN KEY (choice_type_from_entity_id) REFERENCES test_database (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
