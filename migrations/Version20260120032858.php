<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260120032858 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_eshop column to analyses table for e-shop detection';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analyses ADD is_eshop BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE analyses ALTER total_score DROP DEFAULT');
        $this->addSql('DROP INDEX analysis_results_issues_gin_idx');
        $this->addSql('ALTER TABLE analysis_results ALTER raw_data DROP DEFAULT');
        $this->addSql('ALTER TABLE analysis_results ALTER issues DROP DEFAULT');
        $this->addSql('ALTER TABLE analysis_results ALTER score DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE analyses DROP is_eshop');
        $this->addSql('ALTER TABLE analyses ALTER total_score SET DEFAULT 0');
        $this->addSql('ALTER TABLE analysis_results ALTER raw_data SET DEFAULT \'{}\'');
        $this->addSql('ALTER TABLE analysis_results ALTER issues SET DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE analysis_results ALTER score SET DEFAULT 0');
        $this->addSql('CREATE INDEX analysis_results_issues_gin_idx ON analysis_results (issues)');
    }
}
