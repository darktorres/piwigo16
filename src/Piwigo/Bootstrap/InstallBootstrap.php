<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbCredentials;

/**
 * The install.php counterpart to RequestBootstrap::bootEntryPoint()/
 * CliBootstrap::buildApplication() for the HTTP request path. Same
 * "ConfigLoader::applyDefaults()/applyEnvOverrides() then Kernel::boot()"
 * shape as CliBootstrap::buildApplication() -- RequestBootstrap::configure()
 * runs Kernel::boot() first and only calls ConfigLoader::applyDefaults()/
 * applyEnvOverrides() afterward (the opposite order, deliberately, per
 * that class's own docblock).
 *
 * boot() stays deliberately minimal (container boot only, no service
 * resolution) and must run before any real credentials are known: a
 * container-resolved UserService would unsafely cache a Connection built
 * from stale/default DB credentials if resolved before InstallWizard::
 * boot()'s own DbCredentials::seed(...) call (from the submitted install
 * form) has run -- InstallWizard constructs its UserService through a
 * private DRY-extraction helper instead (matching RequestBootstrap::
 * activityService()'s own precedent), not a container lookup, for this
 * reason.
 *
 * activateConfigService() is the install-path counterpart to
 * RequestBootstrap::connect()'s/CliBootstrap::buildApplication()'s own
 * CurrentConfigService::set() call -- called separately, and later, than
 * boot() for the identical reason: it must run after real DB credentials
 * are seeded (after InstallWizard::boot()'s own DbCredentials::seed(...)
 * call), or the ConfigService/Connection resolved and cached here would
 * carry stale ones for the rest of the request. Once called, every
 * Tier-2 class reachable from the install path
 * (Admin/themes.php/plugins.php/updates.php/
 * Cache/PermissionCacheInvalidator.php/Image/ImageService.php/
 * Page/NoPhotoYetRenderer.php/Template/Template.php/
 * Image/ImageStdParams.php/Core/UniqueExecLock.php) can safely call
 * CurrentConfigService::get() the same way they already do when reached
 * from the HTTP path (RequestBootstrap::connect() activates it there).
 */
final class InstallBootstrap
{
    public static function boot(Paths $paths): void
    {
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot($paths);

        // Without this call, install.php's "already installed"
        // InstallWizard::boot() -> HtmlService::fatalError() path produces
        // an uncaught PHP fatal error (500) instead of the intended clean
        // error page. See ErrorCollector::installIfConfigured()'s own
        // docblock.
        $errorCollector = Kernel::container()->get(ErrorCollector::class);
        if (! $errorCollector instanceof ErrorCollector) {
            throw new LogicException('Container returned an unexpected type for ' . ErrorCollector::class);
        }
        $errorCollector->installIfConfigured();
    }

    public static function activateConfigService(): void
    {
        $configService = Kernel::container()->get(ConfigService::class);
        if (! $configService instanceof ConfigService) {
            throw new LogicException('Container returned an unexpected type for ' . ConfigService::class);
        }
        $currentConfigService = Kernel::container()->get(CurrentConfigService::class);
        if (! $currentConfigService instanceof CurrentConfigService) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfigService::class);
        }
        $currentConfigService->set($configService);
    }

    /**
     * Resolves the container-shared instance so that `InstallWizard::
     * boot()`'s own `set()` write (the install path's no-RequestBootstrap
     * counterpart to `RequestBootstrap::connect()`'s own seeding) is
     * visible to every other consumer holding the same shared instance --
     * `InstallWizard` itself lives outside `Bootstrap/`, so this is called
     * from there rather than resolving `Kernel::container()` directly,
     * same shape as `activateConfigService()` above.
     */
    public static function currentLogger(): CurrentLogger
    {
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }

        return $currentLogger;
    }

    /**
     * Resolves the container-shared instance -- boot() above always runs
     * first on this class's own entry point (public/install.php), so
     * Kernel is always already booted by the time this is called, so no
     * Kernel::isBooted()-false fallback is needed here, same shape as
     * currentLogger() above.
     */
    public static function dbCredentials(): DbCredentials
    {
        $dbCredentials = Kernel::container()->get(DbCredentials::class);
        if (! $dbCredentials instanceof DbCredentials) {
            throw new LogicException('Container returned an unexpected type for ' . DbCredentials::class);
        }

        return $dbCredentials;
    }
}
