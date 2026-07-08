<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

/**
 * Returns the validated X-Piwigo-Env header value ('test' or 'test-wN'), or null.
 * Centralises the regex so the path functions can't drift from pwg_test_mode_is_active().
 */
function pwg_test_mode_header(): ?string
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
function pwg_test_mode_is_active(): bool
{
    if (pwg_test_mode_header() === null) {
        return false;
    }

    if (PHP_SAPI === 'cli') {
        return true;
    }

    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return $remote === '127.0.0.1' || $remote === '::1';
}

/**
 * Filename of the env file the runtime should load.
 * Returns '.env' in production, '.env.<header>' in test mode
 * (e.g. '.env.test', '.env.test-w1' for a parallel worker).
 */
function pwg_test_mode_env_file(): string
{
    $value = pwg_test_mode_is_active() ? pwg_test_mode_header() : null;
    return $value === null ? '.env' : '.env.' . $value;
}

/**
 * Filename of the install-sentinel stamp under local/.
 * Returns '.installed' in production, '.installed.<header>' in test mode.
 */
function pwg_test_mode_installed_stamp(): string
{
    $value = pwg_test_mode_is_active() ? pwg_test_mode_header() : null;
    return $value === null ? '.installed' : '.installed.' . $value;
}

/**
 * Maps PIWIGO_DB_* env vars into $conf and $prefixeTable.
 * Call after pwg_load_env_file() to apply the loaded values.
 * @param array<string, mixed> $conf
 */
function pwg_apply_env_to_conf(array &$conf, string &$prefixeTable): void
{
    if (($v = getenv('PIWIGO_DB_HOST')) !== false && $v !== '') {
        $conf['db_host'] = $v;
    }

    if (($v = getenv('PIWIGO_DB_USER')) !== false && $v !== '') {
        $conf['db_user'] = $v;
    }

    if (($v = getenv('PIWIGO_DB_PASSWORD')) !== false && $v !== '') {
        $conf['db_password'] = $v;
    }

    if (($v = getenv('PIWIGO_DB_BASE')) !== false && $v !== '') {
        $conf['db_base'] = $v;
    }

    $conf['dblayer'] = 'mysqli';
    $prefixeTable = (($v = getenv('PIWIGO_DB_PREFIX')) !== false && $v !== '') ? $v : 'piwigo_';
}

/**
 * Returns "now" — real wall-clock time normally, or a fixed instant from
 * PIWIGO_TEST_NOW when test mode is active and that var is set. Lets
 * time_since()-based relative-time text (and similar "since" widgets) render
 * deterministically in tests without a full mockable-clock/DI layer.
 */
function pwg_now(): \DateTime
{
    if (pwg_test_mode_is_active()) {
        $frozen = getenv('PIWIGO_TEST_NOW');
        if ($frozen !== false && $frozen !== '') {
            return new \DateTime($frozen);
        }
    }

    return new \DateTime();
}

/**
 * Loads the env file chosen by pwg_test_mode_env_file() from $root.
 * Uses symfony/dotenv with usePutenv() so existing getenv() call sites keep
 * working; process env vars set before this call (systemd EnvironmentFile,
 * Docker -e, shell export) win over file values (Dotenv::populate()'s
 * default $overrideExistingVars = false). Missing file is a safe no-op.
 */
function pwg_load_env_file(string $root): void
{
    $root = rtrim($root, '/\\');
    $file = $root . DIRECTORY_SEPARATOR . pwg_test_mode_env_file();
    if (! is_file($file)) {
        return;
    }

    (new Dotenv())->usePutenv()
        ->load($file);
}
