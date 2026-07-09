<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('IMG_SQUARE', 'square');
define('IMG_THUMB', 'thumb');
define('IMG_XXSMALL', '2small');
define('IMG_XSMALL', 'xsmall');
define('IMG_SMALL', 'small');
define('IMG_MEDIUM', 'medium');
define('IMG_LARGE', 'large');
define('IMG_XLARGE', 'xlarge');
define('IMG_XXLARGE', 'xxlarge');
define('IMG_3XLARGE', '3xlarge');
define('IMG_4XLARGE', '4xlarge');
define('IMG_CUSTOM', 'custom');

/**
 * Container for watermark configuration.
 */
final class WatermarkParams
{
    /**
     * @var string
     */
    public $file = '';

    /**
     * @var int[]
     */
    public $min_size = [500, 500];

    /**
     * @var int
     */
    public $xpos = 50;

    /**
     * @var int
     */
    public $ypos = 50;

    /**
     * @var int
     */
    public $xrepeat = 0;

    /**
     * @var int
     */
    public $yrepeat = 0;

    /**
     * @var int
     */
    public $opacity = 100;
}

/**
 * Container for standard derivatives parameters.
 */
final class ImageStdParams
{
    /**
     * @var string[]
     */
    private static array $all_types = [
        IMG_SQUARE, IMG_THUMB, IMG_XXSMALL, IMG_XSMALL, IMG_SMALL,
        IMG_MEDIUM, IMG_LARGE, IMG_XLARGE, IMG_XXLARGE, IMG_3XLARGE, IMG_4XLARGE,
    ];

    /**
     * @var string[]
     */
    private static array $disabled_types_by_default = [IMG_3XLARGE, IMG_4XLARGE];

    /**
     * @var DerivativeParams[]
     */
    private static $all_type_map = [];

    /**
     * @var DerivativeParams[]
     */
    private static $type_map = [];

    /**
     * @var DerivativeParams[]
     */
    private static $disabled_type_map = [];

    /**
     * @var string[]
     */
    private static $undefined_type_map = [];

    /**
     * @var WatermarkParams
     */
    private static $watermark;

    /**
     * @var array<string, int>
     */
    public static $custom = [];

    /**
     * @var int
     */
    public static $quality = 95;

    /**
     * @return string[]
     */
    public static function get_all_types(): array
    {
        return self::$all_types;
    }

    /**
     * @return DerivativeParams[]
     */
    public static function get_all_type_map()
    {
        return self::$all_type_map;
    }

    /**
     * @return DerivativeParams[]
     */
    public static function get_defined_type_map()
    {
        return self::$type_map;
    }

    /**
     * @return DerivativeParams[]|string $conf['disabled_derivatives'] is
     *   stored serialized in the database — callers must safe_unserialize()
     *   this when it falls through to that fallback
     */
    public static function get_disabled_type_map()
    {
        global $conf;

        if (count(self::$disabled_type_map)) {
            return self::$disabled_type_map;
        }
        return $conf['disabled_derivatives'] ?? [];
    }

    /**
     * @return string[]
     */
    public static function get_undefined_type_map()
    {
        return self::$undefined_type_map;
    }

    /**
     * @return DerivativeParams
     */
    public static function get_by_type(string $type)
    {
        return self::$all_type_map[$type];
    }

    /**
     * @param int $w
     * @param int $h
     * @param float $crop
     * @param int $minw
     * @param int $minh
     */
    public static function get_custom($w, $h, $crop = 0, $minw = null, $minh = null): \DerivativeParams
    {
        // $minw/$minh are always both null or both set together (see the
        // sole caller, template.class.php's func_define_derivative()).
        $min_size = $minw !== null && $minh !== null ? [$minw, $minh] : null;
        $params = new DerivativeParams(new SizingParams([$w, $h], $crop, $min_size));
        self::apply_global($params);

        $key = [];
        $params->add_url_tokens($key);
        $key = implode('_', $key);
        if (@self::$custom[$key] < time() - 24 * 3600) {
            self::$custom[$key] = time();
            self::save();
        }
        return $params;
    }

    /**
     * @return WatermarkParams
     */
    public static function get_watermark()
    {
        return self::$watermark;
    }

    /**
     * Loads derivative configuration from database or initializes it.
     */
    public static function load_from_db(): void
    {
        global $conf;
        $arr = @unserialize($conf['derivatives']);
        // unserialize() is only typed mixed by PHP itself (the serialized
        // blob could decode to any PHP value, or to a malformed non-array
        // shape from a hand-edited config row) -- narrow every sub-value
        // for real before trusting it, rather than assigning the blob
        // blindly into these precisely-typed properties.
        if ($arr !== false && is_array($arr)) {
            $type_map = [];
            if (isset($arr['d']) && is_array($arr['d'])) {
                foreach ($arr['d'] as $type => $params) {
                    if (is_string($type) && $params instanceof DerivativeParams) {
                        $type_map[$type] = $params;
                    }
                }
            }
            self::$type_map = $type_map;

            self::$watermark = isset($arr['w']) && $arr['w'] instanceof WatermarkParams
                ? $arr['w']
                : new WatermarkParams();

            $custom = [];
            if (isset($arr['c']) && is_array($arr['c'])) {
                foreach ($arr['c'] as $key => $value) {
                    if (is_string($key) && is_numeric($value)) {
                        $custom[$key] = (int) $value;
                    }
                }
            }
            self::$custom = $custom;

            if (isset($arr['q']) && is_numeric($arr['q'])) {
                self::$quality = (int) $arr['q'];
            }
        } else {
            self::$watermark = new WatermarkParams();
            self::$type_map = self::get_enabled_default_sizes();
            self::save(false);
        }

        $disabled_raw = safe_unserialize(self::get_disabled_type_map());
        // get_disabled_type_map() persists its map as serialize()d
        // DerivativeParams[] too (see its own docblock) -- same
        // untyped-blob situation as above, so filter it the same way.
        $disabled_type_map = [];
        if (is_array($disabled_raw)) {
            foreach ($disabled_raw as $disabled_type => $disabled_params) {
                if (is_string($disabled_type) && $disabled_params instanceof DerivativeParams) {
                    $disabled_type_map[$disabled_type] = $disabled_params;
                }
            }
        }
        self::$disabled_type_map = $disabled_type_map;
        if (empty(self::$disabled_type_map)) {
            self::$disabled_type_map = self::get_disabled_default_sizes();
            self::save_disabled();
        }

        self::build_maps();
    }

    /**
     * @param WatermarkParams $watermark
     */
    public static function set_watermark($watermark): void
    {
        self::$watermark = $watermark;
    }

    /**
     * @see ImageStdParams::save()
     *
     * @param DerivativeParams[] $map
     */
    public static function set_and_save($map): void
    {
        self::$type_map = $map;
        self::save(false);
        self::build_maps();
    }

    /**
     * Saves the configuration in database.
     */
    public static function save(bool $save_disabled = true): void
    {
        $ser = serialize([
            'd' => self::$type_map,
            'q' => self::$quality,
            'w' => self::$watermark,
            'c' => self::$custom,
        ]);
        conf_update_param('derivatives', addslashes($ser));

        if ($save_disabled) {
            self::save_disabled();
        }
    }

    /**
     * Saves the disabled configuration in database.
     */
    public static function save_disabled(): void
    {
        if (count(self::$disabled_type_map) > 0) {
            $disabled = addslashes(serialize(self::$disabled_type_map));
            conf_update_param('disabled_derivatives', $disabled);
        } else {
            $query = 'DELETE FROM ' . CONFIG_TABLE . ' WHERE param = \'disabled_derivatives\'';
            pwg_query($query);
        }
    }

    /**
     * @param DerivativeParams[] $map
     */
    public static function set_and_save_disabled(array $map): void
    {
        self::$disabled_type_map = $map;
        self::save_disabled();
    }

    public static function restore_default(): void
    {
        self::$type_map = self::get_enabled_default_sizes();
        self::$disabled_type_map = self::get_disabled_default_sizes();
        self::save();
        self::build_maps();
    }

    /**
     * @return DerivativeParams[]
     */
    public static function get_default_sizes(): array
    {
        $arr = [
            IMG_SQUARE => new DerivativeParams(SizingParams::square(120)),
            IMG_THUMB => new DerivativeParams(SizingParams::classic(144, 144)),
            IMG_XXSMALL => new DerivativeParams(SizingParams::classic(240, 240)),
            IMG_XSMALL => new DerivativeParams(SizingParams::classic(432, 324)),
            IMG_SMALL => new DerivativeParams(SizingParams::classic(576, 432)),
            IMG_MEDIUM => new DerivativeParams(SizingParams::classic(792, 594)),
            IMG_LARGE => new DerivativeParams(SizingParams::classic(1008, 756)),
            IMG_XLARGE => new DerivativeParams(SizingParams::classic(1224, 918)),
            IMG_XXLARGE => new DerivativeParams(SizingParams::classic(1656, 1242)),
            IMG_3XLARGE => new DerivativeParams(SizingParams::classic(2232, 1674)),
            IMG_4XLARGE => new DerivativeParams(SizingParams::classic(3000, 2250)),
        ];
        $now = time();
        foreach ($arr as $params) {
            $params->last_mod_time = $now;
        }
        return $arr;
    }

    /**
     * @return DerivativeParams[]
     */
    public static function get_enabled_default_sizes(): array
    {
        $default_sizes = self::get_default_sizes();
        foreach (self::$disabled_types_by_default as $type) {
            unset($default_sizes[$type]);
        }
        return $default_sizes;
    }

    /**
     * @return DerivativeParams[]
     */
    public static function get_disabled_default_sizes(): array
    {
        $all = self::get_default_sizes();
        $disabled_sizes = array_intersect_key($all, array_flip(self::$disabled_types_by_default));
        return $disabled_sizes;
    }

    /**
     * Compute 'apply_watermark'
     *
     * @param DerivativeParams $params
     */
    public static function apply_global($params): void
    {
        $params->use_watermark = ! empty(self::$watermark->file) &&
            (self::$watermark->min_size[0] <= $params->sizing->ideal_size[0]
            or self::$watermark->min_size[1] <= $params->sizing->ideal_size[1]);
    }

    /**
     * Build 'type_map', 'all_type_map' and 'undefined_type_map'.
     */
    private static function build_maps(): void
    {
        foreach (self::$type_map as $type => $params) {
            $params->type = $type;
            self::apply_global($params);
        }
        self::$all_type_map = self::$type_map;

        for ($i = 0; $i < count(self::$all_types); $i++) {
            $tocheck = self::$all_types[$i];
            if (! isset(self::$type_map[$tocheck])) {
                for ($j = $i - 1; $j >= 0; $j--) {
                    $target = self::$all_types[$j];
                    if (isset(self::$type_map[$target])) {
                        self::$all_type_map[$tocheck] = self::$type_map[$target];
                        self::$undefined_type_map[$tocheck] = $target;
                        break;
                    }
                }
            }
        }
    }
}
