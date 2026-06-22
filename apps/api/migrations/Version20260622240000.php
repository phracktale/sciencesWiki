<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Revues de littérature : rubrique détectée (surtitre).
 */
final class Version20260622240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute literature_review.rubric (rubrique détectée).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE literature_review ADD rubric VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE literature_review DROP rubric');
    }
}
