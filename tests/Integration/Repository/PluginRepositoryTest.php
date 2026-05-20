<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see PluginRepository}.
 */
final class PluginRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private PluginRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new PluginRepository($this->conn, 'piwigo_');

        // Plugin rows seeded by the fixture install vary by core-plugin
        // set. Reset to a known baseline so tests assert deterministic
        // expectations.
        $this->conn->executeStatement('TRUNCATE TABLE piwigo_plugins');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_insert_then_findAll_returns_row(): void
    {
        $this->repo->insert('myplugin', '1.2.3');

        $rows = $this->repo->findAll();
        self::assertCount(1, $rows);
        self::assertSame('myplugin', $rows[0]->id);
        self::assertSame('1.2.3', $rows[0]->version);
    }

    public function test_updateVersion_changes_only_version(): void
    {
        $this->repo->insert('myplugin', '1.0.0');
        $this->repo->updateVersion('myplugin', '2.0.0');

        $rows = $this->repo->findAll(id: 'myplugin');
        self::assertSame('2.0.0', $rows[0]->version);
    }

    public function test_updateState_changes_only_state(): void
    {
        $this->repo->insert('myplugin', '1.0.0');
        $this->repo->updateState('myplugin', 'active');

        $rows = $this->repo->findAll(state: 'active');
        self::assertCount(1, $rows);
        self::assertSame('myplugin', $rows[0]->id);
    }

    public function test_findAll_filters_by_state_and_id_independently(): void
    {
        $this->repo->insert('alpha', '1.0');
        $this->repo->updateState('alpha', 'active');
        $this->repo->insert('beta', '1.0');
        $this->repo->updateState('beta', 'inactive');

        self::assertCount(1, $this->repo->findAll(state: 'active'));
        self::assertCount(1, $this->repo->findAll(state: 'inactive'));
        self::assertCount(2, $this->repo->findAll());
        self::assertCount(1, $this->repo->findAll(id: 'alpha'));
    }

    public function test_delete_removes_plugin(): void
    {
        $this->repo->insert('temp', '1.0');
        self::assertCount(1, $this->repo->findAll(id: 'temp'));

        $this->repo->delete('temp');

        self::assertCount(0, $this->repo->findAll(id: 'temp'));
    }
}
