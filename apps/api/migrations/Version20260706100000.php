<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dépôt d'étude par un utilisateur (PDF uploadé pour évaluation critique) :
 *  - publication.submitted_by_id : uploadeur (null pour la moisson) ;
 *  - publication.listed_in_corpus : listée dans le corpus public (true par défaut ;
 *    false pour un dépôt utilisateur tant que le comité ne l'a pas validée) ;
 *  - table corpus_submission : proposition d'ajout au corpus, tranchée par le comité.
 *
 * ADD COLUMN avec DEFAULT constant est instantané en PG (pas de réécriture des 14 M
 * lignes) ; la colonne FK reste NULL sur l'existant (validation FK triviale).
 */
final class Version20260706100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dépôt d\'étude utilisateur : publication.submitted_by_id + listed_in_corpus ; table corpus_submission.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication ADD COLUMN submitted_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE publication ADD COLUMN listed_in_corpus BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('ALTER TABLE publication ADD CONSTRAINT fk_pub_submitted_by FOREIGN KEY (submitted_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_pub_submitted_by ON publication (submitted_by_id)');

        $this->addSql('CREATE TABLE corpus_submission (
            id SERIAL PRIMARY KEY,
            publication_id INT NOT NULL,
            submitted_by_id INT DEFAULT NULL,
            note TEXT DEFAULT NULL,
            status VARCHAR(16) NOT NULL,
            submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            reviewed_by_id INT DEFAULT NULL,
            reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_submission_pub ON corpus_submission (publication_id)');
        $this->addSql('CREATE INDEX idx_submission_status ON corpus_submission (status)');
        $this->addSql('ALTER TABLE corpus_submission ADD CONSTRAINT fk_submission_pub FOREIGN KEY (publication_id) REFERENCES publication (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE corpus_submission ADD CONSTRAINT fk_submission_user FOREIGN KEY (submitted_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE corpus_submission ADD CONSTRAINT fk_submission_reviewer FOREIGN KEY (reviewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS corpus_submission');
        $this->addSql('ALTER TABLE publication DROP CONSTRAINT IF EXISTS fk_pub_submitted_by');
        $this->addSql('DROP INDEX IF EXISTS idx_pub_submitted_by');
        $this->addSql('ALTER TABLE publication DROP COLUMN IF EXISTS submitted_by_id');
        $this->addSql('ALTER TABLE publication DROP COLUMN IF EXISTS listed_in_corpus');
    }
}
