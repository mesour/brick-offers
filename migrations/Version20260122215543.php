<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260122215543 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add discovery_profiles table and relation to leads';
    }

    public function up(Schema $schema): void
    {
        // Create discovery_profiles table
        $this->addSql('CREATE TABLE discovery_profiles (id UUID NOT NULL, user_id UUID NOT NULL, name VARCHAR(100) NOT NULL, description TEXT DEFAULT NULL, industry VARCHAR(50) DEFAULT NULL, is_default BOOLEAN DEFAULT false NOT NULL, discovery_enabled BOOLEAN DEFAULT true NOT NULL, discovery_sources JSON NOT NULL, discovery_queries JSON NOT NULL, discovery_limit INT DEFAULT 50 NOT NULL, extract_data BOOLEAN DEFAULT true NOT NULL, link_company BOOLEAN DEFAULT true NOT NULL, priority INT DEFAULT 5 NOT NULL, auto_analyze BOOLEAN DEFAULT false NOT NULL, analyzer_configs JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX discovery_profiles_user_idx ON discovery_profiles (user_id)');
        $this->addSql('CREATE INDEX discovery_profiles_is_default_idx ON discovery_profiles (is_default)');
        $this->addSql('COMMENT ON COLUMN discovery_profiles.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN discovery_profiles.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN discovery_profiles.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN discovery_profiles.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE discovery_profiles ADD CONSTRAINT FK_A38439B9A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Add discovery_profile_id to leads
        $this->addSql('ALTER TABLE leads ADD discovery_profile_id UUID DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN leads.discovery_profile_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_17904552E46CE6A9 FOREIGN KEY (discovery_profile_id) REFERENCES discovery_profiles (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_17904552E46CE6A9 ON leads (discovery_profile_id)');
    }

    public function down(Schema $schema): void
    {
        // Remove foreign key and column from leads
        $this->addSql('ALTER TABLE leads DROP CONSTRAINT FK_17904552E46CE6A9');
        $this->addSql('DROP INDEX IDX_17904552E46CE6A9');
        $this->addSql('ALTER TABLE leads DROP discovery_profile_id');

        // Drop discovery_profiles table
        $this->addSql('ALTER TABLE discovery_profiles DROP CONSTRAINT FK_A38439B9A76ED395');
        $this->addSql('DROP TABLE discovery_profiles');
    }
}
