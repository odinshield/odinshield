<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Notification;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Resolves who is told that an importer stopped working (issue #63, baustein 4).
 *
 * Before this, the importers knew exactly one recipient: the string in `CSAF_ADMIN_EMAIL` (today
 * also `EXPLOITATION_ADMIN_EMAIL`). Where that variable is unset — its default — a tripped circuit breaker was
 * completely silent apart from a log line. There was no way to reach "the ISBs" at all, even
 * though the roles exist in user administration.
 *
 * The configured address is kept as an *additional* recipient rather than replaced: on our own
 * stages it is set and points at an operations mailbox that is not tied to any user account.
 */
final readonly class OperatorRecipients
{
    /**
     * Roles that are told about a broken importer.
     *
     * ROLE_ISB because continuous information gathering is his duty under BSI-CS 093 — a dead
     * advisory source is his problem before it is anyone else's. ROLE_ADMIN because he is the
     * only one who can actually repair it (see the role split guarded on ImportStatusController).
     */
    private const array NOTIFIED_ROLES = [User::ROLE_ISB, User::ROLE_ADMIN];

    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    /**
     * Every address that should learn about a broken importer: the role holders plus the
     * configured operations address, de-duplicated.
     *
     * @param string|null $configuredAddress the `*_ADMIN_EMAIL` value, may be null or empty
     *
     * @return list<string> may be empty — the caller decides whether that is worth a log line
     */
    public function forImporterFailure(?string $configuredAddress): array
    {
        $addresses = array_map(
            static fn (User $u) => $u->getEmail(),
            $this->userRepository->findActiveByAnyRole(self::NOTIFIED_ROLES),
        );

        if (null !== $configuredAddress && '' !== $configuredAddress) {
            $addresses[] = $configuredAddress;
        }

        // Case-insensitive de-duplication: the same person may hold both roles, and the
        // configured address is frequently a role holder's own mailbox spelled differently.
        $seen = [];
        $out = [];
        foreach ($addresses as $address) {
            if ('' === $address) {
                continue;
            }
            $key = mb_strtolower($address);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $address;
        }

        return $out;
    }
}
