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
        $srcImage = PictureContextRegistry::current()->srcImage;

        if (Config::showExif() and function_exists('exif_read_data') and $srcImage !== null) {
            $this->renderExif($srcImage);
        }

        if (Config::showIptc() and $srcImage !== null) {
            $this->renderIptc($srcImage);
        }
    }

    private function renderExif(SrcImage $src): void
    {
        $exif_mapping = [];
        foreach (Config::showExifFields() as $field) {
            $exif_mapping[$field] = $field;
        }

        $exif = $this->metadataService->getExifData($src->getPath(), $exif_mapping);
        if (count($exif) === 0) {
            return;
        }

        $tpl_meta = ['TITLE' => Lang::t('EXIF Metadata'), 'lines' => []];

        foreach (Config::showExifFields() as $field) {
            if (!str_contains($field, ';')) {
                if (isset($exif[$field]) and !is_array($exif[$field])) {
                    $key = Lang::has('exif_field_' . $field) ? Lang::t('exif_field_' . $field) : $field;
                    $tpl_meta['lines'][$key] = $exif[$field];
                }
            } else {
                $tokens = explode(';', $field);
                if (isset($exif[$field]) and !is_array($exif[$field])) {
                    $key = Lang::has('exif_field_' . $tokens[1]) ? Lang::t('exif_field_' . $tokens[1]) : $tokens[1];
                    $tpl_meta['lines'][$key] = $exif[$field];
                }
            }
        }

        TemplateRegistry::current()->append('metadata', $tpl_meta);
    }

    private function renderIptc(SrcImage $src): void
    {
        $iptc = $this->metadataService->getIptcData($src->getPath(), Config::showIptcMapping(), ', ');
        if (count($iptc) === 0) {
            return;
        }

        $tpl_meta = ['TITLE' => Lang::t('IPTC Metadata'), 'lines' => []];

        foreach ($iptc as $field => $value) {
            $key = Lang::has((string) $field) ? Lang::t((string) $field) : $field;
            $tpl_meta['lines'][$key] = $value;
        }

        TemplateRegistry::current()->append('metadata', $tpl_meta);
    }
}
