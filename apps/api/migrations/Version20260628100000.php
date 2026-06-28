<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Variantes d'article par cible : l'article wiki est désormais décliné en
 * 3 registres — « ado » et « chercheur » (générés par IA, lecture seule) en plus
 * de la version « adulte » canonique (article_md, éditable/validable inchangée).
 */
final class Version20260628100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'tree_node.article_md_ado + article_md_chercheur (variantes d’article par cible).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_node ADD article_md_ado TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE tree_node ADD article_md_chercheur TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tree_node DROP article_md_ado');
        $this->addSql('ALTER TABLE tree_node DROP article_md_chercheur');
    }
}
