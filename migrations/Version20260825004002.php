<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260825004002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE professional_experiences (id INT AUTO_INCREMENT NOT NULL, researcher_id INT NOT NULL, institution_name VARCHAR(255) NOT NULL, institution_code VARCHAR(50) DEFAULT NULL, agency_name VARCHAR(255) DEFAULT NULL, unit_name VARCHAR(255) DEFAULT NULL, role_name VARCHAR(255) DEFAULT NULL, contract_type VARCHAR(150) DEFAULT NULL, workload_hours INT DEFAULT NULL, start_year INT DEFAULT NULL, start_month INT DEFAULT NULL, end_year INT DEFAULT NULL, end_month INT DEFAULT NULL, is_current TINYINT(1) DEFAULT 0 NOT NULL, other_info LONGTEXT DEFAULT NULL, INDEX IDX_30886820C7533BDE (researcher_id), INDEX idx_prof_exp_institution (institution_name), INDEX idx_prof_exp_year (start_year), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE professional_experiences ADD CONSTRAINT FK_30886820C7533BDE FOREIGN KEY (researcher_id) REFERENCES researchers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educations ADD workload_hours INT DEFAULT NULL, ADD granting_agency VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE researchers ADD work_agency VARCHAR(255) DEFAULT NULL, ADD work_postal_code VARCHAR(20) DEFAULT NULL, ADD work_phone VARCHAR(50) DEFAULT NULL, ADD work_city VARCHAR(100) DEFAULT NULL, ADD work_state VARCHAR(50) DEFAULT NULL, ADD work_country VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE professional_experiences DROP FOREIGN KEY FK_30886820C7533BDE');
        $this->addSql('DROP TABLE professional_experiences');
        $this->addSql('ALTER TABLE educations DROP workload_hours, DROP granting_agency');
        $this->addSql('ALTER TABLE researchers DROP work_agency, DROP work_postal_code, DROP work_phone, DROP work_city, DROP work_state, DROP work_country');
    }
}
