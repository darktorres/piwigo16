<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Common\ValueObject\PluginId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\PluginConfig\PluginMigrationEntity;
use Piwigo\PluginConfig\PluginMigrationRepository;

/**
 * Piwigo\PluginConfig\PluginMigrationRepository -- has no dedicated
 * Integration test file of its own (spec ported down from
 * tests/Integration/ExtensionLifecycleTest.php, which exercises
 * record() only indirectly through ExtensionLifecycle's own real
 * install/update flow). Real DB, no HTTP -- same ImageRepositoryTest.php
 * precedent as every other Unit-suite Repository test.
 */
function pluginMigrationTestRepo(): PluginMigrationRepository
{
    $conn = DbConnection::build();
    $repo = EntityManagerFactory::build($conn)->getRepository(PluginMigrationEntity::class);

    return $repo;
}

/**
 * Creates the `plugins` row the ledger row will reference, and returns a
 * cleanup closure.
 *
 * fk_plugin_migrations_plugin_id is ON DELETE RESTRICT, so a ledger entry
 * for a plugin that was never installed is exactly the state the constraint
 * forbids -- these tests used to create it. Owning a real plugin row keeps
 * them honest about the invariant rather than working around it, and the
 * teardown order (ledger first, then plugin) is the same order
 * PluginRegistry::uninstall() has to use for the same reason.
 */
function pluginMigrationTestInstallPlugin(Connection $conn, PluginId $pluginId): callable
{
    $conn->executeStatement(
        'INSERT INTO plugins (id, state, version) VALUES (?, ?, ?)',
        [$pluginId->value, 'inactive', '1.0.0']
    );

    return static function () use ($conn, $pluginId): void {
        $conn->executeStatement('DELETE FROM plugin_migrations WHERE plugin_id = ?', [$pluginId->value]);
        $conn->executeStatement('DELETE FROM plugins WHERE id = ?', [$pluginId->value]);
    };
}

test('record() inserts a new row when no (plugin_id, version) pair exists yet', function (): void {
    $conn = DbConnection::build();
    $pluginId = PluginId::from('unit_test_plugin_migration');
    $uninstall = pluginMigrationTestInstallPlugin($conn, $pluginId);

    try {
        pluginMigrationTestRepo()->record($pluginId, '1.0.0', '2026-08-01 12:00:00');

        $row = $conn->createQueryBuilder()
            ->select('plugin_id', 'version', 'executed_at')
            ->from('plugin_migrations')
            ->where('plugin_id = :pluginId AND version = :version')
            ->setParameter('pluginId', $pluginId->value)
            ->setParameter('version', '1.0.0')
            ->fetchAssociative();

        expect($row)
            ->toBe([
                'plugin_id' => 'unit_test_plugin_migration',
                'version' => '1.0.0',
                'executed_at' => '2026-08-01 12:00:00',
            ]);
    } finally {
        $uninstall();
    }
});

test('record() updates executed_at in place when the same (plugin_id, version) pair recurs -- a real "restore" re-run, not a duplicate insert', function (): void {
    $conn = DbConnection::build();
    $pluginId = PluginId::from('unit_test_plugin_migration_2');
    $uninstall = pluginMigrationTestInstallPlugin($conn, $pluginId);

    try {
        $repo = pluginMigrationTestRepo();
        $repo->record($pluginId, '2.0.0', '2026-08-01 12:00:00');
        $repo->record($pluginId, '2.0.0', '2026-08-02 09:30:00');

        $rows = $conn->createQueryBuilder()
            ->select('plugin_id', 'version', 'executed_at')
            ->from('plugin_migrations')
            ->where('plugin_id = :pluginId AND version = :version')
            ->setParameter('pluginId', $pluginId->value)
            ->setParameter('version', '2.0.0')
            ->fetchAllAssociative();

        expect($rows)
            ->toBe([
                [
                    'plugin_id' => 'unit_test_plugin_migration_2',
                    'version' => '2.0.0',
                    'executed_at' => '2026-08-02 09:30:00',
                ],
            ]);
    } finally {
        $uninstall();
    }
});

test('record() keeps separate rows for the same plugin at different versions (composite PK)', function (): void {
    $conn = DbConnection::build();
    $pluginId = PluginId::from('unit_test_plugin_migration_3');
    $uninstall = pluginMigrationTestInstallPlugin($conn, $pluginId);

    try {
        $repo = pluginMigrationTestRepo();
        $repo->record($pluginId, '1.0.0', '2026-08-01 12:00:00');
        $repo->record($pluginId, '1.1.0', '2026-08-02 12:00:00');

        $rows = $conn->createQueryBuilder()
            ->select('version')
            ->from('plugin_migrations')
            ->where('plugin_id = :pluginId')
            ->orderBy('version', 'ASC')
            ->setParameter('pluginId', $pluginId->value)
            ->fetchFirstColumn();

        expect($rows)
            ->toBe(['1.0.0', '1.1.0']);
    } finally {
        $uninstall();
    }
});
