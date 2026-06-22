<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réponses : modèle d'IA rédacteur (figé) + durée de génération.
 */
final class Version20260622220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute answer.generation_model + answer.generation_ms (signature du modèle figée à la génération).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE answer ADD generation_model VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE answer ADD generation_ms INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE answer DROP generation_model');
        $this->addSql('ALTER TABLE answer DROP generation_ms');
    }
}
