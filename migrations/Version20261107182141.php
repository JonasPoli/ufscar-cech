<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Catálogo institucional de projetos.
 *
 * - `research_project_members`: integrantes de cada projeto como vieram em EQUIPE-DO-PROJETO (brutos) e o docente do
 *   CECH com o mesmo ID Lattes (`matched_researcher_id`, calculado; SET NULL se o docente sair do banco).
 * - `research_project_funders`: todos os financiadores, como vieram em FINANCIADORES-DO-PROJETO.
 * - `research_projects.project_group_key`: chave calculada que une o mesmo projeto declarado em vários currículos.
 *
 * As tabelas nascem vazias e a chave nasce nula: `app:projects:backfill-members --dir=<XMLs>` completa os projetos já
 * importados (e roda a indexação no fim); `app:index:projects` recalcula chaves e vínculos a qualquer momento.
 */
final class Version20261107182141 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Projetos: integrantes, financiadores e chave de agrupamento';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE research_project_funders (id INT AUTO_INCREMENT NOT NULL, research_project_id INT NOT NULL, name VARCHAR(255) NOT NULL, institution_code VARCHAR(50) DEFAULT NULL, nature VARCHAR(50) DEFAULT NULL, funder_order INT DEFAULT NULL, INDEX IDX_82AAFA4BD27719F4 (research_project_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE research_project_members (id INT AUTO_INCREMENT NOT NULL, research_project_id INT NOT NULL, matched_researcher_id INT DEFAULT NULL, full_name VARCHAR(255) NOT NULL, citation_name LONGTEXT DEFAULT NULL, member_order INT DEFAULT NULL, is_responsible TINYINT(1) DEFAULT 0 NOT NULL, id_lattes VARCHAR(30) DEFAULT NULL, INDEX IDX_E1B08926D27719F4 (research_project_id), INDEX IDX_E1B08926FB633BFA (matched_researcher_id), INDEX idx_rpm_id_lattes (id_lattes), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE research_project_funders ADD CONSTRAINT FK_82AAFA4BD27719F4 FOREIGN KEY (research_project_id) REFERENCES research_projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE research_project_members ADD CONSTRAINT FK_E1B08926D27719F4 FOREIGN KEY (research_project_id) REFERENCES research_projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE research_project_members ADD CONSTRAINT FK_E1B08926FB633BFA FOREIGN KEY (matched_researcher_id) REFERENCES researchers (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE research_projects ADD project_group_key VARCHAR(40) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_proj_group_key ON research_projects (project_group_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE research_project_funders DROP FOREIGN KEY FK_82AAFA4BD27719F4');
        $this->addSql('ALTER TABLE research_project_members DROP FOREIGN KEY FK_E1B08926D27719F4');
        $this->addSql('ALTER TABLE research_project_members DROP FOREIGN KEY FK_E1B08926FB633BFA');
        $this->addSql('DROP TABLE research_project_funders');
        $this->addSql('DROP TABLE research_project_members');
        $this->addSql('DROP INDEX idx_proj_group_key ON research_projects');
        $this->addSql('ALTER TABLE research_projects DROP project_group_key');
    }
}
