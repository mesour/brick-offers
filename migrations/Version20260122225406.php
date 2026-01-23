<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop user_analyzer_configs table - now using DiscoveryProfile.analyzerConfigs instead.
 */
final class Version20260122225406 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop user_analyzer_configs table (replaced by DiscoveryProfile.analyzerConfigs)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_analyzer_configs DROP CONSTRAINT IF EXISTS fk_33a796a0a76ed395');
        $this->addSql('DROP TABLE IF EXISTS user_analyzer_configs');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_analyzer_configs (id UUID NOT NULL, user_id UUID NOT NULL, category VARCHAR(50) NOT NULL, enabled BOOLEAN DEFAULT true NOT NULL, priority INT DEFAULT 5 NOT NULL, config JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX user_analyzer_configs_enabled_idx ON user_analyzer_configs (enabled)');
        $this->addSql('CREATE UNIQUE INDEX user_analyzer_configs_user_category_unique ON user_analyzer_configs (user_id, category)');
        $this->addSql('CREATE INDEX user_analyzer_configs_user_idx ON user_analyzer_configs (user_id)');
        $this->addSql('COMMENT ON COLUMN user_analyzer_configs.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN user_analyzer_configs.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN user_analyzer_configs.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN user_analyzer_configs.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE user_analyzer_configs ADD CONSTRAINT fk_33a796a0a76ed395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
