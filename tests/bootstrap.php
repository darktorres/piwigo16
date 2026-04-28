<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Core Piwigo constants required by classes that reference them at parse time.
if (!defined('PHPWG_VERSION')) {
    define('PHPWG_VERSION', '16.0.0-test');
}

// Root path constant required by classes that build file paths.
// Points to the repository root; safe for unit tests that use tmp dirs.
if (!defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', dirname(__DIR__) . '/');
}

// Web-service constants from include/ws_core.inc.php.
if (!defined('WS_PARAM_OPTIONAL')) {
    define('WS_PARAM_ACCEPT_ARRAY', 0x010000);
    define('WS_PARAM_FORCE_ARRAY',  0x030000);
    define('WS_PARAM_OPTIONAL',     0x040000);
    define('WS_TYPE_BOOL',     0x01);
    define('WS_TYPE_INT',      0x02);
    define('WS_TYPE_FLOAT',    0x04);
    define('WS_TYPE_POSITIVE', 0x10);
    define('WS_TYPE_NOTNULL',  0x20);
    define('WS_TYPE_ID',       WS_TYPE_INT | WS_TYPE_POSITIVE | WS_TYPE_NOTNULL);
}

// Stub Piwigo global functions needed by unit-tested classes that normally
// depend on the full HTTP/DB bootstrap not present in isolated unit tests.
if (!function_exists('set_status_header')) {
    function set_status_header(int $code, string $text = ''): void
    {
        // No-op in unit test environment — headers cannot be sent from CLI.
    }
}
