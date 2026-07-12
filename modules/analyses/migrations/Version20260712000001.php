<?php

declare(strict_types=1);

namespace Analyses\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table initiale du module analyses (résultat canonique). Préfixe analys_,
 * dans la base SciencesWiki partagée — aucune table du cœur n'est touchée.
 */
final class Version20260712000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module analyses : création de analys_assessment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE analys_assessment (
                id UUID NOT NULL,
                document_ref VARCHAR(255) NOT NULL,
                primary_design VARCHAR(64) DEFAULT NULL,
                status VARCHAR(32) NOT NULL,
                routing_confidence DOUBLE PRECISION DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_analys_assessment_doc ON analys_assessment (document_ref)');
        $this->addSql("COMMENT ON COLUMN analys_assessment.id IS '(DC2Type:ulid)'");
        $this->addSql("COMMENT ON COLUMN analys_assessment.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE analys_assessment');
    }
}
