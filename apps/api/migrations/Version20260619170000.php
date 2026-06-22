<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Optimisation : publication_chunk.embedding en halfvec(384) (float16) → ÷2 sur
 * le disque et la RAM (et l'index si on en ajoute un plus tard). Qualité de
 * recherche quasi identique. Le plus gros volume de vecteurs (fragments de texte
 * intégral) ; publication.embedding (1/article, via l'ORM) reste en vector(384).
 */
final class Version20260619170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'publication_chunk.embedding : vector(384) → halfvec(384).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication_chunk ALTER COLUMN embedding TYPE halfvec(384) USING embedding::halfvec(384)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication_chunk ALTER COLUMN embedding TYPE vector(384) USING embedding::vector(384)');
    }
}
