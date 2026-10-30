<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Align the declared DB defaults of user.must_change_password and user.email_notifications with
 * the entity PHP defaults (audit L7). Doctrine always inserts the PHP value explicitly, so this is
 * a schema-consistency fix only — it does not change existing rows or runtime behaviour.
 */
final class Version20260706130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: align must_change_password / email_notifications column defaults with PHP defaults.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ALTER must_change_password SET DEFAULT true');
        $this->addSql('ALTER TABLE "user" ALTER email_notifications SET DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ALTER must_change_password SET DEFAULT false');
        $this->addSql('ALTER TABLE "user" ALTER email_notifications SET DEFAULT true');
    }
}
