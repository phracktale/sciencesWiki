<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vue matérialisée `node_subtree_counts` : nombre de publications (distinctes) et de
 * questions par nœud, comptés sur le nœud ET tout son sous-arbre. Sert les pastilles
 * de l'explorateur (children-stats) et le corpus par rubrique en lecture INSTANTANÉE,
 * au lieu de count(DISTINCT) live sur placement_suggestion (~1-3 s par gros domaine).
 * Rafraîchie par cron (app:stats:refresh, CONCURRENTLY — d'où l'index unique).
 *
 * En PROD, créée + peuplée à la main (~35 s) ; IF NOT EXISTS = idempotent (no-op).
 */
final class Version20260701180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vue matérialisée node_subtree_counts (pastilles explorateur, comptes par sous-arbre).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE MATERIALIZED VIEW IF NOT EXISTS node_subtree_counts AS
            WITH RECURSIVE closure AS (
              SELECT id AS ancestor_id, id AS descendant_id FROM tree_node
              UNION
              SELECT c.ancestor_id, e.child_id FROM closure c JOIN tree_edge e ON e.parent_id = c.descendant_id
            ),
            pub AS (
              SELECT cl.ancestor_id AS node_id, count(DISTINCT ps.publication_id) AS publications
              FROM closure cl JOIN placement_suggestion ps ON ps.tree_node_id = cl.descendant_id
              GROUP BY cl.ancestor_id
            ),
            que AS (
              SELECT cl.ancestor_id AS node_id, count(*) AS questions
              FROM closure cl JOIN question q ON q.tree_node_id = cl.descendant_id
              GROUP BY cl.ancestor_id
            )
            SELECT tn.id AS node_id,
                   COALESCE(pub.publications, 0) AS publications,
                   COALESCE(que.questions, 0) AS questions
            FROM tree_node tn
            LEFT JOIN pub ON pub.node_id = tn.id
            LEFT JOIN que ON que.node_id = tn.id
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS idx_nsc_node ON node_subtree_counts (node_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS node_subtree_counts');
    }
}
