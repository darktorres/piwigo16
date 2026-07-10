<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

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

    /**
     * @var int
     */
    public $id;

    /**
     * @var string
     */
    public $rel_path;

    /**
     * @var int
     */
    public $rotation = 0;

    /**
     * @var int[]
     */
    private ?array $size = null;

    private int $flags = 0;

    /**
     * @param array<string, mixed> $infos assoc array of data from images table
     */
    public function __construct(
        array $infos
    ) {
        /** @var array<string, mixed> $conf */
        global $conf;

        // images.id/.path/.file are all NOT NULL DB columns, but every
        // element read back from a DB row is string|null per this driver
        // (no MYSQLI_OPT_INT_AND_FLOAT_NATIVE); narrow explicitly rather
        // than trusting the schema at the type-check level.
        $this->id = is_numeric($infos['id']) ? (int) $infos['id'] : 0;
        $path = is_string($infos['path']) ? $infos['path'] : '';
        $file = is_string($infos['file']) ? $infos['file'] : null;
        $ext = strtolower(get_extension($path));
        $infos['file_ext'] = @strtolower(get_extension($file));
        $infos['path_ext'] = $ext;
        // representative_ext is a nullable DB column; empty()'s silent
        // handling of a missing/non-string key is preserved via `?? null`.
        $representative_ext_raw = $infos['representative_ext'] ?? null;
        $representative_ext = is_string($representative_ext_raw) ? $representative_ext_raw : '';
        // $conf['picture_ext'] is always a string[] set by config_default.inc.php.
        $picture_ext = is_array($conf['picture_ext']) ? $conf['picture_ext'] : [];
        if (in_array($ext, $picture_ext)) {
            $this->rel_path = $path;
            $this->flags |= self::IS_ORIGINAL;
        } elseif (! empty($representative_ext)) {
            $this->rel_path = original_to_representative($path, $representative_ext);
        } else {
            $default_mimetype_location = get_themeconf('mime_icon_dir') . $ext . '.png';
            $mimetype_location = trigger_change('get_mimetype_location', $default_mimetype_location, $ext);
            // trigger_change() hands the value through arbitrary registered
            // event handlers (mixed return); fall back to the pre-filter
            // location if a misbehaving handler returns a non-string.
            $this->rel_path = is_string($mimetype_location) ? $mimetype_location : $default_mimetype_location;
            $this->flags |= self::IS_MIMETYPE;
            if (($size = @getimagesize(PHPWG_ROOT_PATH . $this->rel_path)) === false) {
                if ($ext == 'svg') {
                    $this->rel_path = $path;
                } else {
                    $this->rel_path = 'themes/default/icon/mimetypes/unknown.png';
                }
                $size = getimagesize(PHPWG_ROOT_PATH . $this->rel_path);
                if ($size === false) {
                    throw new Exception('SrcImage: unable to read size of fallback icon ' . $this->rel_path);
                }
            }
            $this->size = [$size[0], $size[1]];
        }

        if (! $this->size) {
            if (isset($infos['width']) && isset($infos['height'])) {
                $width = is_numeric($infos['width']) ? (int) $infos['width'] : 0;
                $height = is_numeric($infos['height']) ? (int) $infos['height'] : 0;

                $rotation_raw = $infos['rotation'] ?? null;
                $this->rotation = is_numeric($rotation_raw) ? intval($rotation_raw) % 4 : 0;
                // 1 or 5 =>  90 clockwise
                // 3 or 7 => 270 clockwise
                if ($this->rotation % 2) {
                    [$width, $height] = [$height, $width];
                }

                $this->size = [$width, $height];
            } elseif (! array_key_exists('width', $infos)) {
                $this->flags |= self::DIM_NOT_GIVEN;
            }
        }
    }

    public function is_original(): bool
    {
        return (bool) ($this->flags & self::IS_ORIGINAL);
    }

    public function is_mimetype(): bool
    {
        return (bool) ($this->flags & self::IS_MIMETYPE);
    }

    public function get_path(): string
    {
        return PHPWG_ROOT_PATH . $this->rel_path;
    }

    public function get_url(): string
    {
        $url = get_root_url() . $this->rel_path;
        if (! ($this->flags & self::IS_MIMETYPE)) {
            $filtered_url = trigger_change('get_src_image_url', $url, $this);
            // trigger_change() hands the value through arbitrary registered
            // event handlers (mixed return); fall back to the pre-filter
            // url if a misbehaving handler returns a non-string.
            $url = is_string($filtered_url) ? $filtered_url : $url;
        }
        return embellish_url($url);
    }

    public function has_size(): bool
    {
        return $this->size != null;
    }

    /**
     * @return int[]|null 0=width, 1=height or null if fail to compute size
     */
    public function get_size(): ?array
    {
        if ($this->size == null) {
            if ($this->flags & self::DIM_NOT_GIVEN) {
                fatal_error('SrcImage dimensions required but not provided');
            }
            // probably not metadata synced
            if (($size = getimagesize($this->get_path())) !== false) {
                $this->size = [$size[0], $size[1]];
                pwg_query('UPDATE ' . IMAGES_TABLE . ' SET width=' . $size[0] . ', height=' . $size[1] . ' WHERE id=' . $this->id);
            }
        }
        return $this->size;
    }
}

/**
 * Holds information (path, url, dimensions) about a derivative image.
 * A derivative image is constructed from a source image (SrcImage class)
 * and derivative parameters (DerivativeParams class).
 */
final class DerivativeImage
{
    private ?DerivativeParams $params = null;

    /**
     * @var string
     */
    private $rel_path;

    /**
     * @var string
     */
    private $rel_url;

    private bool $is_cached = true;

    /**
     * @param string|DerivativeParams $type standard derivative param type (e.g. IMG_*)
     *    or a DerivativeParams object
     * @param SrcImage $src_image the source image of this derivative
     */
    public function __construct(
        $type,
        public SrcImage $src_image
    ) {
        if (is_string($type)) {
            $this->params = ImageStdParams::get_by_type($type);
        } else {
            $this->params = $type;
        }

        self::build($this->src_image, $this->params, $this->rel_path, $this->rel_url, $this->is_cached);
    }

    /**
     * Generates the url of a thumbnail.
     *
     * @param array<string, mixed>|SrcImage $infos array of info from db or SrcImage
     */
    public static function thumb_url($infos): string
    {
        return self::url(IMG_THUMB, $infos);
    }

    /**
     * Generates the url for a particular photo size.
     *
     * @param string|DerivativeParams $type standard derivative param type (e.g. IMG_*)
     *    or a DerivativeParams object
     * @param array<string, mixed>|SrcImage $infos array of info from db or SrcImage
     */
    public static function url($type, $infos): string
    {
        $src_image = is_object($infos) ? $infos : new SrcImage($infos);
        $params = is_string($type) ? ImageStdParams::get_by_type($type) : $type;
        self::build($src_image, $params, $rel_path, $rel_url);
        if ($params == null) {
            return $src_image->get_url();
        }
        $default_url = get_root_url() . $rel_url;
        $filtered_url = trigger_change(
            'get_derivative_url',
            $default_url,
            $params,
            $src_image,
            $rel_url
        );
        // trigger_change() hands the value through arbitrary registered
        // event handlers (mixed return); fall back to the pre-filter url
        // if a misbehaving handler returns a non-string.
        return embellish_url(is_string($filtered_url) ? $filtered_url : $default_url);
    }

    /**
     * Return associative an array of all DerivativeImage for a specific image.
     * Disabled derivative types can be still found in the return, mapped to an
     * enabled derivative (e.g. the values are not unique in the return array).
     * This is useful for any plugin/theme to just use $deriv[IMG_XLARGE] even if
     * the XLARGE is disabled.
     *
     * @param array<string, mixed>|SrcImage $src_image array of info from db or SrcImage
     * @return DerivativeImage[]
     */
    public static function get_all($src_image): array
    {
        if (! is_object($src_image)) {
            $src_image = new SrcImage($src_image);
        }

        $ret = [];
        // build enabled types
        foreach (ImageStdParams::get_defined_type_map() as $type => $params) {
            $derivative = new self($params, $src_image);
            $ret[$type] = $derivative;
        }
        // disabled types, fallback to enabled types
        foreach (ImageStdParams::get_undefined_type_map() as $type => $type2) {
            $ret[$type] = $ret[$type2];
        }

        return $ret;
    }

    /**
     * Returns an instance of DerivativeImage for a specific image and size.
     * Disabled derivatives fallback to an enabled derivative.
     *
     * @param string $type standard derivative param type (e.g. IMG_*)
     * @param array<string, mixed>|SrcImage $src_image array of info from db or SrcImage
     * @return DerivativeImage|null null if $type not found
     */
    public static function get_one($type, $src_image): ?self
    {
        if (! is_object($src_image)) {
            $src_image = new SrcImage($src_image);
        }

        $defined = ImageStdParams::get_defined_type_map();
        if (isset($defined[$type])) {
            return new self($defined[$type], $src_image);
        }

        $undefined = ImageStdParams::get_undefined_type_map();
        if (isset($undefined[$type])) {
            return new self($defined[$undefined[$type]], $src_image);
        }

        return null;
    }

    /**
     * @todo : documentation of DerivativeImage::build
     * @param ?DerivativeParams $params by-ref: may be reassigned to null (source used as-is) or a smaller defined type
     * @param string $rel_path by-ref out-param
     * @param string $rel_url by-ref out-param
     * @param bool $is_cached by-ref out-param; not bound to a real variable when omitted (uses its default)
     */
    private static function build(SrcImage $src, &$params, &$rel_path, &$rel_url, &$is_cached = false): void
    {
        // every real call site (the constructor, url(), and this method's
        // own recursive call below) passes a freshly-resolved, non-null
        // DerivativeParams; it's only ever reassigned to null as an
        // out-param, below.
        assert($params !== null);

        if ($src->has_size()) {
            $src_size = $src->get_size();
            // has_size() checks the same underlying state get_size() would
            // otherwise recompute, so a true has_size() guarantees get_size()
            // returns non-null here.
            assert($src_size !== null);
            if ($params->is_identity($src_size)) {// the source image is smaller than what we should do - we do not upsample
                if (! $params->will_watermark($src_size) && ! $src->rotation) {// no watermark, no rotation required -> we will use the source image
                    $params = null;
                    $rel_path = $rel_url = $src->rel_path;
                    return;
                }
                $defined_types = array_keys(ImageStdParams::get_defined_type_map());
                for ($i = 0; $i < count($defined_types); $i++) {
                    if ($defined_types[$i] == $params->type) {
                        for ($i--; $i >= 0; $i--) {
                            $smaller = ImageStdParams::get_by_type($defined_types[$i]);
                            if ($smaller->sizing->max_crop == $params->sizing->max_crop && $smaller->is_identity($src_size)) {
                                $params = $smaller;
                                self::build($src, $params, $rel_path, $rel_url, $is_cached);
                                return;
                            }
                        }
                        break;
                    }
                }
            }
        }

        $tokens = [];
        $tokens[] = substr((string) $params->type, 0, 2);

        if ($params->type == IMG_CUSTOM) {
            $params->add_url_tokens($tokens);
        }

        $loc = $src->rel_path;
        if (substr_compare((string) $loc, './', 0, 2) == 0) {
            $loc = substr((string) $loc, 2);
        } elseif (substr_compare((string) $loc, '../', 0, 3) == 0) {
            $loc = substr((string) $loc, 3);
        }
        $dot = strrpos((string) $loc, '.');
        if ($dot === false) {
            throw new Exception("DerivativeImage::build(): path '{$loc}' has no extension");
        }
        $loc = substr_replace($loc, '-' . implode('_', $tokens), $dot, 0);

        $rel_path = PWG_DERIVATIVE_DIR . $loc;

        /** @var array<string, mixed> $conf */
        global $conf;
        $url_style = $conf['derivative_url_style'];
        if (! $url_style) {
            $mtime = @filemtime(PHPWG_ROOT_PATH . $rel_path);
            if ($mtime === false or $mtime < $params->last_mod_time) {
                $is_cached = false;
                $url_style = 2;
            } else {
                $url_style = 1;
            }
        }

        if ($url_style == 2) {
            $rel_url = 'i';
            if ($conf['php_extension_in_urls']) {
                $rel_url .= '.php';
            }
            if ($conf['question_mark_in_urls']) {
                $rel_url .= '?';
            }
            $rel_url .= '/' . $loc;
        } else {
            $rel_url = $rel_path;
        }
    }

    public function get_path(): string
    {
        return PHPWG_ROOT_PATH . $this->rel_path;
    }

    public function get_url(): string
    {
        if ($this->params == null) {
            return $this->src_image->get_url();
        }
        $default_url = get_root_url() . $this->rel_url;
        $filtered_url = trigger_change(
            'get_derivative_url',
            $default_url,
            $this->params,
            $this->src_image,
            $this->rel_url
        );
        // trigger_change() hands the value through arbitrary registered
        // event handlers (mixed return); fall back to the pre-filter url
        // if a misbehaving handler returns a non-string.
        return embellish_url(is_string($filtered_url) ? $filtered_url : $default_url);
    }

    public function same_as_source(): bool
    {
        return $this->params == null;
    }

    /**
     * @return string one if IMG_* or 'Original'
     */
    public function get_type()
    {
        if ($this->params == null) {
            return 'Original';
        }
        return $this->params->type;
    }

    /**
     * @return int[]|null null if the source image's own size failed to compute
     */
    public function get_size(): ?array
    {
        $src_size = $this->src_image->get_size();
        if ($this->params == null || $src_size == null) {
            return $src_size;
        }
        return $this->params->compute_final_size($src_size);
    }

    /**
     * Returns the size as CSS rule.
     *
     * @return string
     */
    public function get_size_css()
    {
        $size = $this->get_size();
        if ($size) {
            return 'width:' . $size[0] . 'px; height:' . $size[1] . 'px';
        }
    }

    /**
     * Returns the size as HTML attributes.
     *
     * @return string
     */
    public function get_size_htm()
    {
        $size = $this->get_size();
        if ($size) {
            return 'width="' . $size[0] . '" height="' . $size[1] . '"';
        }
    }

    /**
     * Returns literal size: $widthx$height.
     *
     * @return string
     */
    public function get_size_hr()
    {
        $size = $this->get_size();
        if ($size) {
            return $size[0] . ' x ' . $size[1];
        }
    }

    /**
     * @param int $maxw
     * @param int $maxh
     * @return int[]|null
     */
    public function get_scaled_size($maxw, $maxh): ?array
    {
        $size = $this->get_size();
        if ($size) {
            $ratio_w = $size[0] / $maxw;
            $ratio_h = $size[1] / $maxh;
            if ($ratio_w > 1 || $ratio_h > 1) {
                if ($ratio_w > $ratio_h) {
                    $size[0] = $maxw;
                    $size[1] = (int) floor($size[1] / $ratio_w);
                } else {
                    $size[0] = (int) floor($size[0] / $ratio_h);
                    $size[1] = $maxh;
                }
            }
        }
        return $size;
    }

    /**
     * Returns the scaled size as HTML attributes.
     *
     * @param int $maxw
     * @param int $maxh
     * @return string
     */
    public function get_scaled_size_htm($maxw = 9999, $maxh = 9999)
    {
        $size = $this->get_scaled_size($maxw, $maxh);
        if ($size) {
            return 'width="' . $size[0] . '" height="' . $size[1] . '"';
        }
    }

    public function is_cached(): bool
    {
        return $this->is_cached;
    }
}
