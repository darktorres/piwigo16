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

function pluginMigrationTestDelete(Connection $conn, PluginId $pluginId, string $version): void
{
    $conn->createQueryBuilder()
        ->delete('plugin_migrations')
        ->where('plugin_id = :pluginId AND version = :version')
        ->setParameter('pluginId', $pluginId->value)
        ->setParameter('version', $version)
        ->executeStatement();
}

test('record() inserts a new row when no (plugin_id, version) pair exists yet', function (): void {
    $conn = DbConnection::build();
    $pluginId = PluginId::from('unit_test_plugin_migration');

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
        pluginMigrationTestDelete($conn, $pluginId, '1.0.0');
    }
});

test('record() updates executed_at in place when the same (plugin_id, version) pair recurs -- a real "restore" re-run, not a duplicate insert', function (): void {
    $conn = DbConnection::build();
    $pluginId = PluginId::from('unit_test_plugin_migration_2');

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
        pluginMigrationTestDelete($conn, $pluginId, '2.0.0');
    }
});

test('record() keeps separate rows for the same plugin at different versions (composite PK)', function (): void {
    $conn = DbConnection::build();
    $pluginId = PluginId::from('unit_test_plugin_migration_3');

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
        pluginMigrationTestDelete($conn, $pluginId, '1.0.0');
        pluginMigrationTestDelete($conn, $pluginId, '1.1.0');
    }
});
