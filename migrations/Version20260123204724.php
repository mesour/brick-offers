<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove industry column from discovery_profiles.
 * Industry is now always taken from the user account.
 */
final class Version20260123204724 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove industry column from discovery_profiles table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovery_profiles DROP COLUMN industry');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovery_profiles ADD COLUMN industry VARCHAR(50) DEFAULT NULL');
    }
}
