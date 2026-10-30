<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260722152520 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Issue #47: running import counter on the EUVD cursor so /import-status can show a number instead of a placeholder.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE euvd_import_state ADD advisories_imported_total INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE euvd_import_state DROP advisories_imported_total');
    }
}
