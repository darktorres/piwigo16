<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

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

        $this->conn = DbConnection::build();
        $this->repo = new PluginRepository($this->conn);

        // the fixture ships an empty plugins table -- seed it directly so
        // getDbPlugins()'s filters have something real to select against;
        // a plugin id containing a quote proves the bound-parameter fix
        // (the original built `id="' . $id . '"` via raw concatenation).
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::plugins() . " (id, state, version) VALUES
             ('c13y', 'active', '2.1'),
             ('nut2', 'inactive', '1.0'),
             ('o\\'brien', 'active', '3.0')"
        );
    }

    #[\Override]
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

        self::assertSame(['c13y', "o'brien"], array_column($plugins, 'id'));
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

    public function test_get_db_plugins_handles_an_id_containing_a_quote_safely(): void
    {
        $plugins = $this->repo->getDbPlugins('', "o'brien");

        self::assertCount(1, $plugins);
        self::assertSame("o'brien", $plugins[0]->id);
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
}
