<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * Per-request switch between production and test runtime config.
 *
 * Test runs (PHPUnit, Playwright e2e) signal the runtime by attaching the
 * `X-Piwigo-Env: test` HTTP header. The runtime — `ConfigLoader::loadEnv()`
 * and `InstallSentinel` — checks this flag and reads `.env.test` /
 * `local/.installed.test` instead of the production paths.
 *
 * Effects when active:
 *   - `ConfigLoader::loadEnv()` reads `.env.test` (not `.env`)
 *   - `InstallSentinel` uses `local/.installed.test` (not `local/.installed`)
 *   - `install.php` writes its DB credentials to `.env.test`
 *
 * Triggers (in order, first-match wins):
 *   1. PHP CLI (`PHP_SAPI === 'cli'`) with `$_SERVER['HTTP_X_PIWIGO_ENV'] === 'test'`.
 *      PHPUnit's `tests/bootstrap.php` sets the latter so in-process runtime
 *      invocations from unit/integration tests enter test mode.
 *   2. Web request from loopback (`127.0.0.1` / `::1`) with the same header.
 *      Playwright sends the header on every browser/API request via the
 *      `extraHTTPHeaders` config; only loopback origins are honoured so an
 *      attacker on the network can't flip a public install into test mode.
 *
 * Production runtime is the default — without the header, behaviour is
 * exactly what it was before this class existed.
 */
final class TestMode
{
    public static function isActive(): bool
    {
        $header = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
        if (!is_string($header) || $header !== 'test') {
            return false;
        }
        if (PHP_SAPI === 'cli') {
            return true;
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        return $remote === '127.0.0.1' || $remote === '::1';
    }

    /** Filename of the env file the runtime should load — `.env.test` in test mode, `.env` otherwise. */
    public static function envFile(): string
    {
        return self::isActive() ? '.env.test' : '.env';
    }

    /** Filename of the install-sentinel stamp under `local/`. */
    public static function installedStamp(): string
    {
        return self::isActive() ? '.installed.test' : '.installed';
    }
}
