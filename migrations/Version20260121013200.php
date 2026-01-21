<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lead Discovery Extension - Add contact info and technology detection fields.
 */
final class Version20260121013200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Lead Discovery Extension fields: type, hasWebsite, contact info, technology detection';
    }

    public function up(Schema $schema): void
    {
        // Lead type (website or business_without_web)
        $this->addSql("ALTER TABLE leads ADD type VARCHAR(30) DEFAULT 'website' NOT NULL");
        $this->addSql('ALTER TABLE leads ADD has_website BOOLEAN DEFAULT true NOT NULL');

        // Company identification
        $this->addSql('ALTER TABLE leads ADD ico VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE leads ADD company_name VARCHAR(255) DEFAULT NULL');

        // Extracted contact information
        $this->addSql('ALTER TABLE leads ADD email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE leads ADD phone VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE leads ADD address VARCHAR(500) DEFAULT NULL');

        // Technology detection
        $this->addSql('ALTER TABLE leads ADD detected_cms VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE leads ADD detected_technologies JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE leads ADD social_media JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leads DROP type');
        $this->addSql('ALTER TABLE leads DROP has_website');
        $this->addSql('ALTER TABLE leads DROP ico');
        $this->addSql('ALTER TABLE leads DROP company_name');
        $this->addSql('ALTER TABLE leads DROP email');
        $this->addSql('ALTER TABLE leads DROP phone');
        $this->addSql('ALTER TABLE leads DROP address');
        $this->addSql('ALTER TABLE leads DROP detected_cms');
        $this->addSql('ALTER TABLE leads DROP detected_technologies');
        $this->addSql('ALTER TABLE leads DROP social_media');
    }
}
