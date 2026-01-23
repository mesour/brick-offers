<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refactor MonitoredDomain to be global (remove user_id).
 * MonitoredDomain is now managed by server admins via CLI.
 * Users subscribe to domains via MonitoredDomainSubscription.
 */
final class Version20260123120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove user_id from monitored_domains - make domains global';
    }

    public function up(Schema $schema): void
    {
        // First, handle duplicates: keep one domain instance per unique domain
        // (keep the oldest one by created_at)
        $this->addSql(<<<'SQL'
            DELETE FROM monitored_domains d1
            USING monitored_domains d2
            WHERE d1.domain = d2.domain
              AND d1.created_at > d2.created_at
        SQL);

        // Drop the user_domain unique constraint
        $this->addSql('ALTER TABLE monitored_domains DROP CONSTRAINT IF EXISTS monitored_domains_user_domain_unique');

        // Drop the user_id index
        $this->addSql('DROP INDEX IF EXISTS monitored_domains_user_idx');

        // Drop the foreign key constraint (name may vary, try common patterns)
        $this->addSql('ALTER TABLE monitored_domains DROP CONSTRAINT IF EXISTS fk_monitored_domains_user_id');
        $this->addSql('ALTER TABLE monitored_domains DROP CONSTRAINT IF EXISTS monitored_domains_user_id_fk');

        // Drop the user_id column
        $this->addSql('ALTER TABLE monitored_domains DROP COLUMN IF EXISTS user_id');

        // Ensure unique constraint on domain exists
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS monitored_domains_domain_unique ON monitored_domains (domain)');
    }

    public function down(Schema $schema): void
    {
        // Re-add user_id column
        $this->addSql('ALTER TABLE monitored_domains ADD COLUMN user_id UUID DEFAULT NULL');

        // Re-add foreign key (would need to populate user_id first in practice)
        $this->addSql('ALTER TABLE monitored_domains ADD CONSTRAINT fk_monitored_domains_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        // Re-add user_idx index
        $this->addSql('CREATE INDEX monitored_domains_user_idx ON monitored_domains (user_id)');

        // Drop the domain unique index
        $this->addSql('DROP INDEX IF EXISTS monitored_domains_domain_unique');

        // Re-add user_domain unique constraint
        $this->addSql('ALTER TABLE monitored_domains ADD CONSTRAINT monitored_domains_user_domain_unique UNIQUE (user_id, domain)');
    }
}
