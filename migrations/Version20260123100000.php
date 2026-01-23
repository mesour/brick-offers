<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add excluded_domains JSON column to users table.
 */
final class Version20260123100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add excluded_domains field to users table for domain blacklist';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD excluded_domains JSON NOT NULL DEFAULT \'[]\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN excluded_domains');
    }
}
