<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
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
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\ExtensionContext;
use Piwigo\PluginConfig\ExtensionContextFactory;
use Piwigo\PluginConfig\Facade\CategoryWriteFacade;
use Piwigo\PluginConfig\Facade\ImageReadFacade;
use Piwigo\PluginConfig\Facade\ImageWriteFacade;
use Piwigo\PluginConfig\Facade\ThemeReadFacade;
use Piwigo\PluginConfig\Facade\UserReadFacade;
use Piwigo\PluginConfig\SettingsPageInterface;
use Piwigo\PluginConfig\ThemeDependencyException;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\PluginConfig\ThemeValidationException;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

/**
 * Test-only typed change-event -- `dispatch()`-shaped (each handler runs
 * against the same event instance, mutating whatever the previous
 * handler already changed), used to prove `ThemeRegistry::bootCurrent()`'s
 * parent-chain dispatch order.
 */
final class ThemeRegistryTestFakeChangeEvent
{
    public string $tag = 'untouched';
}

/**
 * Covers ThemeRegistry end-to-end against a real DB + real filesystem
 * scan + real runtime class autoloading -- fixture themes are written to
 * a fresh temp directory per test (each with a unique class/namespace
 * suffix, since PHP can't redeclare a class and Pest runs every test
 * file in one shared process).
 */
final class ThemeRegistryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private ThemeRepository $repository;

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
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $this->conn = DbConnection::build();

        $currentUser = Kernel::container()->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
        }
        $currentUser->attachGlobals();

        $this->repository = $this->containerGet(ThemeRepository::class);
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
            new ThemeReadFacade($this->repository),
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
        $this->conn->executeStatement("DELETE FROM themes WHERE id LIKE 'zz-%'");
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
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

    private function buildRegistry(string $themesDir): ThemeRegistry
    {
        $currentConfig = $this->containerGet(CurrentConfig::class);
        // themesPath is a get-only property hook derived from themesDir
        // ($this->themesDir . '/') -- no trailing slash here, themesPath
        // itself appends it.
        $currentConfig->themesDir = rtrim($themesDir, '/');

        return new ThemeRegistry(
            $this->repository,
            $this->eventDispatcher,
            $this->contextFactory,
            $currentConfig,
            Paths::fromRoot(dirname(__DIR__, 2)),
        );
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/piwigo_theme_registry_test_' . uniqid('', true);
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * Writes a fixture theme: `theme.json` + a real PHP class file
     * implementing `ExtensionInterface`, PSR-4-autoloadable via the
     * manifest's own `autoload.psr-4` map. Every fixture subscribes to
     * `ThemeRegistryTestFakeChangeEvent`, tagging `$event->tag` with its
     * own suffix -- `bootCurrent()`'s dispatch-order test relies on this
     * to prove the child's tag survives (child registered/booted last).
     *
     * @param array<string, string> $require
     */
    private function writeFixtureTheme(
        string $themesDir,
        string $id,
        string $namespaceSuffix,
        ?string $parent = null,
        string $version = '1.0.0',
        array $require = [],
    ): void {
        $dir = $themesDir . '/' . $id;
        if (! is_dir($dir . '/src')) {
            mkdir($dir . '/src', 0o777, true);
        }

        $namespace = 'PiwigoTest\\ThemeFixture' . $namespaceSuffix;
        $className = 'Theme' . $namespaceSuffix;

        $manifest = [
            'id' => $id,
            'name' => $id,
            'version' => $version,
            'description' => 'Fixture theme for ThemeRegistryTest',
            'license' => 'MIT',
            'minPiwigo' => '16.3.0',
            'main' => $namespace . '\\' . $className,
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\' => 'src/',
                ],
            ],
        ];
        if ($parent !== null) {
            $manifest['parent'] = $parent;
        }
        if ($require !== []) {
            $manifest['require'] = $require;
        }

        file_put_contents($dir . '/theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $classSource = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Piwigo\\PluginConfig\\ExtensionContext;
            use Piwigo\\PluginConfig\\ExtensionInterface;
            use Piwigo\\Tests\\Integration\\ThemeRegistryTestFakeChangeEvent;

            final class {$className} implements ExtensionInterface
            {
                public static bool \$installed = false;
                public static bool \$activated = false;
                public static bool \$deactivated = false;
                public static bool \$uninstalled = false;
                public static ?array \$updatedFromTo = null;
                public static ?ExtensionContext \$receivedContext = null;
                public static bool \$booted = false;

                public function boot(ExtensionContext \$context): void
                {
                    self::\$receivedContext = \$context;
                    self::\$booted = true;
                }

                public function install(): void { self::\$installed = true; }
                public function activate(): void { self::\$activated = true; }
                public function deactivate(): void { self::\$deactivated = true; }
                public function uninstall(): void { self::\$uninstalled = true; }
                public function update(string \$oldVersion, string \$newVersion): void
                {
                    self::\$updatedFromTo = [\$oldVersion, \$newVersion];
                }

                public function subscribedEvents(): array
                {
                    return [
                        ThemeRegistryTestFakeChangeEvent::class => \$this->onFakeEvent(...),
                    ];
                }

                public function onFakeEvent(ThemeRegistryTestFakeChangeEvent \$event): ThemeRegistryTestFakeChangeEvent
                {
                    \$event->tag = '{$namespaceSuffix}';

                    return \$event;
                }
            }

            PHP;

        file_put_contents($dir . '/src/' . $className . '.php', $classSource);
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
     * The exact scenario the P27 plan calls out by name: a 2-level
     * parent/child theme pair, both subscribing to the same
     * `dispatch()`-shaped event with distinguishably-tagged
     * handlers. Asserts the *child's* tag is what survives in the final
     * returned event (child boots last, furthest-ancestor-first chain
     * order) -- not merely that both handlers ran, which a
     * self-first/parent-last (the "natural-seeming" but wrong) order
     * would also satisfy.
     */
    public function testBootCurrentDispatchOrderGivesTheChildTheFinalWord(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $parentId = 'zz-parent-' . $suffix;
        $childId = 'zz-child-' . $suffix;
        $this->writeFixtureTheme($dir, $parentId, 'Parent' . $suffix);
        $this->writeFixtureTheme($dir, $childId, 'Child' . $suffix, parent: $parentId);

        $registry = $this->buildRegistry($dir);
        $registry->bootCurrent(ThemeId::from($childId));

        $event = new ThemeRegistryTestFakeChangeEvent();
        $result = $this->eventDispatcher->dispatch($event);

        self::assertSame('Child' . $suffix, $result->tag, 'the child theme must have the final word in the dispatch pipeline');
    }

    public function testBootCurrentBootsBothParentAndChildWithARealExtensionContext(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $parentId = 'zz-both-parent-' . $suffix;
        $childId = 'zz-both-child-' . $suffix;
        $this->writeFixtureTheme($dir, $parentId, 'BothParent' . $suffix);
        $this->writeFixtureTheme($dir, $childId, 'BothChild' . $suffix, parent: $parentId);

        $registry = $this->buildRegistry($dir);
        $registry->bootCurrent(ThemeId::from($childId));

        $parentClass = 'PiwigoTest\\ThemeFixtureBothParent' . $suffix . '\\ThemeBothParent' . $suffix;
        $childClass = 'PiwigoTest\\ThemeFixtureBothChild' . $suffix . '\\ThemeBothChild' . $suffix;

        self::assertTrue($parentClass::$booted);
        self::assertTrue($childClass::$booted);
        self::assertInstanceOf(ExtensionContext::class, $parentClass::$receivedContext);
        self::assertInstanceOf(ExtensionContext::class, $childClass::$receivedContext);
    }

    public function testActivateDeactivateRoundTrip(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-roundtrip-' . $suffix;
        $this->writeFixtureTheme($dir, $id, 'Roundtrip' . $suffix);
        $className = 'PiwigoTest\\ThemeFixtureRoundtrip' . $suffix . '\\ThemeRoundtrip' . $suffix;

        $registry = $this->buildRegistry($dir);

        self::assertFalse($registry->isInstalled($id));

        // Real regression test for a genuine bug this exact fix closed --
        // see PluginRegistryTest::testInstallActivateDeactivateUninstallRoundTrip()'s
        // own identical note for the full story (ThemeRegistry had the
        // exact same gap as PluginRegistry: every lifecycle-invoking
        // method called bootInstance() -- bare `new $class()` -- without
        // ever calling boot() first). No reset-to-null before this first
        // activate() call: $className isn't autoloadable yet -- see
        // PluginRegistryTest's own identical note.
        $registry->activate($id);
        self::assertTrue($className::$activated);
        self::assertTrue($registry->isInstalled($id));
        self::assertNotNull($className::$receivedContext, 'boot() must run before activate()');

        $className::$receivedContext = null;
        $registry->deactivate($id);
        self::assertTrue($className::$deactivated);
        self::assertFalse($registry->isInstalled($id));
        self::assertNotNull($className::$receivedContext, 'boot() must run before deactivate()');
    }

    /**
     * Deactivating/uninstalling a child must not affect the parent's own
     * independent state -- the parent theme's own `themes` row and its
     * own installed/active status are unrelated to whatever the child
     * does.
     */
    public function testDeactivatingTheChildDoesNotAffectTheParentsIndependentState(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $parentId = 'zz-indep-parent-' . $suffix;
        $childId = 'zz-indep-child-' . $suffix;
        $this->writeFixtureTheme($dir, $parentId, 'IndepParent' . $suffix);
        $this->writeFixtureTheme($dir, $childId, 'IndepChild' . $suffix, parent: $parentId);

        $registry = $this->buildRegistry($dir);
        $registry->activate($parentId);
        $registry->activate($childId);

        self::assertTrue($registry->isInstalled($parentId));
        self::assertTrue($registry->isInstalled($childId));

        $registry->deactivate($childId);

        self::assertFalse($registry->isInstalled($childId));
        self::assertTrue($registry->isInstalled($parentId), 'deactivating the child must not deactivate the parent');
    }

    public function testDeactivateRefusesWhileAnActiveDependentStillRequiresIt(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $depId = 'zz-req-dep-' . $suffix;
        $dependentId = 'zz-req-dependent-' . $suffix;
        $this->writeFixtureTheme($dir, $depId, 'ReqDep' . $suffix);
        $this->writeFixtureTheme($dir, $dependentId, 'ReqDependent' . $suffix, require: [
            'theme/' . $depId => '*',
        ]);

        $registry = $this->buildRegistry($dir);
        $registry->activate($depId);
        $registry->activate($dependentId);

        $this->expectException(ThemeDependencyException::class);
        $registry->deactivate($depId);
    }

    public function testInstallUninstallUpdateAndReloadAreCallableAndReflectARealScan(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-lifecycle-' . $suffix;
        $this->writeFixtureTheme($dir, $id, 'Lifecycle' . $suffix, version: '1.0.0');
        $className = 'PiwigoTest\\ThemeFixtureLifecycle' . $suffix . '\\ThemeLifecycle' . $suffix;

        $registry = $this->buildRegistry($dir);

        // install()/uninstall() carry no DB write of their own for themes
        // (see ThemeRegistry's own class docblock) -- just real, callable
        // hooks on the theme's own class. No reset-to-null before this
        // first install() call: $className isn't autoloadable yet -- see
        // PluginRegistryTest's own identical note.
        $registry->install($id);
        self::assertTrue($className::$installed);
        self::assertNotNull($className::$receivedContext, 'boot() must run before install()');

        $className::$receivedContext = null;
        $registry->uninstall($id);
        self::assertTrue($className::$uninstalled);
        self::assertNotNull($className::$receivedContext, 'boot() must run before uninstall()');

        self::assertArrayHasKey($id, $registry->getAllManifests());

        // update() is a no-op when versions match.
        $registry->update($id);
        self::assertNull($className::$updatedFromTo);

        // Bump the on-disk manifest version and reload() to pick it up.
        $this->writeFixtureTheme($dir, $id, 'Lifecycle' . $suffix, version: '2.0.0');
        $registry->activate($id);
        $registry->reload();
        $className::$receivedContext = null;
        $registry->update($id);

        self::assertSame(['1.0.0', '2.0.0'], $className::$updatedFromTo);
        self::assertNotNull($className::$receivedContext, 'boot() must run before update()');
    }

    public function testThemeValidationExceptionCarriesTheFailingIdAndManifestPath(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-invalid-manifest-' . $suffix;
        mkdir($dir . '/' . $id, 0o777, true);
        file_put_contents($dir . '/' . $id . '/theme.json', '{not valid json');

        $registry = $this->buildRegistry($dir);

        try {
            $registry->activate($id);
            self::fail('activate() must throw for a theme with no valid manifest');
        } catch (ThemeValidationException $e) {
            self::assertSame($id, $e->themeId);
            self::assertSame($dir . '/' . $id . '/theme.json', $e->manifestPath);
        }
    }

    public function testThemeDependencyExceptionCarriesTheFailingThemeId(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-dep-exc-' . $suffix;
        $this->writeFixtureTheme($dir, $id, 'DepExc' . $suffix, require: [
            'piwigo' => '>=999.0.0',
        ]);

        $registry = $this->buildRegistry($dir);

        try {
            $registry->activate($id);
            self::fail('activate() must throw when require: is unsatisfiable');
        } catch (ThemeDependencyException $e) {
            self::assertSame($id, $e->themeId);
        }
    }

    /**
     * activate() -- same manifest-authoring check as
     * `PluginRegistryTest`'s own identically-named test.
     */
    public function testActivateThrowsWhenHasSettingsIsDeclaredButSettingsPageInterfaceIsNotImplemented(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-missing-settings-contract-' . $suffix;
        $namespace = 'PiwigoTest\\ThemeFixtureMissingSettings' . $suffix;
        $className = 'Theme' . $suffix;
        mkdir($dir . '/' . $id . '/src', 0o777, true);

        file_put_contents($dir . '/' . $id . '/theme.json', json_encode([
            'id' => $id,
            'name' => $id,
            'version' => '1.0.0',
            'description' => 'Fixture theme declaring hasSettings without implementing SettingsPageInterface',
            'license' => 'MIT',
            'minPiwigo' => '16.3.0',
            'main' => $namespace . '\\' . $className,
            'hasSettings' => true,
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\' => 'src/',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        file_put_contents($dir . '/' . $id . '/src/' . $className . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Piwigo\\PluginConfig\\ExtensionContext;
            use Piwigo\\PluginConfig\\ExtensionInterface;

            final class {$className} implements ExtensionInterface
            {
                public function boot(ExtensionContext \$context): void {}
                public function install(): void {}
                public function activate(): void {}
                public function deactivate(): void {}
                public function uninstall(): void {}
                public function update(string \$oldVersion, string \$newVersion): void {}
                public function subscribedEvents(): array { return []; }
            }
            PHP);

        $registry = $this->buildRegistry($dir);

        try {
            $registry->activate($id);
            self::fail('activate() must throw when hasSettings is declared but SettingsPageInterface is not implemented');
        } catch (ThemeValidationException $e) {
            self::assertSame($id, $e->themeId);
            self::assertStringContainsString('SettingsPageInterface', $e->getMessage());
        }
    }

    /**
     * bootForSettingsPage() -- boots a theme that is NOT the
     * current request's own theme (never touched via bootCurrent() in
     * this test), proving the real reason this method exists separately:
     * an admin can open any installed theme's settings page.
     */
    public function testBootForSettingsPageBootsANonCurrentThemeAndReturnsARealSettingsPageInstance(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-settings-page-' . $suffix;
        $namespace = 'PiwigoTest\\ThemeFixtureSettingsPage' . $suffix;
        $className = 'Theme' . $suffix;
        mkdir($dir . '/' . $id . '/src', 0o777, true);

        file_put_contents($dir . '/' . $id . '/theme.json', json_encode([
            'id' => $id,
            'name' => $id,
            'version' => '1.0.0',
            'description' => 'Fixture theme implementing SettingsPageInterface',
            'license' => 'MIT',
            'minPiwigo' => '16.3.0',
            'main' => $namespace . '\\' . $className,
            'hasSettings' => true,
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\' => 'src/',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        file_put_contents($dir . '/' . $id . '/src/' . $className . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Piwigo\\Core\\View;
            use Piwigo\\PluginConfig\\ExtensionContext;
            use Piwigo\\PluginConfig\\ExtensionInterface;
            use Piwigo\\PluginConfig\\SettingsPageInterface;
            use Psr\\Http\\Message\\ServerRequestInterface;

            final class {$className} implements ExtensionInterface, SettingsPageInterface
            {
                public static ?ExtensionContext \$receivedContext = null;
                public static ?ServerRequestInterface \$receivedRequest = null;

                public function boot(ExtensionContext \$context): void
                {
                    self::\$receivedContext = \$context;
                }

                public function install(): void {}
                public function activate(): void {}
                public function deactivate(): void {}
                public function uninstall(): void {}
                public function update(string \$oldVersion, string \$newVersion): void {}
                public function subscribedEvents(): array { return []; }

                public function handleSettingsRequest(ServerRequestInterface \$request): View
                {
                    self::\$receivedRequest = \$request;

                    return new class implements View {};
                }
            }
            PHP);

        $registry = $this->buildRegistry($dir);
        $fqcn = $namespace . '\\' . $className;

        $instance = $registry->bootForSettingsPage($id);

        self::assertInstanceOf(SettingsPageInterface::class, $instance);
        self::assertInstanceOf(ExtensionContext::class, $fqcn::$receivedContext);

        $request = new ServerRequest('GET', '/admin.php');
        $instance->handleSettingsRequest($request);
        self::assertSame($request, $fqcn::$receivedRequest);
    }
}
