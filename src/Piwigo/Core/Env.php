<?php

declare(strict_types=1);

namespace Piwigo\Core;

use DateTime;
use Piwigo\Common\ValueObject\IpAddress;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Environment/test-mode resolution, holding the 7 free functions
 * `include/env.inc.php` once defined, bodies unchanged. That file
 * survives as a pure "require vendor/autoload.php" seam -- some thin
 * entry points (random.php, i.php, ready.php) rely on it as their only
 * autoloader hookup -- but their real logic lives here.
 *
 * Method mapping (free function -> method):
 * - pwg_test_mode_header()          -> Env::testModeHeader()
 * - pwg_test_mode_is_active()       -> Env::testModeIsActive()
 * - pwg_test_mode_env_file()        -> Env::testModeEnvFile()
 * - pwg_test_mode_installed_stamp() -> Env::testModeInstalledStamp()
 * - pwg_now()                       -> Env::now()
 * - pwg_load_env_file()             -> Env::loadEnvFile()
 */
final class Env
{
    /**
     * Returns the validated X-Piwigo-Env header value ('test' or 'test-wN'), or null.
     * Centralises the regex so the path methods can't drift from testModeIsActive().
     */
    public static function testModeHeader(): ?string
    {
        $header = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
        if (! is_string($header) || preg_match('/^test(-w\d+)?$/', $header) !== 1) {
            return null;
        }

        return $header;
    }

    /**
     * Returns true when the current request is a test-mode runtime.
     * Triggers: CLI process with the header set (Pest bootstrap), or a web
     * request from loopback (127.0.0.1 / ::1) with the header (browser E2E).
     */
    public static function testModeIsActive(): bool
    {
        if (self::testModeHeader() === null) {
            return false;
        }

        if (PHP_SAPI === 'cli') {
            return true;
        }

        $remote = IpAddress::fromRemoteAddr()->value ?? '';
        return $remote === '127.0.0.1' || $remote === '::1';
    }

    /**
     * Filename of the env file the runtime should load.
     * Returns '.env' in production, '.env.<header>' in test mode
     * (e.g. '.env.test', '.env.test-w1' for a parallel worker).
     */
    public static function testModeEnvFile(): string
    {
        $value = self::testModeIsActive() ? self::testModeHeader() : null;
        return $value === null ? '.env' : '.env.' . $value;
    }

    /**
     * Filename of the install-sentinel stamp under local/.
     * Returns '.installed' in production, '.installed.<header>' in test mode.
     */
    public static function testModeInstalledStamp(): string
    {
        $value = self::testModeIsActive() ? self::testModeHeader() : null;
        return $value === null ? '.installed' : '.installed.' . $value;
    }

    /**
     * Returns "now" — real wall-clock time normally, or a fixed instant from
     * PIWIGO_TEST_NOW when test mode is active and that var is set. Lets
     * time_since()-based relative-time text (and similar "since" widgets) render
     * deterministically in tests without a full mockable-clock/DI layer.
     */
    public static function now(): DateTime
    {
        if (self::testModeIsActive()) {
            $frozen = getenv('PIWIGO_TEST_NOW');
            if ($frozen !== false && $frozen !== '') {
                return new DateTime($frozen);
            }
        }

        return new DateTime();
    }

    /**
     * Loads the env file chosen by testModeEnvFile() from $root.
     * Uses symfony/dotenv with usePutenv() so existing getenv() call sites keep
     * working; process env vars set before this call (systemd EnvironmentFile,
     * Docker -e, shell export) win over file values (Dotenv::populate()'s
     * default $overrideExistingVars = false). Missing file is a safe no-op.
     */
    /**
     * Symfony Dotenv's own `populate()` only overrides an already-set env
     * var when that var's name is listed in `SYMFONY_DOTENV_VARS`
     * (tracking "a prior `Dotenv::populate()` call in THIS process set
     * this one, so refresh it" -- distinct from a genuinely external
     * var, e.g. a real systemd `EnvironmentFile`/Docker `-e`, which
     * `overrideExistingVars = false` deliberately protects). That
     * tracking list lives in `$_ENV`/`$_SERVER`, which a normal SAPI
     * request resets every request -- but the actual `putenv()`'d
     * values (and `SYMFONY_DOTENV_VARS` itself, also `putenv()`'d) live
     * in the OS-level process environment table, which survives across
     * requests within the SAME reused Apache/mod_php worker process.
     * Without this, a worker that has ever loaded one of `.env`/
     * `.env.test` first makes every later request in that same worker
     * -- `X-Piwigo-Env: test` header or not -- silently keep routing to
     * whichever file loaded first: `populate()` sees a fresh, empty
     * `$_ENV['SYMFONY_DOTENV_VARS']` this request, doesn't recognize
     * the leftover `putenv()`'d values as its own prior work, and
     * treats them as protected external ones instead. Found live: a
     * manual Playwright/curl session against a shared dev Apache
     * instance kept silently landing on the production DB despite a
     * correctly-sent test-mode header, once any earlier request on that
     * same worker had loaded `.env` first. Re-seeding `$_ENV`/
     * `$_SERVER`'s own copy of `SYMFONY_DOTENV_VARS` from the
     * OS-level value `getenv()` can still see restores Dotenv's own
     * "these were set by a prior load, always refresh them"
     * recognition for this request -- a real genuinely-external var
     * (never `putenv()`'d by this mechanism, so never listed here) is
     * still left untouched, unlike a blanket `Dotenv::overload()` /
     * `overrideExistingVars: true` switch would.
     */
    private static function reseedDotenvLoadedVarsTracking(): void
    {
        $loadedVars = getenv('SYMFONY_DOTENV_VARS');
        if ($loadedVars === false) {
            return;
        }

        $_ENV['SYMFONY_DOTENV_VARS'] = $loadedVars;
        $_SERVER['SYMFONY_DOTENV_VARS'] = $loadedVars;
    }

    public static function loadEnvFile(string $root): void
    {
        $root = rtrim($root, '/\\');
        $file = $root . DIRECTORY_SEPARATOR . self::testModeEnvFile();
        if (! is_file($file)) {
            return;
        }

        self::reseedDotenvLoadedVarsTracking();

        new Dotenv()
            ->usePutenv()
            ->load($file);
    }

    /**
     * Whether Tracy's debug bar should be enabled -- `PIWIGO_TRACY_ENABLED`
     * unset/empty/`'0'` means disabled, matching `SentryBootstrap`'s own
     * "no-op unless explicitly opted in" shape for `SENTRY_DSN`. Lives here
     * (L1Infrastructure), not on `Piwigo\Bootstrap\TracyBootstrap` (L4), so
     * `Piwigo\Template\LatteEngine` (L3) can read it too -- same reasoning
     * as `DefaultLanguageProviderInterface`'s own docblock: a lower layer
     * can't reach upward for something a higher layer would otherwise own.
     */
    public static function isTracyEnabled(): bool
    {
        $value = getenv('PIWIGO_TRACY_ENABLED');

        return $value !== false && $value !== '' && $value !== '0';
    }

    /**
     * Atomically writes/updates $values (KEY => value) into $envFile,
     * preserving every other line already there (a re-installing site's own
     * unrelated vars -- e.g. PIWIGO_TEST_NOW, see now() above -- must not be
     * silently dropped by a later merge). A key present in $values replaces
     * that key's existing line (if any); other lines pass through unchanged.
     * Values are stripped of line-breaks to prevent .env injection via
     * untrusted input (e.g. a submitted install form).
     *
     * Used by InstallWizard::performInstall() to write the freshly
     * submitted DB credentials into .env.
     *
     * @param array<string, string> $values
     */
    public static function mergeIntoEnvFile(string $envFile, array $values): bool
    {
        $values = array_map(
            static fn (string $v): string => str_replace(["\n", "\r", "\0"], '', $v),
            $values
        );

        $body = '';
        foreach ($values as $key => $value) {
            $body .= $key . '=' . $value . "\n";
        }

        if (is_file($envFile)) {
            $existingLines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($existingLines !== false ? $existingLines : [] as $existingLine) {
                $existingKey = strtok($existingLine, '=');
                if ($existingKey !== false && ! array_key_exists($existingKey, $values)) {
                    $body .= $existingLine . "\n";
                }
            }
        }

        $tmp = $envFile . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $body) === false || ! rename($tmp, $envFile)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
