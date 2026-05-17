<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\TestMode;

/**
 * Authoritative answer to "is Piwigo installed on this filesystem?".
 *
 * Sole signal: an empty stamp file under `local/` (created by InstallController (index.php?/install)
 * at the end of a successful install).
 *
 * The stamp filename depends on TestMode — production uses
 * `local/.installed`, test runs use `local/.installed.test`. Tests
 * therefore have their own install lifecycle and never affect the prod
 * sentinel.
 *
 * Used by:
 *   - index.php?/install  (refuse to re-install)
 *   - common.inc.php (redirect to index.php?/install on first request)
 *   - functions_session.inc.php (gate the DB session handler)
 *   - functions_user.inc.php (skip user-mail / registration before install)
 *   - functions.inc.php (language fallback during upgrade)
 */
final class InstallSentinel
{
    public static function isInstalled(): bool
    {
        return file_exists(self::stampFile());
    }

    public static function markInstalled(): void
    {
        $path = self::stampFile();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            Filesystem::mkgetdir($dir, Filesystem::FLAG_RECURSIVE);
        }
        set_error_handler(static fn (): bool => true);
        try {
            touch($path);
        } finally {
            restore_error_handler();
        }
    }

    /** Test helper. Removes the stamp file if it exists. */
    public static function markUninstalled(): void
    {
        $path = self::stampFile();
        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function stampFile(): string
    {
        return Kernel::isBooted()
            ? Kernel::service(Paths::class)->root . 'local/' . TestMode::installedStamp()
            : (defined('PHPWG_ROOT_PATH') ? PHPWG_ROOT_PATH . 'local/' . TestMode::installedStamp() : '');
    }
}
