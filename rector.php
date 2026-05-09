<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\Php85\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRootFiles()
    ->withSkip([
        // load_external_filters() uses implode('', $callback) for compile_id — array callbacks only.
        // Skip ArrayToFirstClassCallableRector on Template so set_prefilter callbacks stay as arrays.
        ArrayToFirstClassCallableRector::class => [
            __DIR__ . '/src/Piwigo/Template/Template.php',
        ],
        // version_compare() first-class callable has a multi-overload type PHPStan rejects for usort.
        // The explicit closure fn(string $a, string $b): int => version_compare($a, $b) is correct.
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [
            __DIR__ . '/src/Piwigo/Calendar/CalendarBase.php',
        ],
        // PHP's #[\Override] attribute is method-only; Psalm correctly flags it on
        // properties as InvalidAttribute. Skip the rule so it doesn't keep adding it.
        AddOverrideAttributeToOverriddenPropertiesRector::class,
    ])
    ->withPhpSets(php85: true)
    ->withComposerBased(doctrine: true, phpunit: true, symfony: true)
    ->withSets([SetList::TYPE_DECLARATION])
    ->withRules([
        DeclareStrictTypesRector::class,
        RemoveUselessParamTagRector::class,
        RemoveUselessReturnTagRector::class,
        RemoveUselessVarTagRector::class,
    ])
    ->withBootstrapFiles([
        __DIR__ . '/tools/phpstan-bootstrap.php',
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withParallel(timeoutSeconds: 300);
