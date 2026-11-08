<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Orientações: programa informado pelo Repositório UFSCar numa coluna própria.
 *
 * O enriquecimento pelo Repositório deixava de respeitar a regra de preservação do Lattes: trocava `nature` para
 * CONCLUIDA e preenchia `course_name`. Agora o programa vai para `repository_program_name`, e a orientação com Handle
 * ou UUID do Repositório conta como concluída na exibição e nos indicadores (Orientation::isConcluded), sem alterar
 * `nature`. Os valores já gravados voltam a ser os do Lattes na próxima reimportação dos currículos.
 */
final class Version20261108030454 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Orientações: repository_program_name (programa vindo do Repositório UFSCar)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orientations ADD repository_program_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orientations DROP repository_program_name');
    }
}
