<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * EUVD importer (#10): euvd_import_state single-row cursor table,
 * vulnerability.euvd_sources + vulnerability.epss_score columns,
 * new VulnerabilitySource::Euvd enum value (no data migration needed, additive).
 */
final class Version20260722120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'EUVD importer schema: euvd_import_state table, vulnerability.euvd_sources + epss_score.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE euvd_import_state (id VARCHAR(16) NOT NULL, last_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_success_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error_message TEXT DEFAULT NULL, consecutive_failures INT DEFAULT 0 NOT NULL, paused BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE vulnerability ADD euvd_sources JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE vulnerability ADD epss_score DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE euvd_import_state');
        $this->addSql('ALTER TABLE vulnerability DROP euvd_sources');
        $this->addSql('ALTER TABLE vulnerability DROP epss_score');
    }
}
