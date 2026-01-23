<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260122193742 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_id to monitored_domains for tenant isolation';
    }

    public function up(Schema $schema): void
    {
        // Drop old domain-only unique constraint
        $this->addSql('DROP INDEX IF EXISTS monitored_domains_domain_unique');

        // Add user_id column (nullable first for existing data)
        $this->addSql('ALTER TABLE monitored_domains ADD user_id UUID DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN monitored_domains.user_id IS \'(DC2Type:uuid)\'');

        // Assign existing domains to first admin user (if any exist)
        $this->addSql('UPDATE monitored_domains SET user_id = (SELECT id FROM users WHERE admin_account_id IS NULL LIMIT 1) WHERE user_id IS NULL');

        // Delete orphaned domains without user
        $this->addSql('DELETE FROM monitored_domains WHERE user_id IS NULL');

        // Make column NOT NULL
        $this->addSql('ALTER TABLE monitored_domains ALTER COLUMN user_id SET NOT NULL');

        // Add foreign key and indexes
        $this->addSql('ALTER TABLE monitored_domains ADD CONSTRAINT FK_BF4C52AFA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX monitored_domains_user_idx ON monitored_domains (user_id)');
        $this->addSql('CREATE UNIQUE INDEX monitored_domains_user_domain_unique ON monitored_domains (user_id, domain)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitored_domains DROP CONSTRAINT FK_BF4C52AFA76ED395');
        $this->addSql('DROP INDEX monitored_domains_user_idx');
        $this->addSql('DROP INDEX monitored_domains_user_domain_unique');
        $this->addSql('ALTER TABLE monitored_domains DROP user_id');
        $this->addSql('CREATE UNIQUE INDEX monitored_domains_domain_unique ON monitored_domains (domain)');
    }
}
