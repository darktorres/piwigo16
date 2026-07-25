<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

/**
 * Legacy Coupling Retirement Phase 8, 8b -- the install.php counterpart to
 * RequestBootstrap::bootEntryPoint()/CliBootstrap::buildApplication() for
 * the HTTP request path. Same "ConfigLoader::applyDefaults()/
 * applyEnvOverrides() then Kernel::boot() before anything else" shape as
 * both of those, applied to the one remaining family of entry points that
 * didn't have it yet.
 *
 * boot() itself stays deliberately minimal (container boot only, no
 * service resolution) and must run before any real credentials are known
 * -- InstallWizard's own former UserService duplicate-construction-chain
 * violation was fixed with a plain private DRY-extraction helper instead
 * (matching RequestBootstrap::activityService()'s own precedent), not a
 * container lookup, for exactly this reason: a container-resolved
 * UserService would unsafely cache a Connection built from stale/default
 * DbCredentials::current() (Config generic-accessor removal moved DB
 * credentials off CurrentConfig:: entirely) if resolved before InstallWizard::
 * boot()'s own DbCredentials::seed(...) call (from the submitted install
 * form) has run.
 *
 * activateConfigService() (Legacy Coupling Retirement Phase 8, 8d) is the
 * install-path counterpart to RequestBootstrap::connect()'s/
 * CliBootstrap::buildApplication()'s own CurrentConfigService::set() call
 * -- called separately, and later, than boot() for the identical reason:
 * it must run after real DB credentials are seeded (after InstallWizard::
 * boot()'s own DbCredentials::seed(...) call), or the ConfigService/
 * Connection resolved and cached here would carry stale ones for the rest
 * of the request. Once called, every Tier-2 class reachable from the
 * install path (Admin/themes.php/plugins.php/updates.php/
 * Cache/UserCacheInvalidator.php/Image/ImageService.php/
 * Page/NoPhotoYetRenderer.php/Template/Template.php/Image/ImageStdParams.php/
 * Core/UniqueExecLock.php) can safely call CurrentConfigService::get() the
 * same way they already do when reached from the HTTP path
 * (RequestBootstrap::connect() activates it there).
 */
final class InstallBootstrap
{
    public static function boot(Paths $paths): void
    {
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot($paths);

        // Found live while verifying Part II's public/ relocation --
        // unrelated to the move itself, but install.php's own "already
        // installed" InstallWizard::boot() -> HtmlService::fatalError()
        // path was a real, pre-existing 500 (uncaught PHP fatal error, not
        // the intended clean error page) because this was never called
        // here. See ErrorCollector::installIfConfigured()'s own docblock.
        ErrorCollector::installIfConfigured();
    }

    public static function activateConfigService(): void
    {
        $configService = Kernel::container()->get(ConfigService::class);
        if (! $configService instanceof ConfigService) {
            throw new \LogicException('Container returned an unexpected type for ' . ConfigService::class);
        }
        CurrentConfigService::set($configService);
    }
}
