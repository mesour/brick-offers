<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260120000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create affiliates and leads tables for Lead Discovery module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE affiliates (
            id UUID NOT NULL,
            name VARCHAR(255) NOT NULL,
            hash VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL,
            commission_rate NUMERIC(5, 2) NOT NULL,
            active BOOLEAN NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7E79B36ED1B862B8 ON affiliates (hash)');
        $this->addSql('COMMENT ON COLUMN affiliates.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN affiliates.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN affiliates.updated_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE leads (
            id UUID NOT NULL,
            affiliate_id UUID DEFAULT NULL,
            url VARCHAR(500) NOT NULL,
            domain VARCHAR(255) NOT NULL,
            source VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            priority INT NOT NULL,
            metadata JSON NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX leads_domain_unique ON leads (domain)');
        $this->addSql('CREATE INDEX leads_status_idx ON leads (status)');
        $this->addSql('CREATE INDEX leads_source_idx ON leads (source)');
        $this->addSql('CREATE INDEX IDX_17904559BBB2B4D ON leads (affiliate_id)');
        $this->addSql('COMMENT ON COLUMN leads.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN leads.affiliate_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN leads.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN leads.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_17904559BBB2B4D FOREIGN KEY (affiliate_id) REFERENCES affiliates (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leads DROP CONSTRAINT FK_17904559BBB2B4D');
        $this->addSql('DROP TABLE leads');
        $this->addSql('DROP TABLE affiliates');
    }
}
