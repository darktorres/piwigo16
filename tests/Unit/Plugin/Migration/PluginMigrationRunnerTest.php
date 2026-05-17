<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Plugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Piwigo\Plugin\Migration\PluginMigrationException;
use Piwigo\Plugin\Migration\PluginMigrationLedger;
use Piwigo\Plugin\Migration\PluginMigrationRunner;
use Psr\Log\NullLogger;

/**
 * Exercises PluginMigrationRunner end-to-end against an in-memory SQLite
 * connection — no MySQL test DB needed. The fixture plugin under
 * tests/fixtures/plugins/migration_plugin ships two migration files that
 * deliberately use SQLite-compatible DDL.
 *
 * Verifies:
 *  - runUp() applies pending migrations in version order
 *  - re-running runUp() is a no-op once everything is in the ledger
 *  - runDown() reverses applied migrations in reverse order and clears
 *    the ledger
 *  - missing namespace / missing path = silent no-op (manifest opted out)
 *  - bad migration class = PluginMigrationException with plugin id +
 *    version embedded
 */
final class PluginMigrationRunnerTest extends TestCase
{
    private Connection $conn;

    private PluginMigrationLedger $ledger;

    private PluginMigrationRunner $runner;

    private string $migrationsDir;

    private const string PLUGIN_ID = 'migration_plugin';

    private const string NAMESPACE_ = 'Piwigo\\Tests\\Fixtures\\Plugins\\MigrationPlugin\\Migrations';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        // Fixtures live outside the PSR-4 tests/ casing — load explicitly
        // so reflection-style class_exists checks succeed inside the runner.
        $repoRoot = dirname(__DIR__, 4);
        require_once $repoRoot . '/tests/fixtures/plugins/migration_plugin/migrations/Version20260516000001.php';
        require_once $repoRoot . '/tests/fixtures/plugins/migration_plugin/migrations/Version20260516000002.php';
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        // Match the production ledger schema exactly: composite (plugin_id,
        // version) PK + executed_at DATETIME. SQLite ignores CHARACTER SET
        // hints so the column types are simplified.
        $this->conn->executeStatement(
            'CREATE TABLE test_plugin_migrations ('
            . 'plugin_id VARCHAR(64) NOT NULL, '
            . 'version VARCHAR(191) NOT NULL, '
            . 'executed_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (plugin_id, version))'
        );

        $this->ledger = new PluginMigrationLedger($this->conn, 'test_');
        $this->runner = new PluginMigrationRunner($this->conn, $this->ledger, new NullLogger());
        $this->migrationsDir = dirname(__DIR__, 4) . '/tests/fixtures/plugins/migration_plugin/migrations';
    }

    public function testRunUpAppliesPendingMigrationsInVersionOrder(): void
    {
        $applied = $this->runner->runUp(self::PLUGIN_ID, self::NAMESPACE_, $this->migrationsDir);

        self::assertCount(2, $applied);
        self::assertSame(self::NAMESPACE_ . '\\Version20260516000001', $applied[0]);
        self::assertSame(self::NAMESPACE_ . '\\Version20260516000002', $applied[1]);

        // Both queries executed: table exists with both columns.
        $sm = $this->conn->createSchemaManager();
        self::assertTrue($sm->tablesExist(['fixture_one']));
        $table = $sm->introspectTableByUnquotedName('fixture_one');
        self::assertTrue($table->hasColumn('id'));
        self::assertTrue($table->hasColumn('label'));

        $ledgerRows = $this->ledger->getApplied(self::PLUGIN_ID);
        self::assertCount(2, $ledgerRows);
    }

    public function testRunUpIsIdempotentOnceLedgerCaughtUp(): void
    {
        $this->runner->runUp(self::PLUGIN_ID, self::NAMESPACE_, $this->migrationsDir);

        $second = $this->runner->runUp(self::PLUGIN_ID, self::NAMESPACE_, $this->migrationsDir);
        self::assertSame([], $second, 'No migration should re-run when ledger already lists them.');
    }

    public function testRunDownReversesAppliedMigrationsInReverseOrder(): void
    {
        $this->runner->runUp(self::PLUGIN_ID, self::NAMESPACE_, $this->migrationsDir);
        $this->runner->runDown(self::PLUGIN_ID, self::NAMESPACE_, $this->migrationsDir);

        self::assertFalse(
            $this->conn->createSchemaManager()->tablesExist(['fixture_one']),
            'down() must drop fixture_one',
        );
        self::assertSame([], $this->ledger->getApplied(self::PLUGIN_ID));
    }

    public function testNoMigrationsManifestNamespaceIsNoOp(): void
    {
        $applied = $this->runner->runUp(self::PLUGIN_ID, null, $this->migrationsDir);
        self::assertSame([], $applied);

        $applied = $this->runner->runUp(self::PLUGIN_ID, self::NAMESPACE_, null);
        self::assertSame([], $applied);
    }

    public function testRunDownWithoutManifestNamespaceStillPurgesLedger(): void
    {
        // Simulate a stray ledger row left over from a previous version
        // whose Version*.php files are no longer on disk.
        $this->ledger->recordApplied(self::PLUGIN_ID, 'OldVersion');
        $this->runner->runDown(self::PLUGIN_ID, null, null);

        self::assertSame([], $this->ledger->getApplied(self::PLUGIN_ID));
    }

    public function testMissingMigrationClassRaisesPluginMigrationException(): void
    {
        // Point the namespace at one that doesn't match the loaded files —
        // the file exists on disk so requireMigrationFiles() includes it,
        // but the class FQCN under the bogus namespace won't be declared.
        $this->expectException(PluginMigrationException::class);
        $this->expectExceptionMessageMatches('/did not declare class/');
        $this->runner->runUp(self::PLUGIN_ID, 'Bogus\\Namespace', $this->migrationsDir);
    }
}
