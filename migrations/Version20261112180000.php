<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Zera o drift do esquema em `qualis_journal_academic_database` e remove a fila do Messenger.
 *
 * A migration Version20260830015144 criou as FKs com nomes manuais (FK_QJ_JOURNAL e FK_QJ_DATABASE); o Doctrine espera
 * FK_4A2CE54B47DF50A9 (qualis_journal_id) e FK_4A2CE54B3C409EFE (academic_database_id), ambas com ON DELETE CASCADE.
 * Em bancos onde a FK com o nome do Doctrine já existe (duplicada), apenas a manual é removida; onde só existe a
 * manual, ela é trocada pela do Doctrine. A regra de remoção em cascata é a mesma.
 *
 * Também elimina `messenger_messages` (fila do Doctrine Messenger, vazia e sem uso: o pacote saiu do projeto).
 */
final class Version20261112180000 extends AbstractMigration
{
    private const TABELA = 'qualis_journal_academic_database';

    public function getDescription(): string
    {
        return 'Renomeia as FKs de qualis_journal_academic_database para os nomes do Doctrine e remove messenger_messages';
    }

    public function up(Schema $schema): void
    {
        $this->trocarFk('FK_QJ_JOURNAL', 'FK_4A2CE54B47DF50A9', 'qualis_journal_id', 'qualis_journals');
        $this->trocarFk('FK_QJ_DATABASE', 'FK_4A2CE54B3C409EFE', 'academic_database_id', 'academic_database');
        $this->addSql('DROP TABLE messenger_messages');
    }

    public function down(Schema $schema): void
    {
        $this->trocarFk('FK_4A2CE54B47DF50A9', 'FK_QJ_JOURNAL', 'qualis_journal_id', 'qualis_journals');
        $this->trocarFk('FK_4A2CE54B3C409EFE', 'FK_QJ_DATABASE', 'academic_database_id', 'academic_database');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', available_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', delivered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    /** Troca a FK de nome (ou só remove a antiga se a nova já existir na coluna). */
    private function trocarFk(string $de, string $para, string $coluna, string $tabelaRef): void
    {
        if ($this->fkExiste($de)) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', self::TABELA, $de));
        }
        if (!$this->fkExiste($para) && !$this->colunaTemFk($coluna, $de)) {
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (id) ON DELETE CASCADE',
                self::TABELA,
                $para,
                $coluna,
                $tabelaRef
            ));
        }
    }

    private function fkExiste(string $nome): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = \'FOREIGN KEY\' AND CONSTRAINT_NAME = ?',
            [self::TABELA, $nome]
        ) > 0;
    }

    /** True se a coluna ainda tem alguma FK, exceto a de nome $ignorar (que está sendo removida). */
    private function colunaTemFk(string $coluna, string $ignorar): bool
    {
        // A FK antiga já foi enfileirada para remoção; só conta a que fica fora dela.
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL AND CONSTRAINT_NAME <> ?',
            [self::TABELA, $coluna, $ignorar]
        ) > 0;
    }
}
