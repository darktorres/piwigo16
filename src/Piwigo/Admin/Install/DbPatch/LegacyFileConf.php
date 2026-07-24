<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;

/**
 * Real-defaults-plus-raw-site-override $conf array for keys that CurrentConfig::
 * can't be trusted for mid-migration -- CurrentConfig::$data at DbPatch/
 * VersionUpgrade apply() time holds only SCHEMA defaults + env overrides
 * (see UpgradeRunner), never a site's local/config/config.inc.php
 * customization nor the DB-persisted config (that loads later, in
 * finish()). Same 2-step layering as the already-reviewed live precedent,
 * UserListPageRenderer::webmasterIdIsLocal() / ConfigurationSubController::
 * orderByIsLocal(): CurrentConfig::defaultsArray(), then local/config/config.inc.php,
 * then -- only if that revealed a local_dir_site override -- the site's
 * real dir-site config file too. Each caller pulls its own key straight
 * off the returned array with its own fallback, matching those two
 * methods' style.
 *
 * "Nothing is frozen" gap-closure (2026-07-22): used to `include
 * include/config_default.inc.php` for the defaults half -- that file is
 * gone (see CurrentConfig::defaultsArray()'s own docblock for why it was
 * redundant, and 3 real values it had silently drifted from SCHEMA on).
 * The site-local override read stays a raw, always-fresh include on
 * purpose: verified real DbPatch classes (Patch108/110/134) WRITE to
 * these exact files mid-upgrade, and later-numbered patches (via
 * LegacyFileConf::read()) must see those writes -- a single up-front
 * snapshot would silently feed stale data to every later patch. This is
 * the one part of the former design that was correct and load-bearing,
 * not an assumption.
 *
 * Legacy Coupling Retirement gap-closure (entry-shell define()/include
 * round): reads Paths via CurrentPaths::get() instead of the retired
 * PHPWG_ROOT_PATH/PWG_LOCAL_DIR constants -- DbPatchInterface::apply() has
 * no Paths parameter of its own (see CurrentPaths's own docblock for why
 * this static-utility shape can't take constructor/method-injected Paths).
 */
final class LegacyFileConf
{
    /**
     * @return array<string, mixed>
     */
    public static function read(): array
    {
        $paths = CurrentPaths::get();

        $conf = CurrentConfig::defaultsArray();
        @include $paths->local . 'config/config.inc.php';
        if (isset($conf['local_dir_site'])) {
            @include $paths->siteLocal . 'config/config.inc.php';
        }

        return $conf;
    }
}
