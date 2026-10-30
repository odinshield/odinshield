<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Issue #59: vulnerability.source becomes system-owned. Realigns every row with its import evidence and retires the dead nvd value.';
    }

    public function up(Schema $schema): void
    {
        // The source column used to be a free-form form field while three importer lookups filtered
        // on it — a user could relabel a row and silently produce duplicates or misrouted
        // enrichment. Those lookups now key on the evidence columns, so the label has to agree
        // with the evidence from here on.
        //
        // Order matters and mirrors the importers: a CSAF row that a EUVD run enriched carries
        // both evidence columns and stays 'csaf'. Rows without any evidence are manual entries —
        // this also retires 'nvd', a value no code path could ever produce (it only ever came from
        // the seeder and the form).
        $this->addSql(<<<'SQL'
            UPDATE vulnerability SET source = CASE
                WHEN COALESCE(csaf_sources::jsonb, '[]'::jsonb) <> '[]'::jsonb THEN 'csaf'
                WHEN COALESCE(euvd_sources::jsonb, '[]'::jsonb) <> '[]'::jsonb THEN 'euvd'
                ELSE 'manual'
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Irreversible by nature: the pre-migration labels carried no information beyond what the
        // evidence columns already state, and the drift this corrects cannot be reconstructed.
        $this->throwIrreversibleMigrationException('Realigning source with the import evidence discards the previous, unreliable labels.');
    }
}
