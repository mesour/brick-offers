<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260121141721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE companies (id UUID NOT NULL, user_id UUID NOT NULL, ico VARCHAR(8) NOT NULL, dic VARCHAR(20) DEFAULT NULL, name VARCHAR(255) NOT NULL, legal_form VARCHAR(100) DEFAULT NULL, street VARCHAR(255) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, city_part VARCHAR(100) DEFAULT NULL, postal_code VARCHAR(10) DEFAULT NULL, business_status VARCHAR(50) DEFAULT NULL, ares_data JSON DEFAULT NULL, ares_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX companies_name_idx ON companies (name)');
        $this->addSql('CREATE INDEX companies_business_status_idx ON companies (business_status)');
        $this->addSql('CREATE INDEX companies_user_idx ON companies (user_id)');
        $this->addSql('CREATE UNIQUE INDEX companies_user_ico_unique ON companies (user_id, ico)');
        $this->addSql('COMMENT ON COLUMN companies.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN companies.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN companies.ares_updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN companies.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN companies.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE competitor_snapshots (id UUID NOT NULL, lead_id UUID NOT NULL, previous_snapshot_id UUID DEFAULT NULL, snapshot_type VARCHAR(20) NOT NULL, content_hash VARCHAR(64) NOT NULL, previous_hash VARCHAR(64) DEFAULT NULL, has_changes BOOLEAN DEFAULT false NOT NULL, significance VARCHAR(20) DEFAULT NULL, raw_data JSON NOT NULL, changes JSON NOT NULL, metrics JSON NOT NULL, source_url VARCHAR(1000) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_5F4EFD58C9F33234 ON competitor_snapshots (previous_snapshot_id)');
        $this->addSql('CREATE INDEX competitor_snapshots_lead_idx ON competitor_snapshots (lead_id)');
        $this->addSql('CREATE INDEX competitor_snapshots_type_idx ON competitor_snapshots (snapshot_type)');
        $this->addSql('CREATE INDEX competitor_snapshots_significance_idx ON competitor_snapshots (significance)');
        $this->addSql('CREATE INDEX competitor_snapshots_created_at_idx ON competitor_snapshots (created_at)');
        $this->addSql('CREATE INDEX competitor_snapshots_lead_type_idx ON competitor_snapshots (lead_id, snapshot_type)');
        $this->addSql('COMMENT ON COLUMN competitor_snapshots.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN competitor_snapshots.lead_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN competitor_snapshots.previous_snapshot_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN competitor_snapshots.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE demand_signals (id UUID NOT NULL, user_id UUID NOT NULL, company_id UUID DEFAULT NULL, converted_lead_id UUID DEFAULT NULL, source VARCHAR(30) NOT NULL, signal_type VARCHAR(50) NOT NULL, status VARCHAR(20) NOT NULL, external_id VARCHAR(255) DEFAULT NULL, title VARCHAR(500) NOT NULL, description TEXT DEFAULT NULL, ico VARCHAR(8) DEFAULT NULL, company_name VARCHAR(255) DEFAULT NULL, contact_email VARCHAR(255) DEFAULT NULL, contact_phone VARCHAR(50) DEFAULT NULL, contact_person VARCHAR(255) DEFAULT NULL, value NUMERIC(15, 2) DEFAULT NULL, value_max NUMERIC(15, 2) DEFAULT NULL, currency VARCHAR(3) DEFAULT \'CZK\' NOT NULL, industry VARCHAR(50) DEFAULT NULL, location VARCHAR(255) DEFAULT NULL, region VARCHAR(255) DEFAULT NULL, deadline TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, source_url VARCHAR(1000) DEFAULT NULL, raw_data JSON NOT NULL, converted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_79D72BD4979B1AD6 ON demand_signals (company_id)');
        $this->addSql('CREATE INDEX IDX_79D72BD451F381BD ON demand_signals (converted_lead_id)');
        $this->addSql('CREATE INDEX demand_signals_source_idx ON demand_signals (source)');
        $this->addSql('CREATE INDEX demand_signals_type_idx ON demand_signals (signal_type)');
        $this->addSql('CREATE INDEX demand_signals_status_idx ON demand_signals (status)');
        $this->addSql('CREATE INDEX demand_signals_industry_idx ON demand_signals (industry)');
        $this->addSql('CREATE INDEX demand_signals_deadline_idx ON demand_signals (deadline)');
        $this->addSql('CREATE INDEX demand_signals_published_at_idx ON demand_signals (published_at)');
        $this->addSql('CREATE INDEX demand_signals_ico_idx ON demand_signals (ico)');
        $this->addSql('CREATE INDEX demand_signals_user_idx ON demand_signals (user_id)');
        $this->addSql('CREATE UNIQUE INDEX demand_signals_source_external_id ON demand_signals (source, external_id)');
        $this->addSql('COMMENT ON COLUMN demand_signals.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN demand_signals.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN demand_signals.company_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN demand_signals.converted_lead_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN demand_signals.deadline IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN demand_signals.published_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN demand_signals.converted_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN demand_signals.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN demand_signals.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, code VARCHAR(50) NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(255) DEFAULT NULL, active BOOLEAN DEFAULT true NOT NULL, settings JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX users_name_idx ON users (name)');
        $this->addSql('CREATE UNIQUE INDEX users_code_unique ON users (code)');
        $this->addSql('CREATE UNIQUE INDEX users_email_unique ON users (email)');
        $this->addSql('COMMENT ON COLUMN users.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN users.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE companies ADD CONSTRAINT FK_8244AA3AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE competitor_snapshots ADD CONSTRAINT FK_5F4EFD5855458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE competitor_snapshots ADD CONSTRAINT FK_5F4EFD58C9F33234 FOREIGN KEY (previous_snapshot_id) REFERENCES competitor_snapshots (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE demand_signals ADD CONSTRAINT FK_79D72BD4A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE demand_signals ADD CONSTRAINT FK_79D72BD4979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE demand_signals ADD CONSTRAINT FK_79D72BD451F381BD FOREIGN KEY (converted_lead_id) REFERENCES leads (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE analyses ALTER issue_delta DROP DEFAULT');
        $this->addSql('DROP INDEX benchmarks_industry_period_unique');
        $this->addSql('ALTER TABLE industry_benchmarks ADD user_id UUID NOT NULL');
        $this->addSql('COMMENT ON COLUMN industry_benchmarks.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE industry_benchmarks ADD CONSTRAINT FK_FA2AA974A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX benchmarks_industry_idx ON industry_benchmarks (industry, period_start)');
        $this->addSql('CREATE INDEX benchmarks_user_idx ON industry_benchmarks (user_id)');
        $this->addSql('CREATE UNIQUE INDEX benchmarks_user_industry_period_unique ON industry_benchmarks (user_id, industry, period_start)');
        $this->addSql('DROP INDEX leads_domain_unique');
        $this->addSql('ALTER TABLE leads ADD user_id UUID NOT NULL');
        $this->addSql('ALTER TABLE leads ADD company_id UUID DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN leads.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN leads.company_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_17904552A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_17904552979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX leads_company_idx ON leads (company_id)');
        $this->addSql('CREATE INDEX leads_user_idx ON leads (user_id)');
        $this->addSql('CREATE UNIQUE INDEX leads_user_domain_unique ON leads (user_id, domain)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE leads DROP CONSTRAINT FK_17904552979B1AD6');
        $this->addSql('ALTER TABLE industry_benchmarks DROP CONSTRAINT FK_FA2AA974A76ED395');
        $this->addSql('ALTER TABLE leads DROP CONSTRAINT FK_17904552A76ED395');
        $this->addSql('ALTER TABLE companies DROP CONSTRAINT FK_8244AA3AA76ED395');
        $this->addSql('ALTER TABLE competitor_snapshots DROP CONSTRAINT FK_5F4EFD5855458D');
        $this->addSql('ALTER TABLE competitor_snapshots DROP CONSTRAINT FK_5F4EFD58C9F33234');
        $this->addSql('ALTER TABLE demand_signals DROP CONSTRAINT FK_79D72BD4A76ED395');
        $this->addSql('ALTER TABLE demand_signals DROP CONSTRAINT FK_79D72BD4979B1AD6');
        $this->addSql('ALTER TABLE demand_signals DROP CONSTRAINT FK_79D72BD451F381BD');
        $this->addSql('DROP TABLE companies');
        $this->addSql('DROP TABLE competitor_snapshots');
        $this->addSql('DROP TABLE demand_signals');
        $this->addSql('DROP TABLE users');
        $this->addSql('ALTER TABLE analyses ALTER issue_delta SET DEFAULT \'{"added":[],"removed":[],"unchanged_count":0}\'');
        $this->addSql('DROP INDEX benchmarks_industry_idx');
        $this->addSql('DROP INDEX benchmarks_user_idx');
        $this->addSql('DROP INDEX benchmarks_user_industry_period_unique');
        $this->addSql('ALTER TABLE industry_benchmarks DROP user_id');
        $this->addSql('CREATE UNIQUE INDEX benchmarks_industry_period_unique ON industry_benchmarks (industry, period_start)');
        $this->addSql('DROP INDEX leads_company_idx');
        $this->addSql('DROP INDEX leads_user_idx');
        $this->addSql('DROP INDEX leads_user_domain_unique');
        $this->addSql('ALTER TABLE leads DROP user_id');
        $this->addSql('ALTER TABLE leads DROP company_id');
        $this->addSql('CREATE UNIQUE INDEX leads_domain_unique ON leads (domain)');
    }
}
