<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pesquisa pública: chave da obra e palavras-chave calculadas pelo banco, e índices da coautoria.
 *
 * Cada consulta da pesquisa (busca, panorama, rede, explorar) recalculava, linha a linha, o MD5 que identifica a obra
 * única e extraía as palavras-chave do JSON `extra_data`. As duas contas passam a ser colunas geradas (STORED): o
 * MySQL as mantém sozinho a cada INSERT/UPDATE, e o dado bruto do Lattes continua intacto. A fórmula é a mesma de
 * App\Service\Research\ResearchSql (workKey e keywords).
 *
 * A autojunção de `production_authors` (pares de coautoria) ganha dois índices compostos que a cobrem.
 */
final class Version20261112150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'production_items: work_key e keywords_text (colunas geradas); production_authors: índices da coautoria';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE production_items
              ADD work_key CHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci GENERATED ALWAYS AS (
                MD5(CASE WHEN (doi IS NOT NULL AND TRIM(doi) != '')
                    THEN CONCAT('DOI:', LOWER(TRIM(doi)))
                    ELSE CONCAT('T:', item_type, ':', COALESCE(`year`, ''), ':', LOWER(TRIM(title))) END)
              ) STORED,
              ADD keywords_text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (
                COALESCE(CAST(JSON_EXTRACT(extra_data, '$.keywords') AS CHAR CHARACTER SET utf8mb4), '')
              ) STORED
            SQL);
        $this->addSql('CREATE INDEX idx_prod_work_key ON production_items (work_key)');
        $this->addSql('CREATE INDEX idx_prod_researcher_work ON production_items (researcher_id, work_key)');
        $this->addSql('CREATE INDEX idx_pa_item_matched ON production_authors (production_item_id, matched_researcher_id)');
        $this->addSql('CREATE INDEX idx_pa_matched_item ON production_authors (matched_researcher_id, production_item_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_pa_matched_item ON production_authors');
        $this->addSql('DROP INDEX idx_pa_item_matched ON production_authors');
        $this->addSql('DROP INDEX idx_prod_researcher_work ON production_items');
        $this->addSql('DROP INDEX idx_prod_work_key ON production_items');
        $this->addSql('ALTER TABLE production_items DROP work_key, DROP keywords_text');
    }
}
