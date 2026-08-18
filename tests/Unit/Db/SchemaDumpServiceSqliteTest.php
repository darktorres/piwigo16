<?php

declare(strict_types=1);

use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\MigrationDependencyFactory;
use Piwigo\Db\SchemaDumpService;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * SchemaDumpService::dump()'s real SQLite branch (Wave 4 of the SQLite
 * campaign) -- reads `sqlite_master`'s own `sql` column directly, no
 * external `sqlite3` CLI dependency the way mysqldump/pg_dump are for
 * the other 2 platforms.
 *
 * dump() writes to the real, tracked
 * install/piwigo_structure-sqlite.sql (no test-injectable output path,
 * matching SchemaDumpServiceTest.php's own established Integration-tier
 * precedent for the mysql/pgsql branches) -- snapshot/restore the file
 * around this test so running it never leaves an uncommitted diff.
 *
 * DbTransactionTestOverride::rollback() as beforeEach()'s first line,
 * same reason as every other sqlite3 test this campaign has added.
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

test('dump() detects sqlite and writes a deterministic schema file from sqlite_master', function (): void {
    $schemaFilePath = dirname(__DIR__, 3) . '/install/piwigo_structure-sqlite.sql';
    $originalContent = (string) file_get_contents($schemaFilePath);

    try {
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

        $service = new SchemaDumpService($conn);
        $result = $service->dump();

        expect($result->label)
            ->toBe('sqlite')
            ->and($result->path)
            ->toBe($schemaFilePath);

        $content = (string) file_get_contents($result->path);
        expect($content)
            ->toContain('CREATE TABLE')
            ->not->toContain('migration_versions')
            // Confirmed live: FTS5's own shadow tables (_data/_idx/
            // _docsize/_config) must never appear as their own CREATE
            // TABLE statement -- re-running one standalone would fail
            // with "table already exists" once the owning
            // CREATE VIRTUAL TABLE ... USING fts5(...) statement already
            // creates it.
            ->not->toMatch('/CREATE TABLE \'\w+_(data|idx|docsize|config)\'/');

        $second = $service->dump();
        $secondContent = (string) file_get_contents($second->path);
        expect($secondContent)
            ->toBe($content);
    } finally {
        file_put_contents($schemaFilePath, $originalContent);
    }
});
