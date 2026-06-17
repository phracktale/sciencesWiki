<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Date de dernière moisson ciblée par rubrique (reprise incrémentale).
 */
final class Version20260617140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'tree_node.last_harvested_at (moisson incrémentale par rubrique).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_node ADD last_harvested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_node DROP last_harvested_at');
    }
}
