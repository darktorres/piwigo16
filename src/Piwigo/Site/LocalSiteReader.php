<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Site;

use Piwigo\Core\FilesystemHelper;
use Piwigo\Db\DbConnection;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;

// provides data for site synchronization from the local file system
class LocalSiteReader
{
    /**
     * @var array<string, int>
     */
    private readonly array $flip_file_ext;

    /**
     * @var array<string, int>
     */
    private readonly array $flip_picture_ext;

    public function __construct(
        public string $site_url,
        private readonly ?MetadataService $metadataService = null,
    ) {
        // Legacy Coupling Retirement Track A batch A4: was memoized on the
        // $conf global (flip_file_ext/flip_picture_ext never DB-persist --
        // pure per-instance derived state), now a private property computed
        // once per instance instead.
        $this->flip_file_ext = array_flip(\Piwigo\Config\Config::fileExtensions());
        $this->flip_picture_ext = array_flip(\Piwigo\Config\Config::pictureExtensions());
    }

    /**
     * Optional-with-lazy-default -- only the 2 metadata-sync methods below
     * reach this dependency, and both real callers construct this class
     * with just a site URL.
     */
    private function metadataService(): MetadataService
    {
        return $this->metadataService
            ?? new MetadataService(new MetadataRepository(DbConnection::build()));
    }

    /**
     * Is this local site ok ?
     *
     * @return bool true on success, false otherwise
     */
    public function open(): bool
    {
        if (! is_dir($this->site_url)) {
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
        $fs_fulldirs = FilesystemHelper::getFsDirectories($basedir);
        return $fs_fulldirs;
    }

    /**
     * Returns an array with all file system files according to
     * Config::fileExtensions() and Config::pictureExtensions()
     * @param string $path recurse in this directory
     * @return array<string, array<string, mixed>> like "pic.jpg"=>array('representative_ext'=>'jpg' ... )
     */
    public function get_elements($path): array
    {
        $flip_file_ext = $this->flip_file_ext;
        $flip_picture_ext = $this->flip_picture_ext;

        $subdirs = [];
        $fs = [];
        if (is_dir($path) && (bool) ($contents = opendir($path))) {
            while (($node = readdir($contents)) !== false) {
                if ($node == '.' or $node == '..') {
                    continue;
                }

                if (is_file($path . '/' . $node)) {
                    $extension = strtolower(\Piwigo\Core\StringHelper::getExtension($node));
                    $filename_wo_ext = \Piwigo\Core\StringHelper::getFilenameWoExtension($node);

                    if (isset($flip_file_ext[$extension])) {
                        $representative_ext = null;
                        if (! isset($flip_picture_ext[$extension])) {
                            $representative_ext = $this->get_representative_ext($path, $filename_wo_ext);
                        }

                        $fs[$path . '/' . $node] = [
                            'representative_ext' => $representative_ext,
                        ];

                        if (\Piwigo\Config\Config::isFormatsEnabled()) {
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
        $data = [];

        $filename = basename($file);
        $extension = \Piwigo\Core\StringHelper::getExtension($filename);

        $representative_ext = null;
        if (! isset($this->flip_picture_ext[$extension])) {
            $dirname = dirname($file);
            $filename_wo_ext = \Piwigo\Core\StringHelper::getFilenameWoExtension($filename);
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
        return $this->metadataService()
            ->getSyncMetadataAttributes();
    }

    // returns a hash of attributes (metadata+filesize+width,...) for file
    /**
     * @param array<string, mixed> $infos
     * @return array<string, mixed>|false
     */
    public function get_element_metadata(array $infos): array|false
    {
        return $this->metadataService()
            ->getSyncMetadata($infos);
    }

    // -------------------------------------------------- private functions --------
    public function get_representative_ext(string $path, string $filename_wo_ext): ?string
    {
        $base_test = $path . '/pwg_representative/' . $filename_wo_ext . '.';
        foreach (\Piwigo\Config\Config::pictureExtensions() as $ext) {
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
        $formats = [];

        $base_test = $path . '/pwg_format/' . $filename_wo_ext . '.';

        foreach (\Piwigo\Config\Config::formatExtensions() as $ext) {
            $test = $base_test . $ext;

            if (is_file($test)) {
                $test_filesize = filesize($test);
                if ($test_filesize !== false) {
                    $formats[$ext] = floor($test_filesize / 1024);
                }
            }
        }

        return $formats;
    }
}
