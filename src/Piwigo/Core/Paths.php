<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Resolved absolute filesystem layout of one Piwigo install.
 *
 * The sole mechanism for `src/Piwigo/` code (and every entry-shell file)
 * to resolve filesystem paths: a single immutable value minted once at
 * the entry point (`Paths::fromIndex(__FILE__)`/`fromRoot()`) and
 * threaded through DI from there. Legacy Coupling Retirement gap-closure
 * (entry-shell `define()`/`include` round): this class fully replaces
 * `PHPWG_ROOT_PATH` -- that constant's own former justification for
 * staying alive ("several legacy call sites hard-assume its literal
 * string shape to compute relative URL prefixes for generated links, not
 * just filesystem include paths") was a real conflation, not a permanent
 * constraint: URL-prefix generation now goes through `UrlService`'s own
 * request-derived mount prefix (`Router::pathInfo()`'s
 * `dirname($scriptName)`+`MOUNT_DEPTH_ATTRIBUTE` computation) instead of
 * ever reading a filesystem-path constant, so nothing depends on
 * `PHPWG_ROOT_PATH`'s literal value shape any more. `PHPWG_ROOT_PATH`/
 * `PWG_LOCAL_DIR` are deleted outright (zero remaining `define()`s or
 * raw reads, confirmed via a repo-wide grep, not assumed).
 *
 * Every property is an absolute path with a trailing slash. Composition
 * is plain string concatenation: `$paths->themes . 'admin/style.css'`.
 *
 * The hardcoded subdirectory names intentionally do not consult `Config`
 * -- `Paths` must be constructible before `ConfigLoader::applyDefaults()`
 * runs. Config-driven directories (`Config::dataLocation()`,
 * `Config::themesPath()`, etc.) compose against `data`/`root` at the call
 * site, where Config is already available.
 *
 * `local` always means the fixed `local/` directory every install reads
 * first (`local/config/config.inc.php`'s own `local_dir_site` flag says
 * "yes, this site instance also has its own separate override
 * directory") -- `siteLocal` is the genuinely-overridable one: the former
 * `PWG_LOCAL_DIR` constant let an external, site-authored wrapper script
 * `define()` a custom value *before* including the app's entry point,
 * supporting Piwigo's classic multi-site-instance-sharing-one-codebase
 * deployment shape (confirmed still a real, tested capability --
 * `tests/Unit/Config/ConfigLoaderTest.php`'s own `local_dir_site` test,
 * `install.php`'s own "PWG_LOCAL_DIR is a real dependency" comment -- not
 * dead legacy code safe to drop, and genuinely a *different* directory
 * than `local` in that deployment shape, not just an alias for it).
 * Replaced with a `PIWIGO_LOCAL_DIR` env var, the same
 * deployment-level-override idiom `ConfigLoader::ENV_MAPPING` already
 * uses for `PIWIGO_DB_*` -- strictly more robust than the old "define a
 * constant before including our file" ordering contract, not just a
 * like-for-like port. Defaults to the same value as `local` when unset
 * (matching `PWG_LOCAL_DIR`'s own `'local/'` default), so every
 * non-multi-instance install (i.e. every real install this repo's own
 * test suite exercises) has `local === siteLocal`.
 */
final readonly class Paths
{
    /**
     * @param string $root        Absolute install directory, trailing slash.
     * @param string $plugins     `{root}plugins/`
     * @param string $themes      `{root}themes/`
     * @param string $local       `{root}local/`, always -- see class docblock
     * @param string $siteLocal   `{root}` + the `PIWIGO_LOCAL_DIR`-overridden
     *                            directory name (`local/` when unset)
     * @param string $data        `{root}_data/`
     * @param string $derivatives `{root}_data/i/`
     * @param string $logs        `{root}_data/logs/`
     * @param string $upload      `{root}upload/`
     * @param string $config      `{root}config/`
     * @param string $vendor      `{root}vendor/`
     */
    public function __construct(
        public string $root,
        public string $plugins,
        public string $themes,
        public string $local,
        public string $siteLocal,
        public string $data,
        public string $derivatives,
        public string $logs,
        public string $upload,
        public string $config,
        public string $vendor,
    ) {}

    /**
     * Manufactures a Paths from the physical location of `index.php` (the
     * only production caller: `Paths::fromIndex(__FILE__)`). `__FILE__`
     * resolves symlinks, so a symlinked install still produces the
     * canonical real path of the install directory.
     */
    public static function fromIndex(string $indexFile): self
    {
        return self::buildFromRoot(dirname($indexFile));
    }

    /**
     * Constructs a Paths from an explicit root directory -- tests and
     * tools that don't have a real `index.php` entry point.
     */
    public static function fromRoot(string $rootDir): self
    {
        return self::buildFromRoot($rootDir);
    }

    private static function buildFromRoot(string $rootDir): self
    {
        $root = rtrim($rootDir, '/\\') . '/';
        $localDirEnv = getenv('PIWIGO_LOCAL_DIR');
        $siteLocalDir = $localDirEnv !== false && $localDirEnv !== '' ? rtrim($localDirEnv, '/\\') . '/' : 'local/';

        return new self(
            root: $root,
            plugins: $root . 'plugins/',
            themes: $root . 'themes/',
            local: $root . 'local/',
            siteLocal: $root . $siteLocalDir,
            data: $root . '_data/',
            derivatives: $root . '_data/i/',
            logs: $root . '_data/logs/',
            upload: $root . 'upload/',
            config: $root . 'config/',
            vendor: $root . 'vendor/',
        );
    }
}
