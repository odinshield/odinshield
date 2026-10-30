<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260508151350 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lock_version column for Doctrine optimistic locking on all auditable entities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset_group ADD lock_version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE asset_type ADD lock_version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE software ADD lock_version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD lock_version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE user_group ADD lock_version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE vendor ADD lock_version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE vulnerability ADD lock_version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE vulnerability_occurrence ADD lock_version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset_group DROP lock_version');
        $this->addSql('ALTER TABLE asset_type DROP lock_version');
        $this->addSql('ALTER TABLE software DROP lock_version');
        $this->addSql('ALTER TABLE "user" DROP lock_version');
        $this->addSql('ALTER TABLE user_group DROP lock_version');
        $this->addSql('ALTER TABLE vendor DROP lock_version');
        $this->addSql('ALTER TABLE vulnerability DROP lock_version');
        $this->addSql('ALTER TABLE vulnerability_occurrence DROP lock_version');
    }
}
