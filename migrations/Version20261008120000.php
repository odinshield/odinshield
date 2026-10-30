<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * #225 — EUVD removed completely: the rows the former EUVD importer created are deleted.
 *
 * Those rows were false positives of the name-containment matching that #134 replaced, and no
 * other source fits them (they did not come from CSAF, and `manual` would fake a human entry).
 * Occurrences and the software links go with them via `ON DELETE CASCADE`.
 *
 * Deleted in SQL, so the audit trail gets no delete entry for them. No production instance
 * exists yet, so only the stages carry such rows.
 */
final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#225: delete vulnerabilities with source euvd (irreversible).';
    }

    public function up(Schema $schema): void
    {
        $count = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM vulnerability WHERE source = 'euvd'");
        $this->write(\sprintf('#225: deleting %d vulnerabilities with source euvd.', $count));

        $this->addSql("DELETE FROM vulnerability WHERE source = 'euvd'");
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('#225 deleted the EUVD vulnerabilities; restore them from a backup.');
    }
}
