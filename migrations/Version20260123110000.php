<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make legacy discovery_sources column nullable.
 */
final class Version20260123110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make discovery_sources column nullable (deprecated, replaced by discovery_source)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovery_profiles ALTER COLUMN discovery_sources DROP NOT NULL');
        $this->addSql("ALTER TABLE discovery_profiles ALTER COLUMN discovery_sources SET DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE discovery_profiles SET discovery_sources = '[]' WHERE discovery_sources IS NULL");
        $this->addSql('ALTER TABLE discovery_profiles ALTER COLUMN discovery_sources SET NOT NULL');
    }
}
