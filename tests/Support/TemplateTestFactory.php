<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\AdminContext;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\Template;

/**
 * Singleton/service-locator elimination campaign, Phase 11 sub-phase 11E:
 * Template's own real constructor now requires 8 collaborators
 * (CurrentConfig/Lang/AdminContext/EventDispatcher/PageState/
 * ErrorCollector/ProcessCache/CurrentConfigService) this test suite's
 * ~227 real call sites previously never had to supply (the shim reads
 * happened internally). ImageStdParams is NOT among them, despite being
 * one of the shims Template itself closes -- its own container factory
 * unconditionally hits the DB (`load_from_db()`), which made it unsafe as
 * a required constructor param (confirmed live: it broke public/install.php
 * before the schema even exists). Template resolves it lazily internally
 * instead (see Template::imageStdParams()'s own docblock). Resolves each
 * of the real 8 from the real container-shared instance when one exists
 * (matching what every real production caller already gets, including
 * honoring any test-seeded state on those instances), falling back to a
 * fresh, DB-free, bare instance for the many plain Unit tests that never
 * boot a Kernel at all -- same "no Kernel::boot(), type-satisfying
 * instance is enough" reasoning already established throughout this
 * campaign (see UrlServiceTestFactory's own docblock).
 */
final class TemplateTestFactory
{
    public static function build(string $root = '.', string $theme = '', string $path = 'template'): Template
    {
        return new Template(
            self::resolve(CurrentConfig::class) ?? new CurrentConfig(),
            self::resolve(Lang::class) ?? new Lang(new Translator(new CurrentConfig()), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag()),
            self::resolve(AdminContext::class) ?? new AdminContext(),
            self::resolve(EventDispatcher::class) ?? new EventDispatcher(),
            self::resolve(PageState::class) ?? new PageState(),
            self::resolve(ErrorCollector::class) ?? new ErrorCollector(new DeploymentPolicy()),
            self::resolve(ProcessCache::class) ?? new ProcessCache(),
            self::resolve(CurrentConfigService::class) ?? new CurrentConfigService(),
            $root,
            $theme,
            $path,
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
            // Paths" scenario) -- Lang-adjacent entries fail to resolve
            // transitively in that case, same as every other
            // REQUEST_SCOPED_ONLY_ENTRIES-class shim. Falls back to a bare
            // instance, matching the "no Kernel::boot()" branch above.
            return null;
        }

        return $instance instanceof $class ? $instance : null;
    }
}
