<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260120010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create analyses and analysis_results tables for Lead Analyzer module';
    }

    public function up(Schema $schema): void
    {
        // Create analyses table (parent table)
        $this->addSql('CREATE TABLE analyses (
            id UUID NOT NULL,
            lead_id UUID NOT NULL,
            status VARCHAR(20) NOT NULL,
            started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            total_score INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');

        // Indexes for analyses
        $this->addSql('CREATE INDEX analyses_lead_id_idx ON analyses (lead_id)');
        $this->addSql('CREATE INDEX analyses_status_idx ON analyses (status)');
        $this->addSql('CREATE INDEX analyses_total_score_idx ON analyses (total_score)');

        // UUID comments for analyses
        $this->addSql('COMMENT ON COLUMN analyses.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN analyses.lead_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN analyses.started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN analyses.completed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN analyses.created_at IS \'(DC2Type:datetime_immutable)\'');

        // Foreign key for analyses -> leads
        $this->addSql('ALTER TABLE analyses ADD CONSTRAINT FK_4CA6C32255458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Create analysis_results table (child table per analyzer type)
        $this->addSql('CREATE TABLE analysis_results (
            id UUID NOT NULL,
            analysis_id UUID NOT NULL,
            category VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            raw_data JSONB NOT NULL DEFAULT \'{}\'::jsonb,
            issues JSONB NOT NULL DEFAULT \'[]\'::jsonb,
            score INT NOT NULL DEFAULT 0,
            error_message TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');

        // Indexes for analysis_results
        $this->addSql('CREATE INDEX analysis_results_analysis_id_idx ON analysis_results (analysis_id)');
        $this->addSql('CREATE INDEX analysis_results_category_idx ON analysis_results (category)');
        $this->addSql('CREATE INDEX analysis_results_status_idx ON analysis_results (status)');
        $this->addSql('CREATE UNIQUE INDEX analysis_results_analysis_category_unique ON analysis_results (analysis_id, category)');

        // JSONB indexes for efficient querying
        $this->addSql('CREATE INDEX analysis_results_issues_gin_idx ON analysis_results USING GIN (issues)');

        // UUID comments for analysis_results
        $this->addSql('COMMENT ON COLUMN analysis_results.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN analysis_results.analysis_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN analysis_results.started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN analysis_results.completed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN analysis_results.created_at IS \'(DC2Type:datetime_immutable)\'');

        // Foreign key for analysis_results -> analyses
        $this->addSql('ALTER TABLE analysis_results ADD CONSTRAINT FK_E81A47E67941003F FOREIGN KEY (analysis_id) REFERENCES analyses (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_results DROP CONSTRAINT FK_E81A47E67941003F');
        $this->addSql('ALTER TABLE analyses DROP CONSTRAINT FK_4CA6C32255458D');
        $this->addSql('DROP INDEX analysis_results_issues_gin_idx');
        $this->addSql('DROP TABLE analysis_results');
        $this->addSql('DROP TABLE analyses');
    }
}
