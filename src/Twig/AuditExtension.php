<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Twig;

use App\Audit\AuditLabelResolver;
use App\Audit\AuditObjectLabel;
use App\Audit\AuditValueFormatter;
use App\Entity\HasIdInterface;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AuditExtension extends AbstractExtension
{
    public function __construct(
        private readonly Reader $reader,
        private readonly AuditLabelResolver $labelResolver,
        private readonly AuditValueFormatter $valueFormatter,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('audit_entries', $this->getEntries(...)),
            new TwigFunction('audit_object_label', $this->objectLabel(...)),
            new TwigFunction('audit_field', $this->valueFormatter->fieldLabel(...)),
            new TwigFunction('audit_value', $this->valueFormatter->format(...)),
        ];
    }

    /**
     * @return array{entries: array<Entry>, total: int, class: class-string}
     */
    public function getEntries(HasIdInterface $entity, int $page = 1, int $pageSize = 50): array
    {
        $query = $this->reader->createQuery($entity::class, [
            'object_id' => (string) $entity->getId(),
            'page' => $page,
            'page_size' => $pageSize,
        ]);

        $entries = $query->execute();

        // Resolve every referenced object of this page in one bundled pass — the shared entry
        // renderer looks names up per row and must not query while doing so (issue #64).
        $this->labelResolver->warmFromEntries(array_map(
            static fn (Entry $entry): array => [$entity::class, $entry],
            $entries,
        ));

        return ['entries' => $entries, 'total' => $query->count(), 'class' => $entity::class];
    }

    /**
     * Readable name of an audited object, for the object column and for relations inside a diff.
     * Reads the resolver's warm cache only.
     */
    public function objectLabel(?string $class, int|string|null $id): AuditObjectLabel
    {
        return $this->labelResolver->resolve($class, $id);
    }
}
