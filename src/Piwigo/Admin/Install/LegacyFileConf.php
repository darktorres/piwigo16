<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;

/**
 * Real-defaults-plus-raw-site-override $conf array for keys that
 * CurrentConfig:: can't be trusted for before install has run --
 * InstallWizard's own constructor needs a site's local/config/config.inc.php
 * customization (e.g. data_location) before a real `config` table exists
 * to load CurrentConfig:: from. Same 2-step layering as the already-
 * reviewed live precedent, UserListPageRenderer::webmasterIdIsLocal() /
 * ConfigurationSubController::orderByIsLocal(): CurrentConfig::defaultsArray(),
 * then local/config/config.inc.php, then -- only if that revealed a
 * local_dir_site override -- the site's real dir-site config file too.
 * Each caller pulls its own key straight off the returned array with its
 * own fallback, matching those two methods' style.
 *
 * Originally lived alongside the frozen DbPatch/VersionUpgrade classes
 * (both needed the identical layering mid-migration) -- moved here once
 * that whole chain was deleted (gap-closure Stage 1a-bis: the in-place
 * upgrade chain contradicted this codebase's own documented "no in-place
 * upgrade" architecture), since InstallWizard is now its only real
 * caller.
 */
final class LegacyFileConf
{
    /**
     * Genuinely arbitrary by design, same residual as ConfigService's own
     * confGetParam(): CurrentConfig::defaultsArray() alone spans ~280
     * heterogeneously-typed keys, and the 2 raw `include`s below let a
     * site's own config.inc.php overwrite any of them with anything
     * (arbitrary PHP, not a schema-checked file).
     *
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
