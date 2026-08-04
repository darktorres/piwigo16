<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Core\Lang;
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
    /**
     * @param array<string, array{src_image: SrcImage, ...}> $picture
     */
    public function render(Lang $lang, array $picture, \Piwigo\Core\CurrentLogger $currentLogger, \Piwigo\PluginConfig\EventDispatcher $eventDispatcher, \Piwigo\Template\CurrentTemplate $currentTemplate): void
    {
        $template = $currentTemplate->get();

        $metadataService = new MetadataService(Lang::current(), new MetadataRepository(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())), $currentLogger, $eventDispatcher);

        if ((\Piwigo\Config\CurrentConfig::showExif()) and function_exists('exif_read_data')) {
            $showExifFields = \Piwigo\Config\CurrentConfig::showExifFields();

            $exifMapping = [];
            foreach ($showExifFields as $field) {
                $exifMapping[$field] = $field;
            }

            $exif = $metadataService->getExifData($picture['current']['src_image']->get_path(), $exifMapping);

            if (count($exif) > 0) {
                $tplMeta = [
                    'TITLE' => $lang->t('EXIF Metadata'),
                    'lines' => [],
                ];

                foreach ($showExifFields as $field) {
                    if (! str_contains($field, ';')) {
                        if (isset($exif[$field]) and ! is_array($exif[$field])) {
                            $key = $field;
                            if ($lang->has('exif_field_' . $field)) {
                                $key = $lang->t('exif_field_' . $field);
                            }
                            $tplMeta['lines'][$key] = $exif[$field];
                        }
                    } else {
                        $tokens = explode(';', $field);
                        if (isset($exif[$field]) and ! is_array($exif[$field])) {
                            $key = $tokens[1];
                            if ($lang->has('exif_field_' . $key)) {
                                $key = $lang->t('exif_field_' . $key);
                            }
                            $tplMeta['lines'][$key] = $exif[$field];
                        }
                    }
                }
                $template->append('metadata', $tplMeta);
            }
        }

        if (\Piwigo\Config\CurrentConfig::showIptc()) {
            $showIptcMapping = \Piwigo\Config\CurrentConfig::showIptcMapping();

            $iptc = $metadataService->getIptcData($picture['current']['src_image']->get_path(), $showIptcMapping, ', ');

            if (count($iptc) > 0) {
                $tplMeta = [
                    'TITLE' => $lang->t('IPTC Metadata'),
                    'lines' => [],
                ];

                foreach ($iptc as $field => $value) {
                    $key = $field;
                    if ($lang->has($field)) {
                        $key = $lang->t($field);
                    }
                    $tplMeta['lines'][$key] = $value;
                }
                $template->append('metadata', $tplMeta);
            }
        }
    }
}
