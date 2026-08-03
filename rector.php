<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictConstantReturnRector;

// P0: install + dry-run only, to record a pending-change count (docs/PLAN.md
// P0 step 6). No rule set is applied to the working tree here — that's P5, once the
// P2 regression harness exists to catch a mis-behaving fixer. Rule selection below
// is deliberately provisional; P5 designs the real strategy from scratch.
return RectorConfig::configure()
    ->withPaths([
        __DIR__,
    ])
    ->withSkip([
        __DIR__ . '/_data',
        __DIR__ . '/galleries',
        __DIR__ . '/install/db',
        __DIR__ . '/language',
        __DIR__ . '/local',
        __DIR__ . '/node_modules',
        __DIR__ . '/vendor',
        // PluginMaintain::install()/activate()/deactivate()/uninstall() and
        // ThemeMaintain::activate()/deactivate()/delete() each only ever
        // `return null;`, which is exactly what this rule infers a native
        // `: null` return type from. That's incompatible with any real
        // plugin/theme maintain.class.php override written without a return
        // type at all (PHP fatals on such a mismatch) -- confirmed by
        // tests/Integration/ExtensionLifecycleTest.php's own
        // writePluginMaintainFile()-based fixtures, which do exactly that on
        // purpose. These two base classes must stay natively untyped here;
        // this is not a style preference this rule can safely "fix".
        ReturnTypeFromStrictConstantReturnRector::class => [
            __DIR__ . '/src/Piwigo/Admin/PluginMaintain.php',
            __DIR__ . '/src/Piwigo/Admin/ThemeMaintain.php',
        ],
    ])
    ->withPhpSets(php85: true)
    ->withPreparedSets(typeDeclarations: true, instanceOf: true)
    ->withParallel(timeoutSeconds: 300);
