<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;

/**
 * A source image is used to get a derivative image. It is either
 * the original file for a jpg/png/... or a 'representative' image
 * of a  non image file or a standard icon for the non-image file.
 */
final class SrcImage
{
    public const int IS_ORIGINAL = 0x01;
    public const int IS_MIMETYPE = 0x02;
    public const int DIM_NOT_GIVEN = 0x04;

    public readonly int $id;
    public string $rel_path = '';
    /** @var int */
    public $rotation = 0;
    /** @var int[] */
    private ?array $size = null;
    private int $flags = 0;

    /**
     * @param array $infos assoc array of data from images table
     */
    /** @param array<string,mixed> $infos */
    public function __construct(array $infos)
    {
        $idRaw = $infos['id'] ?? 0;
        $this->id = is_scalar($idRaw) ? (int) $idRaw : 0;
        $pathRaw = $infos['path'] ?? '';
        $path = is_scalar($pathRaw) ? (string) $pathRaw : '';
        $fileRaw = $infos['file'] ?? '';
        $file = is_scalar($fileRaw) ? (string) $fileRaw : '';
        $ext = strtolower(get_extension($path));
        $infos['file_ext'] = strtolower(get_extension($file));
        $infos['path_ext'] = $ext;
        if (in_array($ext, Config::pictureExtensions())) {
            $this->rel_path = $path;
            $this->flags |= self::IS_ORIGINAL;
        } elseif (!empty($infos['representative_ext'])) {
            $repExt = $infos['representative_ext'];
            $this->rel_path = original_to_representative($path, is_scalar($repExt) ? (string) $repExt : '');
        } else {
            $mimeIconDir = get_themeconf('mime_icon_dir');
            $triggerResult = trigger_change('get_mimetype_location', (is_string($mimeIconDir) ? $mimeIconDir : '').$ext.'.png', $ext);
            $this->rel_path = $triggerResult;
            $this->flags |= self::IS_MIMETYPE;
            if (($size = pwg_safe_getimagesize(PHPWG_ROOT_PATH.$this->rel_path)) === false) {
                if ('svg' == $ext) {
                    $this->rel_path = $path;
                } else {
                    $this->rel_path = 'themes/_base/icon/mimetypes/unknown.png';
                }
                $size = pwg_safe_getimagesize(PHPWG_ROOT_PATH.$this->rel_path);
            }
            if ($size === false) {
                $this->size = null;
            } else {
                $w = $size[0];
                $h = $size[1];
                $this->size = [is_int($w) ? $w : (int) (is_scalar($w) ? $w : 0), is_int($h) ? $h : (int) (is_scalar($h) ? $h : 0)];
            }
        }

        if (!$this->size) {
            if (isset($infos['width']) && isset($infos['height'])) {
                $widthRaw = $infos['width'];
                $heightRaw = $infos['height'];
                $width = is_scalar($widthRaw) ? (int) $widthRaw : 0;
                $height = is_scalar($heightRaw) ? (int) $heightRaw : 0;

                $rotRaw = $infos['rotation'] ?? 0;
                $this->rotation = (is_scalar($rotRaw) ? (int) $rotRaw : 0) % 4;
                // 1 or 5 =>  90 clockwise
                // 3 or 7 => 270 clockwise
                if ($this->rotation % 2) {
                    $width = is_scalar($heightRaw) ? (int) $heightRaw : 0;
                    $height = is_scalar($widthRaw) ? (int) $widthRaw : 0;
                }

                $this->size = [$width, $height];
            } elseif (!array_key_exists('width', $infos)) {
                $this->flags |= self::DIM_NOT_GIVEN;
            }
        }
    }

    public function isOriginal(): int
    {
        return $this->flags & self::IS_ORIGINAL;
    }

    public function isMimetype(): int
    {
        return $this->flags & self::IS_MIMETYPE;
    }

    public function getPath(): string
    {
        return PHPWG_ROOT_PATH.$this->rel_path;
    }

    /**
     * @return string
     */
    /** @return string|array<mixed> */
    public function getUrl(): string|array
    {
        $url = get_root_url().$this->rel_path;
        if (!($this->flags & self::IS_MIMETYPE)) {
            $changed = trigger_change('get_src_image_url', $url, $this);
            $url = $changed;
        }
        return embellish_url($url);
    }

    public function hasSize(): bool
    {
        return $this->size != null;
    }

    /**
     * @return int[]|null 0=width, 1=height or null if fail to compute size
     */
    public function getSize(): ?array
    {
        if ($this->size == null) {
            // probably not metadata synced — try to read from disk before
            // giving up. Covers e.g. the dupe-image upload path where the
            // returning record only carries id, not width/height.
            $path = $this->getPath();
            if (is_readable($path) && ($size = pwg_safe_getimagesize($path)) !== false) {
                $w = $size[0];
                $h = $size[1];
                $wi = is_int($w) ? $w : (int) (is_scalar($w) ? $w : 0);
                $hi = is_int($h) ? $h : (int) (is_scalar($h) ? $h : 0);
                $this->size = [$wi, $hi];
                if ($this->id !== 0) {
                    ServiceLocator::get(ImageRepository::class)
                        ->updateDimensions($this->id, $wi, $hi);
                }
                return $this->size;
            }
        }
        return $this->size;
    }
}
