<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260120010001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add analysis_results table and update analyses table structure';
    }

    public function up(Schema $schema): void
    {
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

        // Remove old JSONB columns from analyses table
        $this->addSql('ALTER TABLE analyses DROP COLUMN IF EXISTS raw_data');
        $this->addSql('ALTER TABLE analyses DROP COLUMN IF EXISTS issues');
        $this->addSql('ALTER TABLE analyses DROP COLUMN IF EXISTS scores');
        $this->addSql('ALTER TABLE analyses DROP COLUMN IF EXISTS error_message');

        // Drop old JSONB index if exists
        $this->addSql('DROP INDEX IF EXISTS analyses_issues_gin_idx');
    }

    public function down(Schema $schema): void
    {
        // Drop analysis_results table
        $this->addSql('ALTER TABLE analysis_results DROP CONSTRAINT FK_E81A47E67941003F');
        $this->addSql('DROP INDEX analysis_results_issues_gin_idx');
        $this->addSql('DROP TABLE analysis_results');

        // Restore old columns on analyses table
        $this->addSql('ALTER TABLE analyses ADD COLUMN raw_data JSONB NOT NULL DEFAULT \'{}\'::jsonb');
        $this->addSql('ALTER TABLE analyses ADD COLUMN issues JSONB NOT NULL DEFAULT \'[]\'::jsonb');
        $this->addSql('ALTER TABLE analyses ADD COLUMN scores JSONB NOT NULL DEFAULT \'{}\'::jsonb');
        $this->addSql('ALTER TABLE analyses ADD COLUMN error_message TEXT DEFAULT NULL');
    }
}
