<?php

declare(strict_types=1);

use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\MigrationDependencyFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * DbMaintenanceRepository::repairOptimizeAllTables()'s real SQLite
 * branch (Wave 4 of the SQLite campaign) -- unlike MySQL/Postgres's own
 * per-table loop, SQLite's `VACUUM` operates on the whole database file
 * at once, so this is a single `VACUUM` + `PRAGMA optimize`, no table
 * introspection needed at all.
 *
 * DbTransactionTestOverride::rollback() as beforeEach()'s first line,
 * same reason as every other sqlite3 test this campaign has added --
 * without it, DbConnection::build() would transparently return the
 * global Unit-suite override's real mysqli/pgsql test connection.
 */
$originalDbDriver = null;
$originalDbBase = null;

beforeEach(function () use (&$originalDbDriver, &$originalDbBase): void {
    DbTransactionTestOverride::rollback();
    // Save+restore, not a blind unset -- this process's real env
    // already carries .env.test's own PIWIGO_DB_DRIVER/PIWIGO_DB_BASE
    // (mysqli/piwigo17_2_test), and every other test in this same
    // worker process needs those back exactly as they were.
    $originalDbDriver = getenv('PIWIGO_DB_DRIVER');
    $originalDbBase = getenv('PIWIGO_DB_BASE');
    putenv('PIWIGO_DB_DRIVER=sqlite3');
    putenv('PIWIGO_DB_BASE=:memory:');
});

afterEach(function () use (&$originalDbDriver, &$originalDbBase): void {
    putenv($originalDbDriver === false ? 'PIWIGO_DB_DRIVER' : 'PIWIGO_DB_DRIVER=' . $originalDbDriver);
    putenv($originalDbBase === false ? 'PIWIGO_DB_BASE' : 'PIWIGO_DB_BASE=' . $originalDbBase);
});

test('repairOptimizeAllTables() completes without throwing and data survives VACUUM', function (): void {
    $conn = DbConnection::build();
    $em = EntityManagerFactory::build($conn);
    $depFactory = MigrationDependencyFactory::build($em);
    $input = new ArrayInput([
        'version' => 'latest',
        '--allow-no-migration' => true,
    ]);
    $input->setInteractive(false);
    $exitCode = new MigrateCommand($depFactory)
        ->run($input, new BufferedOutput());
    expect($exitCode)
        ->toBe(0);

    $conn->executeStatement("INSERT INTO categories (id, name, uppercats) VALUES (1, 'Maintenance Test', '1')");

    $repo = new DbMaintenanceRepository($em);
    $repo->repairOptimizeAllTables();

    expect($conn->fetchOne('SELECT name FROM categories WHERE id = 1'))
        ->toBe('Maintenance Test');
});
