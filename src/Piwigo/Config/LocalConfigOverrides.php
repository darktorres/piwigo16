<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Piwigo\Core\Paths;

/**
 * Reads exactly the `$conf[...]` keys a site owner's own
 * `local/config/config.inc.php` (+ conditionally the `local_dir_site` dir
 * file) sets -- deliberately isolated from `config_default.inc.php`, unlike
 * {@see \Piwigo\Admin\Install\DbPatch\LegacyFileConf::read()}'s "give me an
 * effective value with a sensible fallback" shape. That distinction matters
 * here: this is the source for `ConfigLoader::applyLocalFileOverrides()`,
 * which syncs every key found straight into `Config::override()` -- if
 * `config_default.inc.php`'s own ~277 default-bearing keys were included
 * first, every one of them would look "overridden" and get blindly
 * resynced, risking silent drift wherever `config_default.inc.php`'s
 * literal value and `Config::SCHEMA`'s hardcoded default have diverged.
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

        if (isset($conf['local_dir_site'])) {
            $localDir = defined('PWG_LOCAL_DIR') && is_string(PWG_LOCAL_DIR) ? PWG_LOCAL_DIR : 'local/';
            $dirSiteConfigFile = $paths->root . $localDir . 'config/config.inc.php';
            if (is_file($dirSiteConfigFile)) {
                include $dirSiteConfigFile;
            }
        }

        return $conf;
    }
}
