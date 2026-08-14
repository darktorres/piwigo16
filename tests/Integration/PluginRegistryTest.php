<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AdminContext;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Image\ImageRepository;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\ExtensionContextFactory;
use Piwigo\PluginConfig\Facade\ImageReadFacade;
use Piwigo\PluginConfig\PluginDependencyException;
use Piwigo\PluginConfig\PluginMigrationRepository;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\PluginConfig\PluginRepository;
use Piwigo\PluginConfig\PluginValidationException;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

/**
 * Test-only typed notification -- see `writeFixturePlugin()`'s own
 * docblock for how this proves `PluginRegistry::bootActive()`'s
 * two-pass instance identity.
 */
final class PluginRegistryTestFakeEvent {}

/**
 * Covers PluginRegistry end-to-end against a real DB + real filesystem
 * scan + real runtime class autoloading -- fixture plugins are written
 * to a fresh temp directory per test (each with a unique class/namespace
 * suffix, since PHP can't redeclare a class and Pest runs every test
 * file in one shared process).
 */
final class PluginRegistryTest extends IntegrationTestCase
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

        $this->repository = $this->containerGet(PluginRepository::class);
        $this->migrationRepository = $this->containerGet(PluginMigrationRepository::class);
        $this->eventDispatcher = $this->containerGet(EventDispatcher::class);

        $imageReadFacade = new ImageReadFacade(
            $this->containerGet(CaddieRepository::class),
            $this->containerGet(ImageRepository::class),
            $this->containerGet(CategoryRepository::class),
        );
        $this->contextFactory = new ExtensionContextFactory(
            $this->containerGet(CurrentTemplate::class),
            $this->containerGet(CurrentConfig::class),
            $currentUser,
            $this->containerGet(UserService::class),
            $this->containerGet(Lang::class),
            $this->containerGet(UrlServiceInterface::class),
            $this->containerGet(RedirectServiceInterface::class),
            $this->containerGet(AdminContext::class),
            $this->eventDispatcher,
            $this->containerGet(SessionService::class),
            $imageReadFacade,
            $this->containerGet(Paths::class),
            $this->containerGet(ConfigService::class),
            $this->containerGet(EntityManagerInterface::class),
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        // Every fixture plugin id in this file starts with 'zz-' -- a
        // blanket prefix delete is simpler and more robust than tracking
        // exactly which rows each individual test left behind (some
        // tests deliberately leave a plugin active to prove a guard
        // refused to remove it).
        $this->conn->executeStatement("DELETE FROM plugins WHERE id LIKE 'zz-%'");
        $this->conn->executeStatement("DELETE FROM plugin_migrations WHERE plugin_id LIKE 'zz-%'");
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

    private function buildRegistry(string $pluginsDir): PluginRegistry
    {
        $base = Paths::fromRoot(dirname(__DIR__, 2));
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
        );
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/piwigo_plugin_registry_test_' . uniqid('', true);
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * Writes a fixture plugin: `plugin.json` + a real PHP class file
     * implementing `ExtensionInterface`, PSR-4-autoloadable via the
     * manifest's own `autoload.psr-4` map. `$namespaceSuffix` must be
     * unique across the whole test run -- PHP can't redeclare a class,
     * and Pest runs every test file in one shared process.
     *
     * Every fixture subscribes to `PluginRegistryTestFakeEvent` (its own
     * handler records `$ownState` -- whatever `boot()` last set on
     * `$this` -- onto the event) and, when `$dispatchOnBoot` is true,
     * dispatches that same event from its own `boot()`. This is what
     * lets `testBootActiveUsesTheSameInstanceAcrossBothPasses()` prove
     * real instance identity: if `subscribedEvents()`'s registration
     * pass and `boot()`'s pass ever ran against two different
     * constructed objects, the handler bound during pass 1 would read a
     * stale, never-booted `$ownState` when pass 2's `boot()` dispatches
     * the event, not the value `boot()` itself just set.
     *
     * @param array<string, string> $require
     */
    private function writeFixturePlugin(
        string $pluginsDir,
        string $id,
        string $namespaceSuffix,
        array $require = [],
        string $version = '1.0.0',
        bool $dispatchOnBoot = false,
    ): void {
        $dir = $pluginsDir . '/' . $id;
        if (! is_dir($dir . '/src')) {
            mkdir($dir . '/src', 0o777, true);
        }

        $namespace = 'PiwigoTest\\Fixture' . $namespaceSuffix;
        $className = 'Plugin' . $namespaceSuffix;

        $manifest = [
            'id' => $id,
            'name' => $id,
            'version' => $version,
            'description' => 'Fixture plugin for PluginRegistryTest',
            'license' => 'MIT',
            'minPiwigo' => '16.3.0',
            'main' => $namespace . '\\' . $className,
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\' => 'src/',
                ],
            ],
        ];
        if ($require !== []) {
            $manifest['require'] = $require;
        }

        file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $dispatchCall = $dispatchOnBoot
            ? '$context->dispatchNotify(new \\Piwigo\\Tests\\Integration\\PluginRegistryTestFakeEvent());'
            : '';

        $classSource = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Piwigo\\PluginConfig\\ExtensionContext;
            use Piwigo\\PluginConfig\\ExtensionInterface;
            use Piwigo\\Tests\\Integration\\PluginRegistryTestFakeEvent;

            final class {$className} implements ExtensionInterface
            {
                public static bool \$installed = false;
                public static bool \$activated = false;
                public static bool \$deactivated = false;
                public static bool \$uninstalled = false;
                public static ?array \$updatedFromTo = null;
                public static ?ExtensionContext \$receivedContext = null;
                public static ?string \$observedStateFromEvent = null;
                public static bool \$handlerFired = false;
                public string \$ownState = 'not-booted';

                public function boot(ExtensionContext \$context): void
                {
                    self::\$receivedContext = \$context;
                    \$this->ownState = 'booted-{$namespaceSuffix}';
                    {$dispatchCall}
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
                        PluginRegistryTestFakeEvent::class => \$this->onFakeEvent(...),
                    ];
                }

                public function onFakeEvent(PluginRegistryTestFakeEvent \$event): void
                {
                    self::\$handlerFired = true;
                    self::\$observedStateFromEvent = \$this->ownState;
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

    public function testLoadOrderTopologicallySortsDependenciesBeforeDependents(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $this->writeFixturePlugin($dir, 'zz-load-order-b-' . $suffix, 'LoadOrderB' . $suffix, [
            'plugin/zz-load-order-a-' . $suffix => '*',
        ]);
        $this->writeFixturePlugin($dir, 'zz-load-order-a-' . $suffix, 'LoadOrderA' . $suffix);

        $registry = $this->buildRegistry($dir);
        $order = $registry->getLoadOrder();

        $posA = array_search('zz-load-order-a-' . $suffix, $order, true);
        $posB = array_search('zz-load-order-b-' . $suffix, $order, true);

        self::assertIsInt($posA);
        self::assertIsInt($posB);
        self::assertLessThan($posB, $posA, 'the dependency (A) must load before its dependent (B)');
    }

    public function testResolveDependencyVersionRequiresTheDependencyToBeActiveNotMerelyPresent(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $depId = 'zz-inactive-dep-' . $suffix;
        $mainId = 'zz-needs-active-dep-' . $suffix;
        $this->writeFixturePlugin($dir, $depId, 'InactiveDep' . $suffix);
        $this->writeFixturePlugin($dir, $mainId, 'NeedsActiveDep' . $suffix, [
            'plugin/' . $depId => '*',
        ]);

        // $depId's files exist on disk but it was never installed/activated.
        $registry = $this->buildRegistry($dir);

        $this->expectException(PluginDependencyException::class);
        $registry->activate($mainId);
    }

    public function testActivateSucceedsOnceTheDependencyIsGenuinelyActive(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $depId = 'zz-active-dep-' . $suffix;
        $mainId = 'zz-needs-dep-' . $suffix;
        $this->writeFixturePlugin($dir, $depId, 'ActiveDep' . $suffix);
        $this->writeFixturePlugin($dir, $mainId, 'NeedsDep' . $suffix, [
            'plugin/' . $depId => '*',
        ]);

        $registry = $this->buildRegistry($dir);
        $registry->install($depId);
        $registry->activate($depId);

        // Must not throw now that the dependency is genuinely active.
        $registry->install($mainId);
        $registry->activate($mainId);

        self::assertTrue($registry->isActive($mainId));
    }

    public function testDeactivateRefusesWhileAnActiveDependentStillRequiresIt(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $depId = 'zz-guarded-dep-' . $suffix;
        $dependentId = 'zz-dependent-' . $suffix;
        $this->writeFixturePlugin($dir, $depId, 'GuardedDep' . $suffix);
        $this->writeFixturePlugin($dir, $dependentId, 'Dependent' . $suffix, [
            'plugin/' . $depId => '*',
        ]);

        $registry = $this->buildRegistry($dir);
        $registry->install($depId);
        $registry->activate($depId);
        $registry->install($dependentId);
        $registry->activate($dependentId);

        try {
            $registry->deactivate($depId);
            self::fail('deactivate() must throw while an active dependent still requires it');
        } catch (PluginDependencyException) {
            // expected
        }
        self::assertTrue($registry->isActive($depId), 'the dependency must remain untouched after the refused deactivate');

        // Deactivating the dependent first must unblock it.
        $registry->deactivate($dependentId);
        $registry->deactivate($depId);
        self::assertFalse($registry->isActive($depId));
    }

    public function testUninstallRefusesWhileAnActiveDependentStillRequiresIt(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $depId = 'zz-uninstall-dep-' . $suffix;
        $dependentId = 'zz-uninstall-dependent-' . $suffix;
        $this->writeFixturePlugin($dir, $depId, 'UninstallDep' . $suffix);
        $this->writeFixturePlugin($dir, $dependentId, 'UninstallDependent' . $suffix, [
            'plugin/' . $depId => '*',
        ]);

        $registry = $this->buildRegistry($dir);
        $registry->install($depId);
        $registry->activate($depId);
        $registry->install($dependentId);
        $registry->activate($dependentId);

        $this->expectException(PluginDependencyException::class);
        $registry->uninstall($depId);
    }

    /**
     * The exact scenario the P27 plan calls out by name: Plugin A
     * dispatches a custom event from its own `boot()`; Plugin B
     * subscribes via `subscribedEvents()`. Two independent things must
     * both be true:
     * - B's own handler runs at all, regardless of whether B's *own*
     *   `boot()` has run yet by that point in pass 2's iteration order
     *   (asserted via a plain fired-or-not flag, not B's own
     *   `$ownState` -- B's `boot()` may genuinely not have run yet when
     *   A's dispatch reaches it, and that's fine: what matters is B's
     *   *handler* was already registered). Proves *ordering*: every
     *   active plugin's `subscribedEvents()` is registered in pass 1,
     *   before *any* plugin's `boot()` runs in pass 2.
     * - A's *own* handler, reading `$this->ownState` at the moment A's
     *   own event fires (synchronously, from inside A's own `boot()`),
     *   sees the value A's *own* `boot()` just set a line earlier --
     *   not the fixture's un-booted default. That can only hold if the
     *   exact same constructed object received both the
     *   `subscribedEvents()` registration call in pass 1 and the
     *   `boot()` call in pass 2 -- the assertion that specifically
     *   fails if registration and boot ever end up running against two
     *   different object instances.
     */
    public function testBootActiveUsesTheSameInstanceAcrossBothPassesAndRegistersBeforeAnyoneBoots(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $idA = 'zz-identity-a-' . $suffix;
        $idB = 'zz-identity-b-' . $suffix;
        $this->writeFixturePlugin($dir, $idA, 'IdentityA' . $suffix, dispatchOnBoot: true);
        $this->writeFixturePlugin($dir, $idB, 'IdentityB' . $suffix);

        $registry = $this->buildRegistry($dir);
        $registry->install($idA);
        $registry->activate($idA);
        $registry->install($idB);
        $registry->activate($idB);

        $classA = 'PiwigoTest\\FixtureIdentityA' . $suffix . '\\PluginIdentityA' . $suffix;
        $classB = 'PiwigoTest\\FixtureIdentityB' . $suffix . '\\PluginIdentityB' . $suffix;

        $registry->bootActive();

        self::assertTrue($classB::$handlerFired, 'B\'s handler must have run, proving its subscribedEvents() was registered before A\'s boot() dispatched');
        self::assertSame('booted-IdentityA' . $suffix, $classA::$observedStateFromEvent, 'A\'s own handler must observe A\'s own post-boot state, proving pass 1 and pass 2 ran against the same instance');
    }

    public function testInstallActivateDeactivateUninstallRoundTrip(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-roundtrip-' . $suffix;
        $this->writeFixturePlugin($dir, $id, 'Roundtrip' . $suffix);
        $className = 'PiwigoTest\\FixtureRoundtrip' . $suffix . '\\PluginRoundtrip' . $suffix;

        $registry = $this->buildRegistry($dir);

        self::assertFalse($registry->isActive($id));

        $registry->install($id);
        self::assertTrue($className::$installed);

        $registry->activate($id);
        self::assertTrue($className::$activated);
        self::assertTrue($registry->isActive($id));

        $registry->deactivate($id);
        self::assertTrue($className::$deactivated);
        self::assertFalse($registry->isActive($id));

        $registry->uninstall($id);
        self::assertTrue($className::$uninstalled);
    }

    public function testUpdateInvokesTheHookAndBumpsTheVersionOnlyWhenVersionsDiffer(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-update-' . $suffix;
        $this->writeFixturePlugin($dir, $id, 'Update' . $suffix, [], '1.0.0');
        $className = 'PiwigoTest\\FixtureUpdate' . $suffix . '\\PluginUpdate' . $suffix;

        $registry = $this->buildRegistry($dir);
        $registry->install($id);

        // Same version on disk as installed -- update() must be a no-op.
        $registry->update($id);
        self::assertNull($className::$updatedFromTo);

        // Bump the on-disk manifest version and reload.
        $this->writeFixturePlugin($dir, $id, 'Update' . $suffix, [], '2.0.0');
        $registry->reload();
        $registry->update($id);

        self::assertSame(['1.0.0', '2.0.0'], $className::$updatedFromTo);
    }

    public function testGetManifestGetAllManifestsAndGetActiveIdsReflectARealScan(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-manifests-' . $suffix;
        $this->writeFixturePlugin($dir, $id, 'Manifests' . $suffix, [], '3.1.4');

        $registry = $this->buildRegistry($dir);

        $manifest = $registry->getManifest($id);
        self::assertNotNull($manifest);
        self::assertSame($id, $manifest->id);
        self::assertSame('3.1.4', $manifest->version);
        self::assertNull($registry->getManifest('zz-does-not-exist-' . $suffix));

        self::assertArrayHasKey($id, $registry->getAllManifests());

        self::assertNotContains($id, $registry->getActiveIds(), 'not yet installed/activated');
        $registry->install($id);
        $registry->activate($id);
        self::assertContains($id, $registry->getActiveIds());
    }

    public function testPluginValidationExceptionCarriesTheFailingIdAndManifestPath(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-invalid-manifest-' . $suffix;
        mkdir($dir . '/' . $id, 0o777, true);
        file_put_contents($dir . '/' . $id . '/plugin.json', '{not valid json');

        $registry = $this->buildRegistry($dir);

        try {
            $registry->install($id);
            self::fail('install() must throw for a plugin with no valid manifest');
        } catch (PluginValidationException $e) {
            self::assertSame($id, $e->pluginId);
            self::assertSame($dir . '/' . $id . '/plugin.json', $e->manifestPath);
        }
    }

    public function testPluginDependencyExceptionCarriesTheFailingPluginId(): void
    {
        $dir = $this->makeTempDir();
        $suffix = uniqid('', false);
        $id = 'zz-dep-exc-' . $suffix;
        $this->writeFixturePlugin($dir, $id, 'DepExc' . $suffix, [
            'piwigo' => '>=999.0.0',
        ]);

        $registry = $this->buildRegistry($dir);

        try {
            $registry->install($id);
            self::fail('install() must throw when minPiwigo/require: is unsatisfiable');
        } catch (PluginDependencyException $e) {
            self::assertSame($id, $e->pluginId);
        }
    }
}
