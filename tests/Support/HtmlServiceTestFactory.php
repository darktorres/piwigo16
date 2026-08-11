<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Piwigo\Category\CategoryRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Throwable;

/**
 * HtmlService's constructor requires 8 collaborators: CurrentConfig,
 * EventDispatcher, ProcessCache, ErrorCollector, CurrentUser,
 * CurrentTemplate, PageState, and Translator. `Lang` and `AccessControl`
 * are not among them -- HtmlService resolves both lazily itself; see its
 * own class docblock for the circular-dependency reasoning. This factory
 * resolves each of the 8 from the container-shared instance when one
 * exists (matching what every production caller gets, including any
 * test-seeded state on those instances), falling back to a fresh,
 * DB-free, bare instance for Unit tests that never boot a Kernel.
 */
final class HtmlServiceTestFactory
{
    public static function build(?CategoryRepository $categoryRepo = null): HtmlService
    {
        return new HtmlService(
            self::resolve(CurrentConfig::class) ?? new CurrentConfig(),
            self::resolve(EventDispatcher::class) ?? new EventDispatcher(),
            self::resolve(ProcessCache::class) ?? new ProcessCache(),
            self::resolve(ErrorCollector::class) ?? new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir())),
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
            // Kernel can be booted with no real Paths (e.g. in
            // ContainerSmokeTest.php), which makes Lang/CurrentConfig-
            // adjacent entries fail to resolve transitively. Falls back
            // to a bare instance, matching the "no Kernel::boot()"
            // branch above.
            return null;
        }

        return $instance instanceof $class ? $instance : null;
    }
}
