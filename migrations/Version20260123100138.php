<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refactor DiscoveryProfile: single source with source-specific settings.
 *
 * Changes:
 * - Add discovery_source (single source instead of array)
 * - Add source_settings (JSON for source-specific options)
 * - Keep discovery_queries for backwards compatibility
 */
final class Version20260123100138 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add discovery_source and source_settings columns to discovery_profiles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovery_profiles ADD discovery_source VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE discovery_profiles ADD source_settings JSON NOT NULL DEFAULT \'{}\'');

        // Migrate existing data: take first source from discovery_sources array
        $this->addSql("
            UPDATE discovery_profiles
            SET discovery_source = (
                SELECT TRIM(BOTH '\"' FROM (discovery_sources::json->0)::text)
            )
            WHERE discovery_sources IS NOT NULL
            AND discovery_sources::text != '[]'
            AND discovery_sources::text != 'null'
        ");

        // Copy queries to source_settings
        $this->addSql("
            UPDATE discovery_profiles
            SET source_settings = jsonb_build_object('queries', discovery_queries::jsonb)
            WHERE discovery_queries IS NOT NULL
            AND discovery_queries::text != '[]'
            AND discovery_queries::text != 'null'
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovery_profiles DROP discovery_source');
        $this->addSql('ALTER TABLE discovery_profiles DROP source_settings');
    }
}
