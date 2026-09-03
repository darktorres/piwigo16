<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\RequestMountDepth;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Url\RootPathOverride;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Throwable;

/**
 * Resolves each collaborator from the real container-shared instance when
 * one exists, matching what every production caller gets (including any
 * test-seeded state on those instances). Falls back to a fresh, DB-free,
 * bare instance for tests that never boot a Kernel.
 */
final class UrlServiceTestFactory
{
    public static function build(?HtmlRenderingInterface $htmlRenderer = null, ?RootPathOverride $rootPathOverride = null): UrlService
    {
        return new UrlService(
            $htmlRenderer ?? HtmlServiceTestFactory::build(),
            $rootPathOverride ?? new RootPathOverride(),
            self::resolve(SectionContextRegistry::class) ?? new SectionContextRegistry(),
            self::resolve(RequestMountDepth::class) ?? new RequestMountDepth(),
            self::resolve(CurrentConfig::class) ?? new CurrentConfig(),
            self::resolve(DeploymentPolicy::class) ?? new DeploymentPolicy(),
            self::resolve(CurrentUser::class) ?? new CurrentUser(new CurrentConfig()),
            self::resolve(Lang::class) ?? new Lang(new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag()),
            self::resolve(EventDispatcher::class) ?? new EventDispatcher(),
            self::resolve(EntityManagerInterface::class) ?? EntityManagerFactory::build(DbConnection::build()),
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
