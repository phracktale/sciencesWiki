<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Questions libres publiques : titre court affiché + identité du demandeur
 * (nom/pseudo obligatoire côté applicatif) + IP (audit / rate-limit).
 */
final class Version20260616120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'question: title, asker_name, asker_ip (questions libres publiques).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE question ADD title VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE question ADD asker_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE question ADD asker_ip VARCHAR(45) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE question DROP title');
        $this->addSql('ALTER TABLE question DROP asker_name');
        $this->addSql('ALTER TABLE question DROP asker_ip');
    }
}
