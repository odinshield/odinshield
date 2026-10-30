<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CSAF importer: csaf_provider_state table, vulnerability.csaf_sources column,
 * data migration mitre → manual for VulnerabilitySource, and BSI-WID bootstrap row.
 */
final class Version20260512153056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CSAF importer schema + bootstrap; migrate VulnerabilitySource::Mitre to Manual';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE vulnerability SET source = 'manual' WHERE source = 'mitre'");

        $this->addSql('CREATE TABLE csaf_provider_state (provider_key VARCHAR(64) NOT NULL, display_name VARCHAR(200) NOT NULL, provider_metadata_url VARCHAR(500) NOT NULL, last_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_success_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error_message TEXT DEFAULT NULL, consecutive_failures INT DEFAULT 0 NOT NULL, active BOOLEAN DEFAULT true NOT NULL, paused BOOLEAN DEFAULT false NOT NULL, advisories_imported_total INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (provider_key))');
        $this->addSql('ALTER TABLE vulnerability ADD csaf_sources JSON DEFAULT NULL');

        $this->addSql(<<<'SQL'
            INSERT INTO csaf_provider_state
                (provider_key, display_name, provider_metadata_url, consecutive_failures, active, paused, advisories_imported_total, created_at, updated_at)
            VALUES
                ('bsi_wid', 'BSI Warn- und Informationsdienst', 'https://wid.cert-bund.de/.well-known/csaf/provider-metadata.json', 0, TRUE, FALSE, 0, NOW(), NOW())
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE csaf_provider_state');
        $this->addSql('ALTER TABLE vulnerability DROP csaf_sources');
    }
}
