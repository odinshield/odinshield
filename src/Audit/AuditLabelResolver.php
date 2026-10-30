<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Audit;

use App\Controller\AssetTypeController;
use App\Controller\VendorController;
use App\Entity\AssetGroup;
use App\Entity\AssetType;
use App\Entity\Software;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Entity\VulnerabilityOccurrence;
use App\Security\Voter\AssetGroupVoter;
use App\Security\Voter\SoftwareVoter;
use App\Security\VulnerabilityAccess;
use DH\Auditor\Model\Entry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ExpressionLanguage\Expression;

/**
 * Turns the raw `class + database id` references of the audit trail into readable object names
 * (issue #64): the object column of the timeline and every relation inside a diff.
 *
 * Resolution is **bundled per entity type** — {@see warmFromEntries()} collects every reference
 * of a whole result page first and then runs at most one label query per entity type, plus one
 * access query for the types whose pages are gated per object (vulnerability, occurrence,
 * software, asset group — #201). The query count is therefore bound by the number of audited
 * entity types (8), never by the page size.
 * {@see resolve()} only reads that warm cache, so a template can call it per row without
 * triggering queries.
 *
 * Deliberately resolved by `id`, never by the `label` the auditor stores alongside it: that label
 * is ambiguous (two Software rows named "OpnSense" differing only in version) and degrades to a
 * bare object reference (`App\Entity\AssetGroup#2`) whenever the entity has no `__toString()`.
 */
final class AuditLabelResolver
{
    /**
     * Entity classes this resolver can name, mapped to the route of their detail page.
     * Mirrors {@see AuditTimelineReader::ENTITY_TYPES}; VulnerabilityOccurrence has no page of
     * its own and points at its parent vulnerability instead.
     *
     * @var array<class-string, string>
     */
    private const array ROUTES = [
        Vulnerability::class => 'app_vulnerability_show',
        VulnerabilityOccurrence::class => 'app_vulnerability_show',
        Software::class => 'app_software_show',
        AssetGroup::class => 'app_asset_group_show',
        AssetType::class => 'app_asset_type_show',
        Vendor::class => 'app_vendor_show',
        User::class => 'app_user_show',
        UserGroup::class => 'app_user_group_show',
    ];

    /** @var array<string, AuditObjectLabel> keyed by "FQCN#id" */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly VulnerabilityAccess $vulnerabilityAccess,
    ) {
    }

    /**
     * Collect and resolve every object referenced by a page of audit entries: the audited object
     * itself plus all relations inside its diffs.
     *
     * @param iterable<array{0: class-string, 1: Entry}> $rows [entity class, entry] pairs
     */
    public function warmFromEntries(iterable $rows): void
    {
        /** @var array<class-string, list<int|string>> $refs */
        $refs = [];
        foreach ($rows as [$class, $entry]) {
            $refs[$class][] = $entry->objectId;
            foreach ($this->relationRefs($entry->getDiffs()) as [$relClass, $relId]) {
                $refs[$relClass][] = $relId;
            }
        }

        $this->warm($refs);
    }

    /**
     * @param array<class-string, list<int|string>> $refs
     */
    public function warm(array $refs): void
    {
        foreach ($refs as $class => $ids) {
            if (!isset(self::ROUTES[$class])) {
                continue;
            }

            $missing = [];
            foreach ($ids as $id) {
                if (!is_numeric($id)) {
                    continue;
                }
                $key = $this->key($class, $id);
                if (!isset($this->cache[$key])) {
                    $missing[(int) $id] = (int) $id;
                }
            }

            if ([] === $missing) {
                continue;
            }

            foreach ($this->fetch($class, array_values($missing)) as $id => $label) {
                $this->cache[$this->key($class, $id)] = $label;
                unset($missing[$id]);
            }

            // Whatever the query did not return no longer exists — cache it as deleted so the
            // next page does not ask again.
            foreach ($missing as $id) {
                $this->cache[$this->key($class, $id)] = AuditObjectLabel::deleted($id);
            }
        }
    }

    /**
     * Read-only lookup for templates. Returns the deleted-fallback for anything the warm-up did
     * not cover, so a missing warm-up degrades the display instead of firing queries per row.
     */
    public function resolve(?string $class, int|string|null $id): AuditObjectLabel
    {
        if (null === $id) {
            return AuditObjectLabel::deleted('?');
        }

        return $this->cache[$this->key((string) $class, $id)] ?? AuditObjectLabel::deleted($id);
    }

    /**
     * Drop everything resolved so far. Used by the CSV export between chunks — a full export
     * would otherwise keep every object of the whole trail in memory.
     */
    public function clear(): void
    {
        $this->cache = [];
    }

    /**
     * Walk a diff array and pick out every relation reference. The auditor writes those as
     * `{"id": …, "class": …, "label": …, "table": …}`, nested under `old`/`new` for scalars,
     * under `source`/`target` for associate/dissociate, and inside lists for collections.
     *
     * @param array<array-key, mixed> $diffs
     *
     * @return list<array{0: class-string, 1: int|string}>
     */
    private function relationRefs(array $diffs): array
    {
        $refs = [];
        foreach ($diffs as $value) {
            if (!\is_array($value)) {
                continue;
            }
            if (isset($value['class'], $value['id']) && \is_string($value['class'])) {
                /** @var class-string $class */
                $class = $value['class'];
                $refs[] = [$class, $value['id']];

                continue;
            }
            foreach ($this->relationRefs($value) as $nested) {
                $refs[] = $nested;
            }
        }

        return $refs;
    }

    /**
     * One query per entity type. Scalar selects only — hydrating entities here would drag their
     * relations along and defeat the whole point.
     *
     * @param class-string $class
     * @param list<int>    $ids
     *
     * @return array<int, AuditObjectLabel>
     */
    private function fetch(string $class, array $ids): array
    {
        $qb = $this->em->createQueryBuilder()
            ->from($class, 'e')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids);

        match ($class) {
            VulnerabilityOccurrence::class => $qb
                ->select('e.id AS id', 'v.uuid AS uuid', 'v.title AS a', 'ag.name AS b', 'v.id AS parent')
                ->join('e.vulnerability', 'v')
                ->join('e.assetGroup', 'ag'),
            Vulnerability::class => $qb->select('e.id AS id', 'e.uuid AS uuid', 'e.title AS a'),
            Software::class => $qb->select('e.id AS id', 'e.uuid AS uuid', 'e.name AS a', 'e.version AS b'),
            User::class => $qb->select('e.id AS id', 'e.uuid AS uuid', 'e.username AS a', 'e.firstName AS b', 'e.lastName AS c'),
            default => $qb->select('e.id AS id', 'e.uuid AS uuid', 'e.name AS a'),
        };

        /** @var list<array{id: int, uuid: mixed, a: ?string, b?: ?string, c?: ?string, parent?: int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $viewable = $this->viewableIds($class, $rows);

        $labels = [];
        foreach ($rows as $row) {
            $labels[$row['id']] = new AuditObjectLabel(
                label: $this->buildLabel($class, $row),
                uuid: (string) $row['uuid'],
                // #201: no route — hence no link — for an object the viewer could not open.
                route: isset($viewable[$row['id']]) ? self::ROUTES[$class] : null,
            );
        }

        return $labels;
    }

    /**
     * #201: which of these objects the current viewer may open, decided per entity type in one go
     * by the same rules as the target pages — never one check per row.
     *
     * @param list<array{id: int, parent?: int}> $rows
     *
     * @return array<int, true> keyed by object id
     */
    private function viewableIds(string $class, array $rows): array
    {
        $ids = array_column($rows, 'id');

        return match ($class) {
            Vulnerability::class => $this->viewableVulnerabilityIds($ids),
            // An occurrence links to its vulnerability, so that one decides.
            VulnerabilityOccurrence::class => (function () use ($rows): array {
                $parents = $this->viewableVulnerabilityIds(array_column($rows, 'parent'));
                $viewable = [];
                foreach ($rows as $row) {
                    if (isset($parents[$row['parent'] ?? 0])) {
                        $viewable[$row['id']] = true;
                    }
                }

                return $viewable;
            })(),
            Software::class => $this->viewableByVoter(Software::class, SoftwareVoter::VIEW, $ids),
            AssetGroup::class => $this->viewableByVoter(AssetGroup::class, AssetGroupVoter::VIEW, $ids),
            AssetType::class => $this->allIf($this->security->isGranted(new Expression(AssetTypeController::ACCESS)), $ids),
            Vendor::class => $this->allIf($this->security->isGranted(new Expression(VendorController::ACCESS)), $ids),
            User::class, UserGroup::class => $this->allIf($this->security->isGranted(User::ROLE_ADMIN), $ids),
            default => [],
        };
    }

    /**
     * Same DQL as the vulnerability list (single resolver), so the timeline can never link an
     * entry the list would hide.
     *
     * @param list<int> $ids
     *
     * @return array<int, true>
     */
    private function viewableVulnerabilityIds(array $ids): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || [] === $ids) {
            return [];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('v.id')
            ->from(Vulnerability::class, 'v')
            ->where('v.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($ids)));
        $this->vulnerabilityAccess->applyViewFilter($qb, $user, 'v');

        return array_fill_keys(array_map('intval', $qb->getQuery()->getSingleColumnResult()), true);
    }

    /**
     * @param class-string<Software|AssetGroup> $class
     * @param list<int>                         $ids
     *
     * @return array<int, true>
     */
    private function viewableByVoter(string $class, string $attribute, array $ids): array
    {
        if (null === $this->security->getUser()) {
            return [];
        }

        $viewable = [];
        foreach ($this->em->getRepository($class)->findBy(['id' => $ids]) as $entity) {
            if ($this->security->isGranted($attribute, $entity)) {
                $viewable[$entity->getId()] = true;
            }
        }

        return $viewable;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, true>
     */
    private function allIf(bool $granted, array $ids): array
    {
        return $granted ? array_fill_keys($ids, true) : [];
    }

    /**
     * @param array{id: int, uuid: mixed, a: ?string, b?: ?string, c?: ?string} $row
     */
    private function buildLabel(string $class, array $row): string
    {
        $label = match ($class) {
            // No page of its own: name the occurrence by what it actually is — this vulnerability
            // in that asset group.
            VulnerabilityOccurrence::class => trim(($row['a'] ?? '').' — '.($row['b'] ?? '')),
            // The version is what tells two rows of the same product apart.
            Software::class => trim(($row['a'] ?? '').' '.($row['b'] ?? '')),
            User::class => trim(($row['b'] ?? '').' '.($row['c'] ?? '')) ?: (string) $row['a'],
            default => (string) ($row['a'] ?? ''),
        };

        return '' === $label ? '#'.$row['id'] : $label;
    }

    private function key(string $class, int|string $id): string
    {
        return $class.'#'.$id;
    }
}
