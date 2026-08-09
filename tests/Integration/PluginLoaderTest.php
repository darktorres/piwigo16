<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Activity\ActivityService;
use Override;
use LogicException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Admin\LoadedPlugins;
use Piwigo\Admin\PluginLoader;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\Kernel;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Core\Paths;
use Piwigo\Core\WsContext;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * PluginLoader::loadPlugins()/loadPlugin() -- each test that needs a
 * throwaway root (a real local/plugins/<id>/main.inc.php the test writes
 * itself) rebinds Paths::class for its own scope via
 * KernelContainerOverride::with(), since Kernel is already booted (by
 * parent::setUp(), against the real repo-root Paths) by the time any test
 * method runs -- CurrentPaths is a pure shim over whatever Paths::class the
 * live container has, not an independently rebindable static.
 *
 * autoupdatePlugin()'s own "filesystem version is newer" body (real
 * `Version:` header parsing, dynamically loading a real
 * `{$plugin_id}_maintain` class from a real maintain.class.php, the
 * PageStateTestFactory::get()->errors round-trip through PluginMaintain::update()'s
 * by-reference $errors param, persisting via PluginRepository::updateVersion(),
 * and logging the 'autoupdate' activity row) is covered below, via the
 * same "write a real throwaway maintain.class.php declaring a
 * `{$id}_maintain` class" convention ExtensionLifecycleTest's own
 * writePluginMaintainFile()/buildPluginMaintain() tests use for the
 * sibling ExtensionLifecycle::buildPluginMaintain() -- PluginLoader ships
 * an independent copy of that same dynamic-class-load-and-check pattern,
 * not a delegation to it. Those tests reach $this->activityService
 * (constructor-injected into loadPlugins()) -- harmless for the
 * plugins-disabled/missing-file/no-version-header tests below, which never
 * reach that call.
 */
final class PluginLoaderTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private LoadedPlugins $loadedPlugins;

    private ActivityService $activityService;

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

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        // Kernel is already booted by parent::setUp() (against the real repo
        // root) -- no need to boot again; each test below rebinds Paths::class
        // for its own throwaway root via KernelContainerOverride::with().
        $loadedPlugins = Kernel::container()->get(LoadedPlugins::class);
        if (! $loadedPlugins instanceof LoadedPlugins) {
            throw new LogicException('Container returned an unexpected type for ' . LoadedPlugins::class);
        }

        $this->loadedPlugins = $loadedPlugins;

        $activityService = Kernel::container()->get(ActivityService::class);
        if (! $activityService instanceof ActivityService) {
            throw new LogicException('Container returned an unexpected type for ' . ActivityService::class);
        }

        $this->activityService = $activityService;
    }

    #[Override]
    protected function tearDown(): void
    {
        DbConnection::build()->executeStatement('DELETE FROM ' . Tables::plugins());
        DbConnection::build()->executeStatement(
            'DELETE FROM ' . Tables::activity() . ' WHERE object = ? AND object_id = ? AND action = ?',
            ['system', ActivitySystem::Plugin, 'autoupdate']
        );
        parent::tearDown();
    }

    private function wsContext(): WsContext
    {
        $wsContext = Kernel::container()->get(WsContext::class);
        if (! $wsContext instanceof WsContext) {
            throw new LogicException('Container returned an unexpected type for ' . WsContext::class);
        }

        return $wsContext;
    }

    private function accessControl(): AccessControl
    {
        $accessControl = Kernel::container()->get(AccessControl::class);
        if (! $accessControl instanceof AccessControl) {
            throw new LogicException('Container returned an unexpected type for ' . AccessControl::class);
        }

        return $accessControl;
    }

    private function pluginLoaderTestMarker(): string
    {
        /** @var string|null $marker */
        static $marker = null;

        return $marker ??= sys_get_temp_dir() . '/piwigo-plugin-loader-test-' . bin2hex(random_bytes(8));
    }

    /**
     * Writes a real plugins/<id>/main.inc.php whose "/* ... Version: X *\/"
     * header sits on line 3 (well within autoupdatePlugin()'s own "lines 2
     * to 10" fgets() window) -- the shape its regex
     * `/Version:\s*([\w.-]+)/` genuinely matches, unlike the sibling
     * test_load_plugins_loads_an_active_plugin_with_a_real_main_inc_php's
     * own header-less main.inc.php above (which deliberately keeps
     * autoupdatePlugin()'s "filesystem version is newer" body unreached).
     */
    private function writeVersionedPluginMainFile(string $root, string $id, string $version): void
    {
        $pluginDir = $root . 'plugins/' . $id;
        mkdir($pluginDir, 0o777, true);
        file_put_contents($pluginDir . '/main.inc.php', "<?php\n/*\nVersion: {$version}\n*/\n");
    }

    /**
     * Writes a real plugins/<id>/maintain.class.php declaring a
     * global-namespace `{$id}_maintain` class (hyphens folded to '_',
     * matching autoupdatePlugin()'s own str_replace) -- same convention as
     * ExtensionLifecycleTest::writePluginMaintainFile(), a separate local
     * copy since that one is private to ExtensionLifecycleTest.
     */
    private function writePluginMaintainClass(string $root, string $id, bool $extendsBase, string $body = ''): void
    {
        $pluginDir = $root . 'plugins/' . $id;
        $classname = str_replace('-', '_', $id . '_maintain');
        $extends = $extendsBase ? ' extends \\Piwigo\\Admin\\PluginMaintain' : '';
        file_put_contents(
            $pluginDir . '/maintain.class.php',
            "<?php\nclass {$classname}{$extends}\n{\n{$body}\n}\n"
        );
    }

    public function test_load_plugins_stays_empty_and_skips_the_db_query_when_plugins_are_disabled(): void
    {
        CurrentConfigTestFactory::get()->setEnablePlugins(false);

        PluginLoader::loadPlugins($this->loadedPlugins, new EventDispatcher(), $this->activityService, CurrentConfigTestFactory::get(), $this->wsContext(), $this->accessControl(), PageStateTestFactory::get(), CurrentPathsTestFactory::get());

        expect($this->loadedPlugins->get())->toBe([]);
    }

    public function test_load_plugins_skips_an_active_plugin_whose_main_inc_php_file_is_missing(): void
    {
        $root = $this->pluginLoaderTestMarker() . '/no-file/';
        mkdir($root . 'plugins', 0o777, true);
        CurrentConfigTestFactory::get()->setEnablePlugins(true);

        DbConnection::build()->executeStatement(
            "INSERT INTO " . Tables::plugins() . " (id, state, version) VALUES ('ghost-plugin', 'active', '1.0')"
        );

        KernelContainerOverride::with([Paths::class => Paths::fromRoot($root)], function (): void {
            PluginLoader::loadPlugins($this->loadedPlugins, new EventDispatcher(), $this->activityService, CurrentConfigTestFactory::get(), $this->wsContext(), $this->accessControl(), PageStateTestFactory::get(), CurrentPathsTestFactory::get());

            expect($this->loadedPlugins->get())->toBe([]);
        });
    }

    public function test_load_plugins_loads_an_active_plugin_with_a_real_main_inc_php(): void
    {
        $root = $this->pluginLoaderTestMarker() . '/real-file/';
        $pluginDir = $root . 'plugins/loadable-plugin';
        mkdir($pluginDir, 0o777, true);
        $marker = $pluginDir . '/loaded.marker';
        file_put_contents(
            $pluginDir . '/main.inc.php',
            "<?php file_put_contents(" . var_export($marker, true) . ", 'loaded');"
        );
        CurrentConfigTestFactory::get()->setEnablePlugins(true);

        DbConnection::build()->executeStatement(
            "INSERT INTO " . Tables::plugins() . " (id, state, version) VALUES ('loadable-plugin', 'active', '1.0')"
        );

        KernelContainerOverride::with([Paths::class => Paths::fromRoot($root)], function () use ($marker): void {
            PluginLoader::loadPlugins($this->loadedPlugins, new EventDispatcher(), $this->activityService, CurrentConfigTestFactory::get(), $this->wsContext(), $this->accessControl(), PageStateTestFactory::get(), CurrentPathsTestFactory::get());

            expect($this->loadedPlugins->get())->toHaveKey('loadable-plugin');
            expect($this->loadedPlugins->get()['loadable-plugin']['version'])->toBe('1.0');
            expect(file_exists($marker))->toBeTrue();
        });
    }

    public function test_load_plugins_ignores_an_inactive_plugin(): void
    {
        $root = $this->pluginLoaderTestMarker() . '/inactive/';
        $pluginDir = $root . 'plugins/inactive-plugin';
        mkdir($pluginDir, 0o777, true);
        file_put_contents($pluginDir . '/main.inc.php', '<?php');
        CurrentConfigTestFactory::get()->setEnablePlugins(true);

        DbConnection::build()->executeStatement(
            "INSERT INTO " . Tables::plugins() . " (id, state, version) VALUES ('inactive-plugin', 'inactive', '1.0')"
        );

        KernelContainerOverride::with([Paths::class => Paths::fromRoot($root)], function (): void {
            PluginLoader::loadPlugins($this->loadedPlugins, new EventDispatcher(), $this->activityService, CurrentConfigTestFactory::get(), $this->wsContext(), $this->accessControl(), PageStateTestFactory::get(), CurrentPathsTestFactory::get());

            expect($this->loadedPlugins->get())->toBe([]);
        });
    }

    // ---------------------------------------- autoupdatePlugin(), real update

    public function test_autoupdate_plugin_runs_the_real_maintain_update_persists_the_new_version_and_logs_activity(): void
    {
        $id = 'pl-autoupdate-' . bin2hex(random_bytes(4));
        $root = $this->pluginLoaderTestMarker() . '/autoupdate-ok/';
        $this->writeVersionedPluginMainFile($root, $id, '2.0');
        // update()'s real body pushes a message through the by-reference
        // $errors param -- proves autoupdatePlugin() actually round-trips
        // PageStateTestFactory::get()->errors into the call and the mutated array
        // back out (not just that the call happens without erroring).
        $this->writePluginMaintainClass($root, $id, extendsBase: true, body: <<<'PHP'
    public function update($old_version, $new_version, &$errors = [])
    {
        $errors[] = "updated from {$old_version} to {$new_version}";
    }
PHP);
        CurrentConfigTestFactory::get()->setEnablePlugins(true);

        DbConnection::build()->executeStatement(
            "INSERT INTO " . Tables::plugins() . " (id, state, version) VALUES (?, 'active', '1.0')",
            [$id]
        );

        KernelContainerOverride::with([Paths::class => Paths::fromRoot($root)], function () use ($id): void {
            PluginLoader::loadPlugins($this->loadedPlugins, new EventDispatcher(), $this->activityService, CurrentConfigTestFactory::get(), $this->wsContext(), $this->accessControl(), PageStateTestFactory::get(), CurrentPathsTestFactory::get());

            expect(PageStateTestFactory::get()->errors)->toBe(['updated from 1.0 to 2.0']);
            expect($this->loadedPlugins->get()[$id]['version'])->toBe('2.0');

            $storedVersion = DbConnection::build()->fetchOne(
                'SELECT version FROM ' . Tables::plugins() . ' WHERE id = ?',
                [$id]
            );
            expect($storedVersion)->toBe('2.0');

            $row = DbConnection::build()->fetchAssociative(
                'SELECT object_id, details FROM ' . Tables::activity()
                . " WHERE object = 'system' AND action = 'autoupdate' ORDER BY activity_id DESC LIMIT 1"
            );
            self::assertIsArray($row);
            self::assertIsString($row['details']);
            expect($row['object_id'])->toBe(ActivitySystem::Plugin);
            // MySQL's JSON column type reorders object members (by key length,
            // then lexicographically) independent of the original insertion
            // order -- ksort() both sides, matching this codebase's own
            // established convention for this gotcha (see
            // tests/Contract/WsImagesFilteredSearchTest.php's docblock).
            $expectedDetails = [
                'plugin_id' => $id,
                'from_version' => '1.0',
                'to_version' => '2.0',
            ];
            $actualDetails = json_decode($row['details'], true);
            ksort($expectedDetails);
            self::assertIsArray($actualDetails);
            ksort($actualDetails);
            expect($actualDetails)->toBe($expectedDetails);
        });
    }

    public function test_autoupdate_plugin_throws_when_the_maintain_class_does_not_extend_plugin_maintain(): void
    {
        // Same "does not extend" contract ExtensionLifecycleTest's own
        // test_build_plugin_maintain_throws_when_the_class_php_class_does_not_extend_plugin_maintain()
        // already proves for the sibling ExtensionLifecycle::
        // buildPluginMaintain() -- exercised here through
        // PluginLoader::autoupdatePlugin()'s own separate implementation of
        // the same dynamic-class-load-and-check pattern.
        $id = 'pl-badmaintain-' . bin2hex(random_bytes(4));
        $root = $this->pluginLoaderTestMarker() . '/autoupdate-bad-maintain/';
        $this->writeVersionedPluginMainFile($root, $id, '2.0');
        $this->writePluginMaintainClass($root, $id, extendsBase: false);
        $classname = str_replace('-', '_', $id . '_maintain');
        CurrentConfigTestFactory::get()->setEnablePlugins(true);

        DbConnection::build()->executeStatement(
            "INSERT INTO " . Tables::plugins() . " (id, state, version) VALUES (?, 'active', '1.0')",
            [$id]
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains("PluginLoader::autoupdatePlugin(): {$classname} does not extend PluginMaintain");

        KernelContainerOverride::with([Paths::class => Paths::fromRoot($root)], function (): void {
            PluginLoader::loadPlugins($this->loadedPlugins, new EventDispatcher(), $this->activityService, CurrentConfigTestFactory::get(), $this->wsContext(), $this->accessControl(), PageStateTestFactory::get(), CurrentPathsTestFactory::get());
        });
    }

    public function test_autoupdate_plugin_skips_the_db_and_activity_write_for_an_auto_to_auto_update(): void
    {
        // Bonus behavioral lock-in for the exact quirk autoupdatePlugin()'s
        // own comment documents ("avoid registering an 'auto' to 'auto'
        // update, which happens for each 'version=auto' plugin on each
        // page load") -- not one of the specifically-uncovered lines this
        // suite closes above (the guard at that line executes either way),
        // but it's the one real scenario where $new_version === $old_version
        // and the DB/activity write is skipped, so it's worth locking in
        // directly rather than only via the differing-version case above.
        $id = 'pl-autoauto-' . bin2hex(random_bytes(4));
        $root = $this->pluginLoaderTestMarker() . '/autoupdate-auto-to-auto/';
        $this->writeVersionedPluginMainFile($root, $id, 'auto');
        CurrentConfigTestFactory::get()->setEnablePlugins(true);

        DbConnection::build()->executeStatement(
            "INSERT INTO " . Tables::plugins() . " (id, state, version) VALUES (?, 'active', 'auto')",
            [$id]
        );

        KernelContainerOverride::with([Paths::class => Paths::fromRoot($root)], function () use ($id): void {
            PluginLoader::loadPlugins($this->loadedPlugins, new EventDispatcher(), $this->activityService, CurrentConfigTestFactory::get(), $this->wsContext(), $this->accessControl(), PageStateTestFactory::get(), CurrentPathsTestFactory::get());

            $storedVersion = DbConnection::build()->fetchOne(
                'SELECT version FROM ' . Tables::plugins() . ' WHERE id = ?',
                [$id]
            );
            expect($storedVersion)->toBe('auto');

            // activity.details is a genuine json/jsonb column on both
            // platforms -- LIKE against it directly errors on Postgres
            // ("operator does not exist: jsonb ~~ unknown"), needs an
            // explicit ::text cast first (same real gap already fixed in
            // RequestBootstrapConnectTest/GroupServiceTest).
            $detailsColumn = $this->dbDriver === 'pgsql' ? 'details::text' : 'details';
            $activityCount = DbConnection::build()->fetchOne(
                'SELECT COUNT(*) FROM ' . Tables::activity()
                . " WHERE object = 'system' AND action = 'autoupdate' AND {$detailsColumn} LIKE ?",
                ['%' . $id . '%']
            );
            expect(is_numeric($activityCount) ? (int) $activityCount : -1)->toBe(0);
        });
    }
}
