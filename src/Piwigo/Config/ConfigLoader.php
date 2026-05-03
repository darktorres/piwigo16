<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;

/**
 * Boot-time orchestration for Config: loads .env / .env.local from the repo
 * root and applies env-var overrides into the $conf array.
 *
 * Convention (matches vlucas/phpdotenv defaults):
 *   .env        — committed-template-derived runtime config (gitignored)
 *   .env.local  — local-only overrides (gitignored, loaded last so wins)
 *   .env.example — committed template, NOT loaded
 *
 * Both files are optional. If neither is present, env-derived overrides are
 * skipped and the legacy database.inc.php is the sole source of DB credentials.
 *
 * Env-var → conf-key mapping is hand-curated — currently only DB credentials,
 * which is the only sensitive runtime config that benefits from env injection.
 * Extend ENV_MAPPING when more keys need env override (e.g., for 12-factor
 * deployments).
 *
 * Idempotent: subsequent calls are no-ops (Dotenv won't re-load already-set
 * vars; overrides only fire when the env var is non-empty).
 */
final class ConfigLoader
{
    /**
     * Env var → SCHEMA key. Only keys that benefit from runtime env override.
     * Validated against SCHEMA in apply() — typos in this map fail fast.
     */
    private const ENV_MAPPING = [
        'PIWIGO_DB_HOST'     => 'db_host',
        'PIWIGO_DB_USER'     => 'db_user',
        'PIWIGO_DB_PASSWORD' => 'db_password',
        'PIWIGO_DB_BASE'     => 'db_base',
        'PIWIGO_DB_PREFIX'   => 'db_prefix',
    ];

    /**
     * Loads the named env files from the given repo root. All optional.
     * Uses immutable mode so already-set process env vars (e.g., systemd
     * EnvironmentFile=, Docker -e, or a parent shell export) win over the
     * file values — standard 12-factor precedence.
     *
     * Default loads only `.env` at runtime — that's the committed-defaults
     * file. `.env.local` is reserved for the test runner (loaded explicitly
     * by tests/bootstrap.php), since it historically held test-only DB
     * credentials in this repo and silently overriding runtime DB settings
     * with test creds would break installs.
     *
     * @param list<string> $files filenames to attempt loading, in order
     */
    public static function loadEnv(string $repoRoot, array $files = ['.env']): void
    {
        $repoRoot = rtrim($repoRoot, '/\\');
        $present  = [];
        foreach ($files as $file) {
            if (is_file($repoRoot . DIRECTORY_SEPARATOR . $file)) {
                $present[] = $file;
            }
        }
        if ($present === []) {
            return;
        }
        // Default phpdotenv writers populate only $_ENV + $_SERVER. Add
        // PutenvAdapter so getenv() is also populated — IntegrationTestCase
        // and Piwigo's existing env-reading sites all use getenv().
        $repository = RepositoryBuilder::createWithDefaultAdapters()
            ->addAdapter(PutenvAdapter::class)
            ->immutable()
            ->make();
        Dotenv::create($repository, $repoRoot, $present, false)->safeLoad();
    }

    /**
     * Populates $conf with default values for every key in Config::SCHEMA
     * that isn't already set. Replaces the legacy include/config_default.inc.php
     * file as the single source of compile-time defaults — SCHEMA itself
     * carries the simple-type defaults; for keys flagged 'custom' => true the
     * default comes from invoking the typed accessor (which encodes its
     * hardcoded fallback in the accessor body, e.g., file_ext = picture_ext +
     * extras, recent_post_dates = nested RSS/NBM structure).
     *
     * Idempotent: keys already populated (e.g., from an earlier call or
     * from .env via applyEnvOverrides) are skipped.
     *
     * Call BEFORE applyEnvOverrides + load_conf_from_db so DB / env values
     * win over compile-time defaults.
     *
     * @param array<string, mixed> $conf
     */
    public static function applyDefaults(array &$conf): void
    {
        foreach (Config::SCHEMA as $key => $entry) {
            if (array_key_exists($key, $conf)) {
                continue;
            }
            // Skip null-defaulted keys (the nullable-string cluster — gallery_url,
            // cache_sizes, filters_views, last_major_update, etc.). Their
            // "default" is genuine absence: callers use Config::has() to detect
            // whether the user has ever set them, and seeding null would flip
            // has() permanently to true and break that detection.
            if (!empty($entry['custom'])) {
                // The accessor's body encodes the rich default; calling it
                // with $conf empty for this key returns that fallback.
                $method = $entry['method'];
                $value  = Config::$method();
                if ($value !== null) {
                    $conf[$key] = $value;
                }
            } elseif ($entry['default'] !== null) {
                $conf[$key] = $entry['default'];
            }
        }
    }

    /**
     * Applies ENV_MAPPING overrides into $conf. Env vars that are unset or
     * empty are ignored — leaves the existing $conf value (the SCHEMA
     * default seeded by applyDefaults) in place.
     *
     * @param array<string, mixed> $conf
     */
    public static function applyEnvOverrides(array &$conf): void
    {
        foreach (self::ENV_MAPPING as $envKey => $confKey) {
            if (!array_key_exists($confKey, Config::SCHEMA)) {
                throw new \LogicException(
                    "ConfigLoader::ENV_MAPPING references conf key '$confKey' which is not in Config::SCHEMA."
                );
            }
            $val = $_ENV[$envKey] ?? false;
            if ($val === false) {
                $val = getenv($envKey);
            }
            if ($val !== false && $val !== '') {
                $conf[$confKey] = $val;
            }
        }
    }
}
