<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Piwigo\Admin\Extensions\Projection\ExtractedFileEntry;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilesystemHelper;
use ZipArchive;

/**
 * Safe archive extraction for the extension (plugin/theme/language)
 * install/update flow.
 *
 * Guards against zip-slip / path traversal: an entry's resolved
 * destination is checked for containment within $destPath before any
 * file is written, since a malicious archive entry named e.g.
 * "../../../../var/www/html/admin.php" would otherwise write outside the
 * intended extension directory. [SEC-32] Also enforces an entry-count
 * ceiling (MAX_ENTRIES) and a total uncompressed-size ceiling
 * (MAX_UNCOMPRESSED_BYTES) to guard against zip bombs.
 *
 * Path containment is checked lexically (normalizePath()), not via
 * realpath(): the destination directory doesn't exist yet when extraction
 * starts (that's the whole point -- it's being created), so realpath()
 * would just return false for every entry before anything is written.
 */
final class ZipExtractor
{
    private const int MAX_ENTRIES = 20000;

    private const int MAX_UNCOMPRESSED_BYTES = 500 * 1024 * 1024;

    /**
     * @return list<string>|null null on failure to open the archive
     */
    public function listFilenames(string $archive): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            return null;
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $names[] = $stat['name'];
        }
        $zip->close();

        return $names;
    }

    /**
     * Extracts $archive to $destPath, stripping $removePrefix from the
     * start of each entry's stored path. Every entry is always overwritten
     * if already present. When $onlyStoredName is given, only the entry
     * whose stored path exactly matches it is extracted (used to retry a
     * single file after making its destination writable).
     *
     * Returns null on: failure to open the archive, more than MAX_ENTRIES
     * entries, more than MAX_UNCOMPRESSED_BYTES total uncompressed size, any
     * entry whose stored name is absolute or contains a null byte, or any
     * entry whose resolved destination would fall outside $destPath.
     *
     * @return list<ExtractedFileEntry>|null
     */
    public function extract(
        string $archive,
        string $destPath,
        string $removePrefix,
        CurrentConfig $currentConfig,
        ?int $chmod = null,
        ?string $onlyStoredName = null,
    ): ?array {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            return null;
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();

            return null;
        }

        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $totalUncompressed += $stat['size'];
            if ($totalUncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                $zip->close();

                return null;
            }
        }

        // A bare '.' means "the marker file sits at the archive root" --
        // there is no real prefix to strip.
        if ($removePrefix === '.') {
            $removePrefix = '';
        }
        if ($removePrefix !== '' && ! str_ends_with($removePrefix, '/')) {
            $removePrefix .= '/';
        }

        if (str_starts_with($destPath, './')) {
            $destPath = substr($destPath, 2);
        }
        $destPath = rtrim($destPath, '/');
        $normalizedDestPath = self::normalizePath($destPath);

        $result = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $storedName = $stat['name'];

            if ($onlyStoredName !== null && $storedName !== $onlyStoredName) {
                continue;
            }

            // Absolute paths and null bytes are never legitimate archive
            // entry names -- reject the whole archive rather than silently
            // skip a single crafted entry.
            if (str_starts_with($storedName, '/') || str_contains($storedName, "\0")) {
                $zip->close();

                return null;
            }

            if ($removePrefix !== '' && $storedName === $removePrefix) {
                $result[] = new ExtractedFileEntry($storedName, $storedName, 'filtered');
                continue;
            }

            $strippedName = $storedName;
            if ($removePrefix !== '' && str_starts_with($storedName, $removePrefix)) {
                $strippedName = substr($storedName, strlen($removePrefix));
            }

            $filename = $destPath === '' ? $strippedName : $destPath . '/' . $strippedName;

            // Zip-slip guard: the lexically-normalized destination must
            // stay inside $destPath. Checked before any filesystem write.
            $normalizedFilename = self::normalizePath($filename);
            if ($normalizedFilename !== $normalizedDestPath
                && ! str_starts_with($normalizedFilename, $normalizedDestPath . '/')) {
                $zip->close();

                return null;
            }

            $isDir = str_ends_with($storedName, '/');

            if ($isDir) {
                FilesystemHelper::mkgetdir($filename, $currentConfig, FilesystemHelper::MKGETDIR_RECURSIVE);
                $result[] = new ExtractedFileEntry($filename, $storedName, 'ok');
                continue;
            }

            if (is_dir($filename)) {
                $result[] = new ExtractedFileEntry($filename, $storedName, 'already_a_directory');
                continue;
            }

            FilesystemHelper::mkgetdir(dirname($filename), $currentConfig, FilesystemHelper::MKGETDIR_RECURSIVE);

            $source = $zip->getStream($storedName);
            $dest = $source !== false ? @fopen($filename, 'wb') : false;
            if ($source === false || $dest === false) {
                if (is_resource($source)) {
                    fclose($source);
                }
                $result[] = new ExtractedFileEntry($filename, $storedName, 'write_error');
                continue;
            }

            stream_copy_to_stream($source, $dest);
            fclose($source);
            fclose($dest);
            touch($filename, $stat['mtime']);
            if ($chmod !== null) {
                @chmod($filename, $chmod);
            }

            $result[] = new ExtractedFileEntry($filename, $storedName, 'ok');
        }
        $zip->close();

        return $result;
    }

    /**
     * Lexically resolves "." and ".." segments in a "/"-separated path,
     * without touching the filesystem (the path may not exist yet). A
     * leading ".." that would escape the root collapses to the root itself
     * -- callers compare the result against a known-safe prefix, so an
     * over-collapsed (rather than under-collapsed) path is the safe
     * direction to err in.
     */
    private static function normalizePath(string $path): string
    {
        $segments = explode('/', $path);
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolved);
                continue;
            }
            $resolved[] = $segment;
        }

        return '/' . implode('/', $resolved);
    }
}
