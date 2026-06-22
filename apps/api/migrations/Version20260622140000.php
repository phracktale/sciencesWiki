<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dénormalise le nombre de publications par auteur (author.publication_count) :
 * le tri « auteurs les plus prolifiques » faisait une sous-requête corrélée sur
 * les 3,2 M auteurs avant la pagination (≈ 5 s). Colonne indexée, recomptée par
 * cron (app:authors:recount).
 */
final class Version20260622140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'author.publication_count (dénormalisé, indexé) pour un tri/affichage rapides.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE author ADD publication_count INT NOT NULL DEFAULT 0');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_author_pubcount ON author (publication_count DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_author_pubcount');
        $this->addSql('ALTER TABLE author DROP publication_count');
    }
}
