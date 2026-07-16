<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8d: relocated from include/functions.inc.php, unchanged logic.
 * The MKGETDIR_ bitmask flags stay global constants (not class
 * constants) -- same "widely-used bootstrap-level constants" precedent as
 * PHPWG_PLUGINS_PATH / QST_ / CAL_VIEW_ constants in that same file (10
 * real call sites use them with bitwise operators; converting to class
 * constants would be pure churn for zero benefit).
 */
final class FilesystemHelper
{
    /**
     * creates directory if not exists and ensures that directory is writable
     *
     * @param int $flags combination of MKGETDIR_xxx
     */
    public static function mkgetdir(string $dir, int $flags = MKGETDIR_DEFAULT): bool
    {
        if (! is_dir($dir)) {
            /** @var array<string, mixed> $conf */
            global $conf;
            if (str_starts_with(PHP_OS, 'WIN')) {
                $dir = str_replace('/', DIRECTORY_SEPARATOR, $dir);
            }
            $umask = umask(0);
            $chmod_value = $conf['chmod_value'];
            $chmod_value = is_numeric($chmod_value) ? (int) $chmod_value : 0755;
            $mkd = @mkdir($dir, $chmod_value, ((bool) ($flags & MKGETDIR_RECURSIVE)) ? true : false);
            umask($umask);
            if ($mkd === false) {
                ! (bool) ($flags & MKGETDIR_DIE_ON_ERROR) or fatal_error("{$dir} " . l10n('no write access'));
                return false;
            }
            if ((bool) ($flags & MKGETDIR_PROTECT_HTACCESS)) {
                $file = $dir . '/.htaccess';
                file_exists($file) or (bool) @file_put_contents($file, 'deny from all');
            }
            if ((bool) ($flags & MKGETDIR_PROTECT_INDEX)) {
                $file = $dir . '/index.htm';
                file_exists($file) or (bool) @file_put_contents($file, 'Not allowed!');
            }
        }
        if (! is_writable($dir)) {
            ! (bool) ($flags & MKGETDIR_DIE_ON_ERROR) or fatal_error("{$dir} " . l10n('no write access'));
            return false;
        }
        return true;
    }

    /**
     * makes sure a index.htm protects the directory from browser file listing
     */
    public static function secureDirectory(string $dir): void
    {
        $file = $dir . '/index.htm';
        if (! file_exists($file)) {
            @file_put_contents($file, 'Not allowed!');
        }
    }
}
