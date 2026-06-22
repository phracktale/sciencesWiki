<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suivi des rétractations / mises en garde (Expression of Concern) par publication.
 */
final class Version20260618160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'publication.retraction_status + retraction_checked_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE publication ADD retraction_status VARCHAR(16) NOT NULL DEFAULT 'none'");
        $this->addSql('ALTER TABLE publication ADD retraction_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_publication_retraction ON publication (retraction_status)');
        // Réponse à revalider : une de ses sources a été rétractée/signalée après validation.
        $this->addSql('ALTER TABLE answer ADD needs_revalidation BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_publication_retraction');
        $this->addSql('ALTER TABLE publication DROP retraction_status');
        $this->addSql('ALTER TABLE publication DROP retraction_checked_at');
        $this->addSql('ALTER TABLE answer DROP needs_revalidation');
    }
}
