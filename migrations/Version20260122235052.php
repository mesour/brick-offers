<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260122235052 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add score column to leads table for sorting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leads ADD score INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leads DROP score');
    }
}
