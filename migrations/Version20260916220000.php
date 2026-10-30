<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #50 — manual severity override for imported vulnerabilities.
 *
 * `source_severity` holds the severity the importer derived from the advisory; `severity` may now
 * differ from it when `severity_override_reason` is set. Backfill: every CSAF-imported row gets its
 * current severity as source value. That is exact wherever nobody changed the severity by hand —
 * on the Q data copy that was every row (no manual severity change in the audit trail).
 */
final class Version20260916220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#50: source_severity + severity_override_reason on vulnerability, backfilled for CSAF-imported rows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vulnerability ADD source_severity VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE vulnerability ADD severity_override_reason TEXT DEFAULT NULL');
        $this->addSql('UPDATE vulnerability SET source_severity = severity WHERE csaf_sources IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vulnerability DROP source_severity');
        $this->addSql('ALTER TABLE vulnerability DROP severity_override_reason');
    }
}
