<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Article encyclopédique (long, Markdown) par nœud de l'arbre + paternité
 * (comme les réponses : statut IA / relu-humain, modèle générateur, dates).
 */
final class Version20260622180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'tree_node : article_md + paternité (statut, modèle, dates).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_node ADD article_md TEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE tree_node ADD article_status VARCHAR(20) NOT NULL DEFAULT 'non_relu'");
        $this->addSql('ALTER TABLE tree_node ADD article_model VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE tree_node ADD article_generated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE tree_node ADD article_reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_node DROP article_md');
        $this->addSql('ALTER TABLE tree_node DROP article_status');
        $this->addSql('ALTER TABLE tree_node DROP article_model');
        $this->addSql('ALTER TABLE tree_node DROP article_generated_at');
        $this->addSql('ALTER TABLE tree_node DROP article_reviewed_at');
    }
}
