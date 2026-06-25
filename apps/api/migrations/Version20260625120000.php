<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index d'expression sur les identifiants externes (external_ids ->> 'clé').
 *
 * Le dédup d'import (Deduplicator → findOneByExternalId) interroge
 * `external_ids ->> 'openalex' / 'doi' / …` pour CHAQUE œuvre importée. Sans index,
 * chaque vérif = scan séquentiel de toute la table `publication` → O(n) par insert,
 * O(n²) sur une moisson (catastrophique pour l'ingestion OpenAlex). Ces index
 * d'expression rendent le dédup O(log n).
 *
 * NB : la requête doit utiliser une clé LITTÉRALE (external_ids ->> 'doi'), pas un
 * paramètre lié (->> :key) — sinon le planner ne peut pas choisir l'index
 * (cf. PublicationRepository::findOneByExternalId).
 */
final class Version20260625120000 extends AbstractMigration
{
    /** Clés d'identifiants produites par les mappers (OpenAlex, arXiv, PubMed…). */
    private const KEYS = ['openalex', 'doi', 'arxiv', 'pmid', 'pmcid'];

    public function getDescription(): string
    {
        return 'Index d\'expression sur external_ids ->> clé (dédup d\'import rapide).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::KEYS as $key) {
            $this->addSql(\sprintf(
                "CREATE INDEX IF NOT EXISTS idx_pub_extid_%s ON publication ((external_ids ->> '%s'))",
                $key,
                $key,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::KEYS as $key) {
            $this->addSql(\sprintf('DROP INDEX IF EXISTS idx_pub_extid_%s', $key));
        }
    }
}
