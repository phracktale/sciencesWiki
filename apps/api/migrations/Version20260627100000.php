<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index sur author.name : la dédup d'auteur à l'import cherche par nom quand il
 * n'y a pas d'ORCID (≈43 % des auteurs OpenAlex). Sans index, chaque résolution
 * faisait un SEQ SCAN de la table author (5 M+ lignes) → ingestion du snapshot
 * de plus en plus lente. L'index rend la résolution instantanée.
 *
 * NB : déjà créé en prod via CREATE INDEX CONCURRENTLY (sans interrompre
 * l'ingestion) ; ce IF NOT EXISTS le rend idempotent au boot.
 */
final class Version20260627100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index author.name (dédup d’auteur sans ORCID — évite le seq scan à l’import).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_author_name ON author (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_author_name');
    }
}
