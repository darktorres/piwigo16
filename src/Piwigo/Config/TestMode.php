<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * Per-request switch between production and test runtime config.
 *
 * Test runs (PHPUnit, Playwright e2e) signal the runtime by attaching the
 * `X-Piwigo-Env: test` HTTP header (or `test-w<N>` under parallel testing
 * — see below). The runtime — `ConfigLoader::loadEnv()` and
 * `InstallSentinel` — checks this flag and reads `.env.test` /
 * `local/.installed.test` (or per-worker variants) instead of the
 * production paths.
 *
 * Effects when active:
 *   - `ConfigLoader::loadEnv()` reads `.env.<header>` (not `.env`)
 *   - `InstallSentinel` uses `local/.installed.<header>` (not `.installed`)
 *   - `install.php` writes its DB credentials to `.env.<header>`
 *
 * Triggers (in order, first-match wins):
 *   1. PHP CLI (`PHP_SAPI === 'cli'`) with `$_SERVER['HTTP_X_PIWIGO_ENV']`
 *      matching `test` or `test-w<N>`. PHPUnit's `tests/bootstrap.php` sets
 *      the latter so in-process runtime invocations from unit/integration
 *      tests enter test mode.
 *   2. Web request from loopback (`127.0.0.1` / `::1`) with the same header.
 *      Playwright sends the header on every browser/API request via the
 *      `extraHTTPHeaders` config; only loopback origins are honoured so an
 *      attacker on the network can't flip a public install into test mode.
 *
 * Per-worker variants (`test-w1`, `test-w2`, …): under paratest each worker
 * carries a distinct token in the header so it can route to its own .env
 * and sentinel files without colliding with other workers. The accepted
 * header regex (`^test(-w\d+)?$`) is intentionally narrow so the .env file
 * suffix cannot be shaped into an arbitrary path by an attacker.
 *
 * Production runtime is the default — without the header, behaviour is
 * exactly what it was before this class existed.
 */
final class TestMode
{
    /** Accepted header values: `test` or `test-w<digits>` (paratest worker). */
    private const string HEADER_REGEX = '/^test(-w\d+)?$/';

    public static function isActive(): bool
    {
        if (self::headerValue() === null) {
            return false;
        }
        if (PHP_SAPI === 'cli') {
            return true;
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        return $remote === '127.0.0.1' || $remote === '::1';
    }

    /** Filename of the env file the runtime should load — `.env.<header>` in test mode, `.env` otherwise. */
    public static function envFile(): string
    {
        $value = self::isActive() ? self::headerValue() : null;
        return $value === null ? '.env' : '.env.' . $value;
    }

    /** Filename of the install-sentinel stamp under `local/`. */
    public static function installedStamp(): string
    {
        $value = self::isActive() ? self::headerValue() : null;
        return $value === null ? '.installed' : '.installed.' . $value;
    }

    /**
     * Validated header value, or null when absent / malformed. Centralises
     * the regex so the file-path methods can't drift from {@see isActive()}.
     */
    private static function headerValue(): ?string
    {
        $header = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
        if (!is_string($header) || preg_match(self::HEADER_REGEX, $header) !== 1) {
            return null;
        }
        return $header;
    }
}
