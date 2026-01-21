<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260120005936 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add analyzed_at, done_at, deal_at timestamp columns to leads table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX uniq_7e79b36ed1b862b8 RENAME TO UNIQ_108C6A8FD1B862B8');
        $this->addSql('ALTER TABLE leads ADD analyzed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE leads ADD done_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE leads ADD deal_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN leads.analyzed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN leads.done_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN leads.deal_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER INDEX idx_17904559bbb2b4d RENAME TO IDX_179045529F12C49A');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE leads DROP analyzed_at');
        $this->addSql('ALTER TABLE leads DROP done_at');
        $this->addSql('ALTER TABLE leads DROP deal_at');
        $this->addSql('ALTER INDEX idx_179045529f12c49a RENAME TO idx_17904559bbb2b4d');
        $this->addSql('ALTER INDEX uniq_108c6a8fd1b862b8 RENAME TO uniq_7e79b36ed1b862b8');
    }
}
