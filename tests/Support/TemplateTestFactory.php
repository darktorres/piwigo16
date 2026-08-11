<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Piwigo\Auth\AccessLevelChecker;
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
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
use Throwable;

/**
 * Resolves each collaborator from the real container-shared instance when
 * one exists, matching what every production caller gets (including any
 * test-seeded state on those instances). Falls back to a fresh, DB-free,
 * bare instance for tests that never boot a Kernel.
 *
 * ImageStdParams is not among the resolved collaborators: its container
 * factory unconditionally hits the DB (`load_from_db()`), which is unsafe
 * as a required constructor param before the schema exists (e.g.
 * public/install.php). Template resolves it lazily internally instead
 * (see Template::imageStdParams()).
 *
 * SessionService's container factory does not eagerly touch the DB
 * (SessionRepository extends Doctrine's lazy EntityRepository), so a
 * throwaway instance built from a fresh DbConnection::build() is safe as
 * the no-Kernel-booted fallback -- it is only queried if Template's
 * get_device modifier is invoked, which no production template does.
 */
final class TemplateTestFactory
{
    public static function build(string $root = '.', string $theme = '', string $path = 'template'): Template
    {
        $currentConfig = self::resolve(CurrentConfig::class) ?? new CurrentConfig();
        $currentUser = self::resolve(CurrentUser::class) ?? new CurrentUser($currentConfig);

        return new Template(
            $currentConfig,
            self::resolve(Lang::class) ?? new Lang(new Translator(new CurrentConfig()), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag()),
            self::resolve(AdminContext::class) ?? new AdminContext(),
            self::resolve(EventDispatcher::class) ?? new EventDispatcher(),
            self::resolve(PageState::class) ?? new PageState(),
            self::resolve(ErrorCollector::class) ?? new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir())),
            self::resolve(ProcessCache::class) ?? new ProcessCache(),
            self::resolve(CurrentConfigService::class) ?? new CurrentConfigService(),
            self::resolve(Paths::class) ?? Paths::fromRoot(sys_get_temp_dir()),
            self::resolve(AccessLevelChecker::class) ?? new AccessLevelChecker($currentUser, $currentConfig),
            self::resolve(SessionService::class) ?? new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), $currentConfig),
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
