<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260122192956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change allowed_industries JSON array to single industry column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD industry VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE users DROP allowed_industries');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD allowed_industries JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE users DROP industry');
    }
}
