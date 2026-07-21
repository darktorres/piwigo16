<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Config\ConfigLoader;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

/**
 * Legacy Coupling Retirement Phase 8, 8b -- the install.php/upgrade.php/
 * upgrade_feed.php counterpart to CommonBootstrap::run()/
 * CliBootstrap::buildApplication() for the HTTP request path. Same
 * "ConfigLoader::applyDefaults()/applyEnvOverrides() then Kernel::boot()
 * before anything else" shape as both of those, applied to the one
 * remaining family of entry points that didn't have it yet.
 *
 * Kept deliberately minimal (boot only, no service resolution) -- unlike
 * RequestBootstrap.php/RedirectService.php (Phase 8, 8a), nothing in
 * InstallWizard/UpgradeRunner/UpgradeFeedRunner/UpgradeService needs a
 * container-resolved dependency yet: InstallWizard's own former
 * UserService duplicate-construction-chain violation was fixed with a
 * plain private DRY-extraction helper instead (matching
 * RequestBootstrap::activityService()'s own precedent), not a container
 * lookup -- a container-resolved UserService would be unsafe here
 * regardless, since PHP-DI treats it as request-shared and it would cache
 * a Connection built from stale/default Config::dbHost() etc. if resolved
 * before InstallWizard::boot()'s own Config::override('db_host', ...)
 * calls (from the submitted install form) have run.
 *
 * This class exists now anyway, ahead of any real caller needing
 * Kernel::container(), because the whole point of booting early is to
 * make the container reliably available by the time later work (Legacy
 * Coupling Retirement Phase 8, 8d) retargets UpgradeRunner.php/
 * UpgradeService.php/Admin/themes.php/plugins.php/updates.php/
 * Cache/UserCacheInvalidator.php/Image/ImageService.php/
 * Page/NoPhotoYetRenderer.php/Template/Template.php/Image/ImageStdParams.php/
 * Core/UniqueExecLock.php onto ConfigService -- those retargets need a
 * booted container, not a newly-booted one at the point each finally
 * needs it.
 */
final class InstallBootstrap
{
    public static function boot(Paths $paths): void
    {
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot($paths);
    }
}
