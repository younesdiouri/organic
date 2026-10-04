<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config', __DIR__.'/migrations', __DIR__.'/public'])
    ->notPath('reference.php')
    ->append([__DIR__.'/bin/console', __FILE__]);

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@Symfony' => true,
        'braces_position' => ['allow_single_line_anonymous_functions' => false],
        'single_line_empty_body' => false,
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'if', 'foreach', 'for', 'while', 'switch', 'try']],
        'class_attributes_separation' => ['elements' => ['const' => 'one', 'method' => 'one', 'property' => 'one', 'trait_import' => 'one']],
    ])
    ->setFinder($finder);
