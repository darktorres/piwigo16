<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Every test-suite process runs in test mode: PHP_SAPI is 'cli' here, so
// Piwigo\Core\Env::testModeIsActive() only needs the header present,
// not a loopback IP. This makes Env::loadEnvFile() below (and any test
// helper that reads getenv(PIWIGO_*)) read .env.test, never production .env.
$_SERVER['HTTP_X_PIWIGO_ENV'] = 'test';

\Piwigo\Core\Env::loadEnvFile(dirname(__DIR__));
