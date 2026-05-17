<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\TestMode;

/**
 * Authoritative answer to "is Piwigo installed on this filesystem?".
 *
 * Sole signal: an empty stamp file under `local/` (created by InstallController
 * (index.php?/install) at the end of a successful install).
 *
 * The stamp filename depends on TestMode — production uses `local/.installed`,
 * test runs use `local/.installed.test`. Tests therefore have their own
 * install lifecycle and never affect the prod sentinel.
 *
 * Each method takes a Paths argument explicitly because the sentinel is
 * checked at the very beginning of CommonBootstrap, before Kernel::boot()
 * runs. Static-method-plus-Paths-arg avoids the chicken-and-egg of needing
 * the DI container to find out whether to bother building it.
 */
final class InstallSentinel
{
    public static function isInstalled(Paths $paths): bool
    {
        return file_exists(self::stampFile($paths));
    }

    public static function markInstalled(Paths $paths): void
    {
        $path = self::stampFile($paths);
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
    public static function markUninstalled(Paths $paths): void
    {
        $path = self::stampFile($paths);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function stampFile(Paths $paths): string
    {
        return $paths->local . TestMode::installedStamp();
    }
}
