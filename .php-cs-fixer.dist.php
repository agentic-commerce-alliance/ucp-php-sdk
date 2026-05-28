<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/packages/core/src',
        __DIR__ . '/packages/core/tests',
        __DIR__ . '/packages/symfony-bundle/src',
        __DIR__ . '/packages/symfony-bundle/tests',
        __DIR__ . '/examples/bootstrap-symfony-app/src',
        __DIR__ . '/examples/bootstrap-symfony-app/public',
        __DIR__ . '/examples/merchant-symfony-app/src',
        __DIR__ . '/examples/merchant-symfony-app/public',
        __DIR__ . '/tools',
    ])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_after_opening_tag' => true,
        'declare_strict_types' => true,
        'fully_qualified_strict_types' => true,
        'line_ending' => true,
        'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
        'no_blank_lines_after_phpdoc' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
    ])
    ->setFinder($finder);
