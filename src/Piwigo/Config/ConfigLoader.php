<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Piwigo\Core\Paths;

/**
 * Boot-time orchestration for Config: seeds Config::$data with SCHEMA
 * defaults + env overrides.
 *
 * Deliberately does NOT own env-FILE loading (no loadEnv(), no TestMode
 * class) -- unlike the reference implementation, which pulls in
 * vlucas/phpdotenv + its own Piwigo\Config\TestMode. This project already
 * has a working, ADR-0021-compliant mechanism built on the already-adopted
 * symfony/dotenv: include/env.inc.php's pwg_load_env_file() +
 * pwg_test_mode_is_active()/pwg_test_mode_env_file(), called by legacy
 * common.inc.php (HTTP path) and bin/piwigo (CLI path, P12) before
 * Config/Kernel ever boot. applyEnvOverrides() below just reads the
 * already-loaded getenv() values.
 *
 * Idempotent: applyDefaults() skips already-populated keys; env overrides
 * only fire when the env var is non-empty.
 */
final class ConfigLoader
{
    /**
     * Env var -> SCHEMA key. Mirrors include/env.inc.php's
     * pwg_apply_env_to_conf() mapping for the legacy $conf array, so both
     * stay in sync. Extend when a new key needs runtime env override.
     */
    private const array ENV_MAPPING = [
        'PIWIGO_DB_HOST' => 'db_host',
        'PIWIGO_DB_USER' => 'db_user',
        'PIWIGO_DB_PASSWORD' => 'db_password',
        'PIWIGO_DB_BASE' => 'db_base',
        'PIWIGO_DB_PREFIX' => 'db_prefix',
        'PIWIGO_DB_DRIVER' => 'db_driver',
        'PIWIGO_DB_PORT' => 'db_port',
    ];

    /**
     * Seeds Config::$data with default values for every key in
     * Config::SCHEMA that isn't already set. For keys flagged
     * 'custom' => true, the default comes from invoking the typed accessor
     * itself (which encodes its own hardcoded fallback in the accessor
     * body -- e.g. file_ext = picture_ext + extras).
     *
     * Null-defaulted keys (the nullable-string cluster -- gallery_url,
     * last_major_update, etc.) are intentionally NOT seeded. Their
     * "default" is genuine absence: callers use Config::has() to detect
     * first-run state, and seeding null would flip has() permanently to
     * true and break that detection.
     *
     * Call before applyEnvOverrides() and before the DB-merge
     * (ConfigService::loadConfFromDb(), called separately once
     * Kernel::boot() makes the container available) so DB/env values win
     * over compile-time defaults -- CommonBootstrap::run()'s real
     * sequence is applyDefaults() -> applyEnvOverrides() ->
     * applyLocalFileOverrides() -> Kernel::boot() ->
     * ConfigService::loadConfFromDb(), DB-merge genuinely last and
     * highest-priority.
     */
    public static function applyDefaults(): void
    {
        foreach (Config::SCHEMA as $key => $entry) {
            if (Config::has($key)) {
                continue;
            }
            if (($entry['custom'] ?? false) === true) {
                $method = $entry['method'];
                $value = new \ReflectionMethod(Config::class, $method)->invoke(null);
                if ($value !== null) {
                    Config::override($key, $value);
                }
            } elseif ($entry['default'] !== null) {
                Config::override($key, $entry['default']);
            }
        }
    }

    /**
     * Applies ENV_MAPPING overrides to Config::$data. Env vars that are
     * unset or empty are ignored -- leaves the SCHEMA default in place.
     */
    public static function applyEnvOverrides(): void
    {
        foreach (self::ENV_MAPPING as $envKey => $confKey) {
            if (! array_key_exists($confKey, Config::SCHEMA)) {
                throw new \LogicException(
                    "ConfigLoader::ENV_MAPPING references conf key '{$confKey}' which is not in Config::SCHEMA."
                );
            }
            $value = getenv($envKey);
            if ($value !== false && $value !== '') {
                Config::override($confKey, $value);
            }
        }
    }

    /**
     * Syncs a site's own `local/config/config.inc.php` (+ conditionally
     * the `local_dir_site` dir file) overrides into Config::$data.
     *
     * Historically this never happened: `include/common.inc.php` builds a
     * bare `$conf` array from these same files, but nothing in the
     * `Config::`-based request path ever read it -- a site owner's file
     * customizations (anything not in the `config` DB table, e.g.
     * `order_by_custom`, `data_location`, `guest_id`) were silently
     * ignored on every real request. This is the fix: real values, not
     * just DB-persisted or SCHEMA-default ones.
     *
     * Only keys present in Config::SCHEMA are applied -- an old or
     * removed legacy key in a site's file is silently ignored rather than
     * polluting Config::$data with an unknown key. See
     * {@see LocalConfigOverrides::read()} for why this reads the site's
     * file(s) in isolation rather than reusing
     * {@see \Piwigo\Admin\Install\DbPatch\LegacyFileConf::read()}'s shape.
     */
    public static function applyLocalFileOverrides(Paths $paths): void
    {
        foreach (LocalConfigOverrides::read($paths) as $key => $value) {
            if (array_key_exists($key, Config::SCHEMA)) {
                Config::override($key, $value);
            }
        }
    }

    /**
     * Assert that all SCHEMA keys marked 'required' => true have a
     * non-empty value. NOT called from the live CommonBootstrap sequence
     * yet -- secret_key (required) has no resolvable source until either
     * P14's DB-merge or a PIWIGO_SECRET_KEY env-var convention lands;
     * calling this today would throw on every real request. Available for
     * callers (tests, future phases) that have a fully-resolved Config.
     *
     * @throws MissingRequiredConfigException
     */
    public static function validateRequired(): void
    {
        foreach (Config::SCHEMA as $key => $meta) {
            if (($meta['required'] ?? false) !== true) {
                continue;
            }
            $value = Config::all()[$key] ?? null;
            if ($value === null || $value === '') {
                throw new MissingRequiredConfigException(
                    "Required config key '{$key}' is missing or empty."
                );
            }
        }
    }
}
