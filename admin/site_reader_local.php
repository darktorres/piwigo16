<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// provides data for site synchronization from the local file system
class LocalSiteReader
{
    public function __construct(
        public string $site_url
    ) {
        /** @var array<string, mixed> $conf */
        global $conf;
        if (! isset($conf['flip_file_ext'])) {
            $file_ext = $conf['file_ext'];
            $file_ext = is_array($file_ext) ? array_filter($file_ext, 'is_string') : [];
            $conf['flip_file_ext'] = array_flip($file_ext);
        }
        if (! isset($conf['flip_picture_ext'])) {
            $picture_ext = $conf['picture_ext'];
            $picture_ext = is_array($picture_ext) ? array_filter($picture_ext, 'is_string') : [];
            $conf['flip_picture_ext'] = array_flip($picture_ext);
        }
    }

    /**
     * Is this local site ok ?
     *
     * @return bool true on success, false otherwise
     */
    public function open(): bool
    {
        /** @var list<array<string, string>> $errors */
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
     * @return mixed[]
     */
    public function get_full_directories(string $basedir): array
    {
        $fs_fulldirs = get_fs_directories($basedir);
        return $fs_fulldirs;
    }

    /**
     * Returns an array with all file system files according to $conf['file_ext']
     * and $conf['picture_ext']
     * @param string $path recurse in this directory
     * @return array<string, array<string, mixed>> like "pic.jpg"=>array('representative_ext'=>'jpg' ... )
     */
    public function get_elements($path): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $flip_file_ext = $conf['flip_file_ext'];
        $flip_file_ext = is_array($flip_file_ext) ? $flip_file_ext : [];
        $flip_picture_ext = $conf['flip_picture_ext'];
        $flip_picture_ext = is_array($flip_picture_ext) ? $flip_picture_ext : [];

        $subdirs = [];
        $fs = [];
        if (is_dir($path) && $contents = opendir($path)) {
            while (($node = readdir($contents)) !== false) {
                if ($node == '.' or $node == '..') {
                    continue;
                }

                if (is_file($path . '/' . $node)) {
                    $extension = strtolower(get_extension($node));
                    $filename_wo_ext = get_filename_wo_extension($node);

                    if (isset($flip_file_ext[$extension])) {
                        $representative_ext = null;
                        if (! isset($flip_picture_ext[$extension])) {
                            $representative_ext = $this->get_representative_ext($path, $filename_wo_ext);
                        }

                        $fs[$path . '/' . $node] = [
                            'representative_ext' => $representative_ext,
                        ];

                        if ($conf['enable_formats']) {
                            $fs[$path . '/' . $node]['formats'] = $this->get_formats($path, $filename_wo_ext);
                        }
                    }
                } elseif (is_dir($path . '/' . $node)
                         and $node != 'pwg_high'
                         and $node != 'pwg_representative'
                         and $node != 'pwg_format'
                         and $node != 'thumbnail') {
                    $subdirs[] = $node;
                }
            } // end while readdir
            closedir($contents);

            foreach ($subdirs as $subdir) {
                $tmp_fs = $this->get_elements($path . '/' . $subdir);
                $fs = array_merge($fs, $tmp_fs);
            }
            ksort($fs);
        } // end if is_dir
        return $fs;
    }

    // returns the name of the attributes that are supported for
    // files update/synchronization
    /**
     * @return string[]
     */
    public function get_update_attributes(): array
    {
        return ['representative_ext'];
    }

    /**
     * @return array{representative_ext: ?string}
     */
    public function get_element_update_attributes(string $file): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;
        $data = [];

        $filename = basename((string) $file);
        $extension = get_extension($filename);

        $flip_picture_ext = $conf['flip_picture_ext'];
        $flip_picture_ext = is_array($flip_picture_ext) ? $flip_picture_ext : [];

        $representative_ext = null;
        if (! isset($flip_picture_ext[$extension])) {
            $dirname = dirname((string) $file);
            $filename_wo_ext = get_filename_wo_extension($filename);
            $representative_ext = $this->get_representative_ext($dirname, $filename_wo_ext);
        }

        $data['representative_ext'] = $representative_ext;
        return $data;
    }

    // returns the name of the attributes that are supported for
    // metadata update/synchronization according to configuration
    /**
     * @return string[]
     */
    public function get_metadata_attributes(): array
    {
        return get_sync_metadata_attributes();
    }

    // returns a hash of attributes (metadata+filesize+width,...) for file
    /**
     * @param array<string, mixed> $infos
     * @return array<string, mixed>|false
     */
    public function get_element_metadata($infos): array|false
    {
        return get_sync_metadata($infos);
    }

    // -------------------------------------------------- private functions --------
    public function get_representative_ext(string $path, string $filename_wo_ext): ?string
    {
        /** @var array<string, mixed> $conf */
        global $conf;
        $base_test = $path . '/pwg_representative/' . $filename_wo_ext . '.';
        $picture_ext = $conf['picture_ext'];
        $picture_ext = is_array($picture_ext) ? array_filter($picture_ext, 'is_string') : [];
        foreach ($picture_ext as $ext) {
            $test = $base_test . $ext;
            if (is_file($test)) {
                return $ext;
            }
        }
        return null;
    }

    /**
     * @return float[]
     */
    public function get_formats(string $path, string $filename_wo_ext): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $formats = [];

        $base_test = $path . '/pwg_format/' . $filename_wo_ext . '.';

        $format_ext = $conf['format_ext'];
        $format_ext = is_array($format_ext) ? array_filter($format_ext, 'is_string') : [];

        foreach ($format_ext as $ext) {
            $test = $base_test . $ext;

            if (is_file($test)) {
                $formats[$ext] = floor(filesize($test) / 1024);
            }
        }

        return $formats;
    }
}
