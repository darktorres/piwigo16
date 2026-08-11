<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Common\ValueObject\PluginId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\PluginConfig\PluginEntity;
use Piwigo\PluginConfig\PluginRepository;

/**
 * Piwigo\PluginConfig\PluginRepository -- has its own dedicated
 * tests/Integration/PluginRepositoryTest.php; this is the same spec
 * ported down to the Unit suite via the real-DB-no-HTTP
 * ImageRepositoryTest.php pattern.
 */
function pluginRepositoryTestRepo(): PluginRepository
{
    $conn = DbConnection::build();
    $repo = EntityManagerFactory::build($conn)->getRepository(PluginEntity::class);

    return $repo;
}

function pluginRepositoryTestInsert(Connection $conn, string $id, string $state, string $version): void
{
    $conn->createQueryBuilder()
        ->insert('plugins')
        ->values([
            'id' => ':id',
            'state' => ':state',
            'version' => ':version',
        ])
        ->setParameter('id', $id)
        ->setParameter('state', $state)
        ->setParameter('version', $version)
        ->executeStatement();
}

function pluginRepositoryTestDelete(Connection $conn, string $id): void
{
    $conn->createQueryBuilder()
        ->delete('plugins')
        ->where('id = :id')
        ->setParameter('id', $id)
        ->executeStatement();
}

test('getDbPlugins() with no filters returns every row', function (): void {
    $conn = DbConnection::build();
    $id = 'ut_plugin_a';

    try {
        pluginRepositoryTestInsert($conn, $id, 'active', '1.0.0');

        $found = pluginRepositoryTestRepo()
            ->getDbPlugins();
        $ours = array_values(array_filter($found, static fn ($p) => $p->id->value === $id));

        expect($ours)
            ->toHaveCount(1)
            ->and($ours[0]->id)->toEqual(PluginId::from($id))
            ->and($ours[0]->state)->toBe('active')
            ->and($ours[0]->version)->toBe('1.0.0');
    } finally {
        pluginRepositoryTestDelete($conn, $id);
    }
});

test('getDbPlugins() filters by state', function (): void {
    $conn = DbConnection::build();
    $activeId = 'ut_plugin_active';
    $inactiveId = 'ut_plugin_inactive';

    try {
        pluginRepositoryTestInsert($conn, $activeId, 'active', '1.0.0');
        pluginRepositoryTestInsert($conn, $inactiveId, 'inactive', '1.0.0');

        $found = pluginRepositoryTestRepo()
            ->getDbPlugins(state: 'active');
        $ids = array_map(static fn ($p) => $p->id->value, $found);

        expect($ids)
            ->toContain($activeId)
            ->and($ids)
            ->not->toContain($inactiveId);
    } finally {
        pluginRepositoryTestDelete($conn, $activeId);
        pluginRepositoryTestDelete($conn, $inactiveId);
    }
});

test('getDbPlugins() filters by id', function (): void {
    $conn = DbConnection::build();
    $id = 'ut_plugin_byid';
    $otherId = 'ut_plugin_other';

    try {
        pluginRepositoryTestInsert($conn, $id, 'active', '1.0.0');
        pluginRepositoryTestInsert($conn, $otherId, 'active', '1.0.0');

        $found = pluginRepositoryTestRepo()
            ->getDbPlugins(id: $id);

        expect($found)
            ->toHaveCount(1)
            ->and($found[0]->id)->toEqual(PluginId::from($id));
    } finally {
        pluginRepositoryTestDelete($conn, $id);
        pluginRepositoryTestDelete($conn, $otherId);
    }
});

test('getDbPlugins() short-circuits to an empty result for a malformed id filter, instead of throwing', function (): void {
    expect(pluginRepositoryTestRepo()->getDbPlugins(id: 'not a valid plugin id!!'))
        ->toBe([]);
});

test('updateVersion() updates only the given plugin\'s version', function (): void {
    $conn = DbConnection::build();
    $id = 'ut_plugin_update';

    try {
        pluginRepositoryTestRepo(); // warms Doctrine metadata before the raw insert below
        pluginRepositoryTestInsert($conn, $id, 'active', '1.0.0');

        pluginRepositoryTestRepo()
            ->updateVersion($id, '2.0.0');

        $version = $conn->createQueryBuilder()
            ->select('version')
            ->from('plugins')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchOne();

        expect($version)
            ->toBe('2.0.0');
    } finally {
        pluginRepositoryTestDelete($conn, $id);
    }
});

test('updateVersion() is a no-op for a malformed id, instead of throwing', function (): void {
    // Not pluginRepositoryTestRepo() -- its own toBeInstanceOf() assertion
    // would make this "risky" (throwsNoExceptions() expects exactly 0).
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(PluginEntity::class);

    $repo->updateVersion('not a valid plugin id!!', '2.0.0');
})->throwsNoExceptions();

test('updateVersion() is a no-op for an id with no matching row', function (): void {
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(PluginEntity::class);

    $repo->updateVersion('ut_plugin_does_not_exist', '2.0.0');
})->throwsNoExceptions();
