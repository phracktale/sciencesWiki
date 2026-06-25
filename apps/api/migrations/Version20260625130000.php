<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index PARTIELS pour les files d'enrichissement (drains embed/placement).
 *
 * findNeedingEmbedding / findNeedingPlacement font `… ORDER BY id LIMIT N` avec un
 * filtre sur l'état d'avancement. Sans index dédié, à mesure que le backlog se vide
 * la requête scanne de plus en plus profond depuis id=1 → les drains ralentissent
 * sur une grosse table (catastrophique à l'échelle OpenAlex : millions d'œuvres).
 *
 * Les index PARTIELS ne couvrent que les lignes ENCORE EN ATTENTE → ils restent
 * minuscules (taille du backlog, pas de la table) et rendent « les N prochaines à
 * traiter » instantané quel que soit le volume total. Une ligne traitée sort
 * automatiquement de l'index.
 */
final class Version20260625130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index partiels pour les files embed/placement (drains scalables OpenAlex).';
    }

    public function up(Schema $schema): void
    {
        // File d'embedding : publications sans embedding (cf. findNeedingEmbedding).
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_pub_need_embedding ON publication (id) WHERE embedding IS NULL');
        // File de placement : publications embeddées en attente de placement (cf. findNeedingPlacement).
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_pub_need_placement ON publication (id) WHERE embedding IS NOT NULL AND processing_status = 'normalized'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_pub_need_embedding');
        $this->addSql('DROP INDEX IF EXISTS idx_pub_need_placement');
    }
}
