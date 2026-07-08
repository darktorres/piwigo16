<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Returns the list of filenames stored in a zip archive.
 *
 * @return array<int, array{filename: string}>|false
 */
function zip_list_filenames(string $archive): array|false
{
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        return false;
    }

    $list = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) {
            continue;
        }
        $list[] = [
            'filename' => $stat['name'],
        ];
    }
    $zip->close();

    return $list;
}

/**
 * Extracts a zip archive to $destPath, stripping $removePrefix from the
 * start of each entry's stored path (mirroring PclZip's
 * PCLZIP_OPT_REMOVE_PATH behavior). Every entry is always overwritten if
 * already present (mirroring PclZip's PCLZIP_OPT_REPLACE_NEWER, which all
 * real callers of the removed PclZip-based extraction always passed).
 *
 * When $onlyStoredName is given, only the entry whose stored path exactly
 * matches it is extracted (mirroring PclZip's PCLZIP_OPT_BY_NAME, used to
 * retry a single file after making its destination writable).
 *
 * @return array<int, array{filename: string, stored_filename: string, status: string}>|false
 */
function zip_extract(string $archive, string $destPath, string $removePrefix, ?int $chmod = null, ?string $onlyStoredName = null): array|false
{
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        return false;
    }

    // A bare '.' means "the marker file sits at the archive root" (e.g.
    // plugins.class.php's dirname($main_filepath) == '.') — there is no
    // real prefix to strip, matching PclZip's own special-cased handling
    // of PCLZIP_OPT_REMOVE_PATH == '.' (PclZipUtilPathInclusion() resolves
    // '.' to an absolute filesystem path, which never matches a relative
    // in-archive entry name, so stripping silently never happens).
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

        if ($removePrefix !== '' && $storedName === $removePrefix) {
            $result[] = [
                'filename' => $storedName,
                'stored_filename' => $storedName,
                'status' => 'filtered',
            ];
            continue;
        }

        $strippedName = $storedName;
        if ($removePrefix !== '' && str_starts_with($storedName, $removePrefix)) {
            $strippedName = substr($storedName, strlen($removePrefix));
        }

        $filename = $destPath === '' ? $strippedName : $destPath . '/' . $strippedName;
        $isDir = str_ends_with($storedName, '/');

        if ($isDir) {
            @mkdir($filename, 0777, true);
            $result[] = [
                'filename' => $filename,
                'stored_filename' => $storedName,
                'status' => 'ok',
            ];
            continue;
        }

        if (is_dir($filename)) {
            $result[] = [
                'filename' => $filename,
                'stored_filename' => $storedName,
                'status' => 'already_a_directory',
            ];
            continue;
        }

        @mkdir(dirname($filename), 0777, true);

        $source = $zip->getStream($storedName);
        $dest = $source !== false ? @fopen($filename, 'wb') : false;
        if ($source === false || $dest === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            $result[] = [
                'filename' => $filename,
                'stored_filename' => $storedName,
                'status' => 'write_error',
            ];
            continue;
        }

        stream_copy_to_stream($source, $dest);
        fclose($source);
        fclose($dest);
        touch($filename, $stat['mtime']);
        if ($chmod !== null) {
            @chmod($filename, $chmod);
        }

        $result[] = [
            'filename' => $filename,
            'stored_filename' => $storedName,
            'status' => 'ok',
        ];
    }
    $zip->close();

    return $result;
}
