<?php

// SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
//
// SPDX-License-Identifier: AGPL-3.0-or-later

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
    ])
    ->setFinder($finder)
;
