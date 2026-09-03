<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Normaliza o campo nature de orientações:
 * 1. Promove para CONCLUIDA todas as orientações que possuem registro vinculado no Repositório Institucional UFSCar (handle IS NOT NULL).
 * 2. Corrige orientações concluídas históricas de docentes que foram importadas equivocadamente como EM_ANDAMENTO via parser HTML.
 */
final class Version20260903151141 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normaliza status nature de orientações com handle no repositório ou concluídas historicamente';
    }

    public function up(Schema $schema): void
    {
        // 1. Obras com link/handle no repositório institucional são defesas concluídas e homologadas
        $this->addSql("UPDATE orientations SET nature = 'CONCLUIDA' WHERE handle IS NOT NULL AND nature = 'EM_ANDAMENTO'");

        // 2. Orientações históricas concluídas que foram marcadas como EM_ANDAMENTO pelo parser HTML
        $this->addSql("UPDATE orientations SET nature = 'CONCLUIDA' WHERE nature = 'EM_ANDAMENTO' AND researcher_id IN (287, 415) AND title NOT LIKE '%Início%' AND title NOT LIKE '%Inicio%'");
    }

    public function down(Schema $schema): void
    {
        // Operação de normalização de dados
    }
}
