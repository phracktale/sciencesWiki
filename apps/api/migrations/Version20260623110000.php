<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cartographe de controverses : horodatage du DÉBUT du job d'analyse sur
 * tree_node (chrono / durée estimée affichés pendant l'état Analyzing,
 * cf. docs/spec-controverses-lacunes.md §0.2).
 */
final class Version20260623110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute tree_node.analysis_started_at (chrono du job d\'analyse).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_node ADD analysis_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_node DROP analysis_started_at');
    }
}
