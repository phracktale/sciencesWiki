<?php

declare(strict_types=1);

namespace Analyses\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module analyses : doctrine d'analyse RICHE reprise de l'AXIS legacy — champs
 * expected / evidence_found / limitations / overall_evidence_type / requires_visual_check
 * par critère, source_type des preuves, applicabilité + réflexion générale de l'évaluation.
 */
final class Version20260713000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module analyses : champs riches (expected/evidence_found/limitations/visual_check, source_type, applicable/summary).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analys_assessment_criterion ALTER verdict TYPE VARCHAR(96)');
        $this->addSql('ALTER TABLE analys_assessment_criterion ADD expected TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE analys_assessment_criterion ADD evidence_found TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE analys_assessment_criterion ADD limitations TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE analys_assessment_criterion ADD overall_evidence_type VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE analys_assessment_criterion ADD requires_visual_check BOOLEAN DEFAULT FALSE NOT NULL');

        $this->addSql('ALTER TABLE analys_evidence ADD source_type VARCHAR(16) DEFAULT NULL');

        $this->addSql('ALTER TABLE analys_assessment ADD applicable BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE analys_assessment ADD summary TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analys_assessment_criterion DROP expected');
        $this->addSql('ALTER TABLE analys_assessment_criterion DROP evidence_found');
        $this->addSql('ALTER TABLE analys_assessment_criterion DROP limitations');
        $this->addSql('ALTER TABLE analys_assessment_criterion DROP overall_evidence_type');
        $this->addSql('ALTER TABLE analys_assessment_criterion DROP requires_visual_check');
        $this->addSql('ALTER TABLE analys_assessment_criterion ALTER verdict TYPE VARCHAR(48)');
        $this->addSql('ALTER TABLE analys_evidence DROP source_type');
        $this->addSql('ALTER TABLE analys_assessment DROP applicable');
        $this->addSql('ALTER TABLE analys_assessment DROP summary');
    }
}
