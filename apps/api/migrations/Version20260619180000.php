<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Traduction française du résumé (à la demande, mise en cache) pour les articles
 * non francophones : on traduit une fois via le LLM puis on réutilise.
 */
final class Version20260619180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'publication.abstract_fr (traduction FR du résumé, mise en cache).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication ADD abstract_fr TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication DROP abstract_fr');
    }
}
