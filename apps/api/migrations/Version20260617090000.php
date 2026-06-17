<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réglages éditables (paramètres IA : prompt système, longueur, température…).
 */
final class Version20260617090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table setting (réglages clé-valeur éditables par l\'admin).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE setting (name VARCHAR(64) NOT NULL, value TEXT NOT NULL, PRIMARY KEY(name))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE setting');
    }
}
