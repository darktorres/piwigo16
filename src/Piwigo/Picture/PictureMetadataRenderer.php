<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Image\SrcImage;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\Picture\Projection\PictureMetadataPageContext;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;

/**
 * Renders the picture page's EXIF/IPTC metadata panel. Ported from
 * include/picture_metadata.inc.php -- pure presentation glue around
 * MetadataService::getExifData()/getIptcData(). No constructor deps --
 * same "plain global-function/global-variable reads" shape as
 * Page\PageHeaderRenderer.
 */
final class PictureMetadataRenderer
{
    /**
     * @param array<string, array{src_image: SrcImage, ...}> $picture
     */
    public function render(Lang $lang, array $picture, CurrentLogger $currentLogger, EventDispatcher $eventDispatcher, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, CurrentUser $currentUser, SessionService $sessionService, Paths $paths, EntityManagerInterface $entityManager): void
    {
        $template = $currentTemplate->get();

        $metadataService = new MetadataService($lang, new MetadataRepository($entityManager), $currentLogger, $eventDispatcher, $currentConfig, $currentUser, $sessionService, $paths);

        $metadata = null;

        if (($currentConfig->showExif) and function_exists('exif_read_data')) {
            $showExifFields = $currentConfig->showExifFields;

            $exifMapping = [];
            foreach ($showExifFields as $field) {
                $exifMapping[$field] = $field;
            }

            $exif = $metadataService->getExifData($picture['current']['src_image']->getPath(), $exifMapping);

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
                $metadata = [$tplMeta];
            }
        }

        if ($currentConfig->showIptc) {
            $showIptcMapping = $currentConfig->showIptcMapping;

            $iptc = $metadataService->getIptcData($picture['current']['src_image']->getPath(), $showIptcMapping, ', ');

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
                $metadata ??= [];
                $metadata[] = $tplMeta;
            }
        }

        $template->assignContext(new PictureMetadataPageContext($metadata));
    }
}
