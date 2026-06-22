<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Texte intégral des publications en accès ouvert : fragments (chunks) vectorisés
 * pour enrichir le RAG au-delà du seul résumé.
 */
final class Version20260618130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'publication_chunk (texte intégral OA vectorisé) + publication.fulltext_fetched_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication ADD fulltext_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE publication_chunk (
                id BIGSERIAL PRIMARY KEY,
                publication_id INT NOT NULL,
                ord INT NOT NULL,
                content TEXT NOT NULL,
                embedding vector(384) DEFAULT NULL,
                CONSTRAINT fk_chunk_publication FOREIGN KEY (publication_id)
                    REFERENCES publication (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_chunk_publication ON publication_chunk (publication_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE publication_chunk');
        $this->addSql('ALTER TABLE publication DROP fulltext_fetched_at');
    }
}
