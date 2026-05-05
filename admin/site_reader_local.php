<?php

declare(strict_types=1);

use Piwigo\Config\Config;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// provides data for site synchronization from the local file system
class LocalSiteReader
{
    public function __construct(public string $site_url)
    {
        if (!Config::has('flip_file_ext')) {
            Config::override('flip_file_ext', array_flip(Config::fileExtensions()));
        }
        if (!Config::has('flip_picture_ext')) {
            Config::override('flip_picture_ext', array_flip(Config::pictureExtensions()));
        }
    }

    /**
     * Is this local site ok ?
     */
    public function open(): bool
    {
        if (!is_dir($this->site_url)) {
            if (!isset($GLOBALS['errors']) || !is_array($GLOBALS['errors'])) {
                $GLOBALS['errors'] = [];
            }
            $GLOBALS['errors'][] = [
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
    /** @return string[] */
    public function get_full_directories(string $basedir): array
    {
        $fs_fulldirs = get_fs_directories($basedir);
        return $fs_fulldirs;
    }

    /**
     * Returns an array with all file system files according to \Piwigo\Config\Config::fileExtensions()
     * and \Piwigo\Config\Config::pictureExtensions()
     * @param string $path recurse in this directory
     * @return array like "pic.jpg"=>array('representative_ext'=>'jpg' ... )
     */
    /** @return array<mixed> */
    public function get_elements(string $path): array
    {
        $subdirs = [];
        $fs = [];
        if (is_dir($path) && $contents = opendir($path)) {
            while (($node = readdir($contents)) !== false) {
                if ($node == '.' or $node == '..') {
                    continue;
                }

                if (is_file($path.'/'.$node)) {
                    $extension = strtolower(get_extension($node));
                    $filename_wo_ext = get_filename_wo_extension($node);

                    if (isset(Config::flipFileExt()[$extension])) {
                        $representative_ext = null;
                        if (! isset(Config::flipPictureExt()[$extension])) {
                            $representative_ext = $this->get_representative_ext($path, $filename_wo_ext);
                        }

                        $fs[ $path.'/'.$node ] = ['representative_ext' => $representative_ext];

                        if (Config::isFormatsEnabled()) {
                            $fs[ $path.'/'.$node ]['formats'] = $this->get_formats($path, $filename_wo_ext);
                        }
                    }
                } elseif (is_dir($path.'/'.$node)
                         and $node != 'pwg_high'
                         and $node != 'pwg_representative'
                         and $node != 'pwg_format'
                         and $node != 'thumbnail') {
                    $subdirs[] = $node;
                }
            } //end while readdir
            closedir($contents);

            foreach ($subdirs as $subdir) {
                $tmp_fs = $this->get_elements($path.'/'.$subdir);
                $fs = array_merge($fs, $tmp_fs);
            }
            ksort($fs);
        } //end if is_dir
        return $fs;
    }

    // returns the name of the attributes that are supported for
    // files update/synchronization
    /** @return array<mixed> */
    public function get_update_attributes(): array
    {
        return ['representative_ext'];
    }

    /**
 * @param array<mixed>|string $file
 * @return array<mixed>
 */
    public function get_element_update_attributes(mixed $file): array
    {
        $data = [];
        if (!is_string($file)) {
            return $data;
        }

        $filename = basename($file);
        $extension = get_extension($filename);

        $representative_ext = null;
        if (! isset(Config::flipPictureExt()[$extension])) {
            $dirname = dirname($file);
            $filename_wo_ext = get_filename_wo_extension($filename);
            $representative_ext = $this->get_representative_ext($dirname, $filename_wo_ext);
        }

        $data['representative_ext'] = $representative_ext;
        return $data;
    }

    // returns the name of the attributes that are supported for
    // metadata update/synchronization according to configuration
    /** @return array<mixed> */
    public function get_metadata_attributes(): array
    {
        return get_sync_metadata_attributes();
    }

    // returns a hash of attributes (metadata+filesize+width,...) for file
    /**
 * @param array<mixed> $infos
 * @return array<mixed>|false
 */
    public function get_element_metadata(array $infos): array|false
    {
        return get_sync_metadata($infos);
    }


    //-------------------------------------------------- private functions --------
    public function get_representative_ext(string $path, string $filename_wo_ext): ?string
    {
        $base_test = $path.'/pwg_representative/'.$filename_wo_ext.'.';
        foreach (Config::pictureExtensions() as $ext) {
            $test = $base_test.$ext;
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
        $formats = [];

        $base_test = $path.'/pwg_format/'.$filename_wo_ext.'.';

        foreach (Config::formatExtensions() as $ext) {
            $test = $base_test.$ext;

            if (is_file($test)) {
                $formats[$ext] = floor(filesize($test) / 1024);
            }
        }

        return $formats;
    }

}
