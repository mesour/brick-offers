<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260121143139 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Multi-tenancy ownership model: shared Company, MonitoredDomain for competitor tracking, DemandSignal subscriptions, UserAnalyzerConfig, EmailTemplate';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE demand_signal_subscriptions (id UUID NOT NULL, user_id UUID NOT NULL, demand_signal_id UUID NOT NULL, converted_lead_id UUID DEFAULT NULL, status VARCHAR(20) DEFAULT \'new\' NOT NULL, notes TEXT DEFAULT NULL, converted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CF24379851F381BD ON demand_signal_subscriptions (converted_lead_id)');
        $this->addSql('CREATE INDEX demand_signal_subscriptions_user_idx ON demand_signal_subscriptions (user_id)');
        $this->addSql('CREATE INDEX demand_signal_subscriptions_signal_idx ON demand_signal_subscriptions (demand_signal_id)');
        $this->addSql('CREATE INDEX demand_signal_subscriptions_status_idx ON demand_signal_subscriptions (status)');
        $this->addSql('CREATE UNIQUE INDEX demand_signal_subscriptions_user_signal_unique ON demand_signal_subscriptions (user_id, demand_signal_id)');
        $this->addSql('COMMENT ON COLUMN demand_signal_subscriptions.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN demand_signal_subscriptions.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN demand_signal_subscriptions.demand_signal_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN demand_signal_subscriptions.converted_lead_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN demand_signal_subscriptions.converted_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN demand_signal_subscriptions.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN demand_signal_subscriptions.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE email_templates (id UUID NOT NULL, user_id UUID DEFAULT NULL, name VARCHAR(100) NOT NULL, subject_template VARCHAR(500) NOT NULL, body_template TEXT NOT NULL, industry VARCHAR(50) DEFAULT NULL, is_default BOOLEAN DEFAULT false NOT NULL, variables JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX email_templates_user_idx ON email_templates (user_id)');
        $this->addSql('CREATE INDEX email_templates_industry_idx ON email_templates (industry)');
        $this->addSql('CREATE INDEX email_templates_is_default_idx ON email_templates (is_default)');
        $this->addSql('CREATE UNIQUE INDEX email_templates_user_name_unique ON email_templates (user_id, name)');
        $this->addSql('COMMENT ON COLUMN email_templates.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN email_templates.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN email_templates.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN email_templates.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE market_watch_filters (id UUID NOT NULL, user_id UUID NOT NULL, name VARCHAR(100) NOT NULL, active BOOLEAN DEFAULT true NOT NULL, industries JSON NOT NULL, regions JSON NOT NULL, signal_types JSON NOT NULL, keywords JSON NOT NULL, exclude_keywords JSON NOT NULL, min_value NUMERIC(15, 2) DEFAULT NULL, max_value NUMERIC(15, 2) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX market_watch_filters_user_idx ON market_watch_filters (user_id)');
        $this->addSql('CREATE INDEX market_watch_filters_active_idx ON market_watch_filters (active)');
        $this->addSql('COMMENT ON COLUMN market_watch_filters.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN market_watch_filters.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN market_watch_filters.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN market_watch_filters.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE monitored_domain_subscriptions (id UUID NOT NULL, user_id UUID NOT NULL, monitored_domain_id UUID NOT NULL, snapshot_types JSON NOT NULL, alert_on_change BOOLEAN DEFAULT true NOT NULL, min_significance VARCHAR(20) DEFAULT \'low\' NOT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX monitored_domain_subscriptions_user_idx ON monitored_domain_subscriptions (user_id)');
        $this->addSql('CREATE INDEX monitored_domain_subscriptions_domain_idx ON monitored_domain_subscriptions (monitored_domain_id)');
        $this->addSql('CREATE UNIQUE INDEX monitored_domain_subscriptions_user_domain_unique ON monitored_domain_subscriptions (user_id, monitored_domain_id)');
        $this->addSql('COMMENT ON COLUMN monitored_domain_subscriptions.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN monitored_domain_subscriptions.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN monitored_domain_subscriptions.monitored_domain_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN monitored_domain_subscriptions.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE monitored_domains (id UUID NOT NULL, domain VARCHAR(255) NOT NULL, url VARCHAR(500) NOT NULL, last_crawled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, crawl_frequency VARCHAR(15) DEFAULT \'weekly\' NOT NULL, active BOOLEAN DEFAULT true NOT NULL, metadata JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX monitored_domains_last_crawled_at_idx ON monitored_domains (last_crawled_at)');
        $this->addSql('CREATE INDEX monitored_domains_crawl_frequency_idx ON monitored_domains (crawl_frequency)');
        $this->addSql('CREATE UNIQUE INDEX monitored_domains_domain_unique ON monitored_domains (domain)');
        $this->addSql('COMMENT ON COLUMN monitored_domains.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN monitored_domains.last_crawled_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN monitored_domains.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE user_analyzer_configs (id UUID NOT NULL, user_id UUID NOT NULL, category VARCHAR(50) NOT NULL, enabled BOOLEAN DEFAULT true NOT NULL, priority INT DEFAULT 5 NOT NULL, config JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX user_analyzer_configs_user_idx ON user_analyzer_configs (user_id)');
        $this->addSql('CREATE INDEX user_analyzer_configs_enabled_idx ON user_analyzer_configs (enabled)');
        $this->addSql('CREATE UNIQUE INDEX user_analyzer_configs_user_category_unique ON user_analyzer_configs (user_id, category)');
        $this->addSql('COMMENT ON COLUMN user_analyzer_configs.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN user_analyzer_configs.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN user_analyzer_configs.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN user_analyzer_configs.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE user_company_notes (id UUID NOT NULL, user_id UUID NOT NULL, company_id UUID NOT NULL, notes TEXT DEFAULT NULL, tags JSON NOT NULL, relationship_status VARCHAR(20) DEFAULT NULL, custom_fields JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX user_company_notes_user_idx ON user_company_notes (user_id)');
        $this->addSql('CREATE INDEX user_company_notes_company_idx ON user_company_notes (company_id)');
        $this->addSql('CREATE INDEX user_company_notes_status_idx ON user_company_notes (relationship_status)');
        $this->addSql('CREATE UNIQUE INDEX user_company_notes_user_company_unique ON user_company_notes (user_id, company_id)');
        $this->addSql('COMMENT ON COLUMN user_company_notes.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN user_company_notes.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN user_company_notes.company_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN user_company_notes.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN user_company_notes.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE demand_signal_subscriptions ADD CONSTRAINT FK_CF243798A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE demand_signal_subscriptions ADD CONSTRAINT FK_CF243798C7865858 FOREIGN KEY (demand_signal_id) REFERENCES demand_signals (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE demand_signal_subscriptions ADD CONSTRAINT FK_CF24379851F381BD FOREIGN KEY (converted_lead_id) REFERENCES leads (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE email_templates ADD CONSTRAINT FK_6023E2A5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE market_watch_filters ADD CONSTRAINT FK_1067E52FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE monitored_domain_subscriptions ADD CONSTRAINT FK_A46D472CA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE monitored_domain_subscriptions ADD CONSTRAINT FK_A46D472C2294F766 FOREIGN KEY (monitored_domain_id) REFERENCES monitored_domains (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_analyzer_configs ADD CONSTRAINT FK_33A796A0A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_company_notes ADD CONSTRAINT FK_A864A9B9A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_company_notes ADD CONSTRAINT FK_A864A9B9979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE companies DROP CONSTRAINT fk_8244aa3aa76ed395');
        $this->addSql('DROP INDEX companies_user_ico_unique');
        $this->addSql('DROP INDEX companies_user_idx');
        $this->addSql('ALTER TABLE companies DROP user_id');
        $this->addSql('CREATE UNIQUE INDEX companies_ico_unique ON companies (ico)');
        $this->addSql('ALTER TABLE competitor_snapshots DROP CONSTRAINT fk_5f4efd5855458d');
        $this->addSql('DROP INDEX competitor_snapshots_lead_idx');
        $this->addSql('DROP INDEX competitor_snapshots_lead_type_idx');
        $this->addSql('ALTER TABLE competitor_snapshots RENAME COLUMN lead_id TO monitored_domain_id');
        $this->addSql('ALTER TABLE competitor_snapshots ADD CONSTRAINT FK_5F4EFD582294F766 FOREIGN KEY (monitored_domain_id) REFERENCES monitored_domains (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX competitor_snapshots_domain_idx ON competitor_snapshots (monitored_domain_id)');
        $this->addSql('CREATE INDEX competitor_snapshots_domain_type_idx ON competitor_snapshots (monitored_domain_id, snapshot_type)');
        $this->addSql('ALTER TABLE demand_signals DROP CONSTRAINT FK_79D72BD4A76ED395');
        $this->addSql('ALTER TABLE demand_signals ADD is_shared BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE demand_signals ALTER user_id DROP NOT NULL');
        $this->addSql('ALTER TABLE demand_signals ADD CONSTRAINT FK_79D72BD4A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        // Note: leads.user_id NOT NULL constraint removed - there are existing leads with null user_id
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE competitor_snapshots DROP CONSTRAINT FK_5F4EFD582294F766');
        $this->addSql('ALTER TABLE demand_signal_subscriptions DROP CONSTRAINT FK_CF243798A76ED395');
        $this->addSql('ALTER TABLE demand_signal_subscriptions DROP CONSTRAINT FK_CF243798C7865858');
        $this->addSql('ALTER TABLE demand_signal_subscriptions DROP CONSTRAINT FK_CF24379851F381BD');
        $this->addSql('ALTER TABLE email_templates DROP CONSTRAINT FK_6023E2A5A76ED395');
        $this->addSql('ALTER TABLE market_watch_filters DROP CONSTRAINT FK_1067E52FA76ED395');
        $this->addSql('ALTER TABLE monitored_domain_subscriptions DROP CONSTRAINT FK_A46D472CA76ED395');
        $this->addSql('ALTER TABLE monitored_domain_subscriptions DROP CONSTRAINT FK_A46D472C2294F766');
        $this->addSql('ALTER TABLE user_analyzer_configs DROP CONSTRAINT FK_33A796A0A76ED395');
        $this->addSql('ALTER TABLE user_company_notes DROP CONSTRAINT FK_A864A9B9A76ED395');
        $this->addSql('ALTER TABLE user_company_notes DROP CONSTRAINT FK_A864A9B9979B1AD6');
        $this->addSql('DROP TABLE demand_signal_subscriptions');
        $this->addSql('DROP TABLE email_templates');
        $this->addSql('DROP TABLE market_watch_filters');
        $this->addSql('DROP TABLE monitored_domain_subscriptions');
        $this->addSql('DROP TABLE monitored_domains');
        $this->addSql('DROP TABLE user_analyzer_configs');
        $this->addSql('DROP TABLE user_company_notes');
        $this->addSql('DROP INDEX companies_ico_unique');
        $this->addSql('ALTER TABLE companies ADD user_id UUID NOT NULL');
        $this->addSql('COMMENT ON COLUMN companies.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE companies ADD CONSTRAINT fk_8244aa3aa76ed395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX companies_user_ico_unique ON companies (user_id, ico)');
        $this->addSql('CREATE INDEX companies_user_idx ON companies (user_id)');
        $this->addSql('ALTER TABLE demand_signals DROP CONSTRAINT fk_79d72bd4a76ed395');
        $this->addSql('ALTER TABLE demand_signals DROP is_shared');
        $this->addSql('ALTER TABLE demand_signals ALTER user_id SET NOT NULL');
        $this->addSql('ALTER TABLE demand_signals ADD CONSTRAINT fk_79d72bd4a76ed395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('DROP INDEX competitor_snapshots_domain_idx');
        $this->addSql('DROP INDEX competitor_snapshots_domain_type_idx');
        $this->addSql('ALTER TABLE competitor_snapshots RENAME COLUMN monitored_domain_id TO lead_id');
        $this->addSql('ALTER TABLE competitor_snapshots ADD CONSTRAINT fk_5f4efd5855458d FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX competitor_snapshots_lead_idx ON competitor_snapshots (lead_id)');
        $this->addSql('CREATE INDEX competitor_snapshots_lead_type_idx ON competitor_snapshots (lead_id, snapshot_type)');
    }
}
