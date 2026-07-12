<?php

declare(strict_types=1);

namespace Analyses\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module analyses : traçabilité du demandeur et de l'override de plan (analyse async).
 */
final class Version20260712000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module analyses : colonnes requested_by et design_override sur assessment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analys_assessment ADD requested_by VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE analys_assessment ADD design_override VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analys_assessment DROP requested_by');
        $this->addSql('ALTER TABLE analys_assessment DROP design_override');
    }
}
