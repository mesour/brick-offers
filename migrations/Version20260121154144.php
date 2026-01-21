<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260121154144 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE proposals (id UUID NOT NULL, user_id UUID NOT NULL, lead_id UUID DEFAULT NULL, analysis_id UUID DEFAULT NULL, original_user_id UUID DEFAULT NULL, type VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, industry VARCHAR(50) DEFAULT NULL, title VARCHAR(255) NOT NULL, content TEXT DEFAULT NULL, summary TEXT DEFAULT NULL, outputs JSON NOT NULL, ai_metadata JSON NOT NULL, is_ai_generated BOOLEAN DEFAULT true NOT NULL, is_customized BOOLEAN DEFAULT false NOT NULL, recyclable BOOLEAN DEFAULT true NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, recycled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_A5BA3A8F55458D ON proposals (lead_id)');
        $this->addSql('CREATE INDEX IDX_A5BA3A8F7941003F ON proposals (analysis_id)');
        $this->addSql('CREATE INDEX IDX_A5BA3A8F21EE7D62 ON proposals (original_user_id)');
        $this->addSql('CREATE INDEX proposals_user_idx ON proposals (user_id)');
        $this->addSql('CREATE INDEX proposals_user_status_idx ON proposals (user_id, status)');
        $this->addSql('CREATE INDEX proposals_status_idx ON proposals (status)');
        $this->addSql('CREATE INDEX proposals_type_idx ON proposals (type)');
        $this->addSql('CREATE INDEX proposals_industry_idx ON proposals (industry)');
        $this->addSql('CREATE INDEX proposals_recyclable_idx ON proposals (status, recyclable, is_customized)');
        $this->addSql('CREATE UNIQUE INDEX proposals_lead_type_unique ON proposals (lead_id, type)');
        $this->addSql('COMMENT ON COLUMN proposals.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN proposals.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN proposals.lead_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN proposals.analysis_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN proposals.original_user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN proposals.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN proposals.recycled_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN proposals.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN proposals.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE proposals ADD CONSTRAINT FK_A5BA3A8FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE proposals ADD CONSTRAINT FK_A5BA3A8F55458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE proposals ADD CONSTRAINT FK_A5BA3A8F7941003F FOREIGN KEY (analysis_id) REFERENCES analyses (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE proposals ADD CONSTRAINT FK_A5BA3A8F21EE7D62 FOREIGN KEY (original_user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE proposals DROP CONSTRAINT FK_A5BA3A8FA76ED395');
        $this->addSql('ALTER TABLE proposals DROP CONSTRAINT FK_A5BA3A8F55458D');
        $this->addSql('ALTER TABLE proposals DROP CONSTRAINT FK_A5BA3A8F7941003F');
        $this->addSql('ALTER TABLE proposals DROP CONSTRAINT FK_A5BA3A8F21EE7D62');
        $this->addSql('DROP TABLE proposals');
    }
}
