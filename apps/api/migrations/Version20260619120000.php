<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index plein-texte (GIN) pour la recherche d'articles avec/sans stemming.
 * Les expressions doivent être IDENTIQUES à celles utilisées dans la requête
 * (PublicationRepository::searchInSubtree), sinon l'index n'est pas utilisé.
 */
final class Version20260619120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index GIN FTS sur titre+résumé (english + simple).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_pub_fts_en ON publication USING GIN (to_tsvector('english', coalesce(title,'') || ' ' || coalesce(abstract,'')))");
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_pub_fts_simple ON publication USING GIN (to_tsvector('simple', coalesce(title,'') || ' ' || coalesce(abstract,'')))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_pub_fts_en');
        $this->addSql('DROP INDEX IF EXISTS idx_pub_fts_simple');
    }
}
