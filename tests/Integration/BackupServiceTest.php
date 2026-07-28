<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Backup\BackupService;
use Piwigo\Core\ShutdownHandler;
use Piwigo\Db\DbCredentials;

/**
 * Real command-level proof for `bin/piwigo backup:create`/`backup:restore`,
 * replacing the rewire tools/restore-drill.sh's own header comment
 * anticipated ("Rewire onto the real bin/piwigo backup:restore once P12
 * lands"). Deliberately NOT wired into that script/its CI job instead --
 * see docs/REFERENCE.md's Restore section for why: that job
 * is intentionally PHP-dependency-free (~10s, no composer install step),
 * and adding one just for this would regress its own deliberate
 * minimalism. This test reuses the exact row-count + join-query smoke
 * assertions restore-drill.sh established, against a real archive produced
 * by the real service -- the round trip restore-drill.sh's own comment was
 * waiting for, just proven here instead.
 *
 * Restores into its own scratch database, never piwigo_test itself --
 * same safety discipline as tools/restore-drill.sh.
 */
final class BackupServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private string $scratchDb = '';

    private string $archivePath = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (!self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $this->scratchDb = $this->dbName . '_backup_service_test';
    }

    #[\Override]
    protected function tearDown(): void
    {
        // install() is never called by this test file, so no real SIGTERM
        // handler is ever wired -- but ShutdownHandler::register() itself
        // still stashes closures into shared static state, and a few tests
        // below invoke them directly via reflection (see
        // tests/Unit/Core/ShutdownHandlerTest.php's own docblock for why
        // that's the established alternative to a real OS signal). reset()
        // here keeps that static state from bleeding into any other test
        // that happens to share this worker process.
        ShutdownHandler::reset();

        $db = $this->newMysqli('');
        $db->query(sprintf('DROP DATABASE IF EXISTS `%s`', $this->scratchDb));
        $db->close();

        if ($this->archivePath !== '' && is_file($this->archivePath)) {
            unlink($this->archivePath);
        }

        parent::tearDown();
    }

    public function test_create_then_restore_round_trips_real_data(): void
    {
        $service = new BackupService();

        $this->archivePath = $service->create();
        self::assertFileExists($this->archivePath);

        $db = $this->newMysqli('');
        $db->query(sprintf('DROP DATABASE IF EXISTS `%s`', $this->scratchDb));
        $db->query(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $this->scratchDb
        ));
        $db->close();

        $service->restore($this->archivePath, $this->scratchDb);

        $scratch = $this->newMysqli($this->scratchDb);

        $imagesResult = $scratch->query(sprintf('SELECT COUNT(*) FROM `%simages`', $this->dbPrefix));
        self::assertInstanceOf(\mysqli_result::class, $imagesResult);
        $imageRow = $imagesResult->fetch_row();
        self::assertIsArray($imageRow);
        $imageCount = is_numeric($imageRow[0]) ? (int) $imageRow[0] : 0;

        $usersResult = $scratch->query(sprintf('SELECT COUNT(*) FROM `%susers`', $this->dbPrefix));
        self::assertInstanceOf(\mysqli_result::class, $usersResult);
        $userRow = $usersResult->fetch_row();
        self::assertIsArray($userRow);
        $userCount = is_numeric($userRow[0]) ? (int) $userRow[0] : 0;

        self::assertGreaterThanOrEqual(1, $imageCount, 'Restored DB should have at least one image');
        self::assertGreaterThanOrEqual(1, $userCount, 'Restored DB should have at least one user');

        // Schema smoke query: a join across tables proves the schema itself
        // (not just raw row presence) survived the restore intact -- same
        // assertion tools/restore-drill.sh makes.
        $joinResult = $scratch->query(sprintf(
            'SELECT i.id FROM `%1$simages` i JOIN `%1$simage_category` ic ON ic.image_id = i.id LIMIT 1',
            $this->dbPrefix
        ));
        self::assertInstanceOf(\mysqli_result::class, $joinResult);

        $scratch->close();
    }

    public function test_restore_rejects_a_corrupt_archive(): void
    {
        $badArchive = tempnam(sys_get_temp_dir(), 'piwigo-bad-backup-');
        self::assertIsString($badArchive);
        file_put_contents($badArchive, 'not a real archive');

        $service = new BackupService();

        try {
            $this->expectException(\RuntimeException::class);
            $service->restore($badArchive, $this->scratchDb);
        } finally {
            unlink($badArchive);
        }
    }

    public function test_create_recreates_the_backups_directory_when_it_is_missing(): void
    {
        $backupsDir = dirname(__DIR__, 2) . '/_data/backups';
        self::assertDirectoryExists($backupsDir, 'Precondition: repo checkout should already have this dir');
        rmdir($backupsDir);
        self::assertDirectoryDoesNotExist($backupsDir);

        $service = new BackupService();
        $this->archivePath = $service->create();

        self::assertDirectoryExists($backupsDir);
        self::assertFileExists($this->archivePath);
    }

    public function test_create_includes_a_present_local_config_file_in_the_archive(): void
    {
        // Confirmed empirically (see this class' own docblock/BackupService's
        // class docblock): this dev checkout has no local/config/config.inc.php
        // of its own, so it's safe to plant one here and remove it again --
        // no real deployment config is at risk.
        $localConfig = dirname(__DIR__, 2) . '/local/config/config.inc.php';
        self::assertFileDoesNotExist($localConfig, 'Precondition: dev checkout has no local config.inc.php');
        file_put_contents($localConfig, "<?php\n\$conf['test_marker'] = 'backup-service-test';\n");

        $service = new BackupService();

        try {
            $this->archivePath = $service->create();
        } finally {
            unlink($localConfig);
        }

        self::assertFileExists($this->archivePath);

        $extractDir = sys_get_temp_dir() . '/piwigo-backup-config-check-' . bin2hex(random_bytes(8));
        mkdir($extractDir);

        try {
            new \PharData($this->archivePath)->extractTo($extractDir);

            self::assertFileExists($extractDir . '/config.inc.php');
            self::assertSame(
                "<?php\n\$conf['test_marker'] = 'backup-service-test';\n",
                file_get_contents($extractDir . '/config.inc.php')
            );

            $manifest = json_decode(
                (string) file_get_contents($extractDir . '/manifest.json'),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            self::assertIsArray($manifest);
            self::assertIsArray($manifest['included']);
            self::assertContains('config.inc.php', $manifest['included']);
        } finally {
            $extractedPaths = glob($extractDir . '/*');
            foreach ($extractedPaths !== false ? $extractedPaths : [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($extractDir);
        }
    }

    public function test_creates_shutdown_cleanup_closure_is_a_no_op_after_normal_completion(): void
    {
        // create()'s own `finally` block already removes its workDir by
        // the time create() returns -- but it also registers a
        // ShutdownHandler cleanup closure over that same workDir for the
        // SIGTERM-mid-operation case (see BackupService's own class
        // docblock: the two paths are disjoint, not redundant). Invoking
        // every registered callback here -- the same ReflectionMethod
        // technique tests/Unit/Core/ShutdownHandlerTest.php uses instead
        // of sending a real OS signal -- proves that closure tolerates
        // its workDir already being gone: removeDir()'s own is_dir()
        // guard makes it a no-op rather than erroring.
        $service = new BackupService();
        $this->archivePath = $service->create();
        self::assertFileExists($this->archivePath);

        $runAll = new \ReflectionMethod(ShutdownHandler::class, 'runAll');
        $runAll->invoke(null);

        // Reaching this line means the already-registered closure ran
        // without throwing.
        self::assertFileExists($this->archivePath);
    }

    public function test_restores_shutdown_cleanup_closure_is_a_no_op_after_normal_completion(): void
    {
        // Same reasoning as the create() variant above, but for restore()'s
        // own registered closure -- deliberately triggered here via a
        // corrupt archive (restore() registers its cleanup closure before
        // attempting extraction, so the closure exists regardless of
        // whether extraction itself succeeds) so this test doesn't have to
        // repeat the full round-trip already covered by
        // test_create_then_restore_round_trips_real_data().
        $badArchive = tempnam(sys_get_temp_dir(), 'piwigo-bad-backup-shutdown-');
        self::assertIsString($badArchive);
        file_put_contents($badArchive, 'not a real archive');

        $service = new BackupService();
        $threw = null;

        try {
            $service->restore($badArchive, $this->scratchDb);
        } catch (\RuntimeException $e) {
            $threw = $e;
        } finally {
            unlink($badArchive);
        }

        self::assertNotNull($threw, 'restore() should have thrown for a corrupt (non-gzip) archive');

        // Not expectNotToPerformAssertions(): this method already performs
        // real assertions above -- confirmed live, PHPUnit's risky-test
        // detector flags that exact combination ("not expected to perform
        // assertions but performed N"), since the check applies to the
        // whole test method's total count, not just what follows the call.
        // If runAll() below were to throw, the test would fail with an
        // uncaught exception regardless -- no separate assertion needed.
        $runAll = new \ReflectionMethod(ShutdownHandler::class, 'runAll');
        $runAll->invoke(null);

        // Reaching this line means the already-registered closure ran
        // without throwing, even though restore()'s own `finally` had
        // already removed the same workDir moments earlier.
    }

    public function test_restore_rejects_a_nonexistent_archive_path(): void
    {
        $missingPath = sys_get_temp_dir() . '/piwigo-does-not-exist-' . bin2hex(random_bytes(8)) . '.tar.gz';
        self::assertFileDoesNotExist($missingPath);

        $service = new BackupService();
        $threw = null;

        try {
            $service->restore($missingPath, $this->scratchDb);
        } catch (\InvalidArgumentException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw);
        self::assertSame("Backup archive not found: {$missingPath}", $threw->getMessage());
    }

    public function test_restore_rejects_an_archive_missing_db_sql(): void
    {
        // A structurally valid archive (real gzip, real manifest.json
        // passing every readManifest() check) that simply never included
        // a db.sql -- distinct from test_restore_rejects_a_corrupt_archive
        // above, which fails at the tar-extraction step itself.
        $archivePath = $this->makeTestArchive([
            'manifest.json' => json_encode([
                'created_at' => '2026-01-01T00:00:00Z',
                'db_prefix' => $this->dbPrefix,
                'included' => [],
            ], JSON_THROW_ON_ERROR),
        ]);

        $service = new BackupService();
        $threw = null;

        try {
            $service->restore($archivePath, $this->scratchDb);
        } catch (\RuntimeException $e) {
            $threw = $e;
        } finally {
            unlink($archivePath);
        }

        self::assertNotNull($threw);
        self::assertSame("Invalid backup archive: missing db.sql in {$archivePath}", $threw->getMessage());
    }

    public function test_restore_rejects_an_archive_missing_manifest_json(): void
    {
        $archivePath = $this->makeTestArchive([
            'db.sql' => 'SELECT 1;',
        ]);

        $service = new BackupService();
        $threw = null;

        try {
            $service->restore($archivePath, $this->scratchDb);
        } catch (\RuntimeException $e) {
            $threw = $e;
        } finally {
            unlink($archivePath);
        }

        self::assertNotNull($threw);
        self::assertSame("Invalid backup archive: missing manifest.json in {$archivePath}", $threw->getMessage());
    }

    public function test_restore_rejects_an_archive_with_a_malformed_manifest(): void
    {
        $archivePath = $this->makeTestArchive([
            // Valid JSON, but missing every key readManifest() requires --
            // distinct from the missing-manifest.json-entirely case above.
            'manifest.json' => json_encode(['unrelated' => 'shape'], JSON_THROW_ON_ERROR),
            'db.sql' => 'SELECT 1;',
        ]);

        $service = new BackupService();
        $threw = null;

        try {
            $service->restore($archivePath, $this->scratchDb);
        } catch (\RuntimeException $e) {
            $threw = $e;
        } finally {
            unlink($archivePath);
        }

        self::assertNotNull($threw);
        self::assertSame("Invalid backup archive: malformed manifest.json in {$archivePath}", $threw->getMessage());
    }

    public function test_restore_database_throws_when_the_dump_file_cannot_be_opened_for_reading(): void
    {
        // restoreDatabase() is private, reached via reflection directly
        // (same technique as TelemetryServiceTest's detectDriverLabel()
        // tests) -- restore()'s own workDir is a randomly-named temp
        // directory generated internally, so there is no way to reach in
        // and chmod its extracted db.sql out from under it via the public
        // API alone. A permission-denied fopen() (torres owns the file
        // but stripped every permission bit) triggers a real PHP E_WARNING
        // this project's phpunit.xml.dist (failOnWarning) would otherwise
        // convert into a test failure -- suppressed here deliberately,
        // same technique as tests/Unit/Lang/TranslatorTest.php's own
        // set_error_handler() use, so fopen() can return its documented
        // `false` and this reaches BackupService's own RuntimeException
        // instead of a PHPUnit\Framework\Error\Warning.
        $dumpPath = tempnam(sys_get_temp_dir(), 'piwigo-unreadable-dump-');
        self::assertIsString($dumpPath);
        file_put_contents($dumpPath, 'SELECT 1;');
        chmod($dumpPath, 0000);

        $service = new BackupService();
        $method = new \ReflectionMethod(BackupService::class, 'restoreDatabase');

        set_error_handler(static fn (): bool => true, E_WARNING);

        $threw = null;
        try {
            $method->invoke($service, DbCredentials::fromEnv(), $this->scratchDb, $dumpPath);
        } catch (\RuntimeException $e) {
            $threw = $e;
        } finally {
            restore_error_handler();
            chmod($dumpPath, 0644);
            unlink($dumpPath);
        }

        self::assertNotNull($threw);
        self::assertSame("Unable to open dump file for reading: {$dumpPath}", $threw->getMessage());
    }

    public function test_restore_database_throws_when_the_mysql_process_itself_fails(): void
    {
        // Distinct from the fopen() failure above -- here the dump file
        // opens fine, but the real `mysql` CLI process fails (confirmed
        // empirically: targeting a database that was never created exits
        // non-zero with "Unknown database"), exercising the
        // `! $process->isSuccessful()` branch instead.
        $dumpPath = tempnam(sys_get_temp_dir(), 'piwigo-bad-target-dump-');
        self::assertIsString($dumpPath);
        file_put_contents($dumpPath, "SELECT 1;\n");

        $missingDb = $this->dbName . '_does_not_exist_' . bin2hex(random_bytes(4));

        $service = new BackupService();
        $method = new \ReflectionMethod(BackupService::class, 'restoreDatabase');

        $threw = null;
        try {
            $method->invoke($service, DbCredentials::fromEnv(), $missingDb, $dumpPath);
        } catch (\RuntimeException $e) {
            $threw = $e;
        } finally {
            unlink($dumpPath);
        }

        self::assertNotNull($threw);
        self::assertStringStartsWith('mysql restore failed: ', $threw->getMessage());
        self::assertStringContainsString('Unknown database', $threw->getMessage());
    }

    public function test_remove_dir_recurses_into_subdirectories(): void
    {
        // Neither create() nor restore() ever populates their own workDir
        // with a real subdirectory (every entry either method writes/
        // extracts there is a flat file: db.sql, manifest.json,
        // config.inc.php, galleries.tar) -- so removeDir()'s own
        // recursive-subdirectory branch is unreachable through either
        // public method in this environment, and is exercised directly
        // via reflection instead, with a hand-built nested tree.
        $root = sys_get_temp_dir() . '/piwigo-removedir-recurse-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/nested');
        file_put_contents($root . '/top-level.txt', 'a');
        file_put_contents($root . '/nested/inner.txt', 'b');

        $service = new BackupService();
        $method = new \ReflectionMethod(BackupService::class, 'removeDir');
        $method->invoke($service, $root);

        self::assertDirectoryDoesNotExist($root);
    }

    public function test_remove_dir_returns_early_when_scandir_fails(): void
    {
        // removeDir() is private, reached via reflection directly with a
        // directory that genuinely exists (is_dir() true) but can't be
        // listed (scandir() false, same permission-denial mechanism as
        // the fopen() test above -- torres owns the directory but every
        // permission bit is stripped) -- distinct from the sibling
        // is_dir()-false branch the shutdown-cleanup-closure tests above
        // already cover (a directory that simply no longer exists at
        // all). Same set_error_handler() suppression technique as the
        // fopen() test, since scandir() also raises a real E_WARNING on
        // failure.
        $dir = sys_get_temp_dir() . '/piwigo-unreadable-dir-' . bin2hex(random_bytes(8));
        mkdir($dir);
        file_put_contents($dir . '/leftover.txt', 'should remain untouched');
        chmod($dir, 0000);

        $service = new BackupService();
        $method = new \ReflectionMethod(BackupService::class, 'removeDir');

        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            $method->invoke($service, $dir);
        } finally {
            restore_error_handler();
            chmod($dir, 0755);
        }

        // scandir() failing must leave the directory (and its contents)
        // exactly as-is, rather than throwing or partially deleting it.
        self::assertDirectoryExists($dir);
        self::assertFileExists($dir . '/leftover.txt');

        unlink($dir . '/leftover.txt');
        rmdir($dir);
    }

    /**
     * Builds a real gzip tar archive containing exactly the given files --
     * PharData is used for CREATION only here (bypassing BackupService's
     * own create(), which always produces a structurally-valid archive);
     * confirmed empirically that phar.readonly=On (this environment's real
     * ini setting) does not block PharData tar/gz writes, only executable
     * Phar archives.
     *
     * @param array<string, string> $files relative filename => raw content
     */
    private function makeTestArchive(array $files): string
    {
        $srcDir = sys_get_temp_dir() . '/piwigo-backup-test-src-' . bin2hex(random_bytes(8));
        mkdir($srcDir);

        foreach ($files as $name => $content) {
            file_put_contents($srcDir . '/' . $name, $content);
        }

        $tarPath = sys_get_temp_dir() . '/piwigo-backup-test-' . bin2hex(random_bytes(8)) . '.tar';
        $tar = new \PharData($tarPath);
        foreach (array_keys($files) as $name) {
            $tar->addFile($srcDir . '/' . $name, $name);
        }
        $tar->compress(\Phar::GZ);
        unset($tar);
        unlink($tarPath);

        foreach (array_keys($files) as $name) {
            unlink($srcDir . '/' . $name);
        }
        rmdir($srcDir);

        return $tarPath . '.gz';
    }
}
