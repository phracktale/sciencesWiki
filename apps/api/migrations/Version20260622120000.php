<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lien des publications « satellites » (peer-review, erratum, rétractation,
 * annexes…) vers leur article parent : DOI parent (résolu via Crossref
 * `relation`) + FK locale si le parent est présent en base.
 */
final class Version20260622120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'publication.parent_doi + parent_publication_id (lien satellite → article parent).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication ADD parent_doi VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE publication ADD parent_publication_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pub_parent ON publication (parent_publication_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_pub_parent');
        $this->addSql('ALTER TABLE publication DROP parent_doi');
        $this->addSql('ALTER TABLE publication DROP parent_publication_id');
    }
}
