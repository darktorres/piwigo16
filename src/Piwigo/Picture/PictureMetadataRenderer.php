<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Db\DbConnection;
use Piwigo\Image\SrcImage;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;

/**
 * Renders the picture page's EXIF/IPTC metadata panel. Ported from
 * include/picture_metadata.inc.php -- pure presentation glue around
 * MetadataService::getExifData()/getIptcData() (already the real
 * implementation; get_exif_data()/get_iptc_data(), formerly thin wrappers
 * around the same two methods in include/functions_metadata.inc.php,
 * were deleted in P23 sub-batch 8b-1 -- this class always called
 * MetadataService directly, never that wrapper file). No constructor
 * deps -- same "plain global-function/global-variable reads" shape as
 * Page\PageHeaderRenderer.
 */
final class PictureMetadataRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, array{src_image: SrcImage, ...}>
         */
        global $picture;
        $template = \Piwigo\Template\CurrentTemplate::get();

        $metadataService = new MetadataService(new MetadataRepository(DbConnection::build()));

        if ((\Piwigo\Config\Config::showExif()) and function_exists('exif_read_data')) {
            $showExifFieldsRaw = \Piwigo\Config\Config::showExifFields();
            $showExifFields = is_array($showExifFieldsRaw) ? array_values(array_filter($showExifFieldsRaw, is_string(...))) : [];

            $exifMapping = [];
            foreach ($showExifFields as $field) {
                $exifMapping[$field] = $field;
            }

            $exif = $metadataService->getExifData($picture['current']['src_image']->get_path(), $exifMapping);

            if (count($exif) > 0) {
                $tplMeta = [
                    'TITLE' => l10n('EXIF Metadata'),
                    'lines' => [],
                ];

                foreach ($showExifFields as $field) {
                    if (! str_contains($field, ';')) {
                        if (isset($exif[$field]) and ! is_array($exif[$field])) {
                            $key = $field;
                            if (\Piwigo\Core\Lang::has('exif_field_' . $field)) {
                                $key = \Piwigo\Core\Lang::t('exif_field_' . $field);
                            }
                            $tplMeta['lines'][$key] = $exif[$field];
                        }
                    } else {
                        $tokens = explode(';', $field);
                        if (isset($exif[$field]) and ! is_array($exif[$field])) {
                            $key = $tokens[1];
                            if (\Piwigo\Core\Lang::has('exif_field_' . $key)) {
                                $key = \Piwigo\Core\Lang::t('exif_field_' . $key);
                            }
                            $tplMeta['lines'][$key] = $exif[$field];
                        }
                    }
                }
                $template->append('metadata', $tplMeta);
            }
        }

        if (\Piwigo\Config\Config::showIptc()) {
            $showIptcMappingRaw = \Piwigo\Config\Config::showIptcMapping();
            $showIptcMapping = [];
            if (is_array($showIptcMappingRaw)) {
                foreach ($showIptcMappingRaw as $iptcMapKey => $iptcMapValue) {
                    if (is_string($iptcMapKey) and is_string($iptcMapValue)) {
                        $showIptcMapping[$iptcMapKey] = $iptcMapValue;
                    }
                }
            }

            $iptc = $metadataService->getIptcData($picture['current']['src_image']->get_path(), $showIptcMapping, ', ');

            if (count($iptc) > 0) {
                $tplMeta = [
                    'TITLE' => l10n('IPTC Metadata'),
                    'lines' => [],
                ];

                foreach ($iptc as $field => $value) {
                    $key = $field;
                    if (\Piwigo\Core\Lang::has($field)) {
                        $key = \Piwigo\Core\Lang::t($field);
                    }
                    $tplMeta['lines'][$key] = $value;
                }
                $template->append('metadata', $tplMeta);
            }
        }
    }
}
