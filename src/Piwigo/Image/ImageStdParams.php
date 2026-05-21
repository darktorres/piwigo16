<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Core\Kernel;

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
    /** @var array<string, DerivativeParams> */
    private static array $all_type_map = [];
    /** @var array<string, DerivativeParams> */
    private static array $type_map = [];
    /** @var array<string, DerivativeParams> */
    private static array $disabled_type_map = [];
    /** @var array<string, string> maps undefined type names to defined type names */
    private static array $undefined_type_map = [];
    private static WatermarkParams $watermark;
    /** @var array<string, int> */
    public static array $custom = [];
    public static int $quality = 95;

    /**
     * @return string[]
     */
    public static function getAllTypes(): array
    {
        return self::$all_types;
    }

    /**
     * @return array<string, DerivativeParams>
     */
    public static function getAllTypeMap()
    {
        return self::$all_type_map;
    }

    /**
     * @return array<string, DerivativeParams>
     */
    public static function getDefinedTypeMap(): array
    {
        return self::$type_map;
    }

    /**
     * @return array<string, DerivativeParams>
     */
    public static function getDisabledTypeMap(): array
    {
        return self::$disabled_type_map;
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
        $params = new DerivativeParams(new SizingParams([$w,$h], $crop, ($minw !== null && $minh !== null) ? [$minw,$minh] : [0, 0]));
        self::applyGlobal($params);

        $keyTokens = [];
        $params->addUrlTokens($keyTokens);
        $key = implode('_', $keyTokens);
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
     * Loads derivative configuration from database, or seeds defaults
     * on first run. Reads through the dedicated
     * piwigo_derivative_size + piwigo_derivative_settings tables.
     */
    public static function loadFromDb(): void
    {
        if (!Kernel::isBooted()) {
            return;
        }

        $sizeRepo     = Kernel::service(DerivativeSizeRepository::class);
        $settingsRepo = Kernel::service(DerivativeSettingsRepository::class);

        $settings        = $settingsRepo->load();
        self::$watermark = $settings->watermark;
        self::$custom    = $settings->custom;
        self::$quality   = $settings->quality;

        if ($sizeRepo->hasAny()) {
            $rows = $sizeRepo->loadAll();
            self::$type_map          = $rows->enabled;
            self::$disabled_type_map = $rows->disabled;
        } else {
            self::$type_map          = self::getEnabledDefaultSizes();
            self::$disabled_type_map = self::getDisabledDefaultSizes();
            self::save();
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
     * @param array<string, DerivativeParams> $map
     */
    public static function setAndSave($map): void
    {
        self::$type_map = $map;
        self::save();
        self::buildMaps();
    }

    /**
     * Persist the full derivative-config state (sizes + settings)
     * back to the dedicated tables.
     */
    public static function save(): void
    {
        if (!Kernel::isBooted()) {
            return;
        }
        Kernel::service(DerivativeSizeRepository::class)
            ->replaceAll(self::$type_map, self::$disabled_type_map);
        Kernel::service(DerivativeSettingsRepository::class)
            ->save(self::$quality, self::$watermark, self::$custom);
    }

    /** @param array<string, DerivativeParams> $map */
    public static function setAndSaveDisabled(array $map): void
    {
        self::$disabled_type_map = $map;
        self::save();
    }

    public static function restoreDefault(): void
    {
        self::$type_map = self::getEnabledDefaultSizes();
        self::$disabled_type_map = self::getDisabledDefaultSizes();
        self::save();
        self::buildMaps();
    }

    /**
     * @return array<string, DerivativeParams>
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
     * @return array<string, DerivativeParams>
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
     * @return array<string, DerivativeParams>
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
