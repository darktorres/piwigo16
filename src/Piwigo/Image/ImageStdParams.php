<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Image;

use Piwigo\Caddie\CaddieRepository;
use Piwigo\Db\AdvisorySessionLock;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;

/**
 * Container for standard derivatives parameters.
 *
 * The 12 IMG_* derivative-type identifiers are real class constants here
 * (SEC-60 forbids `define()` inside `src/Piwigo/`); this class owns the
 * canonical mapping from each identifier to its real `DerivativeParams`.
 *
 * Persistence: `loadFromDb()`/`save()`/`saveDisabled()`/`setAndSave()`/
 * `setAndSaveDisabled()`/`restoreDefault()` go through
 * {@see DerivativeSettingsRepository} (the single `derivative_settings` row
 * -- quality/watermark/the on-demand custom-size throttle cache) and
 * {@see DerivativeSizeRepository} (one `derivative_size` row per named
 * size, with an `enabled` column distinguishing enabled from disabled
 * sizes) -- reached via a fresh `EntityManagerFactory::build(DbConnection::build())`
 * per call, same "static utility, throwaway EM, no container dependency"
 * shape as `Piwigo\Caddie\CaddieService`, not the container-shared EM
 * (see `settingsRepository()`/`sizeRepository()`'s own docblock for why).
 * Every field below round-trips through real typed columns.
 * Controller\Admin\ConfigurationSubController calls all 5 public write
 * methods directly from the admin Configuration page's save handler.
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
    private const array ALL_TYPES = [
        self::SQUARE, self::THUMB, self::XXSMALL, self::XSMALL, self::SMALL,
        self::MEDIUM, self::LARGE, self::XLARGE, self::XXLARGE, self::THREE_XLARGE, self::FOUR_XLARGE,
    ];

    /**
     * @var string[]
     */
    private const array DISABLED_TYPES_BY_DEFAULT = [self::THREE_XLARGE, self::FOUR_XLARGE];

    /**
     * @var array<string, DerivativeParams>
     */
    private array $all_type_map = [];

    /**
     * @var array<string, DerivativeParams>
     */
    private array $type_map = [];

    /**
     * @var array<string, DerivativeParams>
     */
    private array $disabled_type_map = [];

    /**
     * Keyed by the IMG_* type the install does not define, valued with the
     * defined type it falls back to -- both from self::ALL_TYPES.
     *
     * @var array<string, string>
     */
    private array $undefined_type_map = [];

    /**
     * Genuinely nullable, not just defensively typed: this property has no
     * default value, and is only ever populated by setWatermark()/
     * loadFromDb() -- a caller reaching save()/applyGlobal() before
     * either of those ran (a real Integration test hits
     * this) sees a real null here, not just a theoretical one.
     */
    private ?WatermarkParams $watermark = null;

    /**
     * @var array<string, int>
     */
    private array $custom = [];

    private int $quality = 95;

    /**
     * @return string[]
     */
    public static function getAllTypes(): array
    {
        return self::ALL_TYPES;
    }

    /**
     * @return array<string, DerivativeParams>
     */
    public function getAllTypeMap(): array
    {
        return $this->all_type_map;
    }

    public function getQuality(): int
    {
        return $this->quality;
    }

    /**
     * Pure in-memory write, same as the former direct `ImageStdParams::$quality
     * = ...` write it replaces -- persistence happens separately via
     * save()/setAndSave(), not automatically here.
     */
    public function setQuality(int $quality): void
    {
        $this->quality = $quality;
    }

    /**
     * The on-demand custom-size throttle cache: custom-size key => last-
     * generated timestamp (getCustom()'s own 24h regeneration guard).
     * Distinct from getCustom($w, $h, ...) above, which computes a
     * DerivativeParams for a given custom size -- this returns the raw
     * timestamp map itself, e.g. for an admin page listing every
     * already-generated custom size.
     *
     * @return array<string, int>
     */
    public function getCustomTimestamps(): array
    {
        return $this->custom;
    }

    /**
     * Pure in-memory write, same as the former direct
     * `unset(ImageStdParams::$custom[$key])` write it replaces --
     * persistence happens separately via save()/setAndSave(), not
     * automatically here.
     */
    public function unsetCustomTimestamp(string $key): void
    {
        unset($this->custom[$key]);
    }

    /**
     * @return array<string, DerivativeParams>
     */
    public function getDefinedTypeMap(): array
    {
        return $this->type_map;
    }

    /**
     * @return array<string, DerivativeParams>
     */
    public function getDisabledTypeMap(): array
    {
        return $this->disabled_type_map;
    }

    /**
     * @return array<string, string>
     */
    public function getUndefinedTypeMap(): array
    {
        return $this->undefined_type_map;
    }

    public function getByType(string $type): DerivativeParams
    {
        return $this->all_type_map[$type];
    }

    public function getCustom(int $w, int $h, float|int $crop = 0, ?int $minw = null, ?int $minh = null): DerivativeParams
    {
        // $minw/$minh are always both null or both set together (see the
        // sole caller, Template::funcDefineDerivative()).
        $min_size = $minw !== null && $minh !== null ? new Dimensions($minw, $minh) : null;
        $params = new DerivativeParams(new SizingParams(new Dimensions($w, $h), $crop, $min_size));
        $this->applyGlobal($params);

        $key = [];
        $params->addUrlTokens($key);
        // Psalm keeps enforcing addUrlTokens()'s by-ref array<int,
        // int|string> constraint on $key even after the call returns and
        // the reference is no longer live -- reassigning it to the joined
        // string here is genuinely safe.
        $key = implode('_', $key);
        if (($this->custom[$key] ?? 0) < time() - 24 * 3600) {
            $this->custom[$key] = time();
            $this->save();
        }
        return $params;
    }

    /**
     * Lazily defaults $watermark the same way applyGlobal() does -- a
     * caller reaching this before setWatermark()/loadFromDb() ever ran
     * gets a sensible fresh WatermarkParams(), not null, keeping this
     * method's own return type (and every real caller's expectations)
     * unchanged.
     */
    public function getWatermark(): WatermarkParams
    {
        $this->watermark ??= new WatermarkParams();

        return $this->watermark;
    }

    /**
     * Loads derivative configuration from database or initializes it.
     */
    public function loadFromDb(): void
    {
        $settings = self::settingsRepository()->load();

        if ($settings instanceof DerivativeSettingsEntity) {
            $this->quality = $settings->defaultQuality;
            $this->watermark = self::watermarkFromJson($settings->watermarkJson);
            $this->custom = self::customFromJson($settings->customJson);
            $this->type_map = self::sizesFromEntities(self::sizeRepository()->findAllEnabled());
        } else {
            $this->loadOrSeedEnabledSizes();
        }

        $this->disabled_type_map = self::sizesFromEntities(self::sizeRepository()->findAllDisabled());
        if ($this->disabled_type_map === []) {
            $this->loadOrSeedDisabledSizes();
        }

        $this->buildMaps();
    }

    /**
     * Two requests racing right after a fresh install/DB reimport (no
     * `derivative_settings` row yet) can otherwise both decide "not
     * seeded" and both try to insert the same default `derivative_size`
     * rows -- confirmed live: `UniqueConstraintViolationException:
     * Duplicate entry '4xlarge' for key 'derivative_size.PRIMARY'`. A
     * blocking advisory lock (MySQL `GET_LOCK()`/Postgres
     * `pg_try_advisory_lock()` via {@see AdvisorySessionLock}, same
     * primitive `Admin\Upload\UploadService::upload()`'s own
     * duplicate-detection lock uses directly, not through {@see
     * \Piwigo\Core\UniqueExecLock}'s `Logger`-requiring wrapper --
     * loadFromDb() runs before `Kernel::boot()`/`CurrentLogger` are
     * guaranteed ready, per this class's own settingsRepository()
     * docblock) serializes the seed. The loser re-checks after
     * acquiring the lock rather than blindly re-seeding, since the
     * winner has, by then, already written the rows.
     */
    private function loadOrSeedEnabledSizes(): void
    {
        $lockConn = DbConnection::build();
        $lockName = self::seedLockName('enabled');
        AdvisorySessionLock::acquire($lockConn, $lockName, 10);
        try {
            $settings = self::settingsRepository()->load();
            if ($settings instanceof DerivativeSettingsEntity) {
                $this->quality = $settings->defaultQuality;
                $this->watermark = self::watermarkFromJson($settings->watermarkJson);
                $this->custom = self::customFromJson($settings->customJson);
                $this->type_map = self::sizesFromEntities(self::sizeRepository()->findAllEnabled());

                return;
            }

            $this->watermark = new WatermarkParams();
            $this->type_map = self::getEnabledDefaultSizes();
            $this->save(false);
        } finally {
            AdvisorySessionLock::release($lockConn, $lockName);
        }
    }

    /**
     * Same race as {@see loadOrSeedEnabledSizes()}, for the disabled-sizes
     * seed -- a separate lock name/token, since the two seeds are
     * otherwise independent and there is no reason to serialize one
     * behind the other.
     */
    private function loadOrSeedDisabledSizes(): void
    {
        $lockConn = DbConnection::build();
        $lockName = self::seedLockName('disabled');
        AdvisorySessionLock::acquire($lockConn, $lockName, 10);
        try {
            $this->disabled_type_map = self::sizesFromEntities(self::sizeRepository()->findAllDisabled());
            if ($this->disabled_type_map !== []) {
                return;
            }

            $this->disabled_type_map = self::getDisabledDefaultSizes();
            $this->saveDisabled();
        } finally {
            AdvisorySessionLock::release($lockConn, $lockName);
        }
    }

    /**
     * Database-scoped, matching `Core\UniqueExecLock::lockName()`'s own
     * collision-avoidance reasoning -- `GET_LOCK()` names are global to
     * the whole MySQL server, not scoped to one database/schema, and
     * this dev environment alone runs several Piwigo installs against
     * one shared MySQL server. The distinguishing input (database +
     * suffix) is hashed as a whole, not concatenated after a fixed
     * prefix -- `GET_LOCK()` names are capped at 64 characters, and
     * hashing keeps this name a fixed, safely-under-the-cap length
     * regardless of the database name's own length.
     */
    private static function seedLockName(string $suffix): string
    {
        return 'piwigo_isp_seed_' . sha1(DbCredentials::fromEnv()->database . ':' . $suffix);
    }

    /**
     * Fresh, throwaway EntityManager per call (like Piwigo\Caddie\CaddieService's
     * own `TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(CaddieEntity::class), CaddieRepository::class)`)
     * rather than
     * Bootstrap\InfrastructureAccessor's container-shared one -- unlike a
     * raw bulk-write onto a table other repositories concurrently read in
     * the same request (InfrastructureAccessor's own stated reason to
     * exist), nothing else in a request reads/writes derivative_settings/
     * derivative_size alongside this class, so there's no identity-map
     * coherency to preserve, and avoiding the container dependency means
     * loadFromDb() (called every request, very early in
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
        return TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(DerivativeSettingsEntity::class), DerivativeSettingsRepository::class);
    }

    private static function sizeRepository(): DerivativeSizeRepository
    {
        return TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(DerivativeSizeEntity::class), DerivativeSizeRepository::class);
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
     * same way loadFromDb() always has for DB-sourced data, rather than
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
                ? new Dimensions($entity->minWidth, $entity->minHeight)
                : null;

            $params = new DerivativeParams(new SizingParams(
                new Dimensions($entity->maxWidth, $entity->maxHeight),
                (float) $entity->maxCrop,
                $minSize,
            ));
            $params->sharpen = (float) $entity->sharpen;
            $params->last_mod_time = $entity->lastModTime;

            $map[$entity->name] = $params;
        }

        // DerivativeSizeRepository::findAllEnabled()/findAllDisabled() have
        // no ORDER BY (name is the PK, so rows come back alphabetically) --
        // every admin-facing consumer of getDefinedTypeMap()/
        // getDisabledTypeMap() (e.g. MaintenanceActionsPageRenderer's
        // "delete multiple size images" list) expects the same canonical
        // square/thumb/.../4xlarge order the former blob-based array always
        // preserved. Re-sort by self::ALL_TYPES instead of adding a
        // MySQL-specific ORDER BY FIELD(...) to the repository.
        $ordered = [];
        foreach (self::ALL_TYPES as $type) {
            if (isset($map[$type])) {
                $ordered[$type] = $map[$type];
            }
        }
        foreach ($map as $type => $params) {
            $ordered[$type] ??= $params;
        }

        return $ordered;
    }

    private static function sizeEntityFromParams(string $name, int $enabled, DerivativeParams $params): DerivativeSizeEntity
    {
        $minSize = $params->sizing->min_size;

        return new DerivativeSizeEntity(
            $name,
            $enabled,
            (int) $params->sizing->ideal_size->width,
            (int) $params->sizing->ideal_size->height,
            self::decimalToString((float) $params->sizing->max_crop),
            $minSize instanceof Dimensions ? (int) $minSize->width : null,
            $minSize instanceof Dimensions ? (int) $minSize->height : null,
            self::decimalToString($params->sharpen),
            $params->last_mod_time,
        );
    }

    private static function decimalToString(float $value): string
    {
        return number_format($value, 4, '.', '');
    }

    public function setWatermark(?WatermarkParams $watermark): void
    {
        $this->watermark = $watermark;
    }

    /**
     * @see ImageStdParams::save()
     *
     * @param array<string, DerivativeParams> $map
     */
    public function setAndSave(array $map): void
    {
        $this->type_map = $map;
        $this->save(false);
        $this->buildMaps();
    }

    /**
     * Saves the configuration in database.
     */
    public function save(bool $save_disabled = true): void
    {
        // $watermark can still be null here if save() is reached before
        // loadFromDb()/setWatermark() ever ran (e.g. a caller sets
        // $type_map directly then calls setAndSave()) -- the original
        // serialize()-blob code tolerated this silently (a null 'w' entry,
        // resolved to a fresh WatermarkParams() on the next loadFromDb()
        // via its own instanceof check); this preserves that same
        // tolerance now that watermarkToJson() needs a real object.
        self::settingsRepository()->save(
            $this->quality,
            self::watermarkToJson($this->watermark ?? new WatermarkParams()),
            $this->custom,
        );

        $rows = [];
        foreach ($this->type_map as $type => $params) {
            $rows[] = self::sizeEntityFromParams($type, 1, $params);
        }
        self::sizeRepository()->syncEnabled($rows);

        if ($save_disabled) {
            $this->saveDisabled();
        }
    }

    /**
     * Saves the disabled configuration in database.
     */
    public function saveDisabled(): void
    {
        $rows = [];
        foreach ($this->disabled_type_map as $type => $params) {
            $rows[] = self::sizeEntityFromParams($type, 0, $params);
        }
        self::sizeRepository()->syncDisabled($rows);
    }

    /**
     * @param array<string, DerivativeParams> $map
     */
    public function setAndSaveDisabled(array $map): void
    {
        $this->disabled_type_map = $map;
        $this->saveDisabled();
    }

    public function restoreDefault(): void
    {
        $this->type_map = self::getEnabledDefaultSizes();
        $this->disabled_type_map = self::getDisabledDefaultSizes();
        $this->save();
        $this->buildMaps();
    }

    /**
     * @return array<string, DerivativeParams>
     */
    public static function getDefaultSizes(): array
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
    public static function getEnabledDefaultSizes(): array
    {
        $default_sizes = self::getDefaultSizes();
        foreach (self::DISABLED_TYPES_BY_DEFAULT as $type) {
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
        $disabled_sizes = array_intersect_key($all, array_flip(self::DISABLED_TYPES_BY_DEFAULT));
        return $disabled_sizes;
    }

    /**
     * Compute 'use_watermark'
     *
     * Pre-existing fragility, not introduced by the derivative_settings/
     * derivative_size migration: $watermark is only ever populated by
     * setWatermark()/loadFromDb(), so a caller reaching buildMaps()
     * (via setAndSave()/restoreDefault()/getCustom()) before either of
     * those ran would hit a read on null here, in the original
     * serialize()-blob code too. Lazily defaults it instead of crashing --
     * self-healing, matching the same null-tolerance save() already needs.
     */
    public function applyGlobal(DerivativeParams $params): void
    {
        $this->watermark ??= new WatermarkParams();

        $params->use_watermark = $this->watermark->file !== '' &&
            ($this->watermark->min_size[0] <= $params->sizing->ideal_size->width
            or $this->watermark->min_size[1] <= $params->sizing->ideal_size->height);
    }

    /**
     * Build 'type_map', 'all_type_map' and 'undefined_type_map'.
     */
    private function buildMaps(): void
    {
        foreach ($this->type_map as $type => $params) {
            $params->type = $type;
            $this->applyGlobal($params);
        }
        $this->all_type_map = $this->type_map;

        for ($i = 0; $i < count(self::ALL_TYPES); $i++) {
            $tocheck = self::ALL_TYPES[$i];
            if (! isset($this->type_map[$tocheck])) {
                for ($j = $i - 1; $j >= 0; $j--) {
                    $target = self::ALL_TYPES[$j];
                    if (isset($this->type_map[$target])) {
                        $this->all_type_map[$tocheck] = $this->type_map[$target];
                        $this->undefined_type_map[$tocheck] = $target;
                        break;
                    }
                }
            }
        }
    }
}
