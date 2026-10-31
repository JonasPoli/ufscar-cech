<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FLAG-DEDICACAO-EXCLUSIVA do Lattes em coluna própria. Até aqui o importador acrescentava o texto " | Dedicação Exclusiva (DE)"
 * dentro de `other_info`, que deve guardar o dado como veio. Linhas antigas continuam com o texto até o currículo ser reimportado.
 */
final class Version20261031040500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Acrescenta professional_experiences.is_exclusive_dedication';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE professional_experiences ADD is_exclusive_dedication TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE professional_experiences DROP is_exclusive_dedication');
    }
}
