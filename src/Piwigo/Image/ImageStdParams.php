<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Tables;

/**
 * Container for standard derivatives parameters.
 */
final class ImageStdParams
{
    /** @var string[] */
    private static array $all_types = [
      DerivativeSize::Square->value, DerivativeSize::Thumb->value, DerivativeSize::TwoSmall->value, DerivativeSize::XSmall->value, DerivativeSize::Small->value,
      DerivativeSize::Medium->value, DerivativeSize::Large->value, DerivativeSize::XLarge->value, DerivativeSize::TwoXLarge->value, DerivativeSize::ThreeXLarge->value, DerivativeSize::FourXLarge->value,
      ];
    /** @var string[] */
    private static array $disabled_types_by_default = [DerivativeSize::ThreeXLarge->value, DerivativeSize::FourXLarge->value];
    /** @var DerivativeParams[] */
    private static $all_type_map = [];
    /** @var DerivativeParams[] */
    private static $type_map = [];
    /** @var DerivativeParams[] */
    private static array $disabled_type_map = [];
    /** @var string[] maps undefined type names to defined type names */
    private static $undefined_type_map = [];
    /** @var WatermarkParams */
    private static $watermark;
    /** @var array */
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
     * @return string[] maps undefined type names to defined type names
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
     * @return WatermarkParams
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
        $arr = StringUtil::safeUnserialize(is_string($derivatives) ? $derivatives : '');
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
            self::$watermark = $w instanceof WatermarkParams ? $w : new WatermarkParams();
            $c = $arr['c'] ?? null;
            self::$custom = is_array($c) ? $c : [];
            $q = $arr['q'] ?? null;
            if (is_int($q)) {
                self::$quality = $q;
            }
        } else {
            self::$watermark = new WatermarkParams();
            self::$type_map = self::getEnabledDefaultSizes();
            self::save(false);
        }

        $rawDisabled = StringUtil::safeUnserialize(self::getDisabledTypeMap());
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
     * @param WatermarkParams $watermark
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
        if (!ServiceLocator::has(ConfigService::class)) {
            return;
        }
        $ser = serialize([
          'd' => self::$type_map,
          'q' => self::$quality,
          'w' => self::$watermark,
          'c' => self::$custom,
          ]);
        ServiceLocator::get(ConfigService::class)->confUpdateParam('derivatives', $ser);

        if ($save_disabled) {
            self::saveDisabled();
        }
    }

    /**
     * Saves the disabled configuration in database.
     */
    public static function saveDisabled(): void
    {
        if (!ServiceLocator::has(ConfigService::class)) {
            return;
        }
        if (count(self::$disabled_type_map) > 0) {
            $disabled = serialize(self::$disabled_type_map);
            ServiceLocator::get(ConfigService::class)->confUpdateParam('disabled_derivatives', $disabled);
        } else {
            ServiceLocator::get(Connection::class)->executeStatement(
                'DELETE FROM ' . Tables::config() . ' WHERE param = \'disabled_derivatives\''
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
          DerivativeSize::Square->value => new DerivativeParams(SizingParams::square(120)),
          DerivativeSize::Thumb->value => new DerivativeParams(SizingParams::classic(144, 144)),
          DerivativeSize::TwoSmall->value => new DerivativeParams(SizingParams::classic(240, 240)),
          DerivativeSize::XSmall->value => new DerivativeParams(SizingParams::classic(432, 324)),
          DerivativeSize::Small->value => new DerivativeParams(SizingParams::classic(576, 432)),
          DerivativeSize::Medium->value => new DerivativeParams(SizingParams::classic(792, 594)),
          DerivativeSize::Large->value => new DerivativeParams(SizingParams::classic(1008, 756)),
          DerivativeSize::XLarge->value => new DerivativeParams(SizingParams::classic(1224, 918)),
          DerivativeSize::TwoXLarge->value => new DerivativeParams(SizingParams::classic(1656, 1242)),
          DerivativeSize::ThreeXLarge->value => new DerivativeParams(SizingParams::classic(2232, 1674)),
          DerivativeSize::FourXLarge->value => new DerivativeParams(SizingParams::classic(3000, 2250)),
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
