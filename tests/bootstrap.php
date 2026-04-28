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

// Stub Piwigo global functions needed by unit-tested classes that normally
// depend on the full HTTP/DB bootstrap not present in isolated unit tests.
if (!function_exists('set_status_header')) {
    function set_status_header(int $code, string $text = ''): void
    {
        // No-op in unit test environment — headers cannot be sent from CLI.
    }
}
