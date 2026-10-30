<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Exception;

/**
 * Raised when a successor `Software` cannot be persisted because the
 * (vendor, productKey, version) tuple is already used.
 */
final class SoftwareSuccessorAlreadyExistsException extends \RuntimeException
{
}
