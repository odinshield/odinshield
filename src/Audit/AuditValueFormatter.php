<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Audit;

use App\Enum\Theme;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\MappingException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Makes the raw contents of an audit diff readable (issue #64): German field names and German
 * enum values instead of `discoveredAt` and `deferred`.
 *
 * Enum values are **not** taken from the enums' own `label()` methods — those are English. The
 * translation key is derived instead: every domain enum already has a catalogue block named
 * `snake_case(<EnumShortName>)` (VulnerabilityStatus → `vulnerability_status.deferred`), so the
 * Doctrine `enumType` of the column is enough to find it. {@see PREFIX_OVERRIDES} carries the
 * single enum that predates that convention.
 *
 * Everything degrades to the raw value: the trail outlives the schema, and it still holds diffs
 * for fields and enum cases the entities no longer have.
 */
final class AuditValueFormatter
{
    /** @var array<class-string, string> enums whose catalogue block is not snake_case(short name) */
    private const array PREFIX_OVERRIDES = [
        Theme::class => 'user.theme',
    ];

    /** @var array<string, class-string|null> resolved enum class per "FQCN::field", null = not an enum */
    private array $enumTypes = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * German name of an audited property, falling back to the property name itself so an
     * untranslated — or long-removed — field renders instead of breaking.
     *
     * `$locale` is only passed by the CSV export: its rows are written from inside a
     * StreamedResponse callback, which runs after the request locale has been unwound.
     */
    public function fieldLabel(string $field, ?string $locale = null): string
    {
        return $this->translate('audit.field.'.$this->snake($field), $field, $locale);
    }

    /**
     * Display form of one diff value. Arrays are rendered element by element (role lists,
     * collection diffs); enum-backed columns are translated; anything else is passed through.
     *
     * @param class-string|null $entityClass
     */
    public function format(?string $entityClass, ?string $field, mixed $value, ?string $locale = null): string
    {
        if (null === $value || '' === $value || [] === $value) {
            return '—';
        }

        if (\is_bool($value)) {
            return $this->translator->trans($value ? 'audit.bool.true' : 'audit.bool.false', locale: $locale);
        }

        if (\is_array($value)) {
            $parts = array_map(fn (mixed $item): string => $this->format($entityClass, $field, $item, $locale), $value);

            return implode(', ', $parts);
        }

        if (!\is_scalar($value)) {
            return '—';
        }

        $raw = (string) $value;

        // Roles are a plain JSON array on User, not an enum-backed column.
        if ('roles' === $field && str_starts_with($raw, 'ROLE_')) {
            return $this->translate('user.role.'.strtolower(substr($raw, 5)), $raw, $locale);
        }

        $enum = $this->enumType($entityClass, $field);
        if (null !== $enum) {
            return $this->translate($this->prefix($enum).'.'.$raw, $raw, $locale);
        }

        // Date columns are stored with a time part even when only the date matters.
        return str_replace(' 00:00:00', '', $raw);
    }

    /**
     * @param class-string|null $entityClass
     *
     * @return class-string|null the backing enum of that column, if it has one
     */
    private function enumType(?string $entityClass, ?string $field): ?string
    {
        if (null === $entityClass || null === $field) {
            return null;
        }

        $cacheKey = $entityClass.'::'.$field;
        if (\array_key_exists($cacheKey, $this->enumTypes)) {
            return $this->enumTypes[$cacheKey];
        }

        $enum = null;

        try {
            $metadata = $this->em->getClassMetadata($entityClass);
            if ($metadata->hasField($field)) {
                $mapping = $metadata->getFieldMapping($field);
                /** @var class-string|null $enum */
                $enum = $mapping->enumType;
            }
        } catch (MappingException|\ReflectionException) {
            $enum = null;
        }

        return $this->enumTypes[$cacheKey] = $enum;
    }

    /**
     * @param class-string $enum
     */
    private function prefix(string $enum): string
    {
        if (isset(self::PREFIX_OVERRIDES[$enum])) {
            return self::PREFIX_OVERRIDES[$enum];
        }

        return $this->snake(substr((string) strrchr('\\'.$enum, '\\'), 1));
    }

    /**
     * `discoveredAt` → `discovered_at`. Catalogue keys are snake_case throughout the project,
     * the audit diff keys are Doctrine property names.
     */
    private function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    private function translate(string $key, string $fallback, ?string $locale = null): string
    {
        $translated = $this->translator->trans($key, locale: $locale);

        return $translated === $key ? $fallback : $translated;
    }
}
