<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Authoritative answer to "is Piwigo installed on this filesystem?".
 *
 * Sole signal: an empty stamp file at local/.installed (created by
 * install.php at the end of a successful install).
 *
 * Used by:
 *   - install.php   (refuse to re-install)
 *   - common.inc.php (redirect to install.php on first request)
 *   - functions_session.inc.php (gate the DB session handler)
 *   - functions_user.inc.php (skip user-mail / registration before install)
 *   - functions.inc.php (language fallback during upgrade)
 */
final class InstallSentinel
{
    private const STAMP_PATH = 'local/.installed';

    public static function isInstalled(): bool
    {
        return file_exists(self::stampFile());
    }

    public static function markInstalled(): void
    {
        $path = self::stampFile();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        @touch($path);
    }

    /** Test helper. Removes the stamp file if it exists. */
    public static function markUninstalled(): void
    {
        $path = self::stampFile();
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    private static function stampFile(): string
    {
        return PHPWG_ROOT_PATH . self::STAMP_PATH;
    }
}
