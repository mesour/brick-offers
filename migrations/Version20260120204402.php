<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260120204402 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Industry support: new tables (analysis_snapshots, industry_benchmarks), extend leads and analyses with industry fields, add analysis history tracking (sequenceNumber, previousAnalysis, scoreDelta, issueDelta)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE analysis_snapshots (id UUID NOT NULL, lead_id UUID NOT NULL, analysis_id UUID DEFAULT NULL, period_type VARCHAR(10) NOT NULL, period_start DATE NOT NULL, total_score INT NOT NULL, category_scores JSON NOT NULL, issue_count INT NOT NULL, critical_issue_count INT NOT NULL, top_issues JSON NOT NULL, score_delta INT DEFAULT NULL, industry VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_BF1A5F0755458D ON analysis_snapshots (lead_id)');
        $this->addSql('CREATE INDEX IDX_BF1A5F077941003F ON analysis_snapshots (analysis_id)');
        $this->addSql('CREATE INDEX snapshots_lead_period_idx ON analysis_snapshots (lead_id, period_start)');
        $this->addSql('CREATE INDEX snapshots_industry_period_idx ON analysis_snapshots (industry, period_start)');
        $this->addSql('CREATE UNIQUE INDEX snapshots_lead_period_unique ON analysis_snapshots (lead_id, period_type, period_start)');
        $this->addSql('COMMENT ON COLUMN analysis_snapshots.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN analysis_snapshots.lead_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN analysis_snapshots.analysis_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN analysis_snapshots.period_start IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN analysis_snapshots.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE industry_benchmarks (id UUID NOT NULL, industry VARCHAR(50) NOT NULL, period_start DATE NOT NULL, avg_score DOUBLE PRECISION NOT NULL, median_score DOUBLE PRECISION NOT NULL, percentiles JSON NOT NULL, avg_category_scores JSON NOT NULL, top_issues JSON NOT NULL, sample_size INT NOT NULL, avg_issue_count DOUBLE PRECISION NOT NULL, avg_critical_issue_count DOUBLE PRECISION NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX benchmarks_industry_period_unique ON industry_benchmarks (industry, period_start)');
        $this->addSql('COMMENT ON COLUMN industry_benchmarks.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN industry_benchmarks.period_start IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN industry_benchmarks.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE analysis_snapshots ADD CONSTRAINT FK_BF1A5F0755458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE analysis_snapshots ADD CONSTRAINT FK_BF1A5F077941003F FOREIGN KEY (analysis_id) REFERENCES analyses (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE analyses ADD previous_analysis_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD industry VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD sequence_number INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE analyses ADD score_delta INT DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD is_improved BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE analyses ADD issue_delta JSON NOT NULL DEFAULT \'{"added":[],"removed":[],"unchanged_count":0}\'');
        $this->addSql('ALTER TABLE analyses ALTER is_eshop DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN analyses.previous_analysis_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE analyses ADD CONSTRAINT FK_AC86883CCB8B0B55 FOREIGN KEY (previous_analysis_id) REFERENCES analyses (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_AC86883CCB8B0B55 ON analyses (previous_analysis_id)');
        $this->addSql('CREATE INDEX analyses_industry_idx ON analyses (industry)');
        $this->addSql('CREATE INDEX analyses_sequence_idx ON analyses (lead_id, sequence_number)');
        $this->addSql('CREATE INDEX analyses_is_improved_idx ON analyses (is_improved)');
        $this->addSql('ALTER TABLE leads ADD latest_analysis_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE leads ADD industry VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE leads ADD analysis_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE leads ADD last_analyzed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE leads ADD snapshot_period VARCHAR(10) DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN leads.latest_analysis_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN leads.last_analyzed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_17904552A2EE128E FOREIGN KEY (latest_analysis_id) REFERENCES analyses (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_17904552A2EE128E ON leads (latest_analysis_id)');
        $this->addSql('CREATE INDEX leads_industry_idx ON leads (industry)');
        $this->addSql('CREATE INDEX leads_last_analyzed_at_idx ON leads (last_analyzed_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE analysis_snapshots DROP CONSTRAINT FK_BF1A5F0755458D');
        $this->addSql('ALTER TABLE analysis_snapshots DROP CONSTRAINT FK_BF1A5F077941003F');
        $this->addSql('DROP TABLE analysis_snapshots');
        $this->addSql('DROP TABLE industry_benchmarks');
        $this->addSql('ALTER TABLE leads DROP CONSTRAINT FK_17904552A2EE128E');
        $this->addSql('DROP INDEX UNIQ_17904552A2EE128E');
        $this->addSql('DROP INDEX leads_industry_idx');
        $this->addSql('DROP INDEX leads_last_analyzed_at_idx');
        $this->addSql('ALTER TABLE leads DROP latest_analysis_id');
        $this->addSql('ALTER TABLE leads DROP industry');
        $this->addSql('ALTER TABLE leads DROP analysis_count');
        $this->addSql('ALTER TABLE leads DROP last_analyzed_at');
        $this->addSql('ALTER TABLE leads DROP snapshot_period');
        $this->addSql('ALTER TABLE analyses DROP CONSTRAINT FK_AC86883CCB8B0B55');
        $this->addSql('DROP INDEX IDX_AC86883CCB8B0B55');
        $this->addSql('DROP INDEX analyses_industry_idx');
        $this->addSql('DROP INDEX analyses_sequence_idx');
        $this->addSql('DROP INDEX analyses_is_improved_idx');
        $this->addSql('ALTER TABLE analyses DROP previous_analysis_id');
        $this->addSql('ALTER TABLE analyses DROP industry');
        $this->addSql('ALTER TABLE analyses DROP sequence_number');
        $this->addSql('ALTER TABLE analyses DROP score_delta');
        $this->addSql('ALTER TABLE analyses DROP is_improved');
        $this->addSql('ALTER TABLE analyses DROP issue_delta');
        $this->addSql('ALTER TABLE analyses ALTER is_eshop SET DEFAULT false');
    }
}
