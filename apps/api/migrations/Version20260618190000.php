<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Demandes « Nous rejoindre » (comité scientifique / auteur / rédacteur).
 */
final class Version20260618190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table join_request (demandes de participation).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE join_request (
                id SERIAL PRIMARY KEY,
                type VARCHAR(16) NOT NULL,
                last_name VARCHAR(120) NOT NULL,
                first_name VARCHAR(120) NOT NULL,
                email VARCHAR(180) DEFAULT NULL,
                profile VARCHAR(32) DEFAULT NULL,
                orcid VARCHAR(32) DEFAULT NULL,
                profession VARCHAR(180) DEFAULT NULL,
                message TEXT DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                ip VARCHAR(45) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_join_status ON join_request (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE join_request');
    }
}
