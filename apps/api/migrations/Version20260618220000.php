<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Référentiel éditeurs/revues + lien canonique de l'article.
 *
 *  - publisher (éditeur, host_organization OpenAlex)
 *  - journal   (revue/source, primary_location.source) → publisher
 *  - publication.journal_id + publication.landing_page_url
 */
final class Version20260618220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables publisher + journal ; publication.journal_id + landing_page_url.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE publisher (
            id SERIAL PRIMARY KEY,
            openalex_id VARCHAR(64) DEFAULT NULL,
            name VARCHAR(512) NOT NULL,
            homepage_url TEXT DEFAULT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_publisher_openalex ON publisher (openalex_id)');

        $this->addSql('CREATE TABLE journal (
            id SERIAL PRIMARY KEY,
            publisher_id INT DEFAULT NULL,
            openalex_id VARCHAR(64) DEFAULT NULL,
            name VARCHAR(512) NOT NULL,
            issn_l VARCHAR(32) DEFAULT NULL,
            type VARCHAR(64) DEFAULT NULL,
            is_oa BOOLEAN NOT NULL DEFAULT FALSE,
            is_in_doaj BOOLEAN NOT NULL DEFAULT FALSE,
            homepage_url TEXT DEFAULT NULL,
            CONSTRAINT fk_journal_publisher FOREIGN KEY (publisher_id) REFERENCES publisher (id) ON DELETE SET NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_journal_openalex ON journal (openalex_id)');
        $this->addSql('CREATE INDEX idx_journal_name ON journal (name)');
        $this->addSql('CREATE INDEX idx_journal_publisher ON journal (publisher_id)');

        $this->addSql('ALTER TABLE publication ADD landing_page_url TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE publication ADD journal_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE publication ADD CONSTRAINT fk_publication_journal FOREIGN KEY (journal_id) REFERENCES journal (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_publication_journal ON publication (journal_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication DROP CONSTRAINT fk_publication_journal');
        $this->addSql('DROP INDEX idx_publication_journal');
        $this->addSql('ALTER TABLE publication DROP journal_id');
        $this->addSql('ALTER TABLE publication DROP landing_page_url');
        $this->addSql('DROP TABLE journal');
        $this->addSql('DROP TABLE publisher');
    }
}
