<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261030123851 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#223: single-row state of the daily update check (update_check_state)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE update_check_state (id VARCHAR(16) NOT NULL, last_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_success_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error_message TEXT DEFAULT NULL, latest_version VARCHAR(64) DEFAULT NULL, latest_url VARCHAR(2048) DEFAULT NULL, latest_security BOOLEAN DEFAULT false NOT NULL, latest_released_at DATE DEFAULT NULL, notified_version VARCHAR(64) DEFAULT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE update_check_state');
    }
}
