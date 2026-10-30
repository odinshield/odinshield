<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reframe Software.update_strategy → maintenance_source (BSI-CS 093 §5.2 semantics change).
 *
 * The column no longer describes how updates arrive but how the software's vulnerabilities
 * are maintained: Import (via the CSAF importer) / Manual / None. The former 'automatic'
 * value maps to 'import' (CSAF-imported); 'manual' and 'none' carry over unchanged.
 */
final class Version20260706120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Software: rename update_strategy to maintenance_source, remap automatic → import.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE software RENAME COLUMN update_strategy TO maintenance_source');
        $this->addSql("UPDATE software SET maintenance_source = 'import' WHERE maintenance_source = 'automatic'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE software SET maintenance_source = 'automatic' WHERE maintenance_source = 'import'");
        $this->addSql('ALTER TABLE software RENAME COLUMN maintenance_source TO update_strategy');
    }
}
