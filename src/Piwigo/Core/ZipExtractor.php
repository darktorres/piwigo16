<?php

declare(strict_types=1);

namespace Piwigo\Core;

use function fclose;
use function is_resource;
use function restore_error_handler;
use function set_error_handler;
use function stream_copy_to_stream;

use ZipArchive;

/**
 * Minimal ZIP extraction helper used by core/plugin/theme/language update flows.
 *
 * Replaces the PclZip vendor library retired in Phase 2c. The returned
 * per-entry status array shape (`filename`, `stored_filename`, `status`)
 * mirrors PclZip's so call-site inspection logic ports unchanged. `filename`
 * carries the on-disk path *relative to `$extractPath`* (i.e. the stored
 * name with `$stripPrefix` removed); `stored_filename` carries the entry's
 * original name as recorded in the archive.
 *
 * `stripPrefix` mirrors PclZip's `PCLZIP_OPT_REMOVE_PATH`: when a stored
 * entry name starts with the prefix, the prefix is removed before writing;
 * otherwise the entry is written under its stored name. Path-traversal is
 * blocked by lexically resolving each target and rejecting any that escapes
 * `$extractPath`.
 */
final class ZipExtractor
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_ALREADY_DIR = 'already_a_directory';
    public const string STATUS_FILTERED = 'filtered';
    public const string STATUS_WRITE_ERROR = 'write_error';
    public const string STATUS_PATH_ERROR = 'path_error';
    public const string STATUS_OPEN_ERROR = 'open_error';

    /**
     * @return list<string> entry names in the archive, in stored order.
     *   Empty list on open failure.
     */
    public static function listNames(string $archivePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            return [];
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name)) {
                $names[] = $name;
            }
        }
        $zip->close();
        return $names;
    }

    /**
     * Extracts archive entries to `$extractPath`. When `$stripPrefix` is
     * non-empty, that prefix is removed from each entry's stored name before
     * writing (mirroring PclZip's `PCLZIP_OPT_REMOVE_PATH`).
     *
     * @param list<string>|null $onlyNames When non-null, only entries whose
     *   stored name appears in the list are extracted; others get a
     *   `STATUS_FILTERED` row.
     * @param int|null $chmod When non-null, applied to each successfully
     *   written file (mirrors PclZip's `PCLZIP_OPT_SET_CHMOD`).
     *
     * @return list<array{filename: string, stored_filename: string, status: string}>
     *   One row per archive entry. Empty list on open failure.
     */
    public static function extract(
        string $archivePath,
        string $extractPath,
        string $stripPrefix = '',
        ?array $onlyNames = null,
        ?int $chmod = null,
    ): array {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            return [];
        }

        $stripPrefix = rtrim($stripPrefix, '/');
        $extractPathResolved = self::lexicallyResolve(rtrim($extractPath, '/'));
        $onlyNamesMap = $onlyNames === null ? null : array_fill_keys($onlyNames, true);

        $results = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $storedName = $zip->getNameIndex($i);
            if (!is_string($storedName)) {
                continue;
            }

            $relative = self::stripPrefix($storedName, $stripPrefix);

            if ($onlyNamesMap !== null && !isset($onlyNamesMap[$storedName])) {
                $results[] = ['filename' => $relative, 'stored_filename' => $storedName, 'status' => self::STATUS_FILTERED];
                continue;
            }

            $targetAbs = self::lexicallyResolve($extractPathResolved . '/' . $relative);
            if (!str_starts_with($targetAbs, $extractPathResolved . '/') && $targetAbs !== $extractPathResolved) {
                $results[] = ['filename' => $relative, 'stored_filename' => $storedName, 'status' => self::STATUS_PATH_ERROR];
                continue;
            }

            if (str_ends_with($storedName, '/')) {
                $status = is_dir($targetAbs) ? self::STATUS_ALREADY_DIR : (mkdir($targetAbs, 0755, true) || is_dir($targetAbs) ? self::STATUS_OK : self::STATUS_WRITE_ERROR);
                $results[] = ['filename' => $relative, 'stored_filename' => $storedName, 'status' => $status];
                continue;
            }

            $parent = dirname($targetAbs);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                $results[] = ['filename' => $relative, 'stored_filename' => $storedName, 'status' => self::STATUS_WRITE_ERROR];
                continue;
            }

            $srcStream = $zip->getStream($storedName);
            if ($srcStream === false) {
                $results[] = ['filename' => $relative, 'stored_filename' => $storedName, 'status' => self::STATUS_OPEN_ERROR];
                continue;
            }
            $destFh = Filesystem::tryFopen($targetAbs, 'wb');
            if (!is_resource($destFh)) {
                fclose($srcStream);
                $results[] = ['filename' => $relative, 'stored_filename' => $storedName, 'status' => self::STATUS_WRITE_ERROR];
                continue;
            }
            set_error_handler(static fn (): bool => true);
            try {
                $copied = stream_copy_to_stream($srcStream, $destFh);
            } finally {
                restore_error_handler();
                fclose($destFh);
                fclose($srcStream);
            }
            if ($copied === false) {
                $results[] = ['filename' => $relative, 'stored_filename' => $storedName, 'status' => self::STATUS_WRITE_ERROR];
                continue;
            }
            if ($chmod !== null) {
                Filesystem::tryChmod($targetAbs, $chmod);
            }
            $results[] = ['filename' => $relative, 'stored_filename' => $storedName, 'status' => self::STATUS_OK];
        }

        $zip->close();
        return $results;
    }

    private static function stripPrefix(string $storedName, string $stripPrefix): string
    {
        if ($stripPrefix === '' || $stripPrefix === '.') {
            return $storedName;
        }
        $prefixWithSlash = $stripPrefix . '/';
        if (str_starts_with($storedName, $prefixWithSlash)) {
            return substr($storedName, strlen($prefixWithSlash));
        }
        if ($storedName === $stripPrefix) {
            return '';
        }
        return $storedName;
    }

    /**
     * Resolve `.` and `..` segments without touching the filesystem so the
     * traversal check works before the target file exists.
     */
    private static function lexicallyResolve(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return ($isAbsolute ? '/' : '') . implode('/', $segments);
    }
}
