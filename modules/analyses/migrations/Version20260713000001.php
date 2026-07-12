<?php

declare(strict_types=1);

namespace Analyses\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module analyses : workflow de validation humaine (correction de critère + validation
 * de l'évaluation par un relecteur).
 */
final class Version20260713000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module analyses : correction humaine des critères + validation de l\'évaluation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analys_assessment_criterion ADD human_answer VARCHAR(24) DEFAULT NULL');
        $this->addSql('ALTER TABLE analys_assessment_criterion ADD reviewed_by VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE analys_assessment_criterion ADD reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN analys_assessment_criterion.reviewed_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('ALTER TABLE analys_assessment ADD validated_by VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE analys_assessment ADD validated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN analys_assessment.validated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analys_assessment_criterion DROP human_answer');
        $this->addSql('ALTER TABLE analys_assessment_criterion DROP reviewed_by');
        $this->addSql('ALTER TABLE analys_assessment_criterion DROP reviewed_at');
        $this->addSql('ALTER TABLE analys_assessment DROP validated_by');
        $this->addSql('ALTER TABLE analys_assessment DROP validated_at');
    }
}
