<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add authentication fields to users table for EasyAdmin.
 */
final class Version20260122165020 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password, roles, permissions, limits, and admin_account_id fields to users table';
    }

    public function up(Schema $schema): void
    {
        // Add authentication and authorization fields
        $this->addSql('ALTER TABLE users ADD admin_account_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD password VARCHAR(255) DEFAULT NULL');
        $this->addSql("ALTER TABLE users ADD roles JSON NOT NULL DEFAULT '[\"ROLE_USER\"]'");
        $this->addSql("ALTER TABLE users ADD permissions JSON NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE users ADD limits JSON NOT NULL DEFAULT '{}'");
        $this->addSql('COMMENT ON COLUMN users.admin_account_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E95355F93F FOREIGN KEY (admin_account_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX users_admin_account_idx ON users (admin_account_id)');

        // Remove defaults after setting them (clean up)
        $this->addSql("ALTER TABLE users ALTER COLUMN roles DROP DEFAULT");
        $this->addSql("ALTER TABLE users ALTER COLUMN permissions DROP DEFAULT");
        $this->addSql("ALTER TABLE users ALTER COLUMN limits DROP DEFAULT");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP CONSTRAINT FK_1483A5E95355F93F');
        $this->addSql('DROP INDEX users_admin_account_idx');
        $this->addSql('ALTER TABLE users DROP admin_account_id');
        $this->addSql('ALTER TABLE users DROP password');
        $this->addSql('ALTER TABLE users DROP roles');
        $this->addSql('ALTER TABLE users DROP permissions');
        $this->addSql('ALTER TABLE users DROP limits');
    }
}
