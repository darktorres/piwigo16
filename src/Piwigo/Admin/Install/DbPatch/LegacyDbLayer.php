<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Core\CurrentPaths;

/**
 * Raw `dblayer` value read straight from the site's own
 * local/config/database.inc.php, the same way upgrade.php/upgrade_feed.php's
 * own `$dblayer = $conf['dblayer'];` reads it at entry-shell scope. Config::
 * never tracks this key -- it isn't part of config_default.inc.php, only
 * ever set here -- and the nearest modern accessor, Config::dbDriver(),
 * uses a different value space ('mysqli'/'pgsql') than these historical
 * patches check against ('mysql'/'pgsql'/'sqlite'/'pdo-sqlite'), so it can't
 * be substituted without changing behavior. An IIFE with its own
 * function-scoped $conf captures the include's side effect in full
 * isolation, same pattern as the data_location/webmaster_id reads
 * elsewhere in this file family (Patch65/94/119/171).
 *
 * Legacy Coupling Retirement gap-closure (entry-shell define()/include
 * round): reads Paths::$siteLocal via CurrentPaths::get() instead of the
 * retired PHPWG_ROOT_PATH/PWG_LOCAL_DIR constants -- database.inc.php
 * genuinely lives in the site-specific directory (not the fixed `local/`
 * every install shares), same as upgrade.php's own database.inc.php read.
 */
final class LegacyDbLayer
{
    public static function value(): string
    {
        $paths = CurrentPaths::get();

        $localConf = (static function () use ($paths): array {
            $conf = [];
            include $paths->siteLocal . 'config/database.inc.php';

            return $conf;
        })();

        // database.inc.php is a site-local file outside this codebase;
        // PHPStan can't see the include's effect on $conf and treats it as
        // permanently empty.
        // @phpstan-ignore nullCoalesce.offset, function.impossibleType
        return is_string($localConf['dblayer'] ?? null) ? $localConf['dblayer'] : 'mysqli';
    }
}
