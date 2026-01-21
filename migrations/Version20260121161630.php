<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260121161630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create offers and user_email_templates tables for Offer Module';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE offers (id UUID NOT NULL, user_id UUID NOT NULL, lead_id UUID NOT NULL, proposal_id UUID DEFAULT NULL, analysis_id UUID DEFAULT NULL, email_template_id UUID DEFAULT NULL, approved_by_id UUID DEFAULT NULL, status VARCHAR(20) NOT NULL, subject VARCHAR(500) NOT NULL, body TEXT DEFAULT NULL, plain_text_body TEXT DEFAULT NULL, tracking_token VARCHAR(100) NOT NULL, recipient_email VARCHAR(255) NOT NULL, recipient_name VARCHAR(255) DEFAULT NULL, ai_metadata JSON NOT NULL, rejection_reason TEXT DEFAULT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, opened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, clicked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, responded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, converted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DA460427B46BB896 ON offers (tracking_token)');
        $this->addSql('CREATE INDEX IDX_DA460427F4792058 ON offers (proposal_id)');
        $this->addSql('CREATE INDEX IDX_DA4604277941003F ON offers (analysis_id)');
        $this->addSql('CREATE INDEX IDX_DA460427131A730F ON offers (email_template_id)');
        $this->addSql('CREATE INDEX IDX_DA4604272D234F6A ON offers (approved_by_id)');
        $this->addSql('CREATE INDEX offers_user_idx ON offers (user_id)');
        $this->addSql('CREATE INDEX offers_user_status_idx ON offers (user_id, status)');
        $this->addSql('CREATE INDEX offers_lead_idx ON offers (lead_id)');
        $this->addSql('CREATE INDEX offers_status_idx ON offers (status)');
        $this->addSql('CREATE INDEX offers_status_sent_idx ON offers (status, sent_at)');
        $this->addSql('COMMENT ON COLUMN offers.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN offers.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN offers.lead_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN offers.proposal_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN offers.analysis_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN offers.email_template_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN offers.approved_by_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN offers.approved_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN offers.sent_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN offers.opened_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN offers.clicked_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN offers.responded_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN offers.converted_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN offers.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN offers.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE user_email_templates (id UUID NOT NULL, user_id UUID NOT NULL, base_template_id UUID DEFAULT NULL, name VARCHAR(100) NOT NULL, subject_template VARCHAR(500) NOT NULL, body_template TEXT NOT NULL, industry VARCHAR(50) DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, ai_personalization_enabled BOOLEAN DEFAULT true NOT NULL, ai_personalization_prompt TEXT DEFAULT NULL, variables JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_72FF8E2ACFFF940 ON user_email_templates (base_template_id)');
        $this->addSql('CREATE INDEX user_email_templates_user_idx ON user_email_templates (user_id)');
        $this->addSql('CREATE INDEX user_email_templates_user_industry_active_idx ON user_email_templates (user_id, industry, is_active)');
        $this->addSql('CREATE UNIQUE INDEX user_email_templates_user_name_unique ON user_email_templates (user_id, name)');
        $this->addSql('COMMENT ON COLUMN user_email_templates.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN user_email_templates.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN user_email_templates.base_template_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN user_email_templates.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN user_email_templates.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE offers ADD CONSTRAINT FK_DA460427A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE offers ADD CONSTRAINT FK_DA46042755458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE offers ADD CONSTRAINT FK_DA460427F4792058 FOREIGN KEY (proposal_id) REFERENCES proposals (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE offers ADD CONSTRAINT FK_DA4604277941003F FOREIGN KEY (analysis_id) REFERENCES analyses (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE offers ADD CONSTRAINT FK_DA460427131A730F FOREIGN KEY (email_template_id) REFERENCES email_templates (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE offers ADD CONSTRAINT FK_DA4604272D234F6A FOREIGN KEY (approved_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_email_templates ADD CONSTRAINT FK_72FF8E2AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_email_templates ADD CONSTRAINT FK_72FF8E2ACFFF940 FOREIGN KEY (base_template_id) REFERENCES email_templates (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE offers DROP CONSTRAINT FK_DA460427A76ED395');
        $this->addSql('ALTER TABLE offers DROP CONSTRAINT FK_DA46042755458D');
        $this->addSql('ALTER TABLE offers DROP CONSTRAINT FK_DA460427F4792058');
        $this->addSql('ALTER TABLE offers DROP CONSTRAINT FK_DA4604277941003F');
        $this->addSql('ALTER TABLE offers DROP CONSTRAINT FK_DA460427131A730F');
        $this->addSql('ALTER TABLE offers DROP CONSTRAINT FK_DA4604272D234F6A');
        $this->addSql('ALTER TABLE user_email_templates DROP CONSTRAINT FK_72FF8E2AA76ED395');
        $this->addSql('ALTER TABLE user_email_templates DROP CONSTRAINT FK_72FF8E2ACFFF940');
        $this->addSql('DROP TABLE offers');
        $this->addSql('DROP TABLE user_email_templates');
    }
}
