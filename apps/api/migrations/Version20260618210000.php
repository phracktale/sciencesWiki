<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Image de fond éditable par rubrique (lanceurs de l'accueil).
 */
final class Version20260618210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'tree_node.image_url (image de fond des lanceurs).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_node ADD image_url TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_node DROP image_url');
    }
}
