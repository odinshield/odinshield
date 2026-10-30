<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Parser;

use App\Service\Csaf\Dto\CsafAdvisory;
use App\Service\Csaf\Dto\CsafProduct;
use App\Service\Csaf\Dto\CsafVulnerability;
use App\Service\Csaf\Exception\CsafParseException;
use App\Service\Csaf\Matching\CpeTokenParser;
use App\Service\Csaf\Matching\KeyNormaliser;
use App\Service\Csaf\Matching\VersionRange;

/**
 * Parses a CSAF 2.0/2.1 JSON document into a flat DTO graph.
 *
 * Documents whose tracking.status is not 'final' raise CsafParseException with
 * code 1 — the runner uses that to skip rather than fail.
 */
final class CsafDocumentParser
{
    public const int SKIP_NON_FINAL = 1;

    private const array SUPPORTED_VERSIONS = ['2.0', '2.1'];

    public function __construct(
        private readonly CpeTokenParser $cpeTokenParser = new CpeTokenParser(),
    ) {
    }

    /**
     * @param array<string, mixed>|string $raw
     */
    public function parse(string $providerKey, string $providerDisplayName, array|string $raw, ?string $sourceUrl = null): CsafAdvisory
    {
        $data = \is_string($raw) ? $this->decode($raw) : $raw;

        $document = $data['document'] ?? null;
        if (!\is_array($document)) {
            throw new CsafParseException('CSAF document missing "document" root.');
        }

        $csafVersion = (string) ($document['csaf_version'] ?? '');
        if (!\in_array($csafVersion, self::SUPPORTED_VERSIONS, true)) {
            throw new CsafParseException(\sprintf('Unsupported CSAF version "%s".', $csafVersion));
        }

        $tracking = $document['tracking'] ?? [];
        $status = (string) ($tracking['status'] ?? '');
        if ('final' !== $status) {
            throw new CsafParseException(\sprintf('CSAF document status "%s" is not final.', $status), self::SKIP_NON_FINAL);
        }

        $docId = (string) ($tracking['id'] ?? '');
        $version = (string) ($tracking['version'] ?? '');
        $title = (string) ($document['title'] ?? $docId);
        if ('' === $docId || '' === $version) {
            throw new CsafParseException('CSAF document missing tracking.id or tracking.version.');
        }

        $initialReleaseDate = $this->parseDate((string) ($tracking['initial_release_date'] ?? ''));
        $currentReleaseDate = $this->parseDate((string) ($tracking['current_release_date'] ?? ($tracking['initial_release_date'] ?? '')));

        $lastContentChangeAt = $this->extractLastContentChange($tracking, $currentReleaseDate);

        $aggregateSeverityLabel = null;
        $aggregate = $document['aggregate_severity'] ?? null;
        if (\is_array($aggregate) && isset($aggregate['text']) && \is_string($aggregate['text']) && '' !== $aggregate['text']) {
            $aggregateSeverityLabel = $aggregate['text'];
        }

        $documentNotes = $this->extractDocumentNotes($document['notes'] ?? []);

        $productsById = $this->flattenProductTree($data['product_tree'] ?? []);
        $vulnerabilities = $this->parseVulnerabilities($data['vulnerabilities'] ?? []);

        return new CsafAdvisory(
            providerKey: $providerKey,
            providerDisplayName: $providerDisplayName,
            documentId: $docId,
            version: $version,
            title: $title,
            initialReleaseDate: $initialReleaseDate,
            currentReleaseDate: $currentReleaseDate,
            productsById: $productsById,
            vulnerabilities: $vulnerabilities,
            sourceUrl: $sourceUrl,
            aggregateSeverityLabel: $aggregateSeverityLabel,
            documentNotes: $documentNotes,
            lastContentChangeAt: $lastContentChangeAt,
        );
    }

    /**
     * Extract operator-relevant document-level notes (BSI puts the actual incident description here).
     *
     * Filters out two kinds of noise we don't want in the imported `description` field:
     *   - `legal_disclaimer` category: boilerplate that the BSI attaches to every advisory
     *   - any note titled "Produktbeschreibung" / "Product description": the software is already
     *     in our inventory, repeating what it is would just dilute the advisory's actual content
     *
     * @param array<int, array<string, mixed>>|mixed $notes
     *
     * @return list<array{title: string, text: string}>
     */
    private function extractDocumentNotes(mixed $notes): array
    {
        if (!\is_array($notes)) {
            return [];
        }

        $allowed = ['summary', 'description', 'general', 'details'];
        $titleBlocklist = ['produktbeschreibung', 'product description'];
        $out = [];
        foreach ($notes as $note) {
            if (!\is_array($note)) {
                continue;
            }
            $category = (string) ($note['category'] ?? '');
            if (!\in_array($category, $allowed, true)) {
                continue;
            }
            $text = (string) ($note['text'] ?? '');
            if ('' === $text) {
                continue;
            }
            $title = (string) ($note['title'] ?? $category);
            if (\in_array(strtolower(trim($title)), $titleBlocklist, true)) {
                continue;
            }
            $out[] = ['title' => $title, 'text' => $text];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CsafParseException('Malformed CSAF JSON: '.$e->getMessage(), 0, $e);
        }

        if (!\is_array($decoded)) {
            throw new CsafParseException('CSAF JSON root must be an object.');
        }

        return $decoded;
    }

    /**
     * Date of the last change that actually touched the advisory's content.
     *
     * #98: Red Hat rebuilds its whole document store daily and writes the rebuild into the
     * document — `current_release_date` and a fresh revision entry both carry the rebuild
     * timestamp, so the file's bytes (and therefore its ETag, which is the body's MD5) change
     * without anything substantive happening. Measured 2026-09-11: of 60 randomly sampled
     * documents that `changes.csv` reported as changed that day, **60 were pure rebuilds**.
     *
     * Red Hat labels the two cases itself in `revision_history`:
     *
     *     rev 2  2022-11-15  Last updated version      <- the real change
     *     rev 3  2026-09-11  Last generated version    <- the rebuild
     *
     * The heuristic is deliberately conservative, because "generated" is Red Hat's wording and
     * not something CSAF prescribes: it only kicks in when the history contains **both** a
     * generation entry and an older substantive one, and it can only ever move the date
     * backwards. Any provider whose history does not draw that distinction keeps
     * `current_release_date` unchanged and is therefore unaffected — no document can be filtered
     * away that would previously have been imported. `null` means "no distinction available" —
     * callers fall back to `current_release_date`.
     *
     * @param array<string, mixed>|mixed $tracking
     */
    private function extractLastContentChange(mixed $tracking, \DateTimeImmutable $fallback): ?\DateTimeImmutable
    {
        if (!\is_array($tracking) || !\is_array($history = $tracking['revision_history'] ?? null)) {
            return null;
        }

        $generated = [];
        $substantive = [];
        foreach ($history as $entry) {
            if (!\is_array($entry) || !\is_string($date = $entry['date'] ?? null) || '' === $date) {
                continue;
            }
            try {
                $parsed = new \DateTimeImmutable($date);
            } catch (\Exception) {
                continue;
            }
            $summary = mb_strtolower((string) ($entry['summary'] ?? ''));
            if (str_contains($summary, 'generated')) {
                $generated[] = $parsed;
            } else {
                $substantive[] = $parsed;
            }
        }

        if ([] === $generated || [] === $substantive) {
            return null;
        }

        $newest = max($substantive);

        return $newest < $fallback ? $newest : null;
    }

    private function parseDate(string $iso): \DateTimeImmutable
    {
        if ('' === $iso) {
            return new \DateTimeImmutable();
        }
        try {
            return new \DateTimeImmutable($iso);
        } catch (\Exception $e) {
            throw new CsafParseException('Invalid CSAF date: '.$iso, 0, $e);
        }
    }

    /**
     * Product identity and version per product id (#134).
     *
     * Only `branches` and `full_product_names` are read. `relationships` (a package *on* a
     * platform — Red Hat's model) are deliberately not flattened (D9): the relationship product
     * carries no version of its own that could be compared, and matching the platform alone
     * would report every RHEL advisory against a single inventory row. Ids that only exist as
     * relationships therefore never resolve and the advisory yields nothing.
     *
     * @param array<string, mixed> $productTree
     *
     * @return array<string, CsafProduct>
     */
    private function flattenProductTree(array $productTree): array
    {
        $out = [];
        $this->collectFromFullProductNames($productTree['full_product_names'] ?? [], $out);
        $this->walkBranches($productTree['branches'] ?? [], ['vendor' => null, 'product' => null, 'version' => null], $out);

        return $out;
    }

    /**
     * Top-level `full_product_names` have no branch context, so identity can only come from a
     * CPE. Entries without one are skipped — there is nothing to compare them against.
     *
     * @param array<int, array<string, mixed>>|mixed $nodes
     * @param array<string, CsafProduct>             $out
     */
    private function collectFromFullProductNames(mixed $nodes, array &$out): void
    {
        if (!\is_array($nodes)) {
            return;
        }
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            $id = (string) ($node['product_id'] ?? '');
            $cpe = $this->cpeFromIdentificationHelper($node['product_identification_helper'] ?? null);
            if ('' === $id || null === $cpe) {
                continue;
            }
            $product = $this->buildProduct($id, (string) ($node['name'] ?? $id), $cpe, null, null, null);
            if (null !== $product) {
                $out[$id] = $product;
            }
        }
    }

    /**
     * Walks the recursive `branches` tree carrying the nearest `vendor`, `product_name` /
     * `product_family` and `product_version` / `product_version_range` names down the path, and
     * emits one {@see CsafProduct} per node that carries a `product` payload.
     *
     * The version branch name is the source of the affected range: BSI writes `<7.6.4` there
     * (and attaches no CPE), SICK `<=2026.1.0`, Siemens the canonical `vers:intdot/>=3.1.6|<3.1.7`.
     * The former sibling-CPE heuristic — borrow the fixed sibling's CPE and wildcard its version —
     * is exactly what turned `<7.6.4` into "every FortiOS" (F1) and is gone.
     *
     * What a sibling CPE may still lend is its *identity*: every leaf in one sibling list sits
     * under the same product, so when the siblings' CPEs agree on one vendor/product pair, a leaf
     * without CPE takes that pair instead of the branch names. That keeps BSI's "Open Source" /
     * "OPNsense" range node on the same identity (`opnsense`/`opnsense`) as its fixed sibling
     * with `cpe:/a:opnsense:opnsense:26.1.8` — the version bound still comes from its own name.
     *
     * @param array<int, array<string, mixed>>|mixed                              $branches
     * @param array{vendor: string|null, product: string|null, version: string|null} $context
     * @param array<string, CsafProduct>                                          $out
     */
    private function walkBranches(mixed $branches, array $context, array &$out): void
    {
        if (!\is_array($branches)) {
            return;
        }

        $siblingIdentity = $this->siblingIdentity($branches);

        foreach ($branches as $branch) {
            if (!\is_array($branch)) {
                continue;
            }
            $ctx = $context;
            $category = (string) ($branch['category'] ?? '');
            $name = trim((string) ($branch['name'] ?? ''));
            switch ($category) {
                case 'vendor':
                    $ctx['vendor'] = $name;
                    break;
                case 'product_name':
                case 'product_family':
                    $ctx['product'] = $name;
                    break;
                case 'product_version':
                case 'product_version_range':
                    $ctx['version'] = $name;
                    break;
            }

            $payload = $branch['product'] ?? null;
            if (\is_array($payload)) {
                $id = (string) ($payload['product_id'] ?? '');
                if ('' !== $id) {
                    $product = $this->buildProduct(
                        $id,
                        (string) ($payload['name'] ?? $id),
                        $this->cpeFromIdentificationHelper($payload['product_identification_helper'] ?? null),
                        $ctx['vendor'],
                        $ctx['product'],
                        $ctx['version'],
                        $siblingIdentity,
                    );
                    if (null !== $product) {
                        $out[$id] = $product;
                    }
                }
            }

            $this->walkBranches($branch['branches'] ?? [], $ctx, $out);
        }
    }

    /**
     * The one vendor/product pair the CPEs in a sibling list agree on, or null when the siblings
     * carry no CPE or disagree (a `product_name` list mixing several products).
     *
     * @param array<int, mixed> $branches
     *
     * @return array{vendor: string, product: string}|null
     */
    private function siblingIdentity(array $branches): ?array
    {
        $identity = null;
        foreach ($branches as $branch) {
            if (!\is_array($branch) || !\is_array($branch['product'] ?? null)) {
                continue;
            }
            $cpe = $this->cpeFromIdentificationHelper($branch['product']['product_identification_helper'] ?? null);
            $tokens = null === $cpe ? null : $this->cpeTokenParser->parseTokens($cpe);
            if (null === $tokens) {
                continue;
            }
            $pair = ['vendor' => $tokens['vendor'], 'product' => $tokens['product']];
            if (null === $identity) {
                $identity = $pair;
            } elseif ($identity !== $pair) {
                return null;
            }
        }

        return $identity;
    }

    /**
     * Identity: the leaf's own CPE tokens, else the pair its siblings' CPEs agree on, else the
     * normalised branch names. Range: the version branch name when there is one, otherwise the
     * CPE's version component, otherwise every version. Returns null only when nothing at all
     * identifies the product.
     *
     * @param array{vendor: string, product: string}|null $siblingIdentity
     */
    private function buildProduct(string $id, string $name, ?string $cpe, ?string $vendorName, ?string $productName, ?string $versionName, ?array $siblingIdentity = null): ?CsafProduct
    {
        $tokens = null === $cpe ? null : $this->cpeTokenParser->parseTokens($cpe);
        $vendorToken = $tokens['vendor'] ?? $siblingIdentity['vendor'] ?? KeyNormaliser::normalise((string) $vendorName);
        $productToken = $tokens['product'] ?? $siblingIdentity['product'] ?? KeyNormaliser::normalise((string) $productName);
        if ('' === $vendorToken && '' === $productToken) {
            return null;
        }

        if (null !== $versionName) {
            $raw = $versionName;
        } elseif (null !== $tokens && null !== $tokens['version']) {
            $raw = $tokens['version'];
        } else {
            $raw = '';
        }

        return new CsafProduct(
            productId: $id,
            name: $name,
            vendorToken: $vendorToken,
            productToken: $productToken,
            range: VersionRange::fromText($raw),
            rawVersionText: $raw,
            cpe: $cpe,
        );
    }

    private function cpeFromIdentificationHelper(mixed $helper): ?string
    {
        if (!\is_array($helper)) {
            return null;
        }
        $cpe = $helper['cpe'] ?? null;

        return \is_string($cpe) && '' !== $cpe ? $cpe : null;
    }

    /**
     * Only `known_affected` (plus `first_affected`) is evaluated on the affected side (D8);
     * `under_investigation` is ignored. `known_not_affected` is carried along only so the upserter
     * can flag a vendor's later correction on an existing entry (#57) — it never creates a row. `fixed`/`first_fixed` feed the
     * fix note. The former BSI "-fixed sibling" heuristic is gone: the fix version now comes from
     * the affected range's upper bound (`<7.6.4` → fixed from 7.6.4), which is what the document
     * actually states.
     *
     * @param array<int, array<string, mixed>>|mixed $vulnerabilities
     *
     * @return list<CsafVulnerability>
     */
    private function parseVulnerabilities(mixed $vulnerabilities): array
    {
        if (!\is_array($vulnerabilities)) {
            return [];
        }

        $out = [];
        foreach ($vulnerabilities as $v) {
            if (!\is_array($v)) {
                continue;
            }

            $cve = isset($v['cve']) ? (string) $v['cve'] : null;
            $title = isset($v['title']) ? (string) $v['title'] : null;

            $productStatus = \is_array($v['product_status'] ?? null) ? $v['product_status'] : [];
            $knownAffected = $this->stringList($productStatus['known_affected'] ?? []);
            $firstAffected = $this->stringList($productStatus['first_affected'] ?? []);
            $fixed = $this->stringList($productStatus['fixed'] ?? []);
            $firstFixed = $this->stringList($productStatus['first_fixed'] ?? []);
            $knownNotAffected = $this->stringList($productStatus['known_not_affected'] ?? []);

            $affectedIds = array_values(array_unique([...$knownAffected, ...$firstAffected]));
            $fixedIds = array_values(array_unique([...$fixed, ...$firstFixed]));

            $cvssScore = $this->extractCvssScore($v['scores'] ?? []);

            $notes = $this->extractNotes($v['notes'] ?? [], ['description', 'summary', 'details']);
            $remediations = $this->extractRemediations($v['remediations'] ?? []);

            $out[] = new CsafVulnerability(
                cve: $cve,
                title: $title,
                cvssScore: $cvssScore,
                knownAffectedProductIds: $affectedIds,
                fixedProductIds: $fixedIds,
                notes: $notes,
                remediations: $remediations,
                knownNotAffectedProductIds: array_values(array_unique($knownNotAffected)),
            );
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $val): array
    {
        if (!\is_array($val)) {
            return [];
        }
        $out = [];
        foreach ($val as $item) {
            if (\is_string($item) && '' !== $item) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>>|mixed $scores
     */
    private function extractCvssScore(mixed $scores): ?float
    {
        if (!\is_array($scores)) {
            return null;
        }

        // Audit L10: one vulnerability may carry several score entries (e.g. per product). Keep the
        // WORST (max) base score per version, not whichever happens to come last in the array.
        $v31 = null;
        $v30 = null;
        foreach ($scores as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            if (isset($entry['cvss_v3_1']) && \is_array($entry['cvss_v3_1'])) {
                $score = $entry['cvss_v3_1']['baseScore'] ?? null;
                if (is_numeric($score)) {
                    $v31 = null === $v31 ? (float) $score : max($v31, (float) $score);
                }
            } elseif (isset($entry['cvss_v3']) && \is_array($entry['cvss_v3'])) {
                $version = (string) ($entry['cvss_v3']['version'] ?? '');
                $score = $entry['cvss_v3']['baseScore'] ?? null;
                if (is_numeric($score)) {
                    if ('3.1' === $version) {
                        $v31 = null === $v31 ? (float) $score : max($v31, (float) $score);
                    } else {
                        $v30 = null === $v30 ? (float) $score : max($v30, (float) $score);
                    }
                }
            } elseif (isset($entry['cvss_v3_0']) && \is_array($entry['cvss_v3_0'])) {
                $score = $entry['cvss_v3_0']['baseScore'] ?? null;
                if (is_numeric($score)) {
                    $v30 = null === $v30 ? (float) $score : max($v30, (float) $score);
                }
            }
        }

        return $v31 ?? $v30;
    }

    /**
     * @param array<int, array<string, mixed>>|mixed $notes
     * @param list<string>                           $categories
     *
     * @return list<string>
     */
    private function extractNotes(mixed $notes, array $categories): array
    {
        if (!\is_array($notes)) {
            return [];
        }
        $out = [];
        foreach ($notes as $note) {
            if (!\is_array($note)) {
                continue;
            }
            $category = (string) ($note['category'] ?? '');
            if (!\in_array($category, $categories, true)) {
                continue;
            }
            $text = (string) ($note['text'] ?? '');
            if ('' !== $text) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * Format CSAF 2.0 §3.2.3.11 remediations into single-line strings preserving the
     * actionable structure (category, URL, date, restart requirement). Without the
     * category label a bare details text loses the distinction between a vendor patch,
     * a workaround, and "no fix available" — which is exactly what the importer would
     * need to communicate to operators reviewing the Mitigation history.
     *
     * Output format: `<Category>: <details>[ (available <date>)][ — <url>][ [Restart: <kind>]]`
     *
     * @param array<int, array<string, mixed>>|mixed $remediations
     *
     * @return list<string>
     */
    private function extractRemediations(mixed $remediations): array
    {
        if (!\is_array($remediations)) {
            return [];
        }
        $out = [];
        foreach ($remediations as $rem) {
            if (!\is_array($rem)) {
                continue;
            }
            $details = trim((string) ($rem['details'] ?? ''));
            if ('' === $details) {
                continue;
            }
            $category = (string) ($rem['category'] ?? '');
            $label = $this->remediationCategoryLabel($category);
            $line = '' !== $label ? $label.': '.$details : $details;
            $date = trim((string) ($rem['date'] ?? ''));
            if ('' !== $date) {
                $line .= ' (available '.$date.')';
            }
            $url = trim((string) ($rem['url'] ?? ''));
            if ('' !== $url) {
                $line .= ' — '.$url;
            }
            // CSAF 2.0 §3.2.3.11.11: restart_required is an object {category, details?} —
            // category=='none' means no restart, anything else (machine/service/parent/…) does.
            $restart = $rem['restart_required'] ?? null;
            if (\is_array($restart) && isset($restart['category']) && \is_string($restart['category']) && 'none' !== $restart['category']) {
                $line .= ' [Restart: '.$restart['category'].']';
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Map CSAF 2.0 remediation category codes to human-readable English labels.
     * English labels keep the Mitigation field culturally neutral when advisories
     * arrive from multiple sources (BSI is German, Red Hat/Siemens/CISA are English).
     */
    private function remediationCategoryLabel(string $category): string
    {
        return match ($category) {
            'vendor_fix' => 'Vendor Fix',
            'mitigation' => 'Mitigation',
            'workaround' => 'Workaround',
            'optional_patch' => 'Optional Patch',
            'none_available' => 'No Fix Available',
            '' => '',
            default => ucfirst(str_replace('_', ' ', $category)),
        };
    }
}
