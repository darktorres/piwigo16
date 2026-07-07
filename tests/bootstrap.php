<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Every test-suite process runs in test mode: PHP_SAPI is 'cli' here, so
// env.inc.php's pwg_test_mode_is_active() only needs the header present,
// not a loopback IP. This makes pwg_load_env_file() below (and any test
// helper that reads getenv(PIWIGO_*)) read .env.test, never production .env.
$_SERVER['HTTP_X_PIWIGO_ENV'] = 'test';

require __DIR__ . '/../include/env.inc.php';
pwg_load_env_file(dirname(__DIR__));
