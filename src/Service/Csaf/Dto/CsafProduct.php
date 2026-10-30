<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Dto;

use App\Service\Csaf\Matching\VersionRange;

/**
 * A single product leaf from a CSAF document's product_tree, reduced to what the matcher needs
 * (#134): an identity (vendor + product token) and the affected version range.
 *
 * Tokens come from the leaf's CPE when it carries one (parts 3/4), otherwise from the nearest
 * `vendor` and `product_name`/`product_family` branch names, normalised the same way as the
 * inventory keys. The range comes from the nearest `product_version`/`product_version_range`
 * branch name — never from the CPE's version component when a branch name exists, because the
 * branch name is where BSI states the bound (`<7.6.4`) that the CPE lacks.
 */
final readonly class CsafProduct
{
    public function __construct(
        public string $productId,
        public string $name,
        /** Normalised vendor token, '' when the document gives none. */
        public string $vendorToken,
        /** Normalised product token, '' when the document gives none. */
        public string $productToken,
        public VersionRange $range,
        /** The version text as published (branch name or CPE component), '' when none. */
        public string $rawVersionText = '',
        public ?string $cpe = null,
    ) {
    }

    public function hasIdentity(): bool
    {
        return '' !== $this->vendorToken && '' !== $this->productToken;
    }
}
