<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505132650 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Software.predecessor (self-ref FK) and Software.supersededAt for version-lineage workflow.';
    }

    public function preUp(Schema $schema): void
    {
        // On a fresh install the software table does not exist yet — the consolidated baseline
        // migration (Version20260506112912) runs last and creates it with all columns included.
        $tableExists = (bool) $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'software'"
        );
        if (!$tableExists) {
            $this->skipIf(true, 'software table absent — consolidated migration will create it with all columns.');

            return;
        }

        $columnExists = (bool) $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'software' AND column_name = 'predecessor_id'"
        );
        $this->skipIf($columnExists, 'Column software.predecessor_id already exists — migration skipped.');
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE software ADD superseded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE software ADD predecessor_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE software ADD CONSTRAINT FK_77D068CF68C90015 FOREIGN KEY (predecessor_id) REFERENCES software (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_77D068CF68C90015 ON software (predecessor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE software DROP CONSTRAINT FK_77D068CF68C90015');
        $this->addSql('DROP INDEX IDX_77D068CF68C90015');
        $this->addSql('ALTER TABLE software DROP superseded_at');
        $this->addSql('ALTER TABLE software DROP predecessor_id');
    }
}
