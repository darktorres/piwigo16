<?php

declare(strict_types=1);

use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Piwigo\Backup\BackupService;
use Piwigo\Core\ShutdownHandler;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\MigrationDependencyFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * BackupService::create()/restore()'s real SQLite branch (Wave 4 of the
 * SQLite campaign) -- `VACUUM INTO` for a clean hot-copy dump, a plain
 * file copy for restore, neither needing a subprocess at all (unlike
 * the mysqldump/mysql and pg_dump/psql branches).
 *
 * A real file path, not `:memory:` -- dumpDatabase()'s own SQLite
 * branch opens its own fresh DriverManager connection straight from
 * DbCredentials (not this test's own $conn), so both have to point at
 * the same real file on disk for the dump to see this test's own
 * inserted row; two independent `:memory:` connections would be two
 * unrelated empty databases.
 *
 * DbTransactionTestOverride::rollback() as beforeEach()'s first line,
 * same reason as every other sqlite3 test this campaign has added.
 */
beforeEach(function (): void {
    DbTransactionTestOverride::rollback();
});

afterEach(function (): void {
    ShutdownHandler::reset();
});

test('create() dumps via VACUUM INTO and restore() copies the file back with the real data intact', function (): void {
    $srcPath = sys_get_temp_dir() . '/piwigo-backup-sqlite-test-src-' . bin2hex(random_bytes(4)) . '.sqlite';
    $restorePath = sys_get_temp_dir() . '/piwigo-backup-sqlite-test-restored-' . bin2hex(random_bytes(4)) . '.sqlite';
    $archivePath = '';

    // Save+restore, not a blind unset -- this process's real env already
    // carries .env.test's own PIWIGO_DB_DRIVER/PIWIGO_DB_BASE (mysqli/
    // piwigo17_2_test), and every other test in this same worker process
    // needs those back exactly as they were.
    $originalDbDriver = getenv('PIWIGO_DB_DRIVER');
    $originalDbBase = getenv('PIWIGO_DB_BASE');

    try {
        putenv('PIWIGO_DB_DRIVER=sqlite3');
        putenv('PIWIGO_DB_BASE=' . $srcPath);

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

        $conn->executeStatement("INSERT INTO categories (id, name, uppercats) VALUES (1, 'Backup Sqlite Test', '1')");

        $backupService = new BackupService();
        $archivePath = $backupService->create();

        expect($archivePath)
            ->toBeFile();

        $backupService->restore($archivePath, $restorePath);

        expect($restorePath)
            ->toBeFile();

        $restoredConn = new SQLite3($restorePath);
        expect($restoredConn->querySingle('SELECT name FROM categories WHERE id = 1'))
            ->toBe('Backup Sqlite Test');
        $restoredConn->close();
    } finally {
        putenv($originalDbDriver === false ? 'PIWIGO_DB_DRIVER' : 'PIWIGO_DB_DRIVER=' . $originalDbDriver);
        putenv($originalDbBase === false ? 'PIWIGO_DB_BASE' : 'PIWIGO_DB_BASE=' . $originalDbBase);

        if ($archivePath !== '' && is_file($archivePath)) {
            unlink($archivePath);
        }

        @unlink($srcPath);
        @unlink($restorePath);
    }
});
