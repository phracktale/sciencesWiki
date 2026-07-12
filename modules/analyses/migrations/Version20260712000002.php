<?php

declare(strict_types=1);

namespace Analyses\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module analyses : enrichit analys_assessment (fingerprint/plan/human_review/model)
 * et ajoute analys_assessment_criterion + analys_evidence.
 */
final class Version20260712000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module analyses : critères, preuves, et champs fingerprint/plan sur assessment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analys_assessment ADD fingerprint JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE analys_assessment ADD plan JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE analys_assessment ADD human_review BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE analys_assessment ADD model VARCHAR(120) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE analys_assessment_criterion (
                id UUID NOT NULL,
                assessment_id UUID NOT NULL,
                framework_id VARCHAR(64) NOT NULL,
                criterion_id VARCHAR(64) NOT NULL,
                dimension VARCHAR(96) DEFAULT NULL,
                question TEXT NOT NULL,
                answer VARCHAR(24) NOT NULL,
                verdict VARCHAR(48) DEFAULT NULL,
                analysis TEXT DEFAULT NULL,
                evidence_type VARCHAR(48) DEFAULT NULL,
                confidence VARCHAR(16) DEFAULT NULL,
                requires_human_review BOOLEAN DEFAULT FALSE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_analys_crit_assessment ON analys_assessment_criterion (assessment_id)');
        $this->addSql("COMMENT ON COLUMN analys_assessment_criterion.id IS '(DC2Type:ulid)'");
        $this->addSql("COMMENT ON COLUMN analys_assessment_criterion.assessment_id IS '(DC2Type:ulid)'");

        $this->addSql(<<<'SQL'
            CREATE TABLE analys_evidence (
                id UUID NOT NULL,
                assessment_id UUID NOT NULL,
                criterion_id VARCHAR(64) DEFAULT NULL,
                quote TEXT NOT NULL,
                normalized_fact TEXT DEFAULT NULL,
                evidence_type VARCHAR(48) NOT NULL,
                confidence VARCHAR(16) DEFAULT NULL,
                section VARCHAR(96) DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_analys_evidence_assessment ON analys_evidence (assessment_id)');
        $this->addSql("COMMENT ON COLUMN analys_evidence.id IS '(DC2Type:ulid)'");
        $this->addSql("COMMENT ON COLUMN analys_evidence.assessment_id IS '(DC2Type:ulid)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE analys_evidence');
        $this->addSql('DROP TABLE analys_assessment_criterion');
        $this->addSql('ALTER TABLE analys_assessment DROP fingerprint');
        $this->addSql('ALTER TABLE analys_assessment DROP plan');
        $this->addSql('ALTER TABLE analys_assessment DROP human_review');
        $this->addSql('ALTER TABLE analys_assessment DROP model');
    }
}
