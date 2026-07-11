<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Resolved absolute filesystem layout of one Piwigo install.
 *
 * A new, parallel mechanism for `src/Piwigo/` code: a single immutable
 * value minted once at the entry point (`Paths::fromIndex(__FILE__)`) and
 * threaded through DI from there. It does NOT replace the legacy
 * `PHPWG_ROOT_PATH` define, which keeps its exact original value (`'./'`)
 * -- several legacy call sites (`get_root_url()`, `section_init.inc.php`,
 * `i.php`) hard-assume that literal string shape to compute relative URL
 * prefixes for generated links, not just filesystem include paths;
 * switching it to an absolute path broke every generated href/src on the
 * live site (confirmed via a real request). The ~885 legacy `include`/
 * `admin` call sites keep reading the untouched `PHPWG_ROOT_PATH`,
 * migrated per-domain in P17-23, not here.
 *
 * Every property is an absolute path with a trailing slash. Composition
 * is plain string concatenation: `$paths->themes . 'admin/style.css'`.
 *
 * The hardcoded subdirectory names intentionally do not consult `Config`
 * -- `Paths` must be constructible before `ConfigLoader::applyDefaults()`
 * runs. Config-driven directories (`Config::dataLocation()`,
 * `Config::themesPath()`, etc.) compose against `data`/`root` at the call
 * site, where Config is already available.
 */
final readonly class Paths
{
    /**
     * @param string $root        Absolute install directory, trailing slash.
     * @param string $plugins     `{root}plugins/`
     * @param string $themes      `{root}themes/`
     * @param string $local       `{root}local/`
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

        return new self(
            root: $root,
            plugins: $root . 'plugins/',
            themes: $root . 'themes/',
            local: $root . 'local/',
            data: $root . '_data/',
            derivatives: $root . '_data/i/',
            logs: $root . '_data/logs/',
            upload: $root . 'upload/',
            config: $root . 'config/',
            vendor: $root . 'vendor/',
        );
    }
}
