<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260824214607 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE author_external_identifiers (id INT AUTO_INCREMENT NOT NULL, author_identity_id INT NOT NULL, provider VARCHAR(50) NOT NULL, identifier VARCHAR(100) NOT NULL, url VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_72831BCDA3CFB7D0 (author_identity_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE author_identities (id INT AUTO_INCREMENT NOT NULL, preferred_name VARCHAR(255) NOT NULL, normalized_name VARCHAR(255) NOT NULL, status TINYINT(1) DEFAULT 1 NOT NULL, review_reasons VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE author_name_variants (id INT AUTO_INCREMENT NOT NULL, author_identity_id INT NOT NULL, original_name VARCHAR(255) NOT NULL, normalized_name VARCHAR(255) NOT NULL, display_name VARCHAR(255) DEFAULT NULL, source VARCHAR(50) DEFAULT NULL, status TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_AC1439C2A3CFB7D0 (author_identity_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE awards (id INT AUTO_INCREMENT NOT NULL, researcher_id INT NOT NULL, name LONGTEXT NOT NULL, year INT DEFAULT NULL, promoter_entity VARCHAR(255) DEFAULT NULL, INDEX IDX_25EAE3FEC7533BDE (researcher_id), INDEX idx_award_year (year), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE cities (id INT AUTO_INCREMENT NOT NULL, state_id INT DEFAULT NULL, name VARCHAR(150) NOT NULL, latitude VARCHAR(50) DEFAULT NULL, longitude VARCHAR(50) DEFAULT NULL, status TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_D95DB16B5D83CC1 (state_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE city_name_variants (id INT AUTO_INCREMENT NOT NULL, city_id INT NOT NULL, variation_name VARCHAR(150) NOT NULL, normalized_name VARCHAR(150) NOT NULL, variation_type VARCHAR(50) DEFAULT NULL, status TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_E2DEA4378BAC62AF (city_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE countries (id INT AUTO_INCREMENT NOT NULL, common_name VARCHAR(150) NOT NULL, official_name VARCHAR(200) DEFAULT NULL, iso_alpha2 VARCHAR(2) DEFAULT NULL, iso_alpha3 VARCHAR(3) DEFAULT NULL, iso_numeric INT DEFAULT NULL, status TINYINT(1) DEFAULT 1 NOT NULL, foundation_year INT DEFAULT NULL, extinction_year INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE country_name_variants (id INT AUTO_INCREMENT NOT NULL, country_id INT NOT NULL, variation_name VARCHAR(150) NOT NULL, normalized_name VARCHAR(150) NOT NULL, variation_type VARCHAR(50) DEFAULT NULL, status TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_6CFC74EBF92F3E70 (country_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE educations (id INT AUTO_INCREMENT NOT NULL, researcher_id INT NOT NULL, level VARCHAR(50) NOT NULL, course_name VARCHAR(255) DEFAULT NULL, institution_name VARCHAR(255) DEFAULT NULL, start_year INT DEFAULT NULL, end_year INT DEFAULT NULL, monograph_title LONGTEXT DEFAULT NULL, advisor_name VARCHAR(255) DEFAULT NULL, INDEX IDX_730876ADC7533BDE (researcher_id), INDEX idx_edu_level (level), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE image (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(255) DEFAULT NULL, image_name VARCHAR(255) DEFAULT NULL, updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE institution_name_variants (id INT AUTO_INCREMENT NOT NULL, institution_id INT NOT NULL, variation_name VARCHAR(255) NOT NULL, normalized_name VARCHAR(255) NOT NULL, variation_type VARCHAR(50) DEFAULT NULL, status TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_21F4B6F210405986 (institution_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE institutions (id INT AUTO_INCREMENT NOT NULL, country_id INT DEFAULT NULL, state_id INT DEFAULT NULL, city_id INT DEFAULT NULL, official_name VARCHAR(255) NOT NULL, short_name VARCHAR(150) DEFAULT NULL, acronym VARCHAR(50) DEFAULT NULL, institution_type VARCHAR(100) DEFAULT NULL, legal_nature VARCHAR(100) DEFAULT NULL, corporate_name VARCHAR(255) DEFAULT NULL, tax_id VARCHAR(20) DEFAULT NULL, sponsor_code INT DEFAULT NULL, higher_education_code INT DEFAULT NULL, latitude VARCHAR(50) DEFAULT NULL, longitude VARCHAR(50) DEFAULT NULL, phone VARCHAR(100) DEFAULT NULL, headquarters_address VARCHAR(255) DEFAULT NULL, academic_organization VARCHAR(100) DEFAULT NULL, accreditation_type VARCHAR(150) DEFAULT NULL, category VARCHAR(100) DEFAULT NULL, administrative_category VARCHAR(100) DEFAULT NULL, institutional_concept VARCHAR(10) DEFAULT NULL, institutional_concept_year INT DEFAULT NULL, distance_learning_concept VARCHAR(10) DEFAULT NULL, distance_learning_concept_year INT DEFAULT NULL, general_course_index VARCHAR(10) DEFAULT NULL, general_course_index_year INT DEFAULT NULL, rector VARCHAR(150) DEFAULT NULL, legal_representative VARCHAR(150) DEFAULT NULL, active_regulations VARCHAR(255) DEFAULT NULL, higher_education_status VARCHAR(50) DEFAULT NULL, vantagepoint VARCHAR(255) DEFAULT NULL, official_website VARCHAR(255) DEFAULT NULL, institutional_email VARCHAR(150) DEFAULT NULL, status TINYINT(1) DEFAULT 1 NOT NULL, notes LONGTEXT DEFAULT NULL, foundation_year INT DEFAULT NULL, extinction_year INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_CB544664F92F3E70 (country_id), INDEX IDX_CB5446645D83CC1 (state_id), INDEX IDX_CB5446648BAC62AF (city_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE knowledge_areas (id INT AUTO_INCREMENT NOT NULL, researcher_id INT NOT NULL, major_area VARCHAR(150) DEFAULT NULL, area VARCHAR(150) DEFAULT NULL, sub_area VARCHAR(150) DEFAULT NULL, specialty VARCHAR(255) DEFAULT NULL, INDEX IDX_796B73A3C7533BDE (researcher_id), INDEX idx_karea_major (major_area), INDEX idx_karea_area (area), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE orientations (id INT AUTO_INCREMENT NOT NULL, researcher_id INT NOT NULL, orientation_type VARCHAR(50) NOT NULL, nature VARCHAR(50) NOT NULL, student_name VARCHAR(255) NOT NULL, title LONGTEXT DEFAULT NULL, year INT DEFAULT NULL, institution_name VARCHAR(255) DEFAULT NULL, course_name VARCHAR(255) DEFAULT NULL, INDEX IDX_9930BB0FC7533BDE (researcher_id), INDEX idx_orient_type (orientation_type), INDEX idx_orient_nature (nature), INDEX idx_orient_year (year), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE production_authors (id INT AUTO_INCREMENT NOT NULL, production_item_id INT NOT NULL, author_name VARCHAR(255) NOT NULL, citation_name VARCHAR(255) DEFAULT NULL, id_lattes VARCHAR(16) DEFAULT NULL, author_order INT DEFAULT NULL, INDEX IDX_9B19A78C77ADF83 (production_item_id), INDEX idx_prod_author_lattes (id_lattes), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE production_items (id INT AUTO_INCREMENT NOT NULL, researcher_id INT NOT NULL, item_type VARCHAR(50) NOT NULL, nature VARCHAR(100) DEFAULT NULL, title LONGTEXT NOT NULL, year INT DEFAULT NULL, doi VARCHAR(255) DEFAULT NULL, language VARCHAR(100) DEFAULT NULL, country VARCHAR(100) DEFAULT NULL, journal_name VARCHAR(255) DEFAULT NULL, issn VARCHAR(50) DEFAULT NULL, qualis VARCHAR(10) DEFAULT NULL, publisher VARCHAR(255) DEFAULT NULL, isbn VARCHAR(50) DEFAULT NULL, volume VARCHAR(50) DEFAULT NULL, issue VARCHAR(50) DEFAULT NULL, pages VARCHAR(50) DEFAULT NULL, event_name VARCHAR(255) DEFAULT NULL, event_city VARCHAR(150) DEFAULT NULL, is_scientific_dissemination TINYINT(1) DEFAULT 0 NOT NULL, order_sequence INT DEFAULT NULL, extra_data JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_FB1F2D7BC7533BDE (researcher_id), INDEX idx_prod_type (item_type), INDEX idx_prod_year (year), INDEX idx_prod_doi (doi), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE researchers (id INT AUTO_INCREMENT NOT NULL, id_lattes VARCHAR(16) NOT NULL, full_name VARCHAR(255) NOT NULL, citation_names LONGTEXT DEFAULT NULL, slug VARCHAR(255) NOT NULL, orcid VARCHAR(50) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, abstract_resume LONGTEXT DEFAULT NULL, department VARCHAR(150) DEFAULT NULL, department_code VARCHAR(50) DEFAULT NULL, unit VARCHAR(150) DEFAULT NULL, admission_year INT DEFAULT NULL, leave_year INT DEFAULT NULL, nationality VARCHAR(50) DEFAULT NULL, birth_country VARCHAR(100) DEFAULT NULL, birth_state VARCHAR(50) DEFAULT NULL, birth_city VARCHAR(100) DEFAULT NULL, photo_url VARCHAR(255) DEFAULT NULL, last_lattes_update DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', status TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_7A10CFF62AB2CDB1 (id_lattes), INDEX idx_researcher_lattes (id_lattes), INDEX idx_researcher_slug (slug), INDEX idx_researcher_dept (department), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE state_name_variants (id INT AUTO_INCREMENT NOT NULL, state_id INT NOT NULL, variation_name VARCHAR(150) NOT NULL, normalized_name VARCHAR(150) NOT NULL, variation_type VARCHAR(50) DEFAULT NULL, status TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_AC5FAAC35D83CC1 (state_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE states (id INT AUTO_INCREMENT NOT NULL, country_id INT DEFAULT NULL, name VARCHAR(150) NOT NULL, code VARCHAR(10) DEFAULT NULL, status TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_31C2774DF92F3E70 (country_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE super_test_fields (id INT AUTO_INCREMENT NOT NULL, choice_type_from_entity_id INT DEFAULT NULL, simple_input_text VARCHAR(255) DEFAULT NULL, edit_text_with_editor LONGTEXT NOT NULL, date_field DATE NOT NULL, date_and_time_field DATETIME NOT NULL, choice_type_from_list VARCHAR(255) NOT NULL, sin_nao_int INT NOT NULL, boolean_true_false TINYINT(1) NOT NULL, image VARCHAR(255) DEFAULT NULL, img_updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', select_enum VARCHAR(255) NOT NULL, email_field VARCHAR(255) NOT NULL, numero_simples INT NOT NULL, INDEX IDX_43206855B736F1D0 (choice_type_from_entity_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE test_database (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE thesaurus_concepts (id INT AUTO_INCREMENT NOT NULL, scheme_id INT NOT NULL, pref_label VARCHAR(255) NOT NULL, normalized_label VARCHAR(255) NOT NULL, notation VARCHAR(100) DEFAULT NULL, alt_labels JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_302F8F0F65797862 (scheme_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE thesaurus_schemes (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(100) NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, uri VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_88EF427C77153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_USERNAME (username), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', available_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', delivered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE author_external_identifiers ADD CONSTRAINT FK_72831BCDA3CFB7D0 FOREIGN KEY (author_identity_id) REFERENCES author_identities (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE author_name_variants ADD CONSTRAINT FK_AC1439C2A3CFB7D0 FOREIGN KEY (author_identity_id) REFERENCES author_identities (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE awards ADD CONSTRAINT FK_25EAE3FEC7533BDE FOREIGN KEY (researcher_id) REFERENCES researchers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cities ADD CONSTRAINT FK_D95DB16B5D83CC1 FOREIGN KEY (state_id) REFERENCES states (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE city_name_variants ADD CONSTRAINT FK_E2DEA4378BAC62AF FOREIGN KEY (city_id) REFERENCES cities (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE country_name_variants ADD CONSTRAINT FK_6CFC74EBF92F3E70 FOREIGN KEY (country_id) REFERENCES countries (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educations ADD CONSTRAINT FK_730876ADC7533BDE FOREIGN KEY (researcher_id) REFERENCES researchers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institution_name_variants ADD CONSTRAINT FK_21F4B6F210405986 FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE institutions ADD CONSTRAINT FK_CB544664F92F3E70 FOREIGN KEY (country_id) REFERENCES countries (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE institutions ADD CONSTRAINT FK_CB5446645D83CC1 FOREIGN KEY (state_id) REFERENCES states (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE institutions ADD CONSTRAINT FK_CB5446648BAC62AF FOREIGN KEY (city_id) REFERENCES cities (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE knowledge_areas ADD CONSTRAINT FK_796B73A3C7533BDE FOREIGN KEY (researcher_id) REFERENCES researchers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE orientations ADD CONSTRAINT FK_9930BB0FC7533BDE FOREIGN KEY (researcher_id) REFERENCES researchers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_authors ADD CONSTRAINT FK_9B19A78C77ADF83 FOREIGN KEY (production_item_id) REFERENCES production_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE production_items ADD CONSTRAINT FK_FB1F2D7BC7533BDE FOREIGN KEY (researcher_id) REFERENCES researchers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE state_name_variants ADD CONSTRAINT FK_AC5FAAC35D83CC1 FOREIGN KEY (state_id) REFERENCES states (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE states ADD CONSTRAINT FK_31C2774DF92F3E70 FOREIGN KEY (country_id) REFERENCES countries (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE super_test_fields ADD CONSTRAINT FK_43206855B736F1D0 FOREIGN KEY (choice_type_from_entity_id) REFERENCES test_database (id)');
        $this->addSql('ALTER TABLE thesaurus_concepts ADD CONSTRAINT FK_302F8F0F65797862 FOREIGN KEY (scheme_id) REFERENCES thesaurus_schemes (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE author_external_identifiers DROP FOREIGN KEY FK_72831BCDA3CFB7D0');
        $this->addSql('ALTER TABLE author_name_variants DROP FOREIGN KEY FK_AC1439C2A3CFB7D0');
        $this->addSql('ALTER TABLE awards DROP FOREIGN KEY FK_25EAE3FEC7533BDE');
        $this->addSql('ALTER TABLE cities DROP FOREIGN KEY FK_D95DB16B5D83CC1');
        $this->addSql('ALTER TABLE city_name_variants DROP FOREIGN KEY FK_E2DEA4378BAC62AF');
        $this->addSql('ALTER TABLE country_name_variants DROP FOREIGN KEY FK_6CFC74EBF92F3E70');
        $this->addSql('ALTER TABLE educations DROP FOREIGN KEY FK_730876ADC7533BDE');
        $this->addSql('ALTER TABLE institution_name_variants DROP FOREIGN KEY FK_21F4B6F210405986');
        $this->addSql('ALTER TABLE institutions DROP FOREIGN KEY FK_CB544664F92F3E70');
        $this->addSql('ALTER TABLE institutions DROP FOREIGN KEY FK_CB5446645D83CC1');
        $this->addSql('ALTER TABLE institutions DROP FOREIGN KEY FK_CB5446648BAC62AF');
        $this->addSql('ALTER TABLE knowledge_areas DROP FOREIGN KEY FK_796B73A3C7533BDE');
        $this->addSql('ALTER TABLE orientations DROP FOREIGN KEY FK_9930BB0FC7533BDE');
        $this->addSql('ALTER TABLE production_authors DROP FOREIGN KEY FK_9B19A78C77ADF83');
        $this->addSql('ALTER TABLE production_items DROP FOREIGN KEY FK_FB1F2D7BC7533BDE');
        $this->addSql('ALTER TABLE state_name_variants DROP FOREIGN KEY FK_AC5FAAC35D83CC1');
        $this->addSql('ALTER TABLE states DROP FOREIGN KEY FK_31C2774DF92F3E70');
        $this->addSql('ALTER TABLE super_test_fields DROP FOREIGN KEY FK_43206855B736F1D0');
        $this->addSql('ALTER TABLE thesaurus_concepts DROP FOREIGN KEY FK_302F8F0F65797862');
        $this->addSql('DROP TABLE author_external_identifiers');
        $this->addSql('DROP TABLE author_identities');
        $this->addSql('DROP TABLE author_name_variants');
        $this->addSql('DROP TABLE awards');
        $this->addSql('DROP TABLE cities');
        $this->addSql('DROP TABLE city_name_variants');
        $this->addSql('DROP TABLE countries');
        $this->addSql('DROP TABLE country_name_variants');
        $this->addSql('DROP TABLE educations');
        $this->addSql('DROP TABLE image');
        $this->addSql('DROP TABLE institution_name_variants');
        $this->addSql('DROP TABLE institutions');
        $this->addSql('DROP TABLE knowledge_areas');
        $this->addSql('DROP TABLE orientations');
        $this->addSql('DROP TABLE production_authors');
        $this->addSql('DROP TABLE production_items');
        $this->addSql('DROP TABLE researchers');
        $this->addSql('DROP TABLE state_name_variants');
        $this->addSql('DROP TABLE states');
        $this->addSql('DROP TABLE super_test_fields');
        $this->addSql('DROP TABLE test_database');
        $this->addSql('DROP TABLE thesaurus_concepts');
        $this->addSql('DROP TABLE thesaurus_schemes');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
