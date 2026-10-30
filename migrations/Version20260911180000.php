<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * #140: store usernames in lowercase.
 *
 * The unique index on `user.username` is binary, so 'CaseTest' and 'casetest' were two distinct
 * accounts that look identical in every list. Authentication compared byte-wise as well, which
 * made an account unreachable when its owner typed the name in a different spelling.
 */
final class Version20260911180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lowercase all existing usernames (#140); aborts if two accounts differ only in spelling.';
    }

    public function up(Schema $schema): void
    {
        // A blind UPDATE would hit the unique index if two accounts differ only in spelling. That
        // case cannot be resolved automatically — which of the two accounts survives is a decision
        // about access rights, so stop and let a human make it.
        $collisions = $this->connection->fetchAllAssociative(
            'SELECT LOWER(username) AS name, COUNT(*) AS hits
               FROM "user"
              WHERE username IS NOT NULL
           GROUP BY LOWER(username)
             HAVING COUNT(*) > 1'
        );

        if ([] !== $collisions) {
            $names = implode(', ', array_map(static fn (array $row): string => (string) $row['name'], $collisions));

            $this->abortIf(true, sprintf(
                'Cannot lowercase usernames: these names exist more than once when compared case-insensitively: %s. '
                .'Decide which account survives (and reassign its responsibilities) before running this migration.',
                $names,
            ));
        }

        $this->addSql('UPDATE "user" SET username = LOWER(username) WHERE username IS NOT NULL AND username <> LOWER(username)');
    }

    public function down(Schema $schema): void
    {
        // The original spelling is not recorded anywhere, so it cannot be restored.
        throw new IrreversibleMigration('Original username spelling is not retained.');
    }
}
