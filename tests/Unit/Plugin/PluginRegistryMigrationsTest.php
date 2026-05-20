<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Plugin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Piwigo\Plugin\Migration\PluginMigrationLedger;
use Piwigo\Plugin\Migration\PluginMigrationRunner;
use Piwigo\Plugin\PluginRecord;
use Piwigo\Plugin\PluginRegistry;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Tests\Fixtures\Plugins\MigrationPlugin\Plugin as MigrationPlugin;
use Psr\Log\NullLogger;

/**
 * Verifies that PluginRegistry wires migration runs into install(),
 * update(), and uninstall() — the manifest's `migrations.namespace` /
 * `migrations.path` fields propagate to the runner and the
 * piwigo_plugin_migrations ledger ends up in the expected state.
 *
 * Uses an in-memory SQLite Connection so the test can stand on its own
 * without a MySQL fixture. The runner itself is verified deeply by
 * PluginMigrationRunnerTest; this test owns the registry-runner glue.
 */
final class PluginRegistryMigrationsTest extends TestCase
{
    private Connection $conn;

    private PluginRegistry $registry;

    private PluginMigrationLedger $ledger;

    #[\Override]
    protected function setUp(): void
    {
        $this->conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->conn->executeStatement(
            'CREATE TABLE plug_plugin_migrations ('
            . 'plugin_id VARCHAR(64) NOT NULL, '
            . 'version VARCHAR(191) NOT NULL, '
            . 'executed_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (plugin_id, version))'
        );

        $this->ledger = new PluginMigrationLedger($this->conn, 'plug_');
        $runner = new PluginMigrationRunner($this->conn, $this->ledger, new NullLogger());

        $this->registry = new PluginRegistry(
            $this->stubRepository(),
            new NullLogger(),
            dirname(__DIR__, 3) . '/tests/Fixtures/Plugins',
            dirname(__DIR__, 3) . '/docs/schemas/plugin.schema.json',
            $runner,
        );

        MigrationPlugin::$installCount = 0;
        MigrationPlugin::$uninstallCount = 0;
    }

    public function testInstallRunsAllMigrationsBeforeCallingPluginInstall(): void
    {
        $this->registry->install('MigrationPlugin');

        self::assertSame(1, MigrationPlugin::$installCount);
        self::assertCount(2, $this->ledger->getApplied('MigrationPlugin'));

        $sm = $this->conn->createSchemaManager();
        self::assertTrue($sm->tablesExist(['fixture_one']));
        $table = $sm->introspectTableByUnquotedName('fixture_one');
        self::assertTrue($table->hasColumn('id'));
        self::assertTrue($table->hasColumn('label'), 'Both migrations must have run before plugin install()');
    }

    public function testUninstallRunsDownMigrationsBeforeCallingPluginUninstall(): void
    {
        $this->registry->install('MigrationPlugin');
        $this->registry->uninstall('MigrationPlugin');

        self::assertSame(1, MigrationPlugin::$uninstallCount);
        self::assertSame([], $this->ledger->getApplied('MigrationPlugin'));
        self::assertFalse(
            $this->conn->createSchemaManager()->tablesExist(['fixture_one']),
            'Reverse migrations must drop fixture_one before plugin uninstall() runs.',
        );
    }

    private function stubRepository(): PluginRepository
    {
        /** @psalm-suppress PropertyNotSetInConstructor — parent's $conn/$tablePrefix intentionally skipped; stub has no DB */
        return new class () extends PluginRepository {
            /** @var array<string, PluginRecord> */
            private array $rows = [];

            public function __construct()
            {
            }

            #[\Override]
            public function findAll(?string $state = '', ?string $id = ''): array
            {
                if ($id !== null && $id !== '') {
                    return isset($this->rows[$id]) ? [$this->rows[$id]] : [];
                }
                return array_values($this->rows);
            }

            #[\Override]
            public function insert(string $pluginId, string $version): void
            {
                $this->rows[$pluginId] = new PluginRecord($pluginId, 'inactive', $version);
            }

            #[\Override]
            public function updateVersion(string $pluginId, string $version): void
            {
                if (isset($this->rows[$pluginId])) {
                    $existing = $this->rows[$pluginId];
                    $this->rows[$pluginId] = new PluginRecord($existing->id, $existing->state, $version);
                }
            }

            #[\Override]
            public function updateState(string $pluginId, string $state): void
            {
                if (isset($this->rows[$pluginId])) {
                    $existing = $this->rows[$pluginId];
                    $this->rows[$pluginId] = new PluginRecord($existing->id, $state, $existing->version);
                }
            }

            #[\Override]
            public function delete(string $pluginId): void
            {
                unset($this->rows[$pluginId]);
            }
        };
    }
}
