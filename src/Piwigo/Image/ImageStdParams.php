<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Image;

/**
 * Container for standard derivatives parameters.
 *
 * P23 batch 8c: the 12 IMG_* derivative-type identifiers, formerly
 * `define()`d in `include/derivative_std_params.inc.php`, are real class
 * constants here instead -- SEC-60 forbids `define()` inside `src/Piwigo/`,
 * and this class already owned the canonical mapping from each identifier
 * to its real `DerivativeParams`. 118 real call sites across 37 files
 * retargeted in the same pass (all L2a/L2b/L4 callers -- Image/Category/
 * Calendar/Admin/Controller -- none below L2a, so no deptrac layering
 * concern, unlike finding 8's cases).
 *
 * Persistence: `load_from_db()`/`save()`/`save_disabled()`/`set_and_save()`/
 * `set_and_save_disabled()`/`restore_default()` go through
 * {@see DerivativeSettingsRepository} (the single `derivative_settings` row
 * -- quality/watermark/the on-demand custom-size throttle cache) and
 * {@see DerivativeSizeRepository} (one `derivative_size` row per named
 * size, `enabled` column replacing the former separate enabled/disabled
 * config keys) -- reached via a fresh `EntityManagerFactory::build(DbConnection::build())`
 * per call, same "static utility, throwaway EM, no container dependency"
 * shape as `Piwigo\Caddie\CaddieService`, not the container-shared EM
 * (see `settingsRepository()`/`sizeRepository()`'s own docblock for why).
 * Retired the former `derivatives`/`disabled_derivatives` piwigo_config
 * keys (raw PHP `serialize()`, the last non-JSON exception in that table)
 * entirely -- every field below round-trips through real typed columns
 * now. Controller\Admin\ConfigurationSubController still calls all 5
 * public write methods directly from the admin Configuration page's save
 * handler; their signatures are unchanged by this persistence swap.
 */
final class ImageStdParams
{
    public const string SQUARE = 'square';

    public const string THUMB = 'thumb';

    public const string XXSMALL = '2small';

    public const string XSMALL = 'xsmall';

    public const string SMALL = 'small';

    public const string MEDIUM = 'medium';

    public const string LARGE = 'large';

    public const string XLARGE = 'xlarge';

    public const string XXLARGE = 'xxlarge';

    public const string THREE_XLARGE = '3xlarge';

    public const string FOUR_XLARGE = '4xlarge';

    public const string CUSTOM = 'custom';

    /**
     * @var string[]
     */
    private static array $all_types = [
        self::SQUARE, self::THUMB, self::XXSMALL, self::XSMALL, self::SMALL,
        self::MEDIUM, self::LARGE, self::XLARGE, self::XXLARGE, self::THREE_XLARGE, self::FOUR_XLARGE,
    ];

    /**
     * @var string[]
     */
    private static array $disabled_types_by_default = [self::THREE_XLARGE, self::FOUR_XLARGE];

    /**
     * @var DerivativeParams[]
     */
    private static $all_type_map = [];

    /**
     * @var DerivativeParams[]
     */
    private static $type_map = [];

    /**
     * @var array<string, DerivativeParams>
     */
    private static array $disabled_type_map = [];

    /**
     * @var string[]
     */
    private static $undefined_type_map = [];

    /**
     * Genuinely nullable, not just defensively typed: this property has no
     * default value, and is only ever populated by set_watermark()/
     * load_from_db() -- a caller reaching save()/apply_global() before
     * either of those ran (confirmed live, a real Integration test hits
     * this) sees a real null here, not just a theoretical one.
     *
     * @var ?WatermarkParams
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
     * @return array<string, DerivativeParams>
     */
    public static function get_disabled_type_map(): array
    {
        return self::$disabled_type_map;
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
     * @param ?int $minw
     * @param ?int $minh
     */
    public static function get_custom($w, $h, $crop = 0, $minw = null, $minh = null): DerivativeParams
    {
        // $minw/$minh are always both null or both set together (see the
        // sole caller, Template::func_define_derivative()).
        $min_size = $minw !== null && $minh !== null ? [$minw, $minh] : null;
        $params = new DerivativeParams(new SizingParams([$w, $h], $crop, $min_size));
        self::apply_global($params);

        $key = [];
        $params->add_url_tokens($key);
        $key = implode('_', $key);
        if ((self::$custom[$key] ?? 0) < time() - 24 * 3600) {
            self::$custom[$key] = time();
            self::save();
        }
        return $params;
    }

    /**
     * Lazily defaults $watermark the same way apply_global() does -- a
     * caller reaching this before set_watermark()/load_from_db() ever ran
     * gets a sensible fresh WatermarkParams(), not null, keeping this
     * method's own return type (and every real caller's expectations)
     * unchanged.
     *
     * @return WatermarkParams
     */
    public static function get_watermark()
    {
        self::$watermark ??= new WatermarkParams();

        return self::$watermark;
    }

    /**
     * Loads derivative configuration from database or initializes it.
     */
    public static function load_from_db(): void
    {
        $settings = self::settingsRepository()->load();

        if ($settings !== null) {
            self::$quality = $settings->defaultQuality;
            self::$watermark = self::watermarkFromJson($settings->watermarkJson);
            self::$custom = self::customFromJson($settings->customJson);
            self::$type_map = self::sizesFromEntities(self::sizeRepository()->findAllEnabled());
        } else {
            self::$watermark = new WatermarkParams();
            self::$type_map = self::get_enabled_default_sizes();
            self::save(false);
        }

        self::$disabled_type_map = self::sizesFromEntities(self::sizeRepository()->findAllDisabled());
        if (self::$disabled_type_map === []) {
            self::$disabled_type_map = self::get_disabled_default_sizes();
            self::save_disabled();
        }

        self::build_maps();
    }

    /**
     * Fresh, throwaway EntityManager per call (like Piwigo\Caddie\CaddieService's
     * own `new CaddieRepository(DbConnection::build())`) rather than
     * Bootstrap\InfrastructureAccessor's container-shared one -- unlike a
     * raw bulk-write onto a table other repositories concurrently read in
     * the same request (InfrastructureAccessor's own stated reason to
     * exist), nothing else in a request reads/writes derivative_settings/
     * derivative_size alongside this class, so there's no identity-map
     * coherency to preserve, and avoiding the container dependency means
     * load_from_db() (called every request, very early in
     * RequestBootstrap) doesn't require Kernel::boot() to have run first.
     */
    private static function settingsRepository(): DerivativeSettingsRepository
    {
        // Unlike Bootstrap\*Accessor's own container-resolved services (a
        // plain PHP-DI binding, not statically provable), getRepository()'s
        // return type here is a real Doctrine generic tied to
        // DerivativeSettingsEntity's own #[ORM\Entity(repositoryClass:...)]
        // attribute -- PHPStan already proves this exact, no runtime guard
        // needed.
        return \Piwigo\Db\EntityManagerFactory::build(\Piwigo\Db\DbConnection::build())->getRepository(DerivativeSettingsEntity::class);
    }

    private static function sizeRepository(): DerivativeSizeRepository
    {
        return \Piwigo\Db\EntityManagerFactory::build(\Piwigo\Db\DbConnection::build())->getRepository(DerivativeSizeEntity::class);
    }

    /**
     * @param array<string, mixed> $json
     */
    private static function watermarkFromJson(array $json): WatermarkParams
    {
        $watermark = new WatermarkParams();

        $file = $json['file'] ?? null;
        $watermark->file = is_string($file) ? $file : $watermark->file;

        $minSize = $json['min_size'] ?? null;
        if (is_array($minSize) && isset($minSize[0], $minSize[1]) && is_numeric($minSize[0]) && is_numeric($minSize[1])) {
            $watermark->min_size = [(int) $minSize[0], (int) $minSize[1]];
        }

        $xpos = $json['xpos'] ?? null;
        $watermark->xpos = is_numeric($xpos) ? (int) $xpos : $watermark->xpos;

        $ypos = $json['ypos'] ?? null;
        $watermark->ypos = is_numeric($ypos) ? (int) $ypos : $watermark->ypos;

        $xrepeat = $json['xrepeat'] ?? null;
        $watermark->xrepeat = is_numeric($xrepeat) ? (int) $xrepeat : $watermark->xrepeat;

        $yrepeat = $json['yrepeat'] ?? null;
        $watermark->yrepeat = is_numeric($yrepeat) ? (int) $yrepeat : $watermark->yrepeat;

        $opacity = $json['opacity'] ?? null;
        $watermark->opacity = is_numeric($opacity) ? (int) $opacity : $watermark->opacity;

        return $watermark;
    }

    /**
     * @return array<string, mixed>
     */
    private static function watermarkToJson(WatermarkParams $watermark): array
    {
        return [
            'file' => $watermark->file,
            'min_size' => $watermark->min_size,
            'xpos' => $watermark->xpos,
            'ypos' => $watermark->ypos,
            'xrepeat' => $watermark->xrepeat,
            'yrepeat' => $watermark->yrepeat,
            'opacity' => $watermark->opacity,
        ];
    }

    /**
     * Doctrine's `json` type only guarantees the column decodes to an
     * array -- it doesn't validate the shape stored inside it (a
     * hand-edited row, or a future format change, could leave a
     * non-numeric value under a key) -- so this narrows every entry the
     * same way load_from_db() always has for DB-sourced data, rather than
     * trusting customJson blindly.
     *
     * @param array<mixed> $json
     * @return array<string, int>
     */
    private static function customFromJson(array $json): array
    {
        $custom = [];
        foreach ($json as $key => $value) {
            if (is_string($key) && is_numeric($value)) {
                $custom[$key] = (int) $value;
            }
        }
        return $custom;
    }

    /**
     * @param list<DerivativeSizeEntity> $entities
     * @return array<string, DerivativeParams>
     */
    private static function sizesFromEntities(array $entities): array
    {
        $map = [];
        foreach ($entities as $entity) {
            $minSize = $entity->minWidth !== null && $entity->minHeight !== null
                ? [$entity->minWidth, $entity->minHeight]
                : null;

            $params = new DerivativeParams(new SizingParams(
                [$entity->maxWidth, $entity->maxHeight],
                (float) $entity->maxCrop,
                $minSize,
            ));
            $params->sharpen = (float) $entity->sharpen;
            $params->last_mod_time = $entity->lastModTime;

            $map[$entity->name] = $params;
        }
        return $map;
    }

    private static function sizeEntityFromParams(string $name, bool $enabled, DerivativeParams $params): DerivativeSizeEntity
    {
        $minSize = $params->sizing->min_size;

        return new DerivativeSizeEntity(
            $name,
            $enabled,
            $params->sizing->ideal_size[0],
            $params->sizing->ideal_size[1],
            self::decimalToString((float) $params->sizing->max_crop),
            $minSize !== null ? $minSize[0] : null,
            $minSize !== null ? $minSize[1] : null,
            self::decimalToString($params->sharpen),
            $params->last_mod_time,
        );
    }

    private static function decimalToString(float $value): string
    {
        return number_format($value, 4, '.', '');
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
        // $watermark can still be null here if save() is reached before
        // load_from_db()/set_watermark() ever ran (e.g. a caller sets
        // $type_map directly then calls set_and_save()) -- the original
        // serialize()-blob code tolerated this silently (a null 'w' entry,
        // resolved to a fresh WatermarkParams() on the next load_from_db()
        // via its own instanceof check); this preserves that same
        // tolerance now that watermarkToJson() needs a real object.
        self::settingsRepository()->save(
            self::$quality,
            self::watermarkToJson(self::$watermark ?? new WatermarkParams()),
            self::$custom,
        );

        $rows = [];
        foreach (self::$type_map as $type => $params) {
            $rows[] = self::sizeEntityFromParams($type, true, $params);
        }
        self::sizeRepository()->syncEnabled($rows);

        if ($save_disabled) {
            self::save_disabled();
        }
    }

    /**
     * Saves the disabled configuration in database.
     */
    public static function save_disabled(): void
    {
        $rows = [];
        foreach (self::$disabled_type_map as $type => $params) {
            $rows[] = self::sizeEntityFromParams($type, false, $params);
        }
        self::sizeRepository()->syncDisabled($rows);
    }

    /**
     * @param array<string, DerivativeParams> $map
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
     * @return array<string, DerivativeParams>
     */
    public static function get_default_sizes(): array
    {
        $arr = [
            self::SQUARE => new DerivativeParams(SizingParams::square(120)),
            self::THUMB => new DerivativeParams(SizingParams::classic(144, 144)),
            self::XXSMALL => new DerivativeParams(SizingParams::classic(240, 240)),
            self::XSMALL => new DerivativeParams(SizingParams::classic(432, 324)),
            self::SMALL => new DerivativeParams(SizingParams::classic(576, 432)),
            self::MEDIUM => new DerivativeParams(SizingParams::classic(792, 594)),
            self::LARGE => new DerivativeParams(SizingParams::classic(1008, 756)),
            self::XLARGE => new DerivativeParams(SizingParams::classic(1224, 918)),
            self::XXLARGE => new DerivativeParams(SizingParams::classic(1656, 1242)),
            self::THREE_XLARGE => new DerivativeParams(SizingParams::classic(2232, 1674)),
            self::FOUR_XLARGE => new DerivativeParams(SizingParams::classic(3000, 2250)),
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
    public static function get_enabled_default_sizes(): array
    {
        $default_sizes = self::get_default_sizes();
        foreach (self::$disabled_types_by_default as $type) {
            unset($default_sizes[$type]);
        }
        return $default_sizes;
    }

    /**
     * @return array<string, DerivativeParams>
     */
    public static function get_disabled_default_sizes(): array
    {
        $all = self::get_default_sizes();
        $disabled_sizes = array_intersect_key($all, array_flip(self::$disabled_types_by_default));
        return $disabled_sizes;
    }

    /**
     * Compute 'use_watermark'
     *
     * Pre-existing fragility, not introduced by the derivative_settings/
     * derivative_size migration: $watermark is only ever populated by
     * set_watermark()/load_from_db(), so a caller reaching build_maps()
     * (via set_and_save()/restore_default()/get_custom()) before either of
     * those ran would hit a read on null here, in the original
     * serialize()-blob code too. Lazily defaults it instead of crashing --
     * self-healing, matching the same null-tolerance save() already needs.
     *
     * @param DerivativeParams $params
     */
    public static function apply_global($params): void
    {
        self::$watermark ??= new WatermarkParams();

        $params->use_watermark = self::$watermark->file !== '' &&
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
