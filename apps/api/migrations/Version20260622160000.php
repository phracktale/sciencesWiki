<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vues MATÉRIALISÉES des statistiques du tableau de bord : au lieu de recalculer
 * une dizaine de count(*) à chaque affichage (plusieurs secondes), on précalcule
 * tout en agrégats `FILTER` (une seule passe) puis on rafraîchit en cron
 * (app:stats:refresh). Lecture du dashboard → quasi instantanée.
 *
 *  - dashboard_stats          : métriques globales (1 ligne)
 *  - dashboard_type_breakdown : répartition par type de publication
 *  - dashboard_domain_stats   : publications par domaine racine (sous-arbre)
 *
 * Créées WITH NO DATA (pas de calcul au démarrage) ; peuplées par le 1er refresh.
 */
final class Version20260622160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vues matérialisées des stats dashboard (dashboard_stats, _type_breakdown, _domain_stats).';
    }

    public function up(Schema $schema): void
    {
        // Tri par défaut de la liste d'articles (date desc) — évite un tri de 1,3 M lignes.
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pub_date ON publication (publication_date DESC NULLS LAST, id DESC)');

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

        $this->addSql("CREATE MATERIALIZED VIEW dashboard_type_breakdown AS
            SELECT COALESCE(NULLIF(type, ''), '(inconnu)') AS type, count(*) AS n
            FROM publication GROUP BY 1
            WITH NO DATA");
        $this->addSql('CREATE UNIQUE INDEX idx_dashboard_type_breakdown_type ON dashboard_type_breakdown (type)');

        $this->addSql("CREATE MATERIALIZED VIEW dashboard_domain_stats AS
            SELECT t.slug, t.label, (
                WITH RECURSIVE sub AS (
                    SELECT t.id AS id
                    UNION SELECT e.child_id FROM tree_edge e JOIN sub ON e.parent_id = sub.id
                ) SELECT count(DISTINCT ps.publication_id) FROM placement_suggestion ps WHERE ps.tree_node_id IN (SELECT id FROM sub)
            ) AS publications
            FROM tree_node t WHERE t.level = 0
            WITH NO DATA");
        $this->addSql('CREATE UNIQUE INDEX idx_dashboard_domain_stats_slug ON dashboard_domain_stats (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS dashboard_stats');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS dashboard_type_breakdown');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS dashboard_domain_stats');
    }
}
