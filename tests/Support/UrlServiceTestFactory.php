<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\RequestMountDepth;
use Piwigo\Core\WsContext;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Url\RootPathOverride;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;

/**
 * Singleton/service-locator elimination campaign, Phase 11 sub-phase 11E:
 * UrlService's own real constructor now requires 8 collaborators
 * (SectionContextRegistry/RequestMountDepth/CurrentConfig/
 * DeploymentPolicy/WsContext/CurrentUser/Lang/EventDispatcher) this test
 * suite's ~280 real call sites previously never had to supply (the shim
 * reads happened internally). Resolves each from the real
 * container-shared instance when one exists (matching what every real
 * production caller already gets, including honoring any test-seeded
 * state on those instances), falling back to a fresh, DB-free, bare
 * instance for the many plain Unit tests that never boot a Kernel at all
 * -- mirrors the "no Kernel::boot(), type-satisfying instance is enough"
 * reasoning already established throughout this campaign (e.g.
 * CoreUpdateServiceTest's own core_update_service_test_lang()).
 */
final class UrlServiceTestFactory
{
    public static function build(?HtmlRenderingInterface $htmlRenderer = null, ?RootPathOverride $rootPathOverride = null): UrlService
    {
        return new UrlService(
            $htmlRenderer ?? new HtmlService(),
            $rootPathOverride ?? new RootPathOverride(),
            self::resolve(SectionContextRegistry::class) ?? new SectionContextRegistry(),
            self::resolve(RequestMountDepth::class) ?? new RequestMountDepth(),
            self::resolve(CurrentConfig::class) ?? new CurrentConfig(),
            self::resolve(DeploymentPolicy::class) ?? new DeploymentPolicy(),
            self::resolve(WsContext::class) ?? new WsContext(),
            self::resolve(CurrentUser::class) ?? new CurrentUser(new CurrentConfig()),
            self::resolve(Lang::class) ?? new Lang(new Translator(new CurrentConfig()), new HtmlService(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag()),
            self::resolve(EventDispatcher::class) ?? new EventDispatcher(),
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    private static function resolve(string $class): ?object
    {
        if (! Kernel::isBooted()) {
            return null;
        }

        try {
            $instance = Kernel::container()->get($class);
        } catch (\Throwable) {
            // Some callers boot Kernel with no real Paths (e.g.
            // ContainerSmokeTest.php's own "Kernel booted with no real
            // Paths" scenario) -- Lang/CurrentConfig-adjacent entries
            // fail to resolve transitively in that case, same as every
            // other REQUEST_SCOPED_ONLY_ENTRIES-class shim. Falls back to
            // a bare instance, matching the "no Kernel::boot()" branch
            // above.
            return null;
        }

        return $instance instanceof $class ? $instance : null;
    }
}
