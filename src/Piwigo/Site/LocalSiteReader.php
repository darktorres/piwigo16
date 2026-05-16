<?php

declare(strict_types=1);

namespace Piwigo\Site;

use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Metadata\MetadataAdminService;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\StringUtil;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

final class LocalSiteReader
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

    public function open(): bool
    {
        return is_dir($this->site_url);
    }

    /** @return string[] */
    public function getFullDirectories(string $basedir): array
    {
        return Kernel::service(ImageAdminService::class)->getFsDirectories($basedir);
    }

    /**
     * @return array<mixed>
     */
    public function getElements(string $path): array
    {
        $subdirs = [];
        $fs = [];
        if (is_dir($path) && $contents = opendir($path)) {
            while (($node = readdir($contents)) !== false) {
                if ($node == '.' or $node == '..') {
                    continue;
                }

                if (is_file($path.'/'.$node)) {
                    $extension = strtolower(StringUtil::getExtension($node));
                    $filename_wo_ext = StringUtil::getFilenameWoExtension($node);

                    if (isset(Config::flipFileExt()[$extension])) {
                        $representative_ext = null;
                        if (! isset(Config::flipPictureExt()[$extension])) {
                            $representative_ext = $this->getRepresentativeExt($path, $filename_wo_ext);
                        }

                        $fs[ $path.'/'.$node ] = ['representative_ext' => $representative_ext];

                        if (Config::isFormatsEnabled()) {
                            $fs[ $path.'/'.$node ]['formats'] = $this->getFormats($path, $filename_wo_ext);
                        }
                    }
                } elseif (is_dir($path.'/'.$node)
                         and $node != 'pwg_high'
                         and $node != 'pwg_representative'
                         and $node != 'pwg_format'
                         and $node != 'thumbnail') {
                    $subdirs[] = $node;
                }
            }
            closedir($contents);

            foreach ($subdirs as $subdir) {
                $tmp_fs = $this->getElements($path.'/'.$subdir);
                $fs = array_merge($fs, $tmp_fs);
            }
            ksort($fs);
        }
        return $fs;
    }

    /** @return array<mixed> */
    public function getUpdateAttributes(): array
    {
        return ['representative_ext'];
    }

    /** @return array<mixed> */
    public function getElementUpdateAttributes(string $file): array
    {
        $data = [];
        $filename = basename($file);
        $extension = StringUtil::getExtension($filename);

        $representative_ext = null;
        if (! isset(Config::flipPictureExt()[$extension])) {
            $dirname = dirname($file);
            $filename_wo_ext = StringUtil::getFilenameWoExtension($filename);
            $representative_ext = $this->getRepresentativeExt($dirname, $filename_wo_ext);
        }

        $data['representative_ext'] = $representative_ext;
        return $data;
    }

    /** @return array<mixed> */
    public function getMetadataAttributes(): array
    {
        return Kernel::service(MetadataAdminService::class)->getSyncMetadataAttributes();
    }

    /**
     * @param array<mixed> $infos
     * @return array<mixed>|false
     */
    public function getElementMetadata(array $infos): array|false
    {
        return Kernel::service(MetadataAdminService::class)->getSyncMetadata($infos);
    }

    public function getRepresentativeExt(string $path, string $filename_wo_ext): ?string
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

    /** @return float[] */
    public function getFormats(string $path, string $filename_wo_ext): array
    {
        $formats = [];
        $base_test = $path.'/pwg_format/'.$filename_wo_ext.'.';

        foreach (Config::formatExtensions() as $ext) {
            $test = $base_test.$ext;
            if (is_file($test)) {
                $fsResult = filesize($test);
                $formats[$ext] = floor(($fsResult !== false ? $fsResult : 0) / 1024);
            }
        }

        return $formats;
    }
}
