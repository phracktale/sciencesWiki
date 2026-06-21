<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index HNSW (pgvector) pour la recherche sémantique : kNN cosinus indexé sur
 * l'embedding du résumé (publication.embedding, vector) et des fragments de
 * texte intégral (publication_chunk.embedding, halfvec). Remplace le scan
 * séquentiel par un parcours de graphe (≈ ms).
 *
 * IF NOT EXISTS : no-op si l'index a déjà été bâti manuellement en production
 * (CONCURRENTLY, hors migration, pour ne pas verrouiller une table peuplée) ;
 * sur une base neuve (vide) la création est instantanée.
 */
final class Version20260621120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index HNSW sur les embeddings (publication + publication_chunk) — recherche sémantique.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pub_emb_hnsw ON publication USING hnsw (embedding vector_cosine_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_chunk_emb_hnsw ON publication_chunk USING hnsw (embedding halfvec_cosine_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_pub_emb_hnsw');
        $this->addSql('DROP INDEX IF EXISTS idx_chunk_emb_hnsw');
    }
}
