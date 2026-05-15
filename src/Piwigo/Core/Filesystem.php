<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\Config;
use Piwigo\Html\HtmlService;

/**
 * Small helpers that replace `@`-prefixed file calls with explicit
 * preflight checks or scoped error-handler suppression.
 *
 * The intent — and what every caller previously expressed by writing `@`
 * in front of a filesystem call — is "I know this can fail; I want the
 * boolean result, not a warning". These helpers express that without
 * polluting the global error stream.
 */
final class Filesystem
{
    public const FLAG_NONE          = 0;
    public const FLAG_RECURSIVE     = 1;
    public const FLAG_DIE_ON_ERROR  = 2;
    public const FLAG_PROTECT_INDEX = 4;
    public const FLAG_DEFAULT       = self::FLAG_RECURSIVE | self::FLAG_DIE_ON_ERROR | self::FLAG_PROTECT_INDEX;

    /**
     * Ensure `$dir` exists and is writable, creating it if necessary, with
     * an `index.htm` sentinel to block directory listing. Static — safe to
     * call before Kernel::boot() (used by SessionBootstrap and
     * InstallSentinel during pre-DI bootstrapping).
     */
    public static function mkgetdir(string $dir, int $flags = self::FLAG_DEFAULT): bool
    {
        if (!is_dir($dir)) {
            if (str_starts_with(PHP_OS, 'WIN')) {
                $dir = str_replace('/', DIRECTORY_SEPARATOR, $dir);
            }
            $umask = umask(0);
            set_error_handler(static fn (): bool => true);
            try {
                $mkd = mkdir($dir, Config::chmodValue(), ($flags & self::FLAG_RECURSIVE) ? true : false);
            } finally {
                restore_error_handler();
            }
            umask($umask);
            if ($mkd == false) {
                if (!($flags & self::FLAG_DIE_ON_ERROR)) {
                    return false;
                }
                HtmlService::fatalError("$dir " . Lang::t('no write access'));
            }
            if ($flags & self::FLAG_PROTECT_INDEX) {
                $file = $dir . '/index.htm';
                if (!file_exists($file) && is_writable($dir)) {
                    file_put_contents($file, 'Not allowed!');
                }
            }
        }
        if (!is_writable($dir)) {
            if (!($flags & self::FLAG_DIE_ON_ERROR)) {
                return false;
            }
            HtmlService::fatalError("$dir " . Lang::t('no write access'));
        }
        return true;
    }

    /**
     * `unlink($p)` if `$p` is a regular file. Returns whether it ended up
     * removed.
     */
    public static function tryUnlink(string $path): bool
    {
        return is_file($path) && unlink($path);
    }

    /**
     * `rmdir($p)` if `$p` is a directory. Suppresses the warning emitted
     * when the directory is non-empty (callers handle the false result).
     */
    public static function tryRmdir(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }
        set_error_handler(static fn (): bool => true);
        try {
            return rmdir($path);
        } finally {
            restore_error_handler();
        }
    }

    public static function tryFileMtime(string $path): int|false
    {
        return is_file($path) ? filemtime($path) : false;
    }

    public static function tryFilesize(string $path): int|false
    {
        return is_file($path) ? filesize($path) : false;
    }

    /**
     * `rename($from, $to)` only if `$from` exists. Returns the rename's
     * boolean result, or false when the source is missing.
     */
    public static function tryRename(string $from, string $to): bool
    {
        if (!file_exists($from)) {
            return false;
        }
        set_error_handler(static fn (): bool => true);
        try {
            return rename($from, $to);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * `chmod($p, $m)` only if `$p` exists. The boolean return is
     * intentionally discardable — callers used `@chmod()` to express
     * "best-effort, we don't care if this fails".
     */
    public static function tryChmod(string $path, int $mode): bool
    {
        if (!file_exists($path)) {
            return false;
        }
        set_error_handler(static fn (): bool => true);
        try {
            return chmod($path, $mode);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Open a file for reading or writing. Returns the resource on
     * success, null on failure. Caller should null-check before using.
     */
    public static function tryFopen(string $path, string $mode): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            $fh = fopen($path, $mode);
        } finally {
            restore_error_handler();
        }
        return $fh === false ? null : $fh;
    }
}
