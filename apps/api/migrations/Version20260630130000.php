<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pré-détection des outils d'évaluation critique applicables à chaque étude :
 * un classificateur (LLM) détecte le DEVIS (study_design) → on en déduit la liste
 * d'outils applicables (appraisal_tools) ; à la recherche, on propose un bouton par
 * outil. Évite de lancer une grille inadaptée. ADD COLUMN nullable = instantané.
 */
final class Version20260630130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'publication.study_design / appraisal_tools / classified_at (boîte à outils d’évaluation critique).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication ADD COLUMN IF NOT EXISTS study_design VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE publication ADD COLUMN IF NOT EXISTS appraisal_tools JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE publication ADD COLUMN IF NOT EXISTS classified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication DROP COLUMN IF EXISTS study_design');
        $this->addSql('ALTER TABLE publication DROP COLUMN IF EXISTS appraisal_tools');
        $this->addSql('ALTER TABLE publication DROP COLUMN IF EXISTS classified_at');
    }
}
