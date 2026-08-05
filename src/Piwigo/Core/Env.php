<?php

declare(strict_types=1);

namespace Piwigo\Core;

use DateTime;
use Piwigo\Common\ValueObject\IpAddress;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Environment/test-mode resolution, ported verbatim from the 7 free
 * functions that used to live in include/env.inc.php (P23 sub-batch 8f-5).
 * That file survives as a pure "require vendor/autoload.php" seam (some
 * thin entry points -- random.php, i.php, ready.php -- rely on it as
 * their only autoloader hookup); everything it used to *do* lives here.
 *
 * Method mapping (former free function -> method), all bodies unchanged:
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
    public static function loadEnvFile(string $root): void
    {
        $root = rtrim($root, '/\\');
        $file = $root . DIRECTORY_SEPARATOR . self::testModeEnvFile();
        if (! is_file($file)) {
            return;
        }

        new Dotenv()
            ->usePutenv()
            ->load($file);
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
