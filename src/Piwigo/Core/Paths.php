<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Resolved absolute filesystem layout of one Piwigo install.
 *
 * Replaces the legacy `PHPWG_ROOT_PATH` define: a single immutable value
 * minted once at the entry point (`Paths::fromIndex(__FILE__)` in
 * `index.php`) and threaded explicitly through DI from there. Services
 * receive a Paths instance via constructor injection; nothing in the
 * codebase should compute or resolve filesystem paths on its own.
 *
 * Every property is an absolute path with a trailing slash. Composition
 * is plain string concatenation: `$paths->themes . 'admin/style.css'`.
 *
 * The hardcoded subdirectory names (`plugins/`, `themes/`, `_data/`, …)
 * intentionally do not consult `Config` — `Paths` must be constructible
 * before `ConfigLoader::applyDefaults()` runs. Config-driven directories
 * (e.g. `Config::dataLocation()`, `Config::logDir()`) compose against
 * the `data` / `root` bases at the call site, where Config is already
 * available.
 */
final readonly class Paths
{
    /**
     * @param string $root        Absolute install directory, trailing slash.
     * @param string $plugins     `{root}plugins/`
     * @param string $themes      `{root}themes/`
     * @param string $local       `{root}local/` (PWG_LOCAL_DIR equivalent)
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
    ) {
    }

    /**
     * Manufactures a Paths from the physical location of `index.php`.
     * The only production caller is `index.php` itself:
     *   $paths = Paths::fromIndex(__FILE__);
     *
     * `__FILE__` resolves symlinks, so a symlinked install still produces
     * the canonical real path of the install directory.
     */
    public static function fromIndex(string $indexFile): self
    {
        return self::buildFromRoot(dirname($indexFile));
    }

    /**
     * Constructs a Paths from an explicit root directory. Used by:
     *   - tests, with a per-test tmp dir from IntegrationTestCase setUp
     *   - the Phase 1 DI container shim, which still derives the root from
     *     the legacy PHPWG_ROOT_PATH define
     * Production entry points should prefer fromIndex(__FILE__) — it carries
     * the install location in the call itself, no global lookup needed.
     */
    public static function fromRoot(string $rootDir): self
    {
        return self::buildFromRoot($rootDir);
    }

    private static function buildFromRoot(string $rootDir): self
    {
        $root = rtrim($rootDir, '/\\') . '/';
        return new self(
            root:        $root,
            plugins:     $root . 'plugins/',
            themes:      $root . 'themes/',
            local:       $root . 'local/',
            data:        $root . '_data/',
            derivatives: $root . '_data/i/',
            logs:        $root . '_data/logs/',
            upload:      $root . 'upload/',
            config:      $root . 'config/',
            vendor:      $root . 'vendor/',
        );
    }
}
