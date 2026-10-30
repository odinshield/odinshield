<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Matching;

use App\Entity\Software;
use App\Service\Csaf\Dto\CsafProduct;
use Psr\Log\LoggerInterface;

/**
 * Matches advisory products against the inventory by identity and version (#134, D2/D4/D5/D7).
 *
 *     for each advisory product p, for each inventory software s:
 *         keys equal (vendorKey ∪ vendor aliases, productKey ∪ software aliases)?
 *             version comparable and range intersects → hit (exact | prefix)
 *             version not comparable                  → hit (inventory-unparseable)
 *             range unreadable                        → hit (advisory-unparseable)
 *             otherwise                               → version-outside, counted only
 *         keys merely contain each other?             → near-miss, logged only
 *
 * Equality is after normalisation on both sides. Containment is never a match — name
 * containment produced findings for products nobody runs (#134).
 */
final readonly class InventoryMatcher
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Identity and version.
     *
     * @param list<CsafProduct> $products
     * @param list<Software>    $inventory
     * @param array<string, mixed> $logContext added to the near-miss notice (provider, docId)
     */
    public function match(array $products, array $inventory, array $logContext = []): MatchResult
    {
        $hits = [];
        $seen = [];
        $outside = [];
        $near = [];
        $nearSeen = [];

        foreach ($products as $product) {
            if (!$product->hasIdentity()) {
                continue;
            }
            foreach ($inventory as $software) {
                $vendorEq = $this->anyEquals($product->vendorToken, $software->getVendor()->getVendorTokens());
                $productEq = $this->anyEquals($product->productToken, $software->getProductTokens());

                if ($vendorEq && $productEq) {
                    $reason = $this->reasonFor($product, $software);
                    if (null === $reason) {
                        $outside[] = ['product' => $product, 'software' => $software];
                        continue;
                    }
                    if (isset($seen[$software->getId()])) {
                        continue;
                    }
                    $seen[$software->getId()] = true;
                    $hits[] = new InventoryMatch($software, $product, $reason);
                    continue;
                }

                $vendorNear = $vendorEq || $this->anyContains($product->vendorToken, $software->getVendor()->getVendorTokens());
                $productNear = $productEq || $this->anyContains($product->productToken, $software->getProductTokens());
                if ($vendorNear && $productNear) {
                    $key = $product->vendorToken.'/'.$product->productToken.'#'.$software->getId();
                    if (isset($nearSeen[$key])) {
                        continue;
                    }
                    $nearSeen[$key] = true;
                    $near[] = ['product' => $product, 'software' => $software];
                    $this->logger->notice('CSAF near-miss: advisory product resembles inventory software but keys differ — consider an alias', $logContext + [
                        'advisoryVendor' => $product->vendorToken,
                        'advisoryProduct' => $product->productToken,
                        'advisoryName' => $product->name,
                        'inventoryVendorKey' => $software->getVendor()->getVendorKey(),
                        'inventoryProductKey' => $software->getProductKey(),
                        'inventoryName' => $software->getName().' '.$software->getVersion(),
                    ]);
                }
            }
        }

        return new MatchResult($hits, $outside, $near);
    }

    /**
     * Identity only — for the advisory's *fixed* products, where the question is "does a fix for
     * this product exist", not "is my version inside a range".
     *
     * @param list<CsafProduct> $products
     * @param list<Software>    $inventory
     *
     * @return list<array{product: CsafProduct, software: Software}>
     */
    public function matchKeys(array $products, array $inventory): array
    {
        $out = [];
        $seen = [];
        foreach ($products as $product) {
            if (!$product->hasIdentity()) {
                continue;
            }
            foreach ($inventory as $software) {
                if (isset($seen[$software->getId()])) {
                    continue;
                }
                if ($this->anyEquals($product->vendorToken, $software->getVendor()->getVendorTokens())
                    && $this->anyEquals($product->productToken, $software->getProductTokens())) {
                    $seen[$software->getId()] = true;
                    $out[] = ['product' => $product, 'software' => $software];
                }
            }
        }

        return $out;
    }

    /**
     * Null when the keys match but the version lies outside the advisory's range.
     */
    public function reasonFor(CsafProduct $product, Software $software): ?MatchReason
    {
        if (null !== $product->range->parseFailure) {
            return MatchReason::AdvisoryUnparseable;
        }
        $inventory = ComparableVersion::fromString($software->getVersion());
        if (null === $inventory) {
            return MatchReason::InventoryUnparseable;
        }
        if (!$product->range->intersects($inventory)) {
            return null;
        }

        return $product->range->isExactly($inventory) ? MatchReason::Exact : MatchReason::Prefix;
    }

    /**
     * @param list<string> $candidates
     */
    private function anyEquals(string $token, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (KeyNormaliser::equals($token, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $candidates
     */
    private function anyContains(string $token, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (KeyNormaliser::containsEitherWay($token, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
