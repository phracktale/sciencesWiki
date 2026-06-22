<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Snapshot quotidien de la volumétrie (progression du corpus / des Q/R dans le temps).
 */
final class Version20260617100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table daily_stat (progression datée du corpus et des réponses).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE daily_stat (day DATE NOT NULL, publications INT NOT NULL DEFAULT 0, answers INT NOT NULL DEFAULT 0, questions INT NOT NULL DEFAULT 0, PRIMARY KEY(day))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE daily_stat');
    }
}
