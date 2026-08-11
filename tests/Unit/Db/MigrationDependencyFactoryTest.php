<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\MigrationDefinitions;
use Piwigo\Db\MigrationDependencyFactory;

/**
 * MigrationDependencyFactory::build()'s own return value is
 * getConfiguration()-inspectable without ever touching a real connection:
 * ConfigurationArray::getConfiguration() (Doctrine Migrations' own class,
 * the ConfigurationLoader this method feeds) is a pure config-parsing call
 * that never reads/writes through the given EntityManager -- same
 * "constructing an EntityManager doesn't touch the DB" reasoning as
 * tests/Unit/Db/EntityManagerFactoryTest.php's own in-memory pdo_sqlite
 * technique, reused here for the same reason.
 */
test('build() preserves MigrationDefinitions::config()\'s own migrations_paths entry', function (): void {
    $conn = DriverManager::getConnection([
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ]);
    $em = EntityManagerFactory::build($conn);

    $dependencyFactory = MigrationDependencyFactory::build($em);

    // Recomputed independently via MigrationDefinitions::config() rather
    // than hardcoded, so a future change to its own migrations_paths
    // doesn't desynchronize this expectation.
    $expected = MigrationDefinitions::config();
    expect($expected['migrations_paths'])->not->toBeEmpty();

    expect($dependencyFactory->getConfiguration()->getMigrationDirectories())
        ->toBe($expected['migrations_paths']);
});

test('build() names the migrations ledger table migration_versions', function (): void {
    // Kills line 61's RemoveArrayItem (table_storage => [], silently
    // falling back to Doctrine Migrations' own unprefixed
    // 'doctrine_migration_versions' default).
    $conn = DriverManager::getConnection([
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ]);
    $em = EntityManagerFactory::build($conn);

    $dependencyFactory = MigrationDependencyFactory::build($em);

    $metadataStorageConfiguration = $dependencyFactory->getConfiguration()
        ->getMetadataStorageConfiguration();
    expect($metadataStorageConfiguration)
        ->toBeInstanceOf(TableMetadataStorageConfiguration::class);
    assert($metadataStorageConfiguration instanceof TableMetadataStorageConfiguration);

    expect($metadataStorageConfiguration->getTableName())
        ->toBe('migration_versions');
});
