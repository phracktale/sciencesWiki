<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260614161540 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE answer ADD validated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE answer ADD CONSTRAINT FK_DADD4A25C69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_DADD4A25C69DE5E5 ON answer (validated_by_id)');
        $this->addSql('ALTER TABLE answer_revision ADD author_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE answer_revision ADD CONSTRAINT FK_4A2914A9F675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_4A2914A9F675F31B ON answer_revision (author_id)');
        $this->addSql('ALTER TABLE publication ALTER embedding TYPE vector(384)');
        $this->addSql('ALTER TABLE question ALTER embedding TYPE vector(384)');
        $this->addSql('ALTER TABLE tree_node ALTER embedding TYPE vector(384)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE answer DROP CONSTRAINT FK_DADD4A25C69DE5E5');
        $this->addSql('DROP INDEX IDX_DADD4A25C69DE5E5');
        $this->addSql('ALTER TABLE answer DROP validated_by_id');
        $this->addSql('ALTER TABLE answer_revision DROP CONSTRAINT FK_4A2914A9F675F31B');
        $this->addSql('DROP INDEX IDX_4A2914A9F675F31B');
        $this->addSql('ALTER TABLE answer_revision DROP author_id');
        $this->addSql('ALTER TABLE publication ALTER embedding TYPE vector');
        $this->addSql('ALTER TABLE question ALTER embedding TYPE vector');
        $this->addSql('ALTER TABLE tree_node ALTER embedding TYPE vector');
    }
}
