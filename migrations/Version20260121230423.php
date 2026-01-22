<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the messenger_messages table for Symfony Messenger Doctrine transport.
 */
final class Version20260121230423 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create messenger_messages table for async job processing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE messenger_messages (
            id BIGSERIAL NOT NULL,
            body TEXT NOT NULL,
            headers TEXT NOT NULL,
            queue_name VARCHAR(190) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');

        $this->addSql('CREATE INDEX IDX_MESSENGER_QUEUE_NAME ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_MESSENGER_AVAILABLE_AT ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_MESSENGER_DELIVERED_AT ON messenger_messages (delivered_at)');

        // Composite index for efficient polling
        $this->addSql('CREATE INDEX IDX_MESSENGER_QUEUE_AVAILABLE ON messenger_messages (queue_name, available_at, delivered_at)');

        // Add comment for PostgreSQL NOTIFY/LISTEN support
        $this->addSql("COMMENT ON TABLE messenger_messages IS 'Symfony Messenger queue table'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
    }
}
