<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260512171434 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CSAF per-document import: add cve_ids column for CVE bundling';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vulnerability ADD cve_ids JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE vulnerability DROP cve_ids');
    }
}
