<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recherche plein-texte in-domaine performante à l'échelle (14 M+ publications).
 *
 * Table SÉPARÉE `publication_fts(publication_id, tsv)` + index GIN, plutôt qu'une
 * colonne tsvector sur `publication` : cette dernière a ~20 index → chaque UPDATE
 * (backfill) les met TOUS à jour (non-HOT), rendant le remplissage prohibitif. Une
 * table dédiée n'a qu'un index (sa PK) → remplissage par INSERT ~12× plus rapide, et
 * le tri ts_rank lit un tsvector STOCKÉ (plus de recalcul de to_tsvector → recherche
 * ~30 s → < 1 s). Maintenue par trigger sur publication (title/abstract).
 *
 * En PROD, tout a été appliqué + peuplé à la main sous maintenance ; ces instructions
 * IF NOT EXISTS / OR REPLACE sont idempotentes (no-op).
 */
final class Version20260701160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table publication_fts (tsvector stocké) + trigger + index GIN (recherche in-domaine).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS publication_fts (publication_id BIGINT PRIMARY KEY, tsv tsvector)');
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION publication_fts_sync() RETURNS trigger AS $fn$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                DELETE FROM publication_fts WHERE publication_id = OLD.id;
                RETURN OLD;
              END IF;
              INSERT INTO publication_fts (publication_id, tsv)
              VALUES (NEW.id, to_tsvector('english', coalesce(NEW.title,'') || ' ' || coalesce(NEW.abstract,'')))
              ON CONFLICT (publication_id) DO UPDATE SET tsv = EXCLUDED.tsv;
              RETURN NEW;
            END
            $fn$ LANGUAGE plpgsql
            SQL);
        $this->addSql('DROP TRIGGER IF EXISTS trg_publication_fts_upsert ON publication');
        $this->addSql('CREATE TRIGGER trg_publication_fts_upsert AFTER INSERT OR UPDATE OF title, abstract ON publication FOR EACH ROW EXECUTE FUNCTION publication_fts_sync()');
        $this->addSql('DROP TRIGGER IF EXISTS trg_publication_fts_delete ON publication');
        $this->addSql('CREATE TRIGGER trg_publication_fts_delete AFTER DELETE ON publication FOR EACH ROW EXECUTE FUNCTION publication_fts_sync()');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pfts_tsv ON publication_fts USING GIN (tsv)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_publication_fts_upsert ON publication');
        $this->addSql('DROP TRIGGER IF EXISTS trg_publication_fts_delete ON publication');
        $this->addSql('DROP FUNCTION IF EXISTS publication_fts_sync()');
        $this->addSql('DROP TABLE IF EXISTS publication_fts');
    }
}
