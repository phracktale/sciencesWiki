<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Métadonnées OpenAlex utiles à la curation/affichage : citations, FWCI, type
 * Crossref, nb de références, disponibilité du contenu (PDF / TEI GROBID),
 * dépôt OA, et provenance du texte intégral.
 */
final class Version20260619160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'publication : cited_by_count, fwci, type_crossref, referenced_works_count, has_pdf, has_grobid_xml, any_repo_fulltext, fulltext_source.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication ADD cited_by_count INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE publication ADD fwci DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE publication ADD type_crossref VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE publication ADD referenced_works_count INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE publication ADD has_pdf BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE publication ADD has_grobid_xml BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE publication ADD any_repo_fulltext BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE publication ADD fulltext_source VARCHAR(16) DEFAULT NULL');
        // Index pour la curation « top-cités » + ciblage GROBID.
        $this->addSql('CREATE INDEX idx_pub_cited ON publication (cited_by_count)');
        $this->addSql('CREATE INDEX idx_pub_grobid ON publication (has_grobid_xml)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_pub_grobid');
        $this->addSql('DROP INDEX idx_pub_cited');
        $this->addSql('ALTER TABLE publication DROP cited_by_count');
        $this->addSql('ALTER TABLE publication DROP fwci');
        $this->addSql('ALTER TABLE publication DROP type_crossref');
        $this->addSql('ALTER TABLE publication DROP referenced_works_count');
        $this->addSql('ALTER TABLE publication DROP has_pdf');
        $this->addSql('ALTER TABLE publication DROP has_grobid_xml');
        $this->addSql('ALTER TABLE publication DROP any_repo_fulltext');
        $this->addSql('ALTER TABLE publication DROP fulltext_source');
    }
}
