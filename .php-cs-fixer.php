<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => true,
        'phpdoc_scalar' => true,
        'unary_operator_spaces' => true,
        'blank_line_before_statement' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'return_type_declaration' => true,
        'no_extra_blank_lines' => ['tokens' => ['extra', 'throw', 'use']],
        'not_operator_with_successor_space' => true,
        'class_attributes_separation' => ['elements' => ['method' => 'one']],
        'single_trait_insert_per_statement' => true,
        'no_whitespace_before_comma_in_array' => true,
    ])
    ->setFinder($finder);
