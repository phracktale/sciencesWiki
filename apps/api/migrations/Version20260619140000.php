<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dépôt de la version auteur d'un article payant : jeton sécurisé (usage unique,
 * expirant) → formulaire d'upload PDF → intégration RAG.
 */
final class Version20260619140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table contribution_token + publication.author_pdf_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE contribution_token (
            id SERIAL PRIMARY KEY,
            token VARCHAR(64) NOT NULL,
            publication_id INT NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            CONSTRAINT fk_contrib_publication FOREIGN KEY (publication_id) REFERENCES publication (id) ON DELETE CASCADE
        )");
        $this->addSql('CREATE UNIQUE INDEX uniq_contrib_token ON contribution_token (token)');
        $this->addSql('CREATE INDEX idx_contrib_publication ON contribution_token (publication_id)');

        $this->addSql('ALTER TABLE publication ADD author_pdf_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication DROP author_pdf_at');
        $this->addSql('DROP TABLE contribution_token');
    }
}
