<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Email Module migration: email_logs and email_blacklist tables.
 */
final class Version20260121170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create email_logs and email_blacklist tables for Email Module';
    }

    public function up(Schema $schema): void
    {
        // Create email_logs table
        $this->addSql('CREATE TABLE email_logs (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            offer_id UUID DEFAULT NULL,
            provider VARCHAR(20) NOT NULL,
            message_id VARCHAR(255) DEFAULT NULL,
            to_email VARCHAR(255) NOT NULL,
            to_name VARCHAR(255) DEFAULT NULL,
            from_email VARCHAR(255) NOT NULL,
            subject VARCHAR(500) NOT NULL,
            status VARCHAR(20) NOT NULL,
            bounce_type VARCHAR(20) DEFAULT NULL,
            bounce_message TEXT DEFAULT NULL,
            sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            opened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            clicked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            bounced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            metadata JSON NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');

        $this->addSql('CREATE INDEX email_logs_user_status_idx ON email_logs (user_id, status)');
        $this->addSql('CREATE INDEX email_logs_offer_idx ON email_logs (offer_id)');
        $this->addSql('CREATE INDEX email_logs_message_id_idx ON email_logs (message_id)');
        $this->addSql('CREATE INDEX email_logs_to_email_idx ON email_logs (to_email)');
        $this->addSql('CREATE INDEX email_logs_created_at_idx ON email_logs (created_at)');

        $this->addSql('COMMENT ON COLUMN email_logs.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN email_logs.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN email_logs.offer_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN email_logs.sent_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN email_logs.delivered_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN email_logs.opened_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN email_logs.clicked_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN email_logs.bounced_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN email_logs.created_at IS \'(DC2Type:datetime_immutable)\'');

        // Create email_blacklist table
        $this->addSql('CREATE TABLE email_blacklist (
            id UUID NOT NULL,
            email VARCHAR(255) NOT NULL,
            user_id UUID DEFAULT NULL,
            type VARCHAR(20) NOT NULL,
            reason TEXT DEFAULT NULL,
            source_email_log_id UUID DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');

        $this->addSql('CREATE INDEX email_blacklist_email_idx ON email_blacklist (email)');
        $this->addSql('CREATE INDEX email_blacklist_user_idx ON email_blacklist (user_id)');
        $this->addSql('CREATE INDEX email_blacklist_type_idx ON email_blacklist (type)');
        $this->addSql('CREATE UNIQUE INDEX email_blacklist_unique ON email_blacklist (email, user_id)');

        $this->addSql('COMMENT ON COLUMN email_blacklist.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN email_blacklist.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN email_blacklist.source_email_log_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN email_blacklist.created_at IS \'(DC2Type:datetime_immutable)\'');

        // Add foreign keys
        $this->addSql('ALTER TABLE email_logs ADD CONSTRAINT FK_email_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE email_logs ADD CONSTRAINT FK_email_logs_offer FOREIGN KEY (offer_id) REFERENCES offers (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE email_blacklist ADD CONSTRAINT FK_email_blacklist_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE email_blacklist ADD CONSTRAINT FK_email_blacklist_source FOREIGN KEY (source_email_log_id) REFERENCES email_logs (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_blacklist DROP CONSTRAINT FK_email_blacklist_source');
        $this->addSql('ALTER TABLE email_blacklist DROP CONSTRAINT FK_email_blacklist_user');
        $this->addSql('ALTER TABLE email_logs DROP CONSTRAINT FK_email_logs_offer');
        $this->addSql('ALTER TABLE email_logs DROP CONSTRAINT FK_email_logs_user');

        $this->addSql('DROP TABLE email_blacklist');
        $this->addSql('DROP TABLE email_logs');
    }
}
