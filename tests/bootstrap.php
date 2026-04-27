<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Stub Piwigo global functions needed by unit-tested classes that normally
// depend on the full HTTP/DB bootstrap not present in isolated unit tests.
if (!function_exists('set_status_header')) {
    function set_status_header(int $code, string $text = ''): void
    {
        // No-op in unit test environment — headers cannot be sent from CLI.
    }
}
