<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\PluginConfig\PluginEntity;
use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\PluginConfig\PluginRepository;

final class PluginRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private PluginRepository $repo;

    private Connection $conn;

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

        $this->conn = DbConnection::build();
        $this->repo = EntityManagerFactory::build($this->conn)->getRepository(PluginEntity::class);

        // the fixture ships an empty plugins table -- seed it directly so
        // getDbPlugins()'s filters have something real to select against.
        //
        // The 3rd row is a still-edge-case-y (hyphenated) but
        // PluginId-valid id -- PluginEntity::$id's own charset
        // ([a-zA-Z0-9_-] only, matching real PEM manifest ids) can never
        // contain a quote, so a bound-parameter injection attempt is
        // closed at construction time, not just at the bind; the
        // malformed-input-safety property is covered by
        // test_get_db_plugins_filters_by_a_malformed_id_finds_nothing()
        // below instead.
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::plugins() . " (id, state, version) VALUES
             ('c13y', 'active', '2.1'),
             ('nut2', 'inactive', '1.0'),
             ('o-brien', 'active', '3.0')"
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::plugins());
        parent::tearDown();
    }

    public function test_get_db_plugins_returns_every_row_when_unfiltered(): void
    {
        $plugins = $this->repo->getDbPlugins();

        self::assertCount(3, $plugins);
    }

    public function test_get_db_plugins_filters_by_state(): void
    {
        $plugins = $this->repo->getDbPlugins('active');

        self::assertSame(['c13y', 'o-brien'], array_column($plugins, 'id'));
    }

    public function test_get_db_plugins_filters_by_id(): void
    {
        $plugins = $this->repo->getDbPlugins('', 'nut2');

        self::assertCount(1, $plugins);
        self::assertSame('inactive', $plugins[0]->state);
    }

    public function test_get_db_plugins_filters_by_state_and_id_together(): void
    {
        $plugins = $this->repo->getDbPlugins('active', 'nut2');

        self::assertSame([], $plugins);
    }

    public function test_get_db_plugins_filters_by_an_id_containing_a_hyphen(): void
    {
        $plugins = $this->repo->getDbPlugins('', 'o-brien');

        self::assertCount(1, $plugins);
        self::assertSame('o-brien', $plugins[0]->id);
    }

    public function test_get_db_plugins_filters_by_a_malformed_id_finds_nothing(): void
    {
        // A quote can never be part of a real PluginId (charset
        // [a-zA-Z0-9_-] only) -- must return an empty, graceful result
        // rather than throwing, same "no real row could ever match"
        // reasoning as an unknown-but-well-formed id.
        $plugins = $this->repo->getDbPlugins('', "o'brien");

        self::assertSame([], $plugins);
    }

    public function test_update_version_persists_the_new_version(): void
    {
        $this->repo->updateVersion('c13y', '2.2');

        $version = $this->conn->createQueryBuilder()
            ->select('version')
            ->from(Tables::plugins())
            ->where("id = 'c13y'")
            ->executeQuery()
            ->fetchOne();

        self::assertSame('2.2', $version);
    }

    public function test_update_version_does_not_touch_other_plugins(): void
    {
        $this->repo->updateVersion('c13y', '2.2');

        $version = $this->conn->createQueryBuilder()
            ->select('version')
            ->from(Tables::plugins())
            ->where("id = 'nut2'")
            ->executeQuery()
            ->fetchOne();

        self::assertSame('1.0', $version);
    }

    public function test_update_version_is_a_no_op_for_an_unknown_plugin_id(): void
    {
        // must not throw despite there being no matching row to update
        $this->repo->updateVersion('this-plugin-id-does-not-exist', '9.9');

        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::plugins())
            ->where("id = 'this-plugin-id-does-not-exist'")
            ->executeQuery()
            ->fetchOne();

        self::assertSame(0, is_numeric($count) ? (int) $count : -1);
    }
}
