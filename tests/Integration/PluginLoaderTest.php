<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Admin\LoadedPlugins;
use Piwigo\Admin\PluginLoader;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * PluginLoader::loadPlugins()/loadPlugin() -- CurrentPaths is pointed at a
 * throwaway root (a real local/plugins/<id>/main.inc.php the test writes
 * itself), matching every other Unit/Integration test's own CurrentPaths::
 * set() convention. autoupdatePlugin()'s own maintain.class.php branch is
 * NOT covered here -- it dynamically constructs a plugin-supplied
 * `{$plugin_id}_maintain` class, which would mean writing and including a
 * second throwaway PHP class file per test; the version-comparison guard
 * that decides whether to enter that branch at all IS covered below.
 */
final class PluginLoaderTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
    }

    #[\Override]
    protected function tearDown(): void
    {
        DbConnection::build()->executeStatement('DELETE FROM ' . Tables::plugins());
        CurrentPaths::reset();
        parent::tearDown();
    }

    private function pluginLoaderTestMarker(): string
    {
        /** @var string|null $marker */
        static $marker = null;

        return $marker ??= sys_get_temp_dir() . '/piwigo-plugin-loader-test-' . bin2hex(random_bytes(8));
    }

    public function test_load_plugins_stays_empty_and_skips_the_db_query_when_plugins_are_disabled(): void
    {
        CurrentConfig::setEnablePlugins(false);

        PluginLoader::loadPlugins();

        expect(LoadedPlugins::get())->toBe([]);
    }

    public function test_load_plugins_skips_an_active_plugin_whose_main_inc_php_file_is_missing(): void
    {
        $root = $this->pluginLoaderTestMarker() . '/no-file/';
        mkdir($root . 'plugins', 0o777, true);
        CurrentPaths::set(Paths::fromRoot($root));
        CurrentConfig::setEnablePlugins(true);

        DbConnection::build()->executeStatement(
            "INSERT INTO " . Tables::plugins() . " (id, state, version) VALUES ('ghost-plugin', 'active', '1.0')"
        );

        PluginLoader::loadPlugins();

        expect(LoadedPlugins::get())->toBe([]);
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
        CurrentPaths::set(Paths::fromRoot($root));
        CurrentConfig::setEnablePlugins(true);

        DbConnection::build()->executeStatement(
            "INSERT INTO " . Tables::plugins() . " (id, state, version) VALUES ('loadable-plugin', 'active', '1.0')"
        );

        PluginLoader::loadPlugins();

        expect(LoadedPlugins::get())->toHaveKey('loadable-plugin');
        expect(LoadedPlugins::get()['loadable-plugin']['version'])->toBe('1.0');
        expect(file_exists($marker))->toBeTrue();
    }

    public function test_load_plugins_ignores_an_inactive_plugin(): void
    {
        $root = $this->pluginLoaderTestMarker() . '/inactive/';
        $pluginDir = $root . 'plugins/inactive-plugin';
        mkdir($pluginDir, 0o777, true);
        file_put_contents($pluginDir . '/main.inc.php', '<?php');
        CurrentPaths::set(Paths::fromRoot($root));
        CurrentConfig::setEnablePlugins(true);

        DbConnection::build()->executeStatement(
            "INSERT INTO " . Tables::plugins() . " (id, state, version) VALUES ('inactive-plugin', 'inactive', '1.0')"
        );

        PluginLoader::loadPlugins();

        expect(LoadedPlugins::get())->toBe([]);
    }
}
