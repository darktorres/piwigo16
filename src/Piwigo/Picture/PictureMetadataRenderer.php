<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Projection\PictureElement;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Metadata\ExifTool\ExifToolFfi;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\Picture\Projection\MetadataPanel;
use Piwigo\PluginConfig\EventDispatcher;
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
     * @return list<MetadataPanel>|null
     */
    public function render(Lang $lang, PictureElement $picture, CurrentLogger $currentLogger, EventDispatcher $eventDispatcher, CurrentConfig $currentConfig, CurrentUser $currentUser, Paths $paths, EntityManagerInterface $entityManager): ?array
    {
        $metadataService = new MetadataService($lang, new MetadataRepository($entityManager), $currentLogger, $eventDispatcher, $currentConfig, $currentUser, $paths);

        $metadata = null;

        // Checked once, up front, for both panels below -- unlike sync
        // (which should fail loudly for an admin when exiftool is
        // missing), a visitor's picture page should never 500 over it:
        // skip building an ExifToolFfi entirely (no panel) rather than
        // constructing one and catching its throw.
        if (! ExifToolFfi::isAvailable()) {
            return null;
        }

        $exifTool = ($currentConfig->showExif || $currentConfig->showIptc) ? new ExifToolFfi() : null;

        try {
            if ($currentConfig->showExif && $exifTool instanceof ExifToolFfi) {
                $showExifFields = $currentConfig->showExifFields;

                $exifMapping = [];
                foreach ($showExifFields as $field) {
                    $exifMapping[$field] = $field;
                }

                $exif = $metadataService->getExifData($picture->srcImage->getPath(), $exifMapping, $exifTool);

                if (count($exif) > 0) {
                    $lines = [];

                    foreach ($showExifFields as $field) {
                        if (! str_contains($field, ';')) {
                            if (isset($exif[$field]) and ! is_array($exif[$field])) {
                                $key = $field;
                                if ($lang->has('exif_field_' . $field)) {
                                    $key = $lang->t('exif_field_' . $field);
                                }
                                $lines[$key] = $exif[$field];
                            }
                        } else {
                            $tokens = explode(';', $field);
                            if (isset($exif[$field]) and ! is_array($exif[$field])) {
                                $key = $tokens[1];
                                if ($lang->has('exif_field_' . $key)) {
                                    $key = $lang->t('exif_field_' . $key);
                                }
                                $lines[$key] = $exif[$field];
                            }
                        }
                    }
                    $metadata = [new MetadataPanel(title: $lang->t('EXIF Metadata'), lines: $lines)];
                }
            }

            if ($currentConfig->showIptc && $exifTool instanceof ExifToolFfi) {
                $showIptcMapping = $currentConfig->showIptcMapping;

                $iptc = $metadataService->getIptcData($picture->srcImage->getPath(), $showIptcMapping, $exifTool, ', ');

                if (count($iptc) > 0) {
                    $lines = [];

                    foreach ($iptc as $field => $value) {
                        $key = $field;
                        if ($lang->has($field)) {
                            $key = $lang->t($field);
                        }
                        $lines[$key] = $value;
                    }
                    $metadata ??= [];
                    $metadata[] = new MetadataPanel(title: $lang->t('IPTC Metadata'), lines: $lines);
                }
            }
        } finally {
            $exifTool?->close();
        }

        return $metadata;
    }
}
