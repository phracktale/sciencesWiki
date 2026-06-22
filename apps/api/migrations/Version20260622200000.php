<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Versionnage des articles wiki : chaque génération IA ou édition humaine crée une
 * révision (contenu complet + auteur + type + résumé + date). Permet l'historique
 * et le diff en back-office. Les articles existants reçoivent une révision initiale.
 */
final class Version20260622200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'node_article_revision (historique + diff des articles wiki) + backfill.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE node_article_revision (
            id SERIAL PRIMARY KEY,
            tree_node_id INT NOT NULL,
            content_md TEXT NOT NULL,
            author_type VARCHAR(20) NOT NULL DEFAULT \'ia\',
            author_id INT DEFAULT NULL,
            author_label VARCHAR(255) DEFAULT NULL,
            change_summary VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');
        $this->addSql('CREATE INDEX idx_nar_node ON node_article_revision (tree_node_id, created_at DESC)');
        $this->addSql('ALTER TABLE node_article_revision ADD CONSTRAINT fk_nar_node FOREIGN KEY (tree_node_id) REFERENCES tree_node (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE node_article_revision ADD CONSTRAINT fk_nar_author FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE SET NULL');

        // Révision initiale pour les articles déjà rédigés (paternité IA).
        $this->addSql("INSERT INTO node_article_revision (tree_node_id, content_md, author_type, author_label, change_summary, created_at)
            SELECT id, article_md, 'ia', article_model, 'Version initiale (générée par IA)', COALESCE(article_generated_at, CURRENT_TIMESTAMP)
            FROM tree_node WHERE article_md IS NOT NULL AND article_md <> ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE node_article_revision');
    }
}
