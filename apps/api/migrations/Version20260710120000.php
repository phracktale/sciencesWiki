<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Provenance de l'évaluation AXIS : durée de génération (ms) et total de tokens
 * consommés, pour la traçabilité et le PDF exportable. Colonnes nullables (ADD COLUMN
 * instantané en PG ; l'existant reste NULL).
 */
final class Version20260710120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'axis_appraisal : generation_ms + tokens (provenance / PDF).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE axis_appraisal ADD COLUMN generation_ms INT DEFAULT NULL');
        $this->addSql('ALTER TABLE axis_appraisal ADD COLUMN tokens INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE axis_appraisal DROP COLUMN generation_ms');
        $this->addSql('ALTER TABLE axis_appraisal DROP COLUMN tokens');
    }
}
