<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260722152152 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Issue #46: track per-feed failure streaks so a failing ROLIE feed holds the fetch watermark instead of silently skipping its advisories.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE csaf_provider_state ADD failing_feeds JSON DEFAULT \'{}\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE csaf_provider_state DROP failing_feeds');
    }
}
