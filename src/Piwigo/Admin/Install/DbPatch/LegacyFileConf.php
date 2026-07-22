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
 * Raw, file-sourced $conf array for keys that Config:: can't be trusted for
 * mid-migration -- Config::$data at DbPatch/VersionUpgrade apply() time
 * holds only SCHEMA defaults + env overrides (see UpgradeRunner), never a
 * site's local/config/config.inc.php customization nor the DB-persisted
 * config (that loads later, in finish()). Same 3-step layering as the
 * already-reviewed live precedent, UserListPageRenderer::webmasterIdIsLocal()
 * / ConfigurationSubController::orderByIsLocal(): config_default.inc.php,
 * then local/config/config.inc.php, then -- only if that revealed a
 * local_dir_site override -- the site's real dir-site config file too.
 * Each caller pulls its own key straight off the returned array with its
 * own fallback, matching those two methods' style.
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

        $conf = [];
        include $paths->root . 'include/config_default.inc.php';
        @include $paths->local . 'config/config.inc.php';
        if (isset($conf['local_dir_site'])) {
            @include $paths->siteLocal . 'config/config.inc.php';
        }

        return $conf;
    }
}
