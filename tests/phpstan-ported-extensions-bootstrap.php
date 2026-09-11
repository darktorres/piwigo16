<?php

declare(strict_types=1);

use Piwigo\Tests\Support\PortedExtensionAutoloader;

// Registers real PSR-4 autoloading for every already-ported plugin's/
// theme's own src/ (../piwigo16-plugins, ../piwigo16-themes,
// <id>_17.0.0/) before analysis starts -- PHPStan's own reflection
// provider falls back to PHP's native spl_autoload_register-based
// class-loading for any symbol its own static file scanning
// (`parameters.paths`, `scanDirectories`) doesn't already know about, so
// this is what lets tests/PortedExtensions/**/*.php (real files under
// this repo's own `paths: [.]`) resolve a `use Piwigo\Theme\Modus\...`
// without a `class.notFound` error, with zero manual config here when a
// new extension gets ported -- same "fresh glob every run" shape as
// tools/analyse-ported-extensions.sh/build-ported-extension-assets.mjs.
//
// Deliberately separate from tests/bootstrap.php (the real PHPUnit/Pest
// bootstrap): PHPStan's own `bootstrapFiles` run in PHPStan's own
// process, never a real test run.
require __DIR__ . '/../vendor/autoload.php';

PortedExtensionAutoloader::register();
