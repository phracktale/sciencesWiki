<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase pédagogique : classes (un enseignant ROLE_TEACHER) + invitations d'élèves
 * par e-mail (token). L'effectif d'une classe = les invitations acceptées
 * (acceptedBy non null) → pas de table d'adhésion séparée.
 */
final class Version20260630120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Classes pédagogiques + invitations enseignant→élèves (school_class, class_invitation).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS school_class (
                id SERIAL PRIMARY KEY,
                teacher_id INT NOT NULL,
                name VARCHAR(160) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT fk_school_class_teacher FOREIGN KEY (teacher_id)
                    REFERENCES app_user (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_school_class_teacher ON school_class (teacher_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS class_invitation (
                id SERIAL PRIMARY KEY,
                school_class_id INT NOT NULL,
                accepted_by_id INT DEFAULT NULL,
                email VARCHAR(180) NOT NULL,
                token VARCHAR(64) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                CONSTRAINT fk_class_invitation_class FOREIGN KEY (school_class_id)
                    REFERENCES school_class (id) ON DELETE CASCADE,
                CONSTRAINT fk_class_invitation_student FOREIGN KEY (accepted_by_id)
                    REFERENCES app_user (id) ON DELETE SET NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_class_invitation_token ON class_invitation (token)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_class_invitation_class ON class_invitation (school_class_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS class_invitation');
        $this->addSql('DROP TABLE IF EXISTS school_class');
    }
}
