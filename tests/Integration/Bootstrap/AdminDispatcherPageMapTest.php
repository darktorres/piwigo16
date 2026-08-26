<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Bootstrap;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\AdminDispatcher;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\IntroSubController;
use Piwigo\Core\AdminContext;
use Piwigo\Core\ApiContext;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\ThemeRepository;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\CurrentPluginRegistry;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\ExtensionContextFactory;
use Piwigo\PluginConfig\Facade\CategoryWriteFacade;
use Piwigo\PluginConfig\Facade\ImageReadFacade;
use Piwigo\PluginConfig\Facade\ImageWriteFacade;
use Piwigo\PluginConfig\Facade\ThemeReadFacade;
use Piwigo\PluginConfig\Facade\UserReadFacade;
use Piwigo\PluginConfig\PluginMigrationRepository;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\PluginConfig\PluginRepository;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Tests\Integration\IntegrationTestCase;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

/**
 * `Bootstrap\AdminDispatcher::pageMap()` (P43-E) -- the merge step this
 * fork's own `PluginRegistryTest.php` doesn't reach (that file stops at
 * `PluginRegistry::adminPages()` itself, confirming it aggregates every
 * active plugin's own contributed slug and throws on an inter-plugin
 * collision). This file covers the one thing only `pageMap()` itself
 * does: merging that plugin-contributed map onto the real, static
 * `config/admin_pages.php` map, and refusing a plugin-vs-core slug
 * collision the same way.
 */
final class AdminDispatcherPageMapTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private PluginRepository $repository;

    private PluginMigrationRepository $migrationRepository;

    private EventDispatcher $eventDispatcher;

    private ExtensionContextFactory $contextFactory;

    /**
     * @var list<string>
     */
    private array $tempDirs = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 3) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        $this->conn = DbConnection::build();

        $currentUser = Kernel::container()->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
        }
        $currentUser->attachGlobals();

        $this->repository = $this->containerGet(PluginRepository::class);
        $this->migrationRepository = $this->containerGet(PluginMigrationRepository::class);
        $this->eventDispatcher = $this->containerGet(EventDispatcher::class);

        $imageReadFacade = new ImageReadFacade(
            $this->containerGet(CaddieRepository::class),
            $this->containerGet(ImageRepository::class),
            $this->containerGet(CategoryRepository::class),
        );
        $imageWriteFacade = new ImageWriteFacade(
            $this->containerGet(ImageService::class),
            $this->containerGet(TagService::class),
            $this->containerGet(UrlServiceInterface::class),
        );
        $categoryWriteFacade = new CategoryWriteFacade($this->containerGet(CategoryService::class));
        $this->contextFactory = new ExtensionContextFactory(
            $this->containerGet(CurrentTemplate::class),
            $this->containerGet(CurrentConfig::class),
            $currentUser,
            $this->containerGet(UserService::class),
            $this->containerGet(Lang::class),
            $this->containerGet(UrlServiceInterface::class),
            $this->containerGet(RedirectServiceInterface::class),
            $this->containerGet(AdminContext::class),
            $this->containerGet(ApiContext::class),
            $this->eventDispatcher,
            $this->containerGet(SessionService::class),
            $imageReadFacade,
            $this->containerGet(Paths::class),
            $this->containerGet(ConfigService::class),
            $this->containerGet(EntityManagerInterface::class),
            $this->containerGet(MailService::class),
            new UserReadFacade($this->containerGet(UserRepository::class)),
            new ThemeReadFacade($this->containerGet(ThemeRepository::class)),
            $this->containerGet(CsrfService::class),
            $this->containerGet(HtmlRenderingInterface::class),
            $this->containerGet(AccessControl::class),
            $imageWriteFacade,
            $categoryWriteFacade,
            new Renderer($this->containerGet(CurrentTemplate::class)),
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement("DELETE FROM plugin_migrations WHERE plugin_id LIKE 'zz-%'");
        $this->conn->executeStatement("DELETE FROM plugins WHERE id LIKE 'zz-%'");
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function containerGet(string $class): object
    {
        $instance = Kernel::container()->get($class);
        if (! $instance instanceof $class) {
            throw new LogicException('Container returned an unexpected type for ' . $class);
        }

        return $instance;
    }

    private function buildRegistry(string $pluginsDir): PluginRegistry
    {
        $base = Paths::fromRoot(dirname(__DIR__, 3));
        $paths = new Paths(
            root: $base->root,
            plugins: $pluginsDir,
            themes: $base->themes,
            local: $base->local,
            siteLocal: $base->siteLocal,
            data: $base->data,
            derivatives: $base->derivatives,
            logs: $base->logs,
            upload: $base->upload,
            config: $base->config,
            vendor: $base->vendor,
        );

        return new PluginRegistry(
            $this->repository,
            $this->migrationRepository,
            $this->eventDispatcher,
            $this->contextFactory,
            $this->containerGet(CurrentConfig::class),
            $paths,
            $this->conn,
        );
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/piwigo_admin_dispatcher_page_map_test_' . uniqid('', true);
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Writes a fixture plugin implementing `AdminPageProviderInterface`,
     * contributing the one `$slug` given -- same fixture-writing shape as
     * `PluginRegistryTest.php`'s own equivalent, just pinned to the exact
     * slug each test needs.
     */
    private function writeFixtureAdminPagesPlugin(string $pluginsDir, string $id, string $namespaceSuffix, string $slug): void
    {
        $dir = $pluginsDir . '/' . $id;
        mkdir($dir . '/src', 0o777, true);

        $namespace = 'PiwigoTest\\AdminPagesFixture' . $namespaceSuffix;
        $className = 'Plugin' . $namespaceSuffix;

        file_put_contents($dir . '/plugin.json', json_encode([
            'id' => $id,
            'name' => $id,
            'version' => '1.0.0',
            'description' => 'Fixture plugin contributing its own admin page slug',
            'license' => 'MIT',
            'minPiwigo' => '16.3.0',
            'main' => $namespace . '\\' . $className,
            'hasAdminPages' => true,
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\' => 'src/',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        file_put_contents($dir . '/src/' . $className . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Piwigo\\PluginConfig\\AdminPageProviderInterface;
            use Piwigo\\PluginConfig\\ExtensionContext;
            use Piwigo\\PluginConfig\\ExtensionInterface;

            final class {$className} implements ExtensionInterface, AdminPageProviderInterface
            {
                public function boot(ExtensionContext \$context): void {}
                public function install(): void {}
                public function activate(): void {}
                public function deactivate(): void {}
                public function uninstall(): void {}
                public function update(string \$oldVersion, string \$newVersion): void {}
                public function subscribedEvents(): array { return []; }

                public function registerAdminPages(): array
                {
                    return ['{$slug}' => \\Piwigo\\Controller\\Admin\\IntroSubController::class];
                }
            }
            PHP);
    }

    public function testPageMapMergesABootedPluginsOwnAdminPageSlug(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-page-map-' . $suffix;
        $slug = 'zz_page_map_slug_' . $suffix;
        $this->writeFixtureAdminPagesPlugin($dir, $id, 'Merge' . $suffix, $slug);

        $registry = $this->buildRegistry($dir);
        $registry->install($id);
        $registry->activate($id);
        $registry->bootActive();

        $this->containerGet(CurrentPluginRegistry::class)->set($registry);

        $pages = AdminDispatcher::pageMap();

        self::assertSame(IntroSubController::class, $pages[$slug] ?? null);
        // The static core map must still be fully present alongside the
        // merged-in plugin slug, not replaced by it.
        self::assertArrayHasKey('intro', $pages);
    }

    public function testPageMapThrowsWhenAPluginSlugCollidesWithAStaticCoreSlug(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-page-map-collision-' . $suffix;
        // 'intro' is a real, static config/admin_pages.php slug.
        $this->writeFixtureAdminPagesPlugin($dir, $id, 'Collision' . $suffix, 'intro');

        $registry = $this->buildRegistry($dir);
        $registry->install($id);
        $registry->activate($id);
        $registry->bootActive();

        $this->containerGet(CurrentPluginRegistry::class)->set($registry);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('intro');
        AdminDispatcher::pageMap();
    }
}
