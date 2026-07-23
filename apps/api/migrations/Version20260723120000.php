<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comptage quotidien de la consommation LLM (tokens) par jour et par modèle, pour
 * l'indicateur ops de la barre admin. Alimenté par upsert atomique à chaque appel
 * LLM (LlmUsageMeter) ; lu pour le total du jour + ventilation par modèle.
 */
final class Version20260723120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'llm_usage_daily : compteur de tokens LLM par jour/modèle (monitoring).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS llm_usage_daily (
                day DATE NOT NULL,
                model VARCHAR(160) NOT NULL,
                prompt_tokens BIGINT NOT NULL DEFAULT 0,
                completion_tokens BIGINT NOT NULL DEFAULT 0,
                calls INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, model)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS llm_usage_daily');
    }
}
