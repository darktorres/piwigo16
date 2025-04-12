<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Container for standard derivatives parameters.
 */
final class ImageStdParams
{
    public static array $custom = [];

    public static int $quality = 95;

    /**
     * @var array<string>
     */
    private static array $all_types = [
        derivative_std_params::IMG_SQUARE, derivative_std_params::IMG_THUMB, derivative_std_params::IMG_XXSMALL, derivative_std_params::IMG_XSMALL, derivative_std_params::IMG_SMALL,
        derivative_std_params::IMG_MEDIUM, derivative_std_params::IMG_LARGE, derivative_std_params::IMG_XLARGE, derivative_std_params::IMG_XXLARGE,
    ];

    /**
     * @var array<DerivativeParams>
     */
    private static array $all_type_map = [];

    /**
     * @var array<DerivativeParams>
     */
    private static array $type_map = [];

    /**
     * @var array<DerivativeParams>
     */
    private static array $undefined_type_map = [];

    private static WatermarkParams $watermark;

    /**
     * @return array<string>
     */
    public static function get_all_types(): array
    {
        return self::$all_types;
    }

    /**
     * @return DerivativeParams[]
     */
    public static function get_all_type_map(): array
    {
        return self::$all_type_map;
    }

    /**
     * @return DerivativeParams[]
     */
    public static function get_defined_type_map(): array
    {
        return self::$type_map;
    }

    /**
     * @return DerivativeParams[]
     */
    public static function get_undefined_type_map(): array
    {
        return self::$undefined_type_map;
    }

    public static function get_by_type(
        string|DerivativeParams $type
    ): DerivativeParams {
        return self::$all_type_map[$type];
    }

    public static function get_custom(
        string|int $w,
        string|int $h,
        float $crop = 0,
        string|int|null $minw = null,
        string|int|null $minh = null
    ): DerivativeParams {
        $params = new DerivativeParams(new SizingParams([$w, $h], $crop, [$minw, $minh]));
        self::apply_global($params);

        $key = [];
        $params->add_url_tokens($key);
        $key = implode('_', $key);

        if ((self::$custom[$key] ?? null) < time() - 24 * 3600) {
            self::$custom[$key] = time();
            self::save();
        }

        return $params;
    }

    public static function get_watermark(): WatermarkParams
    {
        return self::$watermark;
    }

    /**
     * Loads derivative configuration from database or initializes it.
     */
    public static function load_from_db(): void
    {
        global $conf;
        $arr = $conf->derivatives ?? false;

        if ($arr !== false) {
            self::$type_map = $arr['d'];
            self::$watermark = $arr['w'];

            if (! self::$watermark) {
                self::$watermark = new WatermarkParams();
            }

            self::$custom = $arr['c'];

            if (! self::$custom) {
                self::$custom = [];
            }

            if (isset($arr['q'])) {
                self::$quality = $arr['q'];
            }
        } else {
            self::$watermark = new WatermarkParams();
            self::$type_map = self::get_default_sizes();
            self::save();
        }

        self::build_maps();
    }

    public static function set_watermark(
        WatermarkParams $watermark
    ): void {
        self::$watermark = $watermark;
    }

    /**
     * @see ImageStdParams::save()
     *
     * @param DerivativeParams[] $map
     */
    public static function set_and_save(
        array $map
    ): void {
        self::$type_map = $map;
        self::save();
        self::build_maps();
    }

    /**
     * Saves the configuration in database.
     */
    public static function save(): void
    {
        $ser = serialize([
            'd' => self::$type_map,
            'q' => self::$quality,
            'w' => self::$watermark,
            'c' => self::$custom,
        ]);
        functions::conf_update_param('derivatives', addslashes($ser));
    }

    /**
     * @return DerivativeParams[]
     */
    public static function get_default_sizes(): array
    {
        $arr = [
            derivative_std_params::IMG_SQUARE => new DerivativeParams(SizingParams::square(120)),
            derivative_std_params::IMG_THUMB => new DerivativeParams(SizingParams::classic(144, 144)),
            derivative_std_params::IMG_XXSMALL => new DerivativeParams(SizingParams::classic(240, 240)),
            derivative_std_params::IMG_XSMALL => new DerivativeParams(SizingParams::classic(432, 324)),
            derivative_std_params::IMG_SMALL => new DerivativeParams(SizingParams::classic(576, 432)),
            derivative_std_params::IMG_MEDIUM => new DerivativeParams(SizingParams::classic(792, 594)),
            derivative_std_params::IMG_LARGE => new DerivativeParams(SizingParams::classic(1008, 756)),
            derivative_std_params::IMG_XLARGE => new DerivativeParams(SizingParams::classic(1224, 918)),
            derivative_std_params::IMG_XXLARGE => new DerivativeParams(SizingParams::classic(1656, 1242)),
        ];
        $now = time();

        foreach ($arr as $params) {
            $params->last_mod_time = $now;
        }

        return $arr;
    }

    /**
     * Compute 'apply_watermark'
     */
    public static function apply_global(
        DerivativeParams $params
    ): void {
        $params->use_watermark = ! empty(self::$watermark->file) &&
            (self::$watermark->min_size[0] <= $params->sizing->ideal_size[0] ||
             self::$watermark->min_size[1] <= $params->sizing->ideal_size[1]);
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
