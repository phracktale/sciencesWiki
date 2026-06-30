<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table amstar2_appraisal : évaluation de la confiance dans une revue systématique
 * par l'outil AMSTAR-2 (16 items → niveau de confiance global). Une par publication.
 */
final class Version20260630150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table amstar2_appraisal (évaluation AMSTAR-2 des revues systématiques).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS amstar2_appraisal (
                id SERIAL PRIMARY KEY,
                publication_id INT NOT NULL,
                tree_node_id INT DEFAULT NULL,
                applicability VARCHAR(16) NOT NULL DEFAULT 'uncertain',
                study_design VARCHAR(64) DEFAULT NULL,
                answers JSONB NOT NULL DEFAULT '{}'::jsonb,
                justifications JSONB NOT NULL DEFAULT '{}'::jsonb,
                critical_flaws INT NOT NULL DEFAULT 0,
                weaknesses INT NOT NULL DEFAULT 0,
                overall VARCHAR(16) DEFAULT NULL,
                source_scope VARCHAR(24) NOT NULL DEFAULT 'abstract',
                summary TEXT DEFAULT NULL,
                appraisal_model VARCHAR(128) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'detected',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                reviewed_by_id INT DEFAULT NULL,
                CONSTRAINT fk_amstar2_publication FOREIGN KEY (publication_id) REFERENCES publication (id) ON DELETE CASCADE,
                CONSTRAINT fk_amstar2_tree_node FOREIGN KEY (tree_node_id) REFERENCES tree_node (id) ON DELETE SET NULL,
                CONSTRAINT fk_amstar2_reviewer FOREIGN KEY (reviewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_amstar2_publication ON amstar2_appraisal (publication_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_amstar2_status ON amstar2_appraisal (status)');
        $this->addSql('ALTER TABLE publication ADD COLUMN IF NOT EXISTS amstar2_appraising_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS amstar2_appraisal');
        $this->addSql('ALTER TABLE publication DROP COLUMN IF EXISTS amstar2_appraising_at');
    }
}
