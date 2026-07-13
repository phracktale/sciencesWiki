<?php

declare(strict_types=1);

namespace Analyses\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module analyses : snapshot (titre/DOI) et chemin arborescent de la publication sur
 * l'évaluation, pour le classeur du compte et l'e-mail de notification.
 */
final class Version20260713000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module analyses : document_title, document_doi, tree_path sur assessment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analys_assessment ADD document_title TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE analys_assessment ADD document_doi VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE analys_assessment ADD tree_path JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analys_assessment DROP document_title');
        $this->addSql('ALTER TABLE analys_assessment DROP document_doi');
        $this->addSql('ALTER TABLE analys_assessment DROP tree_path');
    }
}
