<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__,
    ])
    ->withSkip([
        // Generated PHPStan Latte analysis files (bin/piwigo
        // phpstan-latte:compile) -- Latte's own compiled-output style,
        // regenerated wholesale; not code to modernize.
        __DIR__ . '/_analysis',
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
    ->withImportNames()
    ->withParallel(timeoutSeconds: 300);
