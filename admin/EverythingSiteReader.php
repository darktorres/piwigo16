<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin;

use Piwigo\inc\functions;

/**
 * Site reader that uses voidtools Everything SDK v3 for file/directory
 * discovery instead of recursive opendir/readdir traversal.
 *
 * Handles symlinks by detecting them via a quick directory-only scan,
 * then issuing separate Everything queries for each resolved target and
 * remapping results back to the symlink paths.
 *
 * Delegates representative, format, metadata, and update-attribute
 * lookups to a LocalSiteReader instance (composition over inheritance).
 */
final class EverythingSiteReader
{
    private LocalSiteReader $local;

    private EverythingSDK $sdk;

    public string $site_url;

    /** Running count of elements yielded in the current get_elements() traversal. */
    private int $elementsYielded = 0;

    public function __construct(string $site_url, EverythingSDK $sdk)
    {
        $this->site_url = $site_url;
        $this->local = new LocalSiteReader($site_url);
        $this->sdk = $sdk;
    }

    public function open(): bool
    {
        return $this->local->open();
    }

    // ------------------------------------------------------------------
    //  Directory discovery  (replaces recursive get_fs_directories)
    // ------------------------------------------------------------------

    /**
     * @return array<string>
     */
    public function get_full_directories(
        string $basedir,
        ?\Closure $on_dir = null
    ): array {
        global $conf, $logger;

        $absBasedir = realpath($basedir);

        if ($absBasedir === false) {
            return $this->local->get_full_directories($basedir, $on_dir);
        }

        $basedirClean = rtrim($basedir, '/');

        // Exclude set: Piwigo special dirs + user-configured exclusions.
        $excludeFolders = array_flip(array_merge(
            $conf->sync_exclude_folders,
            ['.', '..', '.svn', 'thumbnail', 'pwg_high', 'pwg_representative', 'pwg_format']
        ));

        // Build query targets: the basedir itself + any symlink targets.
        $queryTargets = $this->buildQueryTargets($basedirClean, $absBasedir, $excludeFolders);
        $logger->info('[sync][ev] get_full_directories targets', [
            'count' => count($queryTargets),
            'targets' => array_column($queryTargets, 'piwigoPrefix'),
        ]);

        $dirs = [];

        foreach ($queryTargets as $target) {
            $query = $this->buildPathQuery($target['absPath']) . ' folder:';
            $logger->info('[sync][ev] querying dirs', ['target' => $target['piwigoPrefix'], 'query' => $query]);
            $paths = $this->sdk->queryPaths($query);
            $logger->info('[sync][ev] dirs result', ['target' => $target['piwigoPrefix'], 'raw_count' => count($paths)]);

            $absPrefixFwd = str_replace('\\', '/', $target['absPath']);
            $absPrefixLen = strlen($absPrefixFwd);

            foreach ($paths as $rawPath) {
                $pathFwd = str_replace('\\', '/', $rawPath);

                if (strncasecmp($pathFwd, $absPrefixFwd, $absPrefixLen) !== 0) {
                    continue;
                }

                $relative = ltrim(substr($pathFwd, $absPrefixLen), '/');

                if ($relative === '') {
                    continue;
                }

                if ($this->hasExcludedComponent($relative, $excludeFolders)) {
                    continue;
                }

                $piwigoPath = $target['piwigoPrefix'] . '/' . $relative;
                $dirs[] = $piwigoPath;

                if ($on_dir !== null) {
                    $on_dir($piwigoPath);
                }
            }

            // The symlink directory itself is also a valid directory.
            if ($target['piwigoPrefix'] !== $basedirClean) {
                $dirs[] = $target['piwigoPrefix'];

                if ($on_dir !== null) {
                    $on_dir($target['piwigoPrefix']);
                }
            }
        }

        return $dirs;
    }

    // ------------------------------------------------------------------
    //  File discovery  (replaces recursive get_elements)
    // ------------------------------------------------------------------

    /**
     * Yields all file system files matching $conf->file_ext / $conf->picture_ext.
     *
     * Converted from array-building to a Generator so the caller can process
     * each entry immediately without materialising the full list in memory.
     * The Everything SDK query still loads all results at once internally, but
     * the $fs array that was previously built on top of those results is eliminated.
     *
     * @return \Generator<string, array{representative_ext: ?string, fs_filesize: int, formats?: array}>
     */
    public function get_elements(
        string $path,
        int $depth = 0,
        ?\Closure $on_dir = null
    ): \Generator {
        global $conf, $logger;

        $absBasedir = realpath($path);

        if ($absBasedir === false) {
            yield from $this->local->get_elements($path, $depth, $on_dir);
            return;
        }

        $this->elementsYielded = 0;
        $profiling = $conf->sync_profiling;

        if ($profiling) {
            $tStart = microtime(true);
        }

        $basedirClean = rtrim($path, '/');

        // Directories excluded during file scanning (matches LocalSiteReader).
        $excludeDirs = array_flip([
            'pwg_high', 'pwg_representative', 'pwg_format', 'thumbnail',
        ]);

        // Build extension filter from Piwigo config.
        $extList = implode(';', $conf->file_ext);

        // Build query targets: the basedir itself + any symlink targets.
        // For file scanning, use the file-scan exclude list for symlink discovery.
        $queryTargets = $this->buildQueryTargets($basedirClean, $absBasedir, $excludeDirs);
        $logger->info('[sync][ev] get_elements targets', [
            'count' => count($queryTargets),
            'targets' => array_column($queryTargets, 'piwigoPrefix'),
        ]);

        $dirFileCounts = [];

        foreach ($queryTargets as $target) {
            $query = $this->buildPathQuery($target['absPath']) . ' ext:' . $extList;
            $logger->info('[sync][ev] querying files', ['target' => $target['piwigoPrefix'], 'query' => $query]);
            $results = $this->sdk->queryPathsWithSize($query);
            $logger->info('[sync][ev] files result', ['target' => $target['piwigoPrefix'], 'raw_count' => count($results)]);

            $absPrefixFwd = str_replace('\\', '/', $target['absPath']);
            $absPrefixLen = strlen($absPrefixFwd);

            foreach ($results as $result) {
                $pathFwd = str_replace('\\', '/', $result['path']);

                if (strncasecmp($pathFwd, $absPrefixFwd, $absPrefixLen) !== 0) {
                    continue;
                }

                $relative = ltrim(substr($pathFwd, $absPrefixLen), '/');

                if ($relative === '') {
                    continue;
                }

                $lastSlash = strrpos($relative, '/');

                if ($lastSlash !== false) {
                    $dirPart = substr($relative, 0, $lastSlash);

                    if ($this->hasExcludedComponent($dirPart, $excludeDirs)) {
                        continue;
                    }
                }

                $filename = $lastSlash !== false
                    ? substr($relative, $lastSlash + 1)
                    : $relative;

                $extension = strtolower(functions::get_extension($filename));
                $filenameWoExt = functions::get_filename_wo_extension($filename);

                if (! isset($conf->flip_file_ext[$extension])) {
                    continue;
                }

                $piwigoPath = $target['piwigoPrefix'] . '/' . $relative;
                $dirPath = $lastSlash !== false
                    ? $target['piwigoPrefix'] . '/' . substr($relative, 0, $lastSlash)
                    : $target['piwigoPrefix'];

                $representativeExt = null;

                if (! isset($conf->flip_picture_ext[$extension])) {
                    $representativeExt = $this->local->get_representative_ext(
                        $dirPath,
                        $filenameWoExt
                    );
                }

                $entry = [
                    'representative_ext' => $representativeExt,
                    'fs_filesize' => (int) floor($result['size_bytes'] / 1024),
                ];

                if ($conf->enable_formats) {
                    $entry['formats'] = $this->local->get_formats($dirPath, $filenameWoExt);
                }

                $this->elementsYielded++;
                yield $piwigoPath => $entry;

                if ($on_dir !== null) {
                    $dirFileCounts[$dirPath] = ($dirFileCounts[$dirPath] ?? 0) + 1;

                    // Fire on first file in a new directory, then every 500 files.
                    if ($dirFileCounts[$dirPath] === 1
                        || $dirFileCounts[$dirPath] % 500 === 0
                    ) {
                        $on_dir($dirPath, $this->elementsYielded);
                    }
                }
            }
        }

        if ($profiling) {
            $elapsed = microtime(true) - $tStart;
            $logger->info('[sync][scan] Everything SDK scan summary', [
                'total_elapsed_s' => round($elapsed, 4),
                'files_matched' => $this->elementsYielded,
                'source' => 'Everything SDK v3',
            ]);
        }
    }

    /**
     * Returns null — an accurate upfront count requires path-level filtering
     * that cannot be expressed cheaply via the Everything SDK query alone.
     * The caller will instead use running counters from the get_elements() loop.
     */
    public function count_elements(string $path): ?int
    {
        return null;
    }

    // ------------------------------------------------------------------
    //  Delegated methods (identical to LocalSiteReader)
    // ------------------------------------------------------------------

    public function get_update_attributes(): array
    {
        return $this->local->get_update_attributes();
    }

    public function get_element_update_attributes(string $file): array
    {
        return $this->local->get_element_update_attributes($file);
    }

    public function get_metadata_attributes(): array
    {
        return $this->local->get_metadata_attributes();
    }

    public function get_element_metadata(array $infos): array|bool|null
    {
        return $this->local->get_element_metadata($infos);
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /**
     * Build the list of Everything query targets.
     *
     * The basedir itself is always a target. Additionally, any symlink
     * directories found under it are resolved and added as separate
     * targets so Everything can find files at their physical location.
     *
     * Each target is an array with:
     *   - absPath:      absolute physical path (for the Everything query)
     *   - piwigoPrefix: the Piwigo-format path prefix (for result remapping)
     *
     * @param array<string, int> $excludeDirs  directories to skip
     * @return list<array{absPath: string, piwigoPrefix: string}>
     */
    /**
     * Build query targets: the basedir itself plus any symlink directories
     * that are immediate children of basedir (one level only).
     *
     * @param array<string, int> $excludeDirs  directories to skip
     * @return list<array{absPath: string, piwigoPrefix: string}>
     */
    private function buildQueryTargets(
        string $basedirClean,
        string $absBasedir,
        array $excludeDirs
    ): array {
        $targets = [
            ['absPath' => $absBasedir, 'piwigoPrefix' => $basedirClean],
        ];

        $baseFwd = str_replace('\\', '/', $absBasedir);

        $contents = opendir($basedirClean);

        if ($contents === false) {
            return $targets;
        }

        while (($node = readdir($contents)) !== false) {
            if ($node === '.' || $node === '..' || isset($excludeDirs[$node])) {
                continue;
            }

            $fullPath = $basedirClean . '/' . $node;

            if (is_link($fullPath) && is_dir($fullPath)) {
                $resolved = realpath($fullPath);

                if ($resolved === false) {
                    continue;
                }

                // Skip if the target is already under the physical basedir.
                $resolvedFwd = str_replace('\\', '/', $resolved);

                if (strncasecmp($resolvedFwd, $baseFwd . '/', strlen($baseFwd) + 1) === 0) {
                    continue;
                }

                $targets[] = ['absPath' => $resolved, 'piwigoPrefix' => $fullPath];
            }
        }

        closedir($contents);

        return $targets;
    }

    private function buildPathQuery(string $absPath): string
    {
        $winPath = str_replace('/', '\\', $absPath);

        if (! str_ends_with($winPath, '\\')) {
            $winPath .= '\\';
        }

        return '"' . $winPath . '"';
    }

    /**
     * @param array<string, int> $excludeSet  array_flip'd exclusion list
     */
    private function hasExcludedComponent(string $relativePath, array $excludeSet): bool
    {
        foreach (explode('/', $relativePath) as $component) {
            if (isset($excludeSet[$component])) {
                return true;
            }
        }

        return false;
    }
}
