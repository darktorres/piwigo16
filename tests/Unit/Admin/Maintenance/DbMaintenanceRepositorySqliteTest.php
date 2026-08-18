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
beforeEach(function (): void {
    DbTransactionTestOverride::rollback();
    putenv('PIWIGO_DB_DRIVER=sqlite3');
    putenv('PIWIGO_DB_BASE=:memory:');
});

afterEach(function (): void {
    putenv('PIWIGO_DB_DRIVER');
    putenv('PIWIGO_DB_BASE');
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
