<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Image\SrcImage;
use Piwigo\Metadata\MetadataService;
use Piwigo\Template\TemplateRegistry;

final readonly class PictureMetadataRenderer
{
    public function __construct(
        private MetadataService $metadataService,
    ) {
    }
    public function render(): void
    {
        $picture = is_array($GLOBALS['picture'] ?? null) ? $GLOBALS['picture'] : [];
        $current = is_array($picture['current'] ?? null) ? $picture['current'] : [];
        $srcImageRaw = $current['src_image'] ?? null;
        $srcImage = $srcImageRaw instanceof SrcImage ? $srcImageRaw : null;

        if (Config::showExif() and function_exists('exif_read_data') and $srcImage !== null) {
            $exif_mapping = [];
            foreach (Config::showExifFields() as $field) {
                $exif_mapping[$field] = $field;
            }

            $exif = $this->metadataService->getExifData($srcImage->getPath(), $exif_mapping);

            if (count($exif) > 0) {
                $tpl_meta = ['TITLE' => Lang::t('EXIF Metadata'), 'lines' => []];

                foreach (Config::showExifFields() as $field) {
                    if (!str_contains($field, ';')) {
                        if (isset($exif[$field]) and !is_array($exif[$field])) {
                            $key = $field;
                            if (Lang::has('exif_field_' . $field)) {
                                $key = Lang::t('exif_field_' . $field);
                            }
                            $tpl_meta['lines'][$key] = $exif[$field];
                        }
                    } else {
                        $tokens = explode(';', $field);
                        if (isset($exif[$field]) and !is_array($exif[$field])) {
                            $key = $tokens[1];
                            if (Lang::has('exif_field_' . $key)) {
                                $key = Lang::t('exif_field_' . $key);
                            }
                            $tpl_meta['lines'][$key] = $exif[$field];
                        }
                    }
                }
                TemplateRegistry::current()->append('metadata', $tpl_meta);
            }
        }

        if (Config::showIptc() and $srcImage !== null) {
            $iptc = $this->metadataService->getIptcData($srcImage->getPath(), Config::showIptcMapping(), ', ');

            if (count($iptc) > 0) {
                $tpl_meta = ['TITLE' => Lang::t('IPTC Metadata'), 'lines' => []];

                foreach ($iptc as $field => $value) {
                    $key = $field;
                    if (Lang::has((string) $field)) {
                        $key = Lang::t((string) $field);
                    }
                    $tpl_meta['lines'][$key] = $value;
                }
                TemplateRegistry::current()->append('metadata', $tpl_meta);
            }
        }
    }
}
