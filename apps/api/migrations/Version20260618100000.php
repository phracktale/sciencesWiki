<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Journal d'audit : historique des moissons, questions (humain/IA), réponses,
 * modifications de l'admin, actions utilisateurs, réglages, etc.
 */
final class Version20260618100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table activity_log (journal d\'audit transversal).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE activity_log (
                id BIGSERIAL PRIMARY KEY,
                occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                category VARCHAR(32) NOT NULL,
                action VARCHAR(64) NOT NULL,
                actor VARCHAR(255) NOT NULL DEFAULT 'system',
                summary TEXT DEFAULT NULL,
                context JSON DEFAULT NULL,
                ip VARCHAR(45) DEFAULT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_activity_occurred ON activity_log (occurred_at)');
        $this->addSql('CREATE INDEX idx_activity_category ON activity_log (category)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE activity_log');
    }
}
