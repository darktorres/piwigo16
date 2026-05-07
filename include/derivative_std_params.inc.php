<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\SizingParams;
use Piwigo\Db\Tables;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package Derivatives
 */


define('IMG_SQUARE', DerivativeSize::Square->value);
define('IMG_THUMB', DerivativeSize::Thumb->value);
define('IMG_XXSMALL', DerivativeSize::TwoSmall->value);
define('IMG_XSMALL', DerivativeSize::XSmall->value);
define('IMG_SMALL', DerivativeSize::Small->value);
define('IMG_MEDIUM', DerivativeSize::Medium->value);
define('IMG_LARGE', DerivativeSize::Large->value);
define('IMG_XLARGE', DerivativeSize::XLarge->value);
define('IMG_XXLARGE', DerivativeSize::TwoXLarge->value);
define('IMG_3XLARGE', DerivativeSize::ThreeXLarge->value);
define('IMG_4XLARGE', DerivativeSize::FourXLarge->value);
define('IMG_CUSTOM', DerivativeSize::Custom->value);


/**
 * Container for watermark configuration.
 */
final class WatermarkParams
{
    /** @var string */
    public $file = '';
    /** @var int[] */
    public $min_size = [500,500];
    /** @var int */
    public $xpos = 50;
    /** @var int */
    public $ypos = 50;
    /** @var int */
    public $xrepeat = 0;
    /** @var int */
    public $yrepeat = 0;
    /** @var int */
    public $opacity = 100;
}


/**
 * Container for standard derivatives parameters.
 */
final class ImageStdParams
{
    /** @var string[] */
    private static array $all_types = [
      IMG_SQUARE, IMG_THUMB, IMG_XXSMALL, IMG_XSMALL, IMG_SMALL,
      IMG_MEDIUM, IMG_LARGE, IMG_XLARGE, IMG_XXLARGE, IMG_3XLARGE, IMG_4XLARGE,
      ];
    /** @var string[] */
    private static array $disabled_types_by_default = [IMG_3XLARGE, IMG_4XLARGE];
    /** @var DerivativeParams[] */
    private static $all_type_map = [];
    /** @var DerivativeParams[] */
    private static $type_map = [];
    /** @var DerivativeParams[] */
    private static array $disabled_type_map = [];
    /** @var array<string, DerivativeParams|string> */
    private static $undefined_type_map = [];
    /** @var \Piwigo\Image\WatermarkParams */
    private static $watermark;
    /** @var array<mixed> */
    public static array $custom = [];
    /** @var int */
    public static $quality = 95;

    /**
     * @return string[]
     */
    public static function getAllTypes(): array
    {
        return self::$all_types;
    }

    /**
     * @return DerivativeParams[]
     */
    public static function getAllTypeMap()
    {
        return self::$all_type_map;
    }

    /**
     * @return DerivativeParams[]
     */
    public static function getDefinedTypeMap()
    {
        return self::$type_map;
    }

    /**
     * @return DerivativeParams[]|string
     */
    public static function getDisabledTypeMap(): array|string
    {
        if (count(self::$disabled_type_map)) {
            return self::$disabled_type_map;
        }
        return Config::disabledDerivatives() ?? '';
    }

    /**
     * @return array<string, DerivativeParams|string>
     */
    public static function getUndefinedTypeMap()
    {
        return self::$undefined_type_map;
    }

    public static function getByType(string $type): DerivativeParams
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
    public static function getCustom($w, $h, $crop = 0, $minw = null, $minh = null): DerivativeParams
    {
        $params = new DerivativeParams(new SizingParams([$w,$h], $crop, ($minw !== null && $minh !== null) ? [$minw,$minh] : null));
        self::applyGlobal($params);

        $key = [];
        $params->addUrlTokens($key);
        $key = implode('_', $key);
        if ((self::$custom[$key] ?? 0) < time() - 24 * 3600) {
            self::$custom[$key] = time();
            self::save();
        }
        return $params;
    }

    /**
     * @return \Piwigo\Image\WatermarkParams
     */
    public static function getWatermark()
    {
        return self::$watermark;
    }

    /**
     * Loads derivative configuration from database or initializes it.
     */
    public static function loadFromDb(): void
    {
        $derivatives = Config::derivatives();
        $arr = safe_unserialize(is_string($derivatives) ? $derivatives : '');
        if ($arr !== []) {
            $typeMapRaw = is_array($arr['d'] ?? null) ? $arr['d'] : [];
            $typeMap = [];
            foreach ($typeMapRaw as $k => $v) {
                if ($v instanceof DerivativeParams) {
                    $typeMap[$k] = $v;
                }
            }
            self::$type_map = $typeMap;
            $w = $arr['w'] ?? null;
            self::$watermark = $w instanceof \Piwigo\Image\WatermarkParams ? $w : new \Piwigo\Image\WatermarkParams();
            $c = $arr['c'] ?? null;
            self::$custom = is_array($c) ? $c : [];
            $q = $arr['q'] ?? null;
            if (is_int($q)) {
                self::$quality = $q;
            }
        } else {
            self::$watermark = new \Piwigo\Image\WatermarkParams();
            self::$type_map = self::getEnabledDefaultSizes();
            self::save(false);
        }

        $rawDisabled = safe_unserialize(self::getDisabledTypeMap());
        $filteredDisabled = [];
        foreach ($rawDisabled as $k => $v) {
            if ($v instanceof DerivativeParams) {
                $filteredDisabled[$k] = $v;
            }
        }
        self::$disabled_type_map = $filteredDisabled;
        if (empty(self::$disabled_type_map)) {
            self::$disabled_type_map = self::getDisabledDefaultSizes();
            self::saveDisabled();
        }

        self::buildMaps();
    }

    /**
     * @param \Piwigo\Image\WatermarkParams $watermark
     */
    public static function setWatermark($watermark): void
    {
        self::$watermark = $watermark;
    }

    /**
     * @see ImageStdParams::save()
     *
     * @param DerivativeParams[] $map
     */
    public static function setAndSave($map): void
    {
        self::$type_map = $map;
        self::save(false);
        self::buildMaps();
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
            self::saveDisabled();
        }
    }

    /**
     * Saves the disabled configuration in database.
     */
    public static function saveDisabled(): void
    {
        if (count(self::$disabled_type_map) > 0) {
            $disabled = addslashes(serialize(self::$disabled_type_map));
            conf_update_param('disabled_derivatives', $disabled);
        } else {
            ServiceLocator::get(Connection::class)->executeStatement(
                'DELETE FROM ' . Tables::config() . " WHERE param = 'disabled_derivatives'"
            );
        }
    }

    /** @param DerivativeParams[] $map */
    public static function setAndSaveDisabled(array $map): void
    {
        self::$disabled_type_map = $map;
        self::saveDisabled();
    }

    public static function restoreDefault(): void
    {
        self::$type_map = self::getEnabledDefaultSizes();
        self::$disabled_type_map = self::getDisabledDefaultSizes();
        self::save();
        self::buildMaps();
    }

    /**
     * @return DerivativeParams[]
     */
    public static function getDefaultSizes(): array
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
    public static function getEnabledDefaultSizes(): array
    {
        $default_sizes = self::getDefaultSizes();
        foreach (self::$disabled_types_by_default as $type) {
            unset($default_sizes[$type]);
        }
        return $default_sizes;
    }

    /**
     * @return DerivativeParams[]
     */
    public static function getDisabledDefaultSizes(): array
    {
        $all = self::getDefaultSizes();
        $disabled_sizes = array_intersect_key($all, array_flip(self::$disabled_types_by_default));
        return $disabled_sizes;
    }

    /**
     * Compute 'apply_watermark'
     *
     * @param DerivativeParams $params
     */
    public static function applyGlobal($params): void
    {
        $params->use_watermark = !empty(self::$watermark->file) &&
            (self::$watermark->min_size[0] <= $params->sizing->ideal_size[0]
            or self::$watermark->min_size[1] <= $params->sizing->ideal_size[1]);
    }

    /**
     * Build 'type_map', 'all_type_map' and 'undefined_type_map'.
     */
    private static function buildMaps(): void
    {
        foreach (self::$type_map as $type => $params) {
            $params->type = $type;
            self::applyGlobal($params);
        }
        self::$all_type_map = self::$type_map;

        for ($i = 0; $i < count(self::$all_types); $i++) {
            $tocheck = self::$all_types[$i];
            if (!isset(self::$type_map[$tocheck])) {
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
