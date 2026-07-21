<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// P0: install + dry-run only, to record a pending-change count (docs/PLAN-REPLAY.md
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
    ])
    ->withPhpSets(php85: true)
    ->withPreparedSets(typeDeclarations: true, instanceOf: true)
    ->withParallel(timeoutSeconds: 300);
