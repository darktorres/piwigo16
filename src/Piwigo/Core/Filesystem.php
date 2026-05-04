<?php

declare(strict_types=1);

namespace Piwigo\Core;

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
