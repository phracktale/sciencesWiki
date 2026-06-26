<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marqueur « (re)génération d'article IA en cours » au niveau nœud : permet au wiki
 * public d'afficher un loader persistant (visible par tous, survit au reload) pendant
 * que l'analysis-worker rédige l'article — miroir de analysis_started_at pour les
 * controverses. Non-null = génération en cours.
 */
final class Version20260626160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'tree_node.article_generating_at (loader « génération article en cours »).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_node ADD article_generating_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_node DROP article_generating_at');
    }
}
