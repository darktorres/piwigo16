<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Throwable;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\ProcessCache;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;

/**
 * Singleton/service-locator elimination campaign, Phase 11 sub-phase 11E:
 * HtmlService's own real constructor now requires 8 collaborators
 * (CurrentConfig/EventDispatcher/ProcessCache/ErrorCollector/CurrentUser/
 * CurrentTemplate/PageState/Translator) this test suite's ~37 real call
 * sites previously never had to supply (the shim reads happened
 * internally). `Lang`/`AccessControl` are NOT among them -- HtmlService
 * itself resolves both lazily, see its own class docblock for the real
 * circular-dependency reasoning. Resolves each of the 8 from the real
 * container-shared instance when one exists (matching what every real
 * production caller already gets, including honoring any test-seeded
 * state on those instances), falling back to a fresh, DB-free, bare
 * instance for the many plain Unit tests that never boot a Kernel at all
 * -- same "no Kernel::boot(), type-satisfying instance is enough"
 * reasoning already established throughout this campaign (see
 * UrlServiceTestFactory's own docblock).
 */
final class HtmlServiceTestFactory
{
    public static function build(?CategoryRepository $categoryRepo = null): HtmlService
    {
        return new HtmlService(
            self::resolve(CurrentConfig::class) ?? new CurrentConfig(),
            self::resolve(EventDispatcher::class) ?? new EventDispatcher(),
            self::resolve(ProcessCache::class) ?? new ProcessCache(),
            self::resolve(ErrorCollector::class) ?? new ErrorCollector(new DeploymentPolicy()),
            self::resolve(CurrentUser::class) ?? new CurrentUser(new CurrentConfig()),
            self::resolve(CurrentTemplate::class) ?? new CurrentTemplate(),
            self::resolve(PageState::class) ?? new PageState(),
            self::resolve(Translator::class) ?? new Translator(new CurrentConfig()),
            $categoryRepo,
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
        } catch (Throwable) {
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
