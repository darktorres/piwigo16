<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Admin\LoadedPlugins;
use Piwigo\Admin\LoadedPluginsMiddleware;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Http\Middleware\PluginBootstrapMiddleware;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `Admin\LoadedPluginsMiddleware` is the real, direct successor of
 * `Bootstrap\RequestBootstrap::connect()`'s own `Admin\LoadedPlugins`
 * repopulation glue (workstream C3 Phase 1) -- split out of
 * `Http\Middleware\PluginBootstrapMiddleware` itself only because
 * `LoadedPlugins` is L4Integration and `PluginBootstrapMiddleware` is
 * L3Presentation (see that class's own docblock). Replaces the equivalent
 * cases from the now-deleted `RequestBootstrapConnectTest`'s
 * `testConnectPopulatesLoadedPluginsFromAnActivePluginRegistryEntry()`/
 * `testConnectLeavesLoadedPluginsEmptyWhenPluginsAreDisabled()`, ported
 * onto the new middleware boundary rather than mechanically moved (Plan
 * 3's own "Test portability correction").
 *
 * This middleware reads `PluginConfig\CurrentPluginRegistry`, which only
 * `PluginBootstrapMiddleware` populates (via `set($pluginRegistry)`,
 * right after `bootActive()` runs) -- so every test here runs the real
 * two-middleware slice, `PluginBootstrapMiddleware` then this one, the
 * same real dependency the production pipeline has, rather than faking
 * `CurrentPluginRegistry`'s state directly.
 */
final class LoadedPluginsMiddlewareTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    private ConfigService $configService;

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

        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $configService = Kernel::container()->get(ConfigService::class);
        self::assertInstanceOf(ConfigService::class, $configService);
        $this->configService = $configService;
        $this->configService->loadConfFromDb();

        // Same real precondition PluginBootstrapMiddlewareTest establishes
        // -- LoungeMaintenance::needsEmptying() -> PermissionCacheInvalidator
        // reaches CurrentConfigService::get() transitively when
        // PluginBootstrapMiddleware runs ahead of this middleware below.
        CurrentConfigServiceTestFactory::get()->set($this->configService);

        unset($_REQUEST['method']);
    }

    #[Override]
    protected function tearDown(): void
    {
        EventDispatcherTestFactory::get()->reset();
        unset($_REQUEST['method']);

        parent::tearDown();
    }

    /**
     * `connect()`'s own `PluginRegistry::bootActive()`/`LoadedPlugins`
     * repopulation glue (P27.4, replacing `Admin\PluginLoader::
     * loadPlugins()`'s former direct writes) has no coverage anywhere
     * else: `PluginRegistryTest.php` builds `PluginRegistry` directly
     * against an isolated temp `Paths::plugins` root, never through this
     * real call site, and this class doesn't override `Paths::class` (it
     * runs against the real repo-root `plugins/` directory, the same one
     * the live dev server serves), so a real fixture written there is
     * genuinely scanned by the same `PluginRegistry` instance
     * `PluginBootstrapMiddleware` itself resolves.
     */
    public function testProcessPopulatesLoadedPluginsFromAnActivePluginRegistryEntry(): void
    {
        $pluginId = 'zz-lpmw-active-' . bin2hex(random_bytes(4));
        $this->writeFixturePlugin($pluginId);
        $this->conn->executeStatement(
            "INSERT INTO plugins (id, state, version) VALUES (?, 'active', '1.0.0')",
            [$pluginId],
        );

        try {
            $this->runPluginBootstrapThenLoadedPlugins();

            $entries = $this->loadedPlugins()
                ->get();
            self::assertArrayHasKey($pluginId, $entries);
            self::assertSame([
                'id' => $pluginId,
                'state' => 'active',
                'version' => '1.0.0',
            ], $entries[$pluginId]);
        } finally {
            $this->conn->executeStatement('DELETE FROM plugins WHERE id = ?', [$pluginId]);
            $this->removeFixturePlugin($pluginId);
        }
    }

    /**
     * `PluginRegistry::bootActive()`'s own `enablePlugins` guard (a
     * no-op early return) has no test anywhere that proves it's actually
     * honored at this real call site, only that the property exists --
     * a real active plugin present on disk + an active DB row must still
     * leave `LoadedPlugins` empty when the config flag is off.
     */
    public function testProcessLeavesLoadedPluginsEmptyWhenPluginsAreDisabled(): void
    {
        $pluginId = 'zz-lpmw-disabled-' . bin2hex(random_bytes(4));
        $this->writeFixturePlugin($pluginId);
        $this->conn->executeStatement(
            "INSERT INTO plugins (id, state, version) VALUES (?, 'active', '1.0.0')",
            [$pluginId],
        );
        $this->configService->confUpdateParam('enable_plugins', false);
        $this->configService->loadConfFromDb();

        try {
            $this->runPluginBootstrapThenLoadedPlugins();

            self::assertArrayNotHasKey($pluginId, $this->loadedPlugins()->get());
        } finally {
            $this->configService->confUpdateParam('enable_plugins', true);
            $this->conn->executeStatement('DELETE FROM plugins WHERE id = ?', [$pluginId]);
            $this->removeFixturePlugin($pluginId);
        }
    }

    /**
     * Runs the real two-middleware slice, `PluginBootstrapMiddleware`
     * (which populates `CurrentPluginRegistry`) then `LoadedPluginsMiddleware`
     * (which reads it) -- matching their real, fixed relative order in
     * `Bootstrap\RequestPipeline::DEFAULT_MIDDLEWARE`.
     */
    private function runPluginBootstrapThenLoadedPlugins(): void
    {
        $pluginBootstrap = Kernel::container()->get(PluginBootstrapMiddleware::class);
        self::assertInstanceOf(PluginBootstrapMiddleware::class, $pluginBootstrap);
        $loadedPluginsMiddleware = Kernel::container()->get(LoadedPluginsMiddleware::class);
        self::assertInstanceOf(LoadedPluginsMiddleware::class, $loadedPluginsMiddleware);

        $pluginBootstrap->process(new ServerRequest('GET', '/'), new readonly class($loadedPluginsMiddleware) implements RequestHandlerInterface {
            public function __construct(
                private LoadedPluginsMiddleware $loadedPluginsMiddleware,
            ) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->loadedPluginsMiddleware->process($request, $this->terminalHandler());
            }

            private function terminalHandler(): RequestHandlerInterface
            {
                return new class() implements RequestHandlerInterface {
                    #[Override]
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return new Response(200);
                    }
                };
            }
        });
    }

    private function loadedPlugins(): LoadedPlugins
    {
        $loadedPlugins = Kernel::container()->get(LoadedPlugins::class);
        if (! $loadedPlugins instanceof LoadedPlugins) {
            throw new LogicException('Container returned an unexpected type for ' . LoadedPlugins::class);
        }

        return $loadedPlugins;
    }

    /**
     * Writes a real `plugin.json` + PSR-4-autoloadable `ExtensionInterface`
     * class under the real, repo-root `plugins/` directory -- same
     * fixture shape the Browser/Contract suites use (e.g.
     * tests/Browser/AlbumSubControllerTest.php's own
     * albumSubWriteFixturePlugin()), adapted here since this class runs
     * against the real Paths::plugins root rather than an isolated temp
     * one. An empty boot()/subscribedEvents() -- this test only needs
     * the plugin to be a valid, bootable manifest, not to do anything.
     */
    private function writeFixturePlugin(string $pluginId): void
    {
        $dir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
        if (! is_dir($dir . '/src')) {
            mkdir($dir . '/src', 0o777, true);
        }

        $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

        file_put_contents($dir . '/plugin.json', json_encode([
            'id' => $pluginId,
            'name' => $pluginId,
            'version' => '1.0.0',
            'description' => 'Test-only fixture plugin (tests/Integration/LoadedPluginsMiddlewareTest.php).',
            'license' => 'MIT',
            'minPiwigo' => '16.3.0',
            'main' => $namespace . '\\Plugin',
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\' => 'src/',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        file_put_contents($dir . '/src/Plugin.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Piwigo\\PluginConfig\\ExtensionContext;
            use Piwigo\\PluginConfig\\ExtensionInterface;

            final class Plugin implements ExtensionInterface
            {
                public function boot(ExtensionContext \$context): void {}

                public function install(): void {}
                public function activate(): void {}
                public function deactivate(): void {}
                public function uninstall(): void {}
                public function update(string \$oldVersion, string \$newVersion): void {}

                public function subscribedEvents(): array
                {
                    return [];
                }
            }

            PHP);
    }

    private function removeFixturePlugin(string $pluginId): void
    {
        $dir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
        @unlink($dir . '/src/Plugin.php');
        @rmdir($dir . '/src');
        @unlink($dir . '/plugin.json');
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
}
