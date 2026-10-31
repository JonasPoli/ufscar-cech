<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Docentes importados pelo HTML ficaram com `slug` vazio e sem página pública: o parser de HTML não gerava o slug.
 * O slug é derivado do nome, não é dado do Lattes.
 */
final class Version20261031041000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gera o slug dos docentes que estão com ele vazio';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative("SELECT id, full_name FROM researchers WHERE slug = '' OR slug IS NULL");
        foreach ($rows as $row) {
            $this->addSql('UPDATE researchers SET slug = ? WHERE id = ?', [StringNormalizer::slugify((string)$row['full_name']), (int)$row['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        // Nada a desfazer: o slug vazio deixava o docente sem página.
    }
}
