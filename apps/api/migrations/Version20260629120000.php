<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Colonne publication.axis_appraising_at : marqueur « évaluation AXIS en file/en
 * cours » (non-null = en cours), posé au dispatch et levé par le worker. Permet à
 * l'outil AXIS (espaces recherche/pédagogie) d'afficher un loader + de poller le
 * résultat sans bloquer la requête (l'appel LLM dure ~1 min).
 *
 * ADD COLUMN nullable sans défaut = changement de métadonnées instantané même sur
 * la table publication (8 M+ lignes) ; pas de réécriture.
 */
final class Version20260629120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'publication.axis_appraising_at (marqueur évaluation AXIS asynchrone à la demande).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication ADD COLUMN IF NOT EXISTS axis_appraising_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication DROP COLUMN IF EXISTS axis_appraising_at');
    }
}
