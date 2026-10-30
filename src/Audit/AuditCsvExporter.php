<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Audit;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Streams the filtered audit timeline as CSV for hand-over to an auditor (issue #64).
 *
 * **One row per changed field**, not per audit entry: that is what makes the file filterable and
 * sortable in a spreadsheet, which is the whole point of the export. An entry that changed three
 * fields therefore produces three rows sharing the same timestamp, object and action.
 *
 * The IP address stays out of the file by decision — it remains in the database only; no page
 * shows it either.
 */
final class AuditCsvExporter
{
    private const string DELIMITER = ';';

    /** Excel only recognises UTF-8 in a CSV when the file starts with a byte-order mark. */
    private const string BOM = "\u{FEFF}";

    public function __construct(
        private readonly AuditTimelineReader $reader,
        private readonly AuditLabelResolver $labelResolver,
        private readonly AuditValueFormatter $valueFormatter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The rows are written from inside a StreamedResponse callback, which runs after the request
     * locale has been unwound — so the locale of the requesting user is passed in explicitly
     * instead of relying on the translator's current one.
     *
     * @param resource $handle
     */
    public function write($handle, AuditTimelineFilter $filter, string $locale): void
    {
        fwrite($handle, self::BOM);
        $this->put($handle, [
            $this->translator->trans('audit_timeline.col.time', locale: $locale),
            $this->translator->trans('audit_timeline.col.object_type', locale: $locale),
            $this->translator->trans('audit_timeline.col.object', locale: $locale),
            $this->translator->trans('audit_timeline.export.col.object_id', locale: $locale),
            $this->translator->trans('audit_timeline.col.action', locale: $locale),
            $this->translator->trans('audit_timeline.export.col.field', locale: $locale),
            $this->translator->trans('audit_timeline.export.col.old', locale: $locale),
            $this->translator->trans('audit_timeline.export.col.new', locale: $locale),
            $this->translator->trans('audit_timeline.col.user', locale: $locale),
        ]);

        foreach ($this->reader->stream($filter) as $item) {
            $entry = $item['entry'];
            $class = $item['class'];

            $base = [
                $entry->createdAt?->format('Y-m-d H:i:s') ?? '',
                $this->translator->trans('audit.entity.'.$item['entity_type'], locale: $locale),
                $this->objectLabel($class, $entry->objectId, $locale),
                $entry->objectId,
                $this->translator->trans('audit.action.'.$entry->type, locale: $locale),
            ];
            $user = $entry->username ?? '';

            foreach ($this->changes($entry->type, $entry->getDiffs(), $class, $locale) as [$field, $old, $new]) {
                $this->put($handle, [...$base, $field, $old, $new, $user]);
            }
        }
    }

    /**
     * One triple per changed field. Mirrors the branches of the `audit_diffs` Twig macro:
     * associate/dissociate carry a source/target pair, everything else a field map whose values
     * are either an old/new pair or a list of them (collection changes).
     *
     * @param array<array-key, mixed> $diffs
     * @param class-string            $class
     *
     * @return list<array{0: string, 1: string, 2: string}> [field, old, new]
     */
    private function changes(string $type, array $diffs, string $class, string $locale): array
    {
        if (\in_array($type, ['associate', 'dissociate'], true)) {
            $source = \is_array($diffs['source'] ?? null) ? $diffs['source'] : [];
            $target = $diffs['target'] ?? null;
            $field = \is_string($source['field'] ?? null) ? $source['field'] : '?';

            return [[$this->valueFormatter->fieldLabel($field, $locale), '', $this->value($target, $class, null, $locale)]];
        }

        $rows = [];
        foreach ($diffs as $field => $diff) {
            if (!\is_string($field) || str_starts_with($field, '@') || !\is_array($diff)) {
                continue;
            }

            $label = $this->valueFormatter->fieldLabel($field, $locale);
            $old = $diff['old'] ?? null;
            $new = $diff['new'] ?? null;

            if (null === $old && null === $new) {
                // Collection change: a list of {old}/{new} items for the same field.
                $olds = [];
                $news = [];
                foreach ($diff as $item) {
                    if (!\is_array($item)) {
                        continue;
                    }
                    if (\array_key_exists('old', $item)) {
                        $olds[] = $this->value($item['old'], $class, $field, $locale);
                    }
                    if (\array_key_exists('new', $item)) {
                        $news[] = $this->value($item['new'], $class, $field, $locale);
                    }
                }
                $rows[] = [$label, implode(', ', $olds), implode(', ', $news)];

                continue;
            }

            $rows[] = [$label, $this->value($old, $class, $field, $locale), $this->value($new, $class, $field, $locale)];
        }

        if ([] === $rows) {
            $rows[] = ['', '', ''];
        }

        return $rows;
    }

    /**
     * @param class-string $class
     */
    private function value(mixed $value, string $class, ?string $field, string $locale): string
    {
        if (\is_array($value) && isset($value['class'], $value['id']) && \is_string($value['class'])) {
            return $this->objectLabel($value['class'], $value['id'], $locale);
        }

        return $this->valueFormatter->format($class, $field, $value, $locale);
    }

    /**
     * A deleted object is only a bare `#id` here. On screen a badge says so; a CSV cell has no
     * badge, so the marker is spelled out — otherwise the auditor cannot tell a deleted object
     * from an unnamed one.
     */
    private function objectLabel(string $class, int|string|null $id, string $locale): string
    {
        $label = $this->labelResolver->resolve($class, $id);

        return $label->deleted
            ? sprintf('%s (%s)', $label->label, $this->translator->trans('audit.object.deleted', locale: $locale))
            : $label->label;
    }

    /**
     * @param resource     $handle
     * @param list<string> $row
     */
    private function put($handle, array $row): void
    {
        fputcsv($handle, array_map(self::neutralise(...), $row), self::DELIMITER, '"', '');
    }

    /**
     * SEC-017: A cell starting with `=`, `+`, `-` or `@` is a formula to a spreadsheet, and the
     * audit trail carries attacker-influenced text (advisory titles, product names). Prefix those
     * with an apostrophe so the value is shown, never evaluated. OWASP adds a leading TAB and CR
     * (#194); and since some spreadsheets trim leading whitespace before parsing, a formula
     * character behind blanks or line breaks counts as well.
     */
    public static function neutralise(string $value): string
    {
        if ('' === $value) {
            return $value;
        }

        $firstVisible = ltrim($value, " \t\r\n");
        $triggers = str_contains("=+-@\t\r", $value[0])
            || ('' !== $firstVisible && str_contains('=+-@', $firstVisible[0]));

        return $triggers ? "'".$value : $value;
    }
}
