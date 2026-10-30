<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260731155536 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Issue #49: separate importer-written advisory text from the human-owned description. Adds vulnerability.source_description and moves the existing importer text over for imported rows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vulnerability ADD source_description TEXT DEFAULT NULL');

        // On imported rows the description column holds importer output by definition — every
        // import run overwrote whatever was there. Move it into its new home and free the
        // description column for the human. Manually created rows are left untouched.
        $this->addSql("UPDATE vulnerability SET source_description = description, description = NULL WHERE source IN ('csaf', 'euvd') AND description IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE vulnerability SET description = source_description WHERE description IS NULL AND source_description IS NOT NULL');
        $this->addSql('ALTER TABLE vulnerability DROP source_description');
    }
}
