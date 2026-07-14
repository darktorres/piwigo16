<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Per-request "this request was authenticated via a WS api_key" signal.
 *
 * Replaces a raw `define('PWG_API_KEY_REQUEST', true)` that lived in
 * Piwigo\Bootstrap\UserBootstrap (a mechanical port of user.inc.php's own
 * define()) -- classes under src/Piwigo/ may not call define() (see
 * tests/Arch/StructuralTest.php's "no define() calls" check, part of the
 * SEC-60/worker-mode static-state audit): a real PHP constant can never be
 * un-defined, so it would leak `true` into every later request sharing the
 * same future FrankenPHP worker process. UserBootstrap::initialize() calls
 * activate() once per request when a WS api_key authenticates; SessionService
 * (session persistence gate) and PwgServer (api_key-forbidden WS methods)
 * read isActive() instead of defined('PWG_API_KEY_REQUEST').
 */
final class ApiKeyRequestFlag
{
    private static bool $active = false;

    public static function activate(): void
    {
        self::$active = true;
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * Test-only -- resets the flag between test cases in the same process.
     */
    public static function reset(): void
    {
        self::$active = false;
    }
}
