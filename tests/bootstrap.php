<?php

declare(strict_types=1);

use Piwigo\Core\Env;
use Piwigo\Tests\Support\PortedExtensionAutoloader;

require __DIR__ . '/../vendor/autoload.php';

// Every test-suite process runs in test mode: PHP_SAPI is 'cli' here, so
// Piwigo\Core\Env::testModeIsActive() only needs the header present,
// not a loopback IP. This makes Env::loadEnvFile() below (and any test
// helper that reads getenv(PIWIGO_*)) read .env.test, never production .env.
$_SERVER['HTTP_X_PIWIGO_ENV'] = 'test';

Env::loadEnvFile(dirname(__DIR__));

// Makes a ported extension's own classes (../piwigo16-plugins,
// ../piwigo16-themes, <id>_17.0.0/) `use`-able from any test file, in
// any suite -- see that class's own docblock. A no-op when neither
// sibling repo exists.
PortedExtensionAutoloader::register();
