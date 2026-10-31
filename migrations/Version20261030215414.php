<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Periódicos por base de indexação: taxonomia CAPES e vínculo artigo-periódico (tarefa T01).
 *
 * Gerada por make:migration e complementada à mão:
 *  - aborta antes de qualquer alteração se journal_name_variants tiver normalized_name duplicado,
 *    porque o índice único novo (uniq_journal_variant_norm) não poderia ser criado;
 *  - semeia capes_areas com as 59 áreas do Apêndice A, com ids fixos;
 *  - copia qualis_journals.area para a coluna nova area_raw (valor original, antes da classificação).
 *
 * O índice comum idx_journal_var_norm_name é trocado pelo único no mesmo ALTER TABLE: o Doctrine
 * considera o comum redundante quando existe um único sobre a mesma coluna.
 * production_items.journal_name (dado bruto do Lattes) não é alterado; só ganha a coluna derivada
 * journal_name_normalized.
 */
final class Version20261030215414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria capes_areas (com seed), journal_areas, journal_area_evidence e journal_name_candidates; '
            . 'acrescenta família, nome exibido e área original em qualis_journals, journal_name_normalized em '
            . 'production_items e índice único em journal_name_variants';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM (SELECT normalized_name FROM journal_name_variants GROUP BY normalized_name HAVING COUNT(*) > 1) d'
            ) > 0,
            'Há normalized_name duplicado em journal_name_variants; mescle antes (lista: SELECT normalized_name, COUNT(*) FROM journal_name_variants GROUP BY 1 HAVING COUNT(*)>1).'
        );

        $this->addSql('CREATE TABLE capes_areas (id SMALLINT UNSIGNED NOT NULL, parent_id SMALLINT UNSIGNED DEFAULT NULL, level SMALLINT UNSIGNED NOT NULL, slug VARCHAR(80) NOT NULL, name VARCHAR(150) NOT NULL, college VARCHAR(80) NOT NULL, sort_order SMALLINT UNSIGNED NOT NULL, INDEX idx_capes_area_parent (parent_id), UNIQUE INDEX uniq_capes_area_slug (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Seed da taxonomia CAPES (Apêndice A do desenho): ids fixos, iguais em todos os ambientes.
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (100, NULL, 1, 'ciencias-agrarias', 'Ciências Agrárias', 'Ciências da Vida', 100)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (101, 100, 2, 'ciencia-de-alimentos', 'Ciência de Alimentos', 'Ciências da Vida', 101)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (102, 100, 2, 'ciencias-agrarias-i', 'Ciências Agrárias I', 'Ciências da Vida', 102)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (103, 100, 2, 'medicina-veterinaria', 'Medicina Veterinária', 'Ciências da Vida', 103)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (104, 100, 2, 'zootecnia-recursos-pesqueiros', 'Zootecnia / Recursos Pesqueiros', 'Ciências da Vida', 104)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (200, NULL, 1, 'ciencias-biologicas', 'Ciências Biológicas', 'Ciências da Vida', 200)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (201, 200, 2, 'biodiversidade', 'Biodiversidade', 'Ciências da Vida', 201)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (202, 200, 2, 'ciencias-biologicas-i', 'Ciências Biológicas I', 'Ciências da Vida', 202)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (203, 200, 2, 'ciencias-biologicas-ii', 'Ciências Biológicas II', 'Ciências da Vida', 203)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (204, 200, 2, 'ciencias-biologicas-iii', 'Ciências Biológicas III', 'Ciências da Vida', 204)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (300, NULL, 1, 'ciencias-da-saude', 'Ciências da Saúde', 'Ciências da Vida', 300)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (301, 300, 2, 'educacao-fisica', 'Educação Física, Fisioterapia, Fonoaudiologia e Terapia Ocupacional', 'Ciências da Vida', 301)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (302, 300, 2, 'enfermagem', 'Enfermagem', 'Ciências da Vida', 302)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (303, 300, 2, 'farmacia', 'Farmácia', 'Ciências da Vida', 303)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (304, 300, 2, 'medicina-i', 'Medicina I', 'Ciências da Vida', 304)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (305, 300, 2, 'medicina-ii', 'Medicina II', 'Ciências da Vida', 305)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (306, 300, 2, 'medicina-iii', 'Medicina III', 'Ciências da Vida', 306)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (307, 300, 2, 'nutricao', 'Nutrição', 'Ciências da Vida', 307)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (308, 300, 2, 'odontologia', 'Odontologia', 'Ciências da Vida', 308)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (309, 300, 2, 'saude-coletiva', 'Saúde Coletiva', 'Ciências da Vida', 309)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (400, NULL, 1, 'ciencias-humanas', 'Ciências Humanas', 'Humanidades', 400)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (401, 400, 2, 'antropologia-arqueologia', 'Antropologia / Arqueologia', 'Humanidades', 401)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (402, 400, 2, 'ciencia-politica-relacoes-internacionais', 'Ciência Política e Relações Internacionais', 'Humanidades', 402)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (403, 400, 2, 'ciencias-da-religiao-teologia', 'Ciências da Religião e Teologia', 'Humanidades', 403)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (404, 400, 2, 'educacao', 'Educação', 'Humanidades', 404)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (405, 400, 2, 'filosofia', 'Filosofia', 'Humanidades', 405)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (406, 400, 2, 'geografia', 'Geografia', 'Humanidades', 406)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (407, 400, 2, 'historia', 'História', 'Humanidades', 407)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (408, 400, 2, 'psicologia', 'Psicologia', 'Humanidades', 408)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (409, 400, 2, 'sociologia', 'Sociologia', 'Humanidades', 409)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (500, NULL, 1, 'ciencias-sociais-aplicadas', 'Ciências Sociais Aplicadas', 'Humanidades', 500)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (501, 500, 2, 'administracao-contabeis-turismo', 'Administração Pública e de Empresas, Ciências Contábeis e Turismo', 'Humanidades', 501)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (502, 500, 2, 'arquitetura-urbanismo-design', 'Arquitetura, Urbanismo e Design', 'Humanidades', 502)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (503, 500, 2, 'comunicacao-informacao-museologia', 'Comunicação, Informação e Museologia', 'Humanidades', 503)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (504, 500, 2, 'direito', 'Direito', 'Humanidades', 504)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (505, 500, 2, 'economia', 'Economia', 'Humanidades', 505)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (506, 500, 2, 'planejamento-urbano-regional-demografia', 'Planejamento Urbano e Regional / Demografia', 'Humanidades', 506)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (507, 500, 2, 'servico-social', 'Serviço Social', 'Humanidades', 507)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (600, NULL, 1, 'linguistica-letras-artes', 'Linguística, Letras e Artes', 'Humanidades', 600)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (601, 600, 2, 'artes', 'Artes', 'Humanidades', 601)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (602, 600, 2, 'linguistica-literatura', 'Linguística e Literatura', 'Humanidades', 602)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (700, NULL, 1, 'ciencias-exatas-terra', 'Ciências Exatas e da Terra', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 700)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (701, 700, 2, 'astronomia-fisica', 'Astronomia / Física', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 701)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (702, 700, 2, 'computacao', 'Computação', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 702)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (703, 700, 2, 'geociencias', 'Geociências', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 703)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (704, 700, 2, 'matematica-estatistica', 'Matemática / Probabilidade e Estatística', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 704)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (705, 700, 2, 'quimica', 'Química', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 705)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (800, NULL, 1, 'engenharias', 'Engenharias', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 800)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (801, 800, 2, 'engenharias-i', 'Engenharias I', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 801)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (802, 800, 2, 'engenharias-ii', 'Engenharias II', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 802)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (803, 800, 2, 'engenharias-iii', 'Engenharias III', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 803)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (804, 800, 2, 'engenharias-iv', 'Engenharias IV', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 804)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (900, NULL, 1, 'multidisciplinar', 'Multidisciplinar', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 900)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (901, 900, 2, 'biotecnologia', 'Biotecnologia', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 901)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (902, 900, 2, 'ciencias-ambientais', 'Ciências Ambientais', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 902)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (903, 900, 2, 'ciencias-humanidades-educacao-basica', 'Ciências e Humanidades para a Educação Básica', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 903)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (904, 900, 2, 'ensino', 'Ensino', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 904)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (905, 900, 2, 'interdisciplinar', 'Interdisciplinar', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 905)");
        $this->addSql("INSERT INTO capes_areas (id, parent_id, level, slug, name, college, sort_order) VALUES (906, 900, 2, 'materiais', 'Materiais', 'Ciências Exatas, Tecnológicas e Multidisciplinar', 906)");

        $this->addSql('CREATE TABLE journal_area_evidence (id INT AUTO_INCREMENT NOT NULL, journal_id INT NOT NULL, source VARCHAR(20) NOT NULL, record_key VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, weight NUMERIC(6, 4) DEFAULT \'1.0000\' NOT NULL, source_title VARCHAR(500) DEFAULT NULL, batch VARCHAR(100) DEFAULT NULL, payload JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_jae_journal (journal_id), INDEX idx_jae_source_batch (source, batch), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE journal_areas (id INT AUTO_INCREMENT NOT NULL, journal_id INT NOT NULL, area_id SMALLINT UNSIGNED NOT NULL, is_primary TINYINT(1) DEFAULT 0 NOT NULL, score NUMERIC(5, 4) DEFAULT \'0.0000\' NOT NULL, confidence VARCHAR(10) NOT NULL, method VARCHAR(20) NOT NULL, run_id VARCHAR(40) DEFAULT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_BB6B8176478E8802 (journal_id), INDEX IDX_BB6B8176BD0F409C (area_id), INDEX idx_journal_area_area (area_id, is_primary), UNIQUE INDEX uniq_journal_area (journal_id, area_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE journal_name_candidates (id INT AUTO_INCREMENT NOT NULL, suggested_journal_id INT DEFAULT NULL, resolved_journal_id INT DEFAULT NULL, normalized_name VARCHAR(500) NOT NULL, raw_name VARCHAR(500) NOT NULL, issn_raw VARCHAR(50) DEFAULT NULL, articles_count INT DEFAULT 0 NOT NULL, researchers_count INT DEFAULT 0 NOT NULL, suggestion_method VARCHAR(20) DEFAULT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, sample_titles JSON DEFAULT NULL, first_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', reviewed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', reviewed_by VARCHAR(100) DEFAULT NULL, INDEX IDX_D4259140AC586334 (suggested_journal_id), INDEX IDX_D4259140376A5B0D (resolved_journal_id), INDEX idx_jnc_status (status, articles_count), UNIQUE INDEX uniq_jnc_norm (normalized_name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE capes_areas ADD CONSTRAINT FK_4AF67644727ACA70 FOREIGN KEY (parent_id) REFERENCES capes_areas (id)');
        $this->addSql('ALTER TABLE journal_area_evidence ADD CONSTRAINT FK_29DE996A478E8802 FOREIGN KEY (journal_id) REFERENCES qualis_journals (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE journal_areas ADD CONSTRAINT FK_BB6B8176478E8802 FOREIGN KEY (journal_id) REFERENCES qualis_journals (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE journal_areas ADD CONSTRAINT FK_BB6B8176BD0F409C FOREIGN KEY (area_id) REFERENCES capes_areas (id)');
        $this->addSql('ALTER TABLE journal_name_candidates ADD CONSTRAINT FK_D4259140AC586334 FOREIGN KEY (suggested_journal_id) REFERENCES qualis_journals (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE journal_name_candidates ADD CONSTRAINT FK_D4259140376A5B0D FOREIGN KEY (resolved_journal_id) REFERENCES qualis_journals (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE journal_name_variants DROP INDEX idx_journal_var_norm_name, ADD UNIQUE INDEX uniq_journal_variant_norm (normalized_name)');
        $this->addSql('ALTER TABLE production_items ADD journal_name_normalized VARCHAR(500) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_prod_journal_name_norm ON production_items (journal_name_normalized)');
        $this->addSql('ALTER TABLE qualis_journals ADD canonical_journal_id INT DEFAULT NULL, ADD area_raw LONGTEXT DEFAULT NULL, ADD normalized_title VARCHAR(500) DEFAULT NULL, ADD canonical_method VARCHAR(20) DEFAULT NULL, ADD display_title VARCHAR(500) DEFAULT NULL, ADD display_title_source VARCHAR(30) DEFAULT NULL, ADD areas_locked TINYINT(1) DEFAULT 0 NOT NULL');

        // Preserva o valor original de `area` antes de qualquer classificação (a coluna `area` não é alterada aqui).
        $this->addSql("UPDATE qualis_journals SET area_raw = area WHERE area IS NOT NULL AND area <> ''");

        $this->addSql('ALTER TABLE qualis_journals ADD CONSTRAINT FK_15096FF014DBEAAA FOREIGN KEY (canonical_journal_id) REFERENCES qualis_journals (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_journal_norm_title ON qualis_journals (normalized_title)');
        $this->addSql('CREATE INDEX idx_journal_canonical ON qualis_journals (canonical_journal_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE capes_areas DROP FOREIGN KEY FK_4AF67644727ACA70');
        $this->addSql('ALTER TABLE journal_area_evidence DROP FOREIGN KEY FK_29DE996A478E8802');
        $this->addSql('ALTER TABLE journal_areas DROP FOREIGN KEY FK_BB6B8176478E8802');
        $this->addSql('ALTER TABLE journal_areas DROP FOREIGN KEY FK_BB6B8176BD0F409C');
        $this->addSql('ALTER TABLE journal_name_candidates DROP FOREIGN KEY FK_D4259140AC586334');
        $this->addSql('ALTER TABLE journal_name_candidates DROP FOREIGN KEY FK_D4259140376A5B0D');
        $this->addSql('DROP TABLE capes_areas');
        $this->addSql('DROP TABLE journal_area_evidence');
        $this->addSql('DROP TABLE journal_areas');
        $this->addSql('DROP TABLE journal_name_candidates');
        $this->addSql('ALTER TABLE journal_name_variants DROP INDEX uniq_journal_variant_norm, ADD INDEX idx_journal_var_norm_name (normalized_name)');
        $this->addSql('DROP INDEX idx_prod_journal_name_norm ON production_items');
        $this->addSql('ALTER TABLE production_items DROP journal_name_normalized');
        $this->addSql('ALTER TABLE qualis_journals DROP FOREIGN KEY FK_15096FF014DBEAAA');
        $this->addSql('DROP INDEX idx_journal_norm_title ON qualis_journals');
        $this->addSql('DROP INDEX idx_journal_canonical ON qualis_journals');

        // `area_raw` guarda o texto original: devolve-o a `area` antes de a coluna ser removida.
        $this->addSql("UPDATE qualis_journals SET area = area_raw WHERE area_raw IS NOT NULL AND area_raw <> ''");
        $this->addSql('ALTER TABLE qualis_journals DROP canonical_journal_id, DROP area_raw, DROP normalized_title, DROP canonical_method, DROP display_title, DROP display_title_source, DROP areas_locked');
    }
}
