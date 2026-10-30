<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Collapse csaf_provider_state.active + .paused into a single tri-state `status` enum
 * (enabled / auto_paused / disabled). Backfill preserves existing operational state.
 */
final class Version20260515165722 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CSAF provider state: replace active+paused booleans with tri-state status column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE csaf_provider_state ADD status VARCHAR(16) DEFAULT 'enabled' NOT NULL");
        // Manual disable wins over auto-pause: a provider with active=false stays disabled
        // regardless of paused state. Resume command would otherwise silently re-enable a
        // deliberately disabled provider.
        $this->addSql(
            "UPDATE csaf_provider_state SET status = CASE
                WHEN active = false THEN 'disabled'
                WHEN paused = true THEN 'auto_paused'
                ELSE 'enabled'
            END"
        );
        $this->addSql('ALTER TABLE csaf_provider_state DROP active');
        $this->addSql('ALTER TABLE csaf_provider_state DROP paused');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE csaf_provider_state ADD active BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE csaf_provider_state ADD paused BOOLEAN DEFAULT false NOT NULL');
        $this->addSql(
            "UPDATE csaf_provider_state SET
                active = CASE WHEN status = 'disabled' THEN false ELSE true END,
                paused = CASE WHEN status = 'auto_paused' THEN true ELSE false END"
        );
        $this->addSql('ALTER TABLE csaf_provider_state DROP status');
    }
}
