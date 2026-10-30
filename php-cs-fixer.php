<?php

declare(strict_types=1);

// $finder = PhpCsFixer\Finder::create()
//    ->in(__DIR__.'/src')
// ;
// $config = new PhpCsFixer\Config();
// return $config->setRules([
//    '@Symfony' => true,
//    'yoda_style' => false,
//    '@PSR12' => true,
//    'array_syntax' => ['syntax' => 'short'],
//    'strict_param' => true,
//    'declare_strict_types' => true,
//    'phpdoc_align' => false,
// ])
//    ->setFinder($finder)
//    ;

$header = <<<'EOF'
    SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
    SPDX-License-Identifier: AGPL-3.0-or-later
    EOF;

$rules = [
    '@Symfony' => true,
    'yoda_style' => false,
    '@PSR12' => true,
    'array_syntax' => ['syntax' => 'short'],
    'strict_param' => true,
    'declare_strict_types' => true,
    'phpdoc_align' => false,
    'header_comment' => [
        'header' => $header,
        'comment_type' => 'comment',
        'location' => 'after_declare_strict',
        'separate' => 'both',
    ],
];
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests']);

return new PhpCsFixer\Config()
    ->setRules($rules)
    ->setRiskyAllowed(true)
    ->setFinder($finder);
