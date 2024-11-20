<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

use Piwigo\inc\dblayer\functions_mysqli;

/**
 * A source image is used to get a derivative image. It is either
 * the original file for a jpg/png/... or a 'representative' image
 * of a non image file or a standard icon for the non-image file.
 */
final class SrcImage
{
    public const int IS_ORIGINAL = 0x01;

    public const int IS_MIMETYPE = 0x02;

    public const int DIM_NOT_GIVEN = 0x04;

    public int|string $id;

    public string $rel_path;

    public int $rotation = 0;

    /**
     * @var array<int>
     */
    private ?array $size = null;

    private int $flags = 0;

    /**
     * @param array<string, ?string> $infos assoc array of data from images table
     */
    public function __construct(
        array $infos
    ) {
        global $conf;

        $this->id = $infos['id'];
        $ext = strtolower(functions::get_extension($infos['path']));
        $infos['path_ext'] = $ext;

        if (in_array($ext, $conf['picture_ext'])) {
            $this->rel_path = $infos['path'];
            $this->flags |= self::IS_ORIGINAL;
        } elseif (! empty($infos['representative_ext'])) {
            $this->rel_path = functions::original_to_representative($infos['path'], $infos['representative_ext']);
        } else {
            $this->rel_path = functions_plugins::trigger_change('get_mimetype_location', functions::get_themeconf('mime_icon_dir') . $ext . '.png', $ext);
            $this->flags |= self::IS_MIMETYPE;
            $size = getimagesize(PHPWG_ROOT_PATH . $this->rel_path);

            if ($size === false) {
                if ($ext == 'svg') {
                    $this->rel_path = $infos['path'];
                } else {
                    $this->rel_path = 'themes/default/icon/mimetypes/unknown.png';
                }

                $size = getimagesize(PHPWG_ROOT_PATH . $this->rel_path);
            }

            $this->size = [$size[0], $size[1]];
        }

        if (! $this->size) {
            if (isset($infos['width']) &&
                isset($infos['height'])
            ) {
                $width = $infos['width'];
                $height = $infos['height'];

                $this->rotation = intval($infos['rotation']) % 4;
                // 1 or 5 =>  90 clockwise
                // 3 or 7 => 270 clockwise
                if ($this->rotation % 2) {
                    $width = $infos['height'];
                    $height = $infos['width'];
                }

                $this->size = [$width, $height];
            } elseif (! array_key_exists('width', $infos)) {
                $this->flags |= self::DIM_NOT_GIVEN;
            }
        }
    }

    public function is_original(): int
    {
        return $this->flags & self::IS_ORIGINAL;
    }

    public function is_mimetype(): int
    {
        return $this->flags & self::IS_MIMETYPE;
    }

    public function get_path(): string
    {
        return PHPWG_ROOT_PATH . $this->rel_path;
    }

    public function get_url(): string
    {
        $url = functions_url::get_root_url() . $this->rel_path;

        if (! ($this->flags & self::IS_MIMETYPE)) {
            $url = functions_plugins::trigger_change('get_src_image_url', $url, $this);
        }

        return functions_url::embellish_url($url);
    }

    public function has_size(): bool
    {
        return $this->size != null;
    }

    /**
     * @return ?array<int> 0=width, 1=height or null if fail to compute size
     */
    public function get_size(): ?array
    {
        if ($this->size == null) {
            if ($this->flags & self::DIM_NOT_GIVEN) {
                functions_html::fatal_error('SrcImage dimensions required but not provided');
            }

            // probably not metadata synced
            $size = getimagesize($this->get_path());

            if ($size !== false) {
                $this->size = [$size[0], $size[1]];
                functions_mysqli::pwg_query("UPDATE images SET width = {$size[0]}, height = {$size[1]} WHERE id = {$this->id};");
            }
        }

        return $this->size;
    }
}
