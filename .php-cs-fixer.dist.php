<?php

declare(strict_types=1);

// Only the Parisek-authored code — the Drupal-vendored classes in src/
// (Drupal\Component\Attribute\*) deliberately keep Drupal's 2-space style so
// upstream refreshes stay diff-able; they are NOT formatted here.
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/tests', __DIR__ . '/scripts'])
    ->exclude('fixtures')
    ->append([__DIR__ . '/AttributeExtension.php'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
