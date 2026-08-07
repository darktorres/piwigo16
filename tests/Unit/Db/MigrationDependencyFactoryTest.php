<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\EntityManagerFactory;
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
function makeMigrationDependencyFactoryDbCredentials(string $prefix): DbCredentials
{
    return new DbCredentials(
        host: 'localhost',
        user: 'unused',
        password: 'unused',
        database: 'unused',
        prefix: $prefix,
    );
}

test('build() preserves config/migrations.php\'s own migrations_paths entry', function (): void {
    // Kills line 50's ForeachEmptyIterable (`foreach ([] as $key =>
    // $value)` instead of `foreach ($raw as $key => $value)`): the copy
    // loop would never run, so migrations_paths -- config/migrations.php's
    // one real entry, besides the table_storage this method adds itself --
    // would never make it into $migrationsConfig, and
    // getMigrationDirectories() would come back empty instead of matching
    // the real config file's own value.
    $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $em = EntityManagerFactory::build($conn);

    $dependencyFactory = MigrationDependencyFactory::build($em, makeMigrationDependencyFactoryDbCredentials('unused_'));

    // Recomputed independently from the real config file rather than
    // hardcoded, so a future change to config/migrations.php's own
    // migrations_paths doesn't desynchronize this expectation.
    $sourceFile = (new ReflectionClass(MigrationDependencyFactory::class))->getFileName();
    expect($sourceFile)->not->toBeFalse();
    /** @var array<string, mixed> $expectedRaw */
    $expectedRaw = require dirname((string) $sourceFile, 4) . '/config/migrations.php';
    expect($expectedRaw['migrations_paths'])->not->toBeEmpty();

    expect($dependencyFactory->getConfiguration()->getMigrationDirectories())->toBe($expectedRaw['migrations_paths']);
});

test('build() names the migrations ledger table after the given DbCredentials prefix', function (): void {
    // Kills line 62's RemoveArrayItem (table_storage => [], silently
    // falling back to Doctrine Migrations' own unprefixed
    // 'doctrine_migration_versions' default), ConcatRemoveLeft
    // ('migration_versions', dropping the prefix), ConcatRemoveRight
    // ($dbCredentials->prefix alone, dropping the 'migration_versions'
    // suffix), and ConcatSwitchSides (suffix-then-prefix) -- a single
    // exact string match against a distinctive prefix (present nowhere
    // else in this expression) distinguishes real code from all 4 at once.
    $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $em = EntityManagerFactory::build($conn);

    $dependencyFactory = MigrationDependencyFactory::build($em, makeMigrationDependencyFactoryDbCredentials('mig_test_prefix_'));

    $metadataStorageConfiguration = $dependencyFactory->getConfiguration()->getMetadataStorageConfiguration();
    expect($metadataStorageConfiguration)->toBeInstanceOf(TableMetadataStorageConfiguration::class);
    assert($metadataStorageConfiguration instanceof TableMetadataStorageConfiguration);

    expect($metadataStorageConfiguration->getTableName())->toBe('mig_test_prefix_migration_versions');
});
