<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename ROLE_ASSET_OWNER to ROLE_HARDWARE_OWNER in user.roles JSON column.';
    }

    public function preUp(Schema $schema): void
    {
        // On a fresh install the user table does not exist yet — skip; consolidated migration creates it.
        $tableExists = (bool) $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'user'"
        );
        $this->skipIf(!$tableExists, 'user table absent — consolidated migration will create it.');
    }

    public function up(Schema $schema): void
    {
        // Idempotent UPDATE — safe to re-run; WHERE clause ensures no-op if already renamed.
        $this->addSql(<<<'SQL'
            UPDATE "user"
            SET roles = (
                SELECT json_agg(
                    CASE elem::text
                        WHEN '"ROLE_ASSET_OWNER"' THEN to_json('ROLE_HARDWARE_OWNER'::text)
                        ELSE elem
                    END
                )
                FROM json_array_elements(roles) AS elem
            )
            WHERE roles::text LIKE '%ROLE_ASSET_OWNER%'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE "user"
            SET roles = (
                SELECT json_agg(
                    CASE elem::text
                        WHEN '"ROLE_HARDWARE_OWNER"' THEN to_json('ROLE_ASSET_OWNER'::text)
                        ELSE elem
                    END
                )
                FROM json_array_elements(roles) AS elem
            )
            WHERE roles::text LIKE '%ROLE_HARDWARE_OWNER%'
        SQL);
    }
}
