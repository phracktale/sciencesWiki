<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table rob2_appraisal : évaluation du risque de biais d'un essai randomisé par
 * l'outil RoB 2 (5 domaines → jugement global). Une évaluation par publication.
 */
final class Version20260630140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table rob2_appraisal (évaluation RoB 2 des essais randomisés).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS rob2_appraisal (
                id SERIAL PRIMARY KEY,
                publication_id INT NOT NULL,
                tree_node_id INT DEFAULT NULL,
                applicability VARCHAR(16) NOT NULL DEFAULT 'uncertain',
                study_design VARCHAR(64) DEFAULT NULL,
                domains JSONB NOT NULL DEFAULT '{}'::jsonb,
                overall VARCHAR(16) DEFAULT NULL,
                source_scope VARCHAR(24) NOT NULL DEFAULT 'abstract',
                summary TEXT DEFAULT NULL,
                appraisal_model VARCHAR(128) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'detected',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                reviewed_by_id INT DEFAULT NULL,
                CONSTRAINT fk_rob2_publication FOREIGN KEY (publication_id) REFERENCES publication (id) ON DELETE CASCADE,
                CONSTRAINT fk_rob2_tree_node FOREIGN KEY (tree_node_id) REFERENCES tree_node (id) ON DELETE SET NULL,
                CONSTRAINT fk_rob2_reviewer FOREIGN KEY (reviewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_rob2_publication ON rob2_appraisal (publication_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_rob2_status ON rob2_appraisal (status)');

        // Marqueur « évaluation RoB 2 en file/en cours » (loader/polling de l'outil), comme axis_appraising_at.
        $this->addSql('ALTER TABLE publication ADD COLUMN IF NOT EXISTS rob2_appraising_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS rob2_appraisal');
        $this->addSql('ALTER TABLE publication DROP COLUMN IF EXISTS rob2_appraising_at');
    }
}
