<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Piwigo\Core\Paths;

/**
 * Reads exactly the `$conf[...]` keys a site owner's own
 * `local/config/config.inc.php` (+ conditionally the `local_dir_site` dir
 * file) sets -- deliberately isolated from `Config::defaultsArray()`,
 * unlike {@see \Piwigo\Admin\Install\DbPatch\LegacyFileConf::read()}'s
 * "give me an effective value with a sensible fallback" shape. That
 * distinction matters here: this is the source for `ConfigLoader::
 * applyLocalFileOverrides()`, which syncs every key found straight into
 * `Config::override()` -- if `Config::defaultsArray()`'s own ~277
 * default-bearing keys were merged in first, every one of them would look
 * "overridden" and get blindly resynced, which is a no-op today (they're
 * already `Config::SCHEMA`'s own defaults) but would silently mask a real
 * bug the instant anyone touched a default value here vs. in code that
 * reads a key's presence to mean "the site genuinely customized this"
 * (confirmed live: `Admin\UserListPageRenderer::webmasterIdIsLocal()`,
 * a presence check for exactly that reason, had to be fixed the same way
 * during the "nothing is frozen" config_default.inc.php retirement,
 * 2026-07-22 -- this class already got that design right from the start).
 * Reading only the site's own file(s) means exactly the keys a site
 * genuinely customized land in the returned array, by construction.
 */
final class LocalConfigOverrides
{
    /**
     * @return array<string, mixed>
     */
    public static function read(Paths $paths): array
    {
        $conf = [];
        $localConfigFile = $paths->local . 'config/config.inc.php';
        if (is_file($localConfigFile)) {
            include $localConfigFile;
        }

        // Real guard, not dead code: $conf is genuinely populated by the
        // include above (a site-local config.inc.php that may assign
        // $conf['local_dir_site']), but PHPStan can't trace mutation
        // through a dynamic include and proves $conf stays [].
        // @phpstan-ignore isset.offset
        if (isset($conf['local_dir_site'])) {
            // Legacy Coupling Retirement gap-closure (entry-shell
            // define()/include round): used to read the raw PWG_LOCAL_DIR
            // constant here -- Paths::$siteLocal (sourced from the
            // PIWIGO_LOCAL_DIR env var, see Paths's own docblock) replaces
            // it, genuinely a different directory than $paths->local above
            // in the real multi-site-instance deployment shape this
            // supports.
            $dirSiteConfigFile = $paths->siteLocal . 'config/config.inc.php';
            if (is_file($dirSiteConfigFile)) {
                include $dirSiteConfigFile;
            }
        }

        return $conf;
    }
}
