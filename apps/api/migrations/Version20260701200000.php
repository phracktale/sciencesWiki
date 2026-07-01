<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Étend la vue matérialisée `dashboard_stats` avec `placed_publications` (pubs
 * distinctes placées) et `last_update` (max updated_at), pour que la page d'accueil
 * lise TOUS ses chiffres depuis la vue — plus aucun count(DISTINCT) live (qui, sur
 * publication_chunk/placement_suggestion, provoquait des pics de latence, surtout
 * pendant la moisson qui écrit dans publication_chunk).
 *
 * Ajoute aussi un index sur publication(created_at) pour la liste « derniers papiers
 * moissonnés » (ORDER BY created_at DESC LIMIT 5) — sinon seq scan de 14 M lignes.
 *
 * La vue doit être recréée (pas de CREATE OR REPLACE MATERIALIZED VIEW en PG) :
 * DROP + CREATE. Recréée WITH NO DATA ; peuplée juste après par app:stats:refresh.
 */
final class Version20260701200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'dashboard_stats : + placed_publications + last_update ; index publication(created_at).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pub_created ON publication (created_at DESC)');

        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS dashboard_stats');
        $this->addSql("CREATE MATERIALIZED VIEW dashboard_stats AS
            SELECT
                1 AS id,
                (SELECT count(*) FROM publication) AS publications,
                count(*) FILTER (WHERE oa_status NOT IN ('closed','unknown')) AS free_full,
                count(*) FILTER (WHERE oa_status = 'closed') AS paywalled,
                count(*) FILTER (WHERE embedding IS NOT NULL) AS embedding_total,
                count(*) FILTER (WHERE fulltext_source = 'grobid_self') AS fulltext_grobid,
                count(*) FILTER (WHERE fulltext_fetched_at IS NOT NULL AND oa_url IS NOT NULL AND oa_url <> ''
                    AND NOT EXISTS (SELECT 1 FROM publication_chunk pc WHERE pc.publication_id = publication.id)) AS fulltext_retryable,
                (SELECT count(DISTINCT publication_id) FROM publication_chunk) AS pdf_consultables,
                (SELECT count(DISTINCT publication_id) FROM placement_suggestion) AS placed_publications,
                (SELECT max(updated_at) FROM publication) AS last_update,
                (SELECT count(*) FROM answer WHERE validation_status = 'valide') AS answers_validated,
                (SELECT count(*) FROM answer WHERE validation_status = 'non_relu') AS answers_ai,
                (SELECT count(*) FROM question WHERE origin = 'suggeree_ia') AS questions_ai,
                (SELECT count(*) FROM question WHERE origin = 'libre_utilisateur') AS questions_human,
                (SELECT count(*) FROM author) AS authors,
                (SELECT count(*) FROM publisher) AS publishers,
                (SELECT count(*) FROM journal) AS journals
            FROM publication
            WITH NO DATA");
        $this->addSql('CREATE UNIQUE INDEX idx_dashboard_stats_id ON dashboard_stats (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS dashboard_stats');
        $this->addSql("CREATE MATERIALIZED VIEW dashboard_stats AS
            SELECT
                1 AS id,
                (SELECT count(*) FROM publication) AS publications,
                count(*) FILTER (WHERE oa_status NOT IN ('closed','unknown')) AS free_full,
                count(*) FILTER (WHERE oa_status = 'closed') AS paywalled,
                count(*) FILTER (WHERE embedding IS NOT NULL) AS embedding_total,
                count(*) FILTER (WHERE fulltext_source = 'grobid_self') AS fulltext_grobid,
                count(*) FILTER (WHERE fulltext_fetched_at IS NOT NULL AND oa_url IS NOT NULL AND oa_url <> ''
                    AND NOT EXISTS (SELECT 1 FROM publication_chunk pc WHERE pc.publication_id = publication.id)) AS fulltext_retryable,
                (SELECT count(DISTINCT publication_id) FROM publication_chunk) AS pdf_consultables,
                (SELECT count(*) FROM answer WHERE validation_status = 'valide') AS answers_validated,
                (SELECT count(*) FROM answer WHERE validation_status = 'non_relu') AS answers_ai,
                (SELECT count(*) FROM question WHERE origin = 'suggeree_ia') AS questions_ai,
                (SELECT count(*) FROM question WHERE origin = 'libre_utilisateur') AS questions_human,
                (SELECT count(*) FROM author) AS authors,
                (SELECT count(*) FROM publisher) AS publishers,
                (SELECT count(*) FROM journal) AS journals
            FROM publication
            WITH NO DATA");
        $this->addSql('CREATE UNIQUE INDEX idx_dashboard_stats_id ON dashboard_stats (id)');
        $this->addSql('DROP INDEX IF EXISTS idx_pub_created');
    }
}
