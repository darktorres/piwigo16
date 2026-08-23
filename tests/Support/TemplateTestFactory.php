<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
use Throwable;

/**
 * Resolves each collaborator from the real container-shared instance when
 * one exists, matching what every production caller gets (including any
 * test-seeded state on those instances). Falls back to a fresh, DB-free,
 * bare instance for tests that never boot a Kernel.
 *
 * ImageStdParams' own container factory (config/container.php) is hardened
 * against both a missing table and an unavailable connection, degrading
 * to its own "not yet loaded" baseline rather than throwing -- safe as a
 * required constructor param even before the schema exists (e.g.
 * public/install.php).
 */
final class TemplateTestFactory
{
    public static function build(string $root = '.', string $theme = '', string $path = 'template'): Template
    {
        $currentConfig = self::resolve(CurrentConfig::class) ?? new CurrentConfig();
        $currentUser = self::resolve(CurrentUser::class) ?? new CurrentUser($currentConfig);

        return new Template(
            $currentConfig,
            self::resolve(Lang::class) ?? new Lang(new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag()),
            self::resolve(EventDispatcher::class) ?? new EventDispatcher(),
            self::resolve(ProcessCache::class) ?? new ProcessCache(),
            self::resolve(CurrentConfigService::class) ?? new CurrentConfigService(),
            self::resolve(Paths::class) ?? Paths::fromRoot(sys_get_temp_dir()),
            self::resolve(AccessLevelChecker::class) ?? new AccessLevelChecker($currentUser, $currentConfig),
            self::resolve(UrlServiceInterface::class) ?? UrlServiceTestFactory::build(),
            self::resolve(PageState::class) ?? new PageState(),
            self::resolve(HtmlRenderingInterface::class) ?? HtmlServiceTestFactory::build(),
            self::resolve(ImageStdParams::class) ?? new ImageStdParams(),
            $root,
            $theme === '' ? null : ThemeId::from($theme),
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
        } catch (Throwable) {
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
