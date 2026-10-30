<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * SEC-018: authentication resolves the identifier case-insensitively (#140). The entity
     * provider's `property: username` would compare byte-wise, so an account created as
     * "MMustermann" could not be reached by typing "mmustermann" — the user only sees the generic
     * "invalid credentials" and is locked out by login throttling after five tries. Stored names
     * are normalised by User::setUsername(); LOWER() on the column covers rows written before
     * that migration and any direct SQL.
     */
    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.username) = :identifier')
            ->setParameter('identifier', mb_strtolower(trim($identifier)))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Case-insensitive lookup for write paths that must not create a second account differing
     * only in spelling (#140).
     */
    public function findOneByUsernameCaseInsensitive(string $username): ?User
    {
        $user = $this->loadUserByIdentifier($username);

        return $user instanceof User ? $user : null;
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    private const array SORTABLE_COLUMNS = ['username', 'email', 'firstName', 'lastName', 'isActive', 'lastLoginAt'];

    /**
     * Active users holding at least one of the given roles, ordered by username.
     *
     * The role filter runs in PHP rather than in SQL on purpose: `roles` is a JSON column, so a
     * database-side match would need vendor-specific JSON operators, while the user table of an
     * internal tool stays small and this method is only ever called when an importer has already
     * failed. Matching goes through {@see User::getRoles()}, which appends ROLE_USER implicitly —
     * asking for that role therefore returns every active user, which is what it means.
     *
     * @param list<string> $roles
     *
     * @return list<User>
     */
    public function findActiveByAnyRole(array $roles): array
    {
        /** @var list<User> $active */
        $active = $this->createQueryBuilder('u')
            ->andWhere('u.isActive = true')
            ->orderBy('u.username', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $active,
            static fn (User $u) => [] !== array_intersect($roles, $u->getRoles()),
        ));
    }

    /**
     * @return Paginator<User>
     */
    public function findPaginated(string $search, int $page, int $limit, string $sort, string $direction, ?bool $isActive = null): Paginator
    {
        $sort = in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'username';
        $order = 'desc' === $direction ? \SortDirection::Descending : \SortDirection::Ascending;

        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.'.$sort, $order);

        if ('' !== $search) {
            $qb->andWhere('LOWER(u.username) LIKE :search OR LOWER(u.email) LIKE :search OR LOWER(u.firstName) LIKE :search OR LOWER(u.lastName) LIKE :search')
                ->setParameter('search', '%'.strtolower($search).'%');
        }

        if (null !== $isActive) {
            $qb->andWhere('u.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
    }
}
