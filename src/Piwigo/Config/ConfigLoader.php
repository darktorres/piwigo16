<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;

/**
 * Boot-time orchestration for Config: loads the per-request env file from
 * the repo root and seeds Config::$data with SCHEMA defaults + env overrides.
 *
 * Two env files exist, fully independent (no inheritance, no merging):
 *   .env        — production runtime config (gitignored)
 *   .env.test   — test runtime config (gitignored), loaded only when the
 *                 X-Piwigo-Env: test header is present (see TestMode)
 *   .env.example — committed template documenting both
 *
 * The choice is made by TestMode::envFile() at every loadEnv() call. There
 * is no swap-and-restore — prod and test config are physically separate
 * files and the runtime picks one or the other per request.
 *
 * Env-var → conf-key mapping is hand-curated — currently only DB credentials,
 * which is the only sensitive runtime config that benefits from env injection.
 * Extend ENV_MAPPING when more keys need env override (e.g., for 12-factor
 * deployments).
 *
 * Idempotent: subsequent calls are no-ops (defaults skip already-populated
 * keys; env overrides only fire when the env var is non-empty).
 */
final class ConfigLoader
{
    /**
     * Env var → SCHEMA key. Only keys that benefit from runtime env override.
     * Validated against SCHEMA in apply() — typos in this map fail fast.
     */
    private const array ENV_MAPPING = [
        'PIWIGO_DB_HOST'     => 'db_host',
        'PIWIGO_DB_USER'     => 'db_user',
        'PIWIGO_DB_PASSWORD' => 'db_password',
        'PIWIGO_DB_BASE'     => 'db_base',
        'PIWIGO_DB_PREFIX'   => 'db_prefix',
        // Comma-separated CIDR list of reverse proxies whose X-Forwarded-Proto
        // / X-Forwarded-For headers should be honoured. Empty (default) means
        // forwarded headers are ignored entirely — secure-by-default for
        // direct deployments. See Piwigo\Http\RequestScheme.
        'PIWIGO_TRUSTED_PROXIES' => 'trusted_proxies',
    ];

    /**
     * Loads the named env files from the given repo root. All optional.
     * Uses immutable mode so already-set process env vars (e.g., systemd
     * EnvironmentFile=, Docker -e, or a parent shell export) win over the
     * file values — standard 12-factor precedence.
     *
     * When called with no `$files` argument, picks the file based on
     * `TestMode::envFile()` — `.env.test` for test-mode requests, `.env`
     * otherwise. The two files are fully independent; there is no
     * inheritance or merging. Tests that exercise the loader directly
     * pass an explicit file list to override this default.
     *
     * @param list<string>|null $files filenames to attempt loading, in order; null = TestMode default
     */
    public static function loadEnv(string $repoRoot, ?array $files = null): void
    {
        if ($files === null) {
            $files = [TestMode::envFile()];
        }
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
     * Seeds Config::$data with default values for every key in Config::SCHEMA
     * that isn't already set. SCHEMA is the single source of compile-time
     * defaults — it carries the simple-type defaults; for keys flagged
     * 'custom' => true the default comes from invoking the typed accessor
     * (which encodes its
     * hardcoded fallback in the accessor body, e.g., file_ext = picture_ext +
     * extras, recent_post_dates = nested RSS/NBM structure).
     *
     * Idempotent: keys already populated (e.g., from an earlier call or
     * from .env via applyEnvOverrides) are skipped.
     *
     * Null-defaulted keys (the nullable-string cluster — gallery_url,
     * last_major_update, etc.) are intentionally
     * NOT seeded. Their "default" is genuine absence: callers use
     * Config::has() to detect first-run state, and seeding null would flip
     * has() permanently to true and break that detection.
     *
     * Call BEFORE applyEnvOverrides + load_conf_from_db so DB / env values
     * win over compile-time defaults.
     */
    public static function applyDefaults(): void
    {
        foreach (Config::SCHEMA as $key => $entry) {
            if (Config::has($key)) {
                continue;
            }
            if (!empty($entry['custom'])) {
                // Custom accessor encodes the rich default in its own body;
                // invoking it with self::$data still empty for this key
                // returns that hardcoded fallback.
                $method = $entry['method'];
                $value  = Config::$method();
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
     * unset or empty are ignored — leaves the SCHEMA default in place.
     */
    public static function applyEnvOverrides(): void
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
                Config::override($confKey, $val);
            }
        }
    }
}
