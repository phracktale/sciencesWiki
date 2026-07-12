<?php

declare(strict_types=1);

namespace Analyses\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module analyses : table de réglages isolée (modèles, seuil, référentiels activés),
 * configurable par l'admin sans redéploiement.
 */
final class Version20260713000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module analyses : table de réglages analys_setting.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE analys_setting (name VARCHAR(96) NOT NULL, value TEXT NOT NULL, PRIMARY KEY(name))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE analys_setting');
    }
}
