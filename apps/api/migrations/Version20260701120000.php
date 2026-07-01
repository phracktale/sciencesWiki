<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table mmat_appraisal : évaluation de la qualité méthodologique d'une étude empirique
 * par l'outil MMAT (2 questions de filtrage + 5 critères de la catégorie détectée). Une
 * par publication.
 */
final class Version20260701120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table mmat_appraisal (évaluation MMAT des études empiriques).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS mmat_appraisal (
                id SERIAL PRIMARY KEY,
                publication_id INT NOT NULL,
                tree_node_id INT DEFAULT NULL,
                applicability VARCHAR(16) NOT NULL DEFAULT 'uncertain',
                category VARCHAR(32) DEFAULT NULL,
                study_design VARCHAR(64) DEFAULT NULL,
                answers JSONB NOT NULL DEFAULT '{}'::jsonb,
                justifications JSONB NOT NULL DEFAULT '{}'::jsonb,
                screening_passed BOOLEAN NOT NULL DEFAULT false,
                met_count INT NOT NULL DEFAULT 0,
                overall VARCHAR(16) DEFAULT NULL,
                source_scope VARCHAR(24) NOT NULL DEFAULT 'abstract',
                summary TEXT DEFAULT NULL,
                appraisal_model VARCHAR(128) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'detected',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                reviewed_by_id INT DEFAULT NULL,
                CONSTRAINT fk_mmat_publication FOREIGN KEY (publication_id) REFERENCES publication (id) ON DELETE CASCADE,
                CONSTRAINT fk_mmat_tree_node FOREIGN KEY (tree_node_id) REFERENCES tree_node (id) ON DELETE SET NULL,
                CONSTRAINT fk_mmat_reviewer FOREIGN KEY (reviewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_mmat_publication ON mmat_appraisal (publication_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mmat_status ON mmat_appraisal (status)');
        $this->addSql('ALTER TABLE publication ADD COLUMN IF NOT EXISTS mmat_appraising_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mmat_appraisal');
        $this->addSql('ALTER TABLE publication DROP COLUMN IF EXISTS mmat_appraising_at');
    }
}
