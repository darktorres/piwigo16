<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin;

use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\functions_metadata_admin;
use Piwigo\inc\functions;

// provides data for site synchronization from the local file system

final class LocalSiteReader
{
    /** @var array<string, array<string, string>> scandir cache: path => [filename_wo_ext => ext] */
    private array $representative_cache = [];

    /** @var array<string, array<string, array<string, int>>> scandir cache: path => [filename_wo_ext => [ext => size_kb]] */
    private array $format_cache = [];

    public function __construct(
        public string $site_url
    ) {
        global $conf;

        if (! isset($conf->flip_file_ext)) {
            $conf->flip_file_ext = array_flip($conf->file_ext);
        }

        if (! isset($conf->flip_picture_ext)) {
            $conf->flip_picture_ext = array_flip($conf->picture_ext);
        }
    }

    /**
     * Is this local site ok ?
     *
     * @return true on success, false otherwise
     */
    public function open(): bool
    {
        global $errors;

        if (! is_dir($this->site_url)) {
            $errors[] = [
                'path' => $this->site_url,
                'type' => 'PWG-ERROR-NO-FS',
            ];

            return false;
        }

        return true;
    }

    // retrieve file system sub-directories fulldirs
    /**
     * @return array<string>
     */
    public function get_full_directories(
        string $basedir,
        ?\Closure $on_dir = null
    ): array {
        return functions_admin::get_fs_directories($basedir, true, $on_dir);
    }

    /**
     * Returns an array with all file system files according to $conf->file_ext
     * and $conf->picture_ext
     * @param string $path recurse in this directory
     * @return array like "pic.jpg"=>array('representative_ext'=>'jpg' ... )
     */
    public function get_elements(
        string $path,
        int $depth = 0,
        ?\Closure $on_dir = null
    ): array {
        global $conf, $logger;

        $subdirs = [];
        $fs = [];
        $profiling = $conf->sync_profiling;

        if ($profiling && $depth === 0) {
            $GLOBALS['sync_scan_prof'] = [
                'dirs_scanned' => 0,
                'files_found' => 0,
                'files_matched' => 0,
                'representative_lookups' => 0,
                'representative_time' => 0,
                'format_lookups' => 0,
                'format_time' => 0,
                'readdir_time' => 0,
            ];
            $GLOBALS['sync_scan_start'] = microtime(true);
        }

        if (is_dir($path) &&
            $contents = opendir($path)
        ) {
            if ($profiling) {
                $GLOBALS['sync_scan_prof']['dirs_scanned']++;
                $t_readdir = microtime(true);
            }

            while (($node = readdir($contents)) !== false) {
                if ($node === '.' ||
                    $node === '..'
                ) {
                    continue;
                }

                if (is_file($path . '/' . $node)) {
                    if ($profiling) {
                        $GLOBALS['sync_scan_prof']['files_found']++;
                    }

                    $extension = strtolower(functions::get_extension($node));
                    $filename_wo_ext = functions::get_filename_wo_extension($node);

                    if (isset($conf->flip_file_ext[$extension])) {
                        if ($profiling) {
                            $GLOBALS['sync_scan_prof']['files_matched']++;
                        }

                        $representative_ext = null;

                        if (! isset($conf->flip_picture_ext[$extension])) {
                            if ($profiling) {
                                $GLOBALS['sync_scan_prof']['representative_lookups']++;
                                $t_rep = microtime(true);
                            }

                            $representative_ext = $this->get_representative_ext($path, $filename_wo_ext);

                            if ($profiling) {
                                $GLOBALS['sync_scan_prof']['representative_time'] += microtime(true) - $t_rep;
                            }
                        }

                        $fs[$path . '/' . $node] = [
                            'representative_ext' => $representative_ext,
                            'fs_filesize' => floor(filesize($path . '/' . $node) / 1024),
                        ];

                        if ($conf->enable_formats) {
                            if ($profiling) {
                                $GLOBALS['sync_scan_prof']['format_lookups']++;
                                $t_fmt = microtime(true);
                            }

                            $fs[$path . '/' . $node]['formats'] = $this->get_formats($path, $filename_wo_ext);

                            if ($profiling) {
                                $GLOBALS['sync_scan_prof']['format_time'] += microtime(true) - $t_fmt;
                            }
                        }
                    }
                } elseif (is_dir($path . '/' . $node) &&
                          $node !== 'pwg_high' &&
                          $node !== 'pwg_representative' &&
                          $node !== 'pwg_format' &&
                          $node !== 'thumbnail'
                ) {
                    $subdirs[] = $node;
                }
            }

            if ($profiling) {
                $GLOBALS['sync_scan_prof']['readdir_time'] += microtime(true) - $t_readdir;
            }

            closedir($contents);

            if ($on_dir !== null) {
                $on_dir($path, count($fs));
            }

            foreach ($subdirs as $subdir) {
                $tmp_fs = $this->get_elements($path . '/' . $subdir, $depth + 1, $on_dir);
                $fs += $tmp_fs;
            }
        }

        if ($depth === 0) {
            ksort($fs);

            if ($profiling) {
                $total_elapsed = microtime(true) - $GLOBALS['sync_scan_start'];
                $p = $GLOBALS['sync_scan_prof'];
                $logger->info('[sync][scan] filesystem scan summary', [
                    'total_elapsed_s' => round($total_elapsed, 4),
                    'dirs_scanned' => $p['dirs_scanned'],
                    'files_found' => $p['files_found'],
                    'files_matched' => $p['files_matched'],
                    'readdir_time_s' => round($p['readdir_time'], 4),
                ]);
                $logger->info('[sync][scan] representative ext lookups', [
                    'lookups' => $p['representative_lookups'],
                    'total_s' => round($p['representative_time'], 4),
                    'avg_s' => $p['representative_lookups'] > 0
                        ? round($p['representative_time'] / $p['representative_lookups'], 5) : 0,
                ]);
                $logger->info('[sync][scan] format lookups', [
                    'lookups' => $p['format_lookups'],
                    'total_s' => round($p['format_time'], 4),
                    'avg_s' => $p['format_lookups'] > 0
                        ? round($p['format_time'] / $p['format_lookups'], 5) : 0,
                ]);
                unset($GLOBALS['sync_scan_prof'], $GLOBALS['sync_scan_start']);
            }
        }

        return $fs;
    }

    // returns the name of the attributes that are supported for
    // files update/synchronization
    public function get_update_attributes(): array
    {
        return ['representative_ext'];
    }

    public function get_element_update_attributes(
        string $file
    ): array {
        global $conf;
        $data = [];

        $filename = basename($file);
        $extension = functions::get_extension($filename);

        $representative_ext = null;

        if (! isset($conf->flip_picture_ext[$extension])) {
            $dirname = dirname($file);
            $filename_wo_ext = functions::get_filename_wo_extension($filename);
            $representative_ext = $this->get_representative_ext($dirname, $filename_wo_ext);
        }

        $data['representative_ext'] = $representative_ext;
        return $data;
    }

    // returns the name of the attributes that are supported for
    // metadata update/synchronization according to configuration
    public function get_metadata_attributes(): array
    {
        return functions_metadata_admin::get_sync_metadata_attributes();
    }

    // returns a hash of attributes (metadata+filesize+width,...) for file
    // returns null if file is unchanged (filesize matches DB)
    public function get_element_metadata(
        array $infos
    ): array|bool|null {
        return functions_metadata_admin::get_sync_metadata($infos);
    }

    //-------------------------------------------------- private functions --------

    private function load_representative_cache(string $path): void
    {
        global $conf;
        $rep_dir = $path . '/pwg_representative';

        if (! is_dir($rep_dir)) {
            $this->representative_cache[$path] = [];
            return;
        }

        $cache = [];
        $entries = scandir($rep_dir);

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $ext = strtolower(functions::get_extension($entry));

                if (isset($conf->flip_picture_ext[$ext])) {
                    $name_wo_ext = functions::get_filename_wo_extension($entry);

                    if (! isset($cache[$name_wo_ext])) {
                        $cache[$name_wo_ext] = $ext;
                    }
                }
            }
        }

        $this->representative_cache[$path] = $cache;
    }

    private function load_format_cache(string $path): void
    {
        global $conf;
        $fmt_dir = $path . '/pwg_format';

        if (! is_dir($fmt_dir)) {
            $this->format_cache[$path] = [];
            return;
        }

        $cache = [];
        $flip_format_ext = array_flip($conf->format_ext);
        $entries = scandir($fmt_dir);

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $ext = strtolower(functions::get_extension($entry));

                if (isset($flip_format_ext[$ext])) {
                    $name_wo_ext = functions::get_filename_wo_extension($entry);

                    if (! isset($cache[$name_wo_ext])) {
                        $cache[$name_wo_ext] = [];
                    }

                    $cache[$name_wo_ext][$ext] = floor(filesize($fmt_dir . '/' . $entry) / 1024);
                }
            }
        }

        $this->format_cache[$path] = $cache;
    }

    public function get_representative_ext(
        string $path,
        string $filename_wo_ext
    ): ?string {
        if (! isset($this->representative_cache[$path])) {
            $this->load_representative_cache($path);
        }

        return $this->representative_cache[$path][$filename_wo_ext] ?? null;
    }

    public function get_formats(
        string $path,
        string $filename_wo_ext
    ): array {
        if (! isset($this->format_cache[$path])) {
            $this->load_format_cache($path);
        }

        return $this->format_cache[$path][$filename_wo_ext] ?? [];
    }
}
