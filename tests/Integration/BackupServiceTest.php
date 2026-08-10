<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use RuntimeException;
use PharData;
use ReflectionMethod;
use ReflectionProperty;
use InvalidArgumentException;
use Phar;
use Throwable;
use Piwigo\Backup\BackupService;
use Piwigo\Core\ShutdownHandler;
use Piwigo\Db\DbCredentials;

/**
 * Real command-level proof for `bin/piwigo backup:create`/`backup:restore`,
 * replacing the rewire tools/restore-drill.sh's own header comment
 * anticipated ("Rewire onto the real bin/piwigo backup:restore once it
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

    #[Override]
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

    #[Override]
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

        $this->dropDatabase($this->scratchDb);

        if ($this->archivePath !== '' && is_file($this->archivePath)) {
            unlink($this->archivePath);
        }

        parent::tearDown();
    }

    public function test_constructor_computes_repo_root_and_backups_dir_from_the_real_source_location(): void
    {
        // Neither value is otherwise observable through the public API in
        // a way that pins its EXACT value (create()/restore() both still
        // "work" even if repoRoot pointed one level too shallow or too
        // deep, as long as whatever it resolves to happens to be
        // writable) -- reflection on both private readonly properties is
        // the only way to confirm dirname(__DIR__, 3) really lands on the
        // real repo root (not one level either side of it) and that
        // backupsDir is built by concatenating, in the right order, onto
        // that exact value.
        $service = new BackupService();

        $repoRootProperty = new ReflectionProperty(BackupService::class, 'repoRoot');
        $repoRoot = $repoRootProperty->getValue($service);

        $backupsDirProperty = new ReflectionProperty(BackupService::class, 'backupsDir');
        $backupsDir = $backupsDirProperty->getValue($service);

        // dirname(__DIR__, 2) from THIS file (tests/Integration/) lands on
        // the same repo root as BackupService's own dirname(__DIR__, 3)
        // from src/Piwigo/Backup/ -- same technique already used by
        // test_create_recreates_the_backups_directory_when_it_is_missing
        // below.
        self::assertSame(dirname(__DIR__, 2), $repoRoot);
        self::assertSame($repoRoot . '/_data/backups', $backupsDir);
    }

    public function test_create_then_restore_round_trips_real_data(): void
    {
        $service = new BackupService();

        $this->archivePath = $service->create();
        self::assertFileExists($this->archivePath);

        $this->dropAndCreateDatabase($this->scratchDb);

        $service->restore($this->archivePath, $this->scratchDb);

        $imageCount = (int) $this->queryScalarFromDatabase(
            $this->scratchDb,
            sprintf('SELECT COUNT(*) FROM %simages', $this->dbPrefix)
        );
        $userCount = (int) $this->queryScalarFromDatabase(
            $this->scratchDb,
            sprintf('SELECT COUNT(*) FROM %susers', $this->dbPrefix)
        );

        self::assertGreaterThanOrEqual(1, $imageCount, 'Restored DB should have at least one image');
        self::assertGreaterThanOrEqual(1, $userCount, 'Restored DB should have at least one user');

        // Schema smoke query: a join across tables proves the schema itself
        // (not just raw row presence) survived the restore intact -- same
        // assertion tools/restore-drill.sh makes. Only the query executing
        // without error matters here (queryScalarFromDatabase() itself
        // asserts a successful query internally); the returned value isn't
        // otherwise checked.
        $this->queryScalarFromDatabase($this->scratchDb, sprintf(
            'SELECT i.id FROM %1$simages i JOIN %1$simage_category ic ON ic.image_id = i.id LIMIT 1',
            $this->dbPrefix
        ));
    }

    public function test_restore_rejects_a_corrupt_archive(): void
    {
        $badArchive = tempnam(sys_get_temp_dir(), 'piwigo-bad-backup-');
        self::assertIsString($badArchive);
        file_put_contents($badArchive, 'not a real archive');

        $service = new BackupService();

        try {
            $this->expectException(RuntimeException::class);
            $service->restore($badArchive, $this->scratchDb);
        } finally {
            unlink($badArchive);
        }
    }

    public function test_run_process_failure_message_includes_the_description_and_the_real_error_output(): void
    {
        // Distinct from test_restore_rejects_a_corrupt_archive above
        // (which only asserts the exception TYPE): this pins the exact
        // message SHAPE the shared runProcess() helper builds --
        // "{$description} failed: " followed by the real
        // $process->getErrorOutput() -- reached here via restore()'s own
        // tar extraction step against a genuinely corrupt (non-gzip)
        // archive, so getErrorOutput() is real tar/gzip stderr, not a
        // canned string.
        $badArchive = tempnam(sys_get_temp_dir(), 'piwigo-bad-backup-message-');
        self::assertIsString($badArchive);
        file_put_contents($badArchive, 'not a real archive');

        $service = new BackupService();
        $threw = null;

        try {
            $service->restore($badArchive, $this->scratchDb);
        } catch (RuntimeException $e) {
            $threw = $e;
        } finally {
            unlink($badArchive);
        }

        self::assertNotNull($threw);
        self::assertStringStartsWith('extract backup archive failed: ', $threw->getMessage());
        self::assertGreaterThan(strlen('extract backup archive failed: '), strlen($threw->getMessage()));
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
            new PharData($this->archivePath)->extractTo($extractDir);

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

    public function test_create_writes_a_real_database_dump_using_the_expected_mysqldump_invocation(): void
    {
        // Distinct from test_create_then_restore_round_trips_real_data
        // above (which only proves the round trip as a whole works): this
        // pins the exact CONTENT dumpDatabase() produces, closing several
        // distinct gaps at once --
        //   - dumpDatabase() must actually be called at all (create()
        //     would otherwise tar a db.sql that was never written and
        //     throw before ever reaching this point);
        //   - its output path argument must be exactly $workDir.'/db.sql'
        //     (any other value makes mysqldump fail to open that path for
        //     writing, or makes tar fail to find it afterward);
        //   - the mysqldump command array itself must be exactly
        //     ['mysqldump', ...credentials, '--skip-comments',
        //     '--result-file='.$outputPath, $database] -- confirmed
        //     empirically that --skip-comments is what suppresses
        //     mysqldump's own "-- MySQL dump ..." header comment, so its
        //     absence here proves that flag really made it into the real
        //     command line, not just that *some* dump happened to work.
        $service = new BackupService();
        $this->archivePath = $service->create();
        self::assertFileExists($this->archivePath);

        $extractDir = sys_get_temp_dir() . '/piwigo-backup-dump-check-' . bin2hex(random_bytes(8));
        mkdir($extractDir);

        try {
            new PharData($this->archivePath)->extractTo($extractDir);

            self::assertFileExists($extractDir . '/db.sql');
            $dump = (string) file_get_contents($extractDir . '/db.sql');

            self::assertNotSame('', $dump);
            self::assertStringContainsString('CREATE TABLE', $dump);
            self::assertStringContainsString($this->dbPrefix . 'images', $dump);

            if ($this->dbDriver !== 'pgsql') {
                self::assertStringNotContainsString('-- MySQL dump', $dump);
            }
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

    public function test_create_registers_a_shutdown_cleanup_closure(): void
    {
        // Distinct from test_creates_shutdown_cleanup_closure_is_a_no_op_
        // after_normal_completion() below, which only proves running an
        // already-registered closure doesn't crash -- that test would
        // pass identically whether create() ever registered a closure at
        // all. Reflection on ShutdownHandler's own private $callbacks
        // registry is the only way to confirm the registration call
        // itself really happened.
        ShutdownHandler::reset();

        $service = new BackupService();
        $this->archivePath = $service->create();

        $callbacks = new ReflectionProperty(ShutdownHandler::class, 'callbacks');
        /** @var list<callable(): void> $callbackValues */
        $callbackValues = $callbacks->getValue();
        self::assertCount(1, $callbackValues);
    }

    public function test_create_removes_its_own_work_dir_after_a_successful_run(): void
    {
        // create()'s own workDir is always named with the
        // 'piwigo-backup-create-' prefix (see makeTempDir()'s own call
        // site above) -- confirming none of that shape survives under
        // sys_get_temp_dir() after a normal, successful create() call
        // proves the `finally` block's removeDir($workDir) call really
        // ran, distinct from create()'s own returned archive simply
        // existing (which says nothing about whether the intermediate
        // workDir was ever cleaned up).
        $beforeGlob = glob(sys_get_temp_dir() . '/piwigo-backup-create-*');
        $before = $beforeGlob !== false ? $beforeGlob : [];

        $service = new BackupService();
        $this->archivePath = $service->create();

        $afterGlob = glob(sys_get_temp_dir() . '/piwigo-backup-create-*');
        $after = $afterGlob !== false ? $afterGlob : [];

        self::assertSame($before, $after, 'create() should remove its own temp work dir on success');
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

        $runAll = new ReflectionMethod(ShutdownHandler::class, 'runAll');
        $runAll->invoke(null);

        // Reaching this line means the already-registered closure ran
        // without throwing.
        self::assertFileExists($this->archivePath);
    }

    public function test_restore_registers_a_shutdown_cleanup_closure(): void
    {
        // Same reasoning as test_create_registers_a_shutdown_cleanup_
        // closure() above, for restore()'s own registration call --
        // exercised here via the same minimal hand-built archive the
        // malformed-manifest tests below use, so this stays fast rather
        // than repeating a full create()-then-restore() round trip.
        $archivePath = $this->makeTestArchive([
            'manifest.json' => json_encode([
                'created_at' => '2026-01-01T00:00:00Z',
                'included' => ['db.sql'],
            ], JSON_THROW_ON_ERROR),
            'db.sql' => "SELECT 1;\n",
        ]);
        $this->dropAndCreateDatabase($this->scratchDb);

        ShutdownHandler::reset();

        $service = new BackupService();
        try {
            $service->restore($archivePath, $this->scratchDb);
        } finally {
            unlink($archivePath);
        }

        $callbacks = new ReflectionProperty(ShutdownHandler::class, 'callbacks');
        /** @var list<callable(): void> $callbackValues */
        $callbackValues = $callbacks->getValue();
        self::assertCount(1, $callbackValues);
    }

    public function test_restore_removes_its_own_work_dir_after_a_successful_run(): void
    {
        // Same reasoning as test_create_removes_its_own_work_dir_after_a_
        // successful_run() above, for restore()'s own
        // 'piwigo-backup-restore-' workDir -- uses the same minimal
        // hand-built archive as test_restore_registers_a_shutdown_
        // cleanup_closure() above to stay fast.
        $archivePath = $this->makeTestArchive([
            'manifest.json' => json_encode([
                'created_at' => '2026-01-01T00:00:00Z',
                'included' => ['db.sql'],
            ], JSON_THROW_ON_ERROR),
            'db.sql' => "SELECT 1;\n",
        ]);
        $this->dropAndCreateDatabase($this->scratchDb);

        $beforeGlob = glob(sys_get_temp_dir() . '/piwigo-backup-restore-*');
        $before = $beforeGlob !== false ? $beforeGlob : [];

        $service = new BackupService();
        try {
            $service->restore($archivePath, $this->scratchDb);
        } finally {
            unlink($archivePath);
        }

        $afterGlob = glob(sys_get_temp_dir() . '/piwigo-backup-restore-*');
        $after = $afterGlob !== false ? $afterGlob : [];

        self::assertSame($before, $after, 'restore() should remove its own temp work dir on success');
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
        } catch (RuntimeException $e) {
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
        $runAll = new ReflectionMethod(ShutdownHandler::class, 'runAll');
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
        } catch (Throwable $e) {
            // Catches ANY throwable, not just InvalidArgumentException: if
            // the is_file() guard were ever skipped, execution would fall
            // through to a real tar extraction attempt against a
            // genuinely missing file, which fails with a RuntimeException
            // instead -- a plain catch(InvalidArgumentException) would let
            // that propagate uncaught rather than surfacing here as a
            // precise, wrong-exception-type assertion failure.
            $threw = $e;
        }

        self::assertInstanceOf(InvalidArgumentException::class, $threw);
        self::assertSame("Backup archive not found: {$missingPath}", $threw->getMessage());
    }

    public function test_restore_extracts_the_archive_using_the_expected_tar_invocation(): void
    {
        // Uses a minimal hand-built archive (same makeTestArchive() helper
        // as the malformed-manifest tests below) instead of the full DB
        // fixture round trip, so this stays fast while still exercising
        // restore()'s real tar extraction end-to-end. Confirmed
        // empirically (real `tar` invocations, not guessed): dropping any
        // single element from ['tar', 'xzf', $archivePath, '-C', $workDir]
        // makes the real tar binary exit non-zero -- missing 'tar' tries
        // to execute "xzf" as a command (not found); missing 'xzf' leaves
        // tar with no action flag; missing $archivePath makes '-C' get
        // consumed as the (nonexistent) archive filename; missing '-C'
        // makes $workDir get treated as a member-name filter that never
        // matches; missing $workDir leaves '-C' without its required
        // argument; and dropping the whole runProcess() call leaves
        // workDir empty, so readManifest() itself throws next. So
        // reaching the mysql restore step below at all already proves
        // every element of that array -- and the call itself -- survived
        // intact.
        $archivePath = $this->makeTestArchive([
            'manifest.json' => json_encode([
                'created_at' => '2026-01-01T00:00:00Z',
                'included' => ['db.sql'],
            ], JSON_THROW_ON_ERROR),
            'db.sql' => "SELECT 1;\n",
        ]);

        $this->dropAndCreateDatabase($this->scratchDb);

        $service = new BackupService();

        try {
            $service->restore($archivePath, $this->scratchDb);
        } finally {
            unlink($archivePath);
        }

        // Reaching this line without an exception already means tar
        // genuinely extracted manifest.json + db.sql into restore()'s own
        // workDir (readManifest() and the db.sql is_file() check both
        // passed) -- this also confirms the trivial dump applied cleanly.
        self::assertSame('1', $this->queryScalarFromDatabase($this->scratchDb, 'SELECT 1'));
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
                'included' => [],
            ], JSON_THROW_ON_ERROR),
        ]);

        $service = new BackupService();
        $threw = null;

        try {
            $service->restore($archivePath, $this->scratchDb);
        } catch (RuntimeException $e) {
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
        } catch (RuntimeException $e) {
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
        } catch (RuntimeException $e) {
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
        $method = new ReflectionMethod(BackupService::class, 'restoreDatabase');

        set_error_handler(static fn (): bool => true, E_WARNING);

        $threw = null;
        try {
            $method->invoke($service, DbCredentials::fromEnv(), $this->scratchDb, $dumpPath);
        } catch (RuntimeException $e) {
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
        // opens fine, but the real mysql/psql CLI process fails (confirmed
        // empirically: targeting a database that was never created exits
        // non-zero -- MySQL says "Unknown database", Postgres says
        // "database ... does not exist"), exercising the
        // `! $process->isSuccessful()` branch instead.
        $dumpPath = tempnam(sys_get_temp_dir(), 'piwigo-bad-target-dump-');
        self::assertIsString($dumpPath);
        file_put_contents($dumpPath, "SELECT 1;\n");

        $missingDb = $this->dbName . '_does_not_exist_' . bin2hex(random_bytes(4));

        $service = new BackupService();
        $method = new ReflectionMethod(BackupService::class, 'restoreDatabase');

        $threw = null;
        try {
            $method->invoke($service, DbCredentials::fromEnv(), $missingDb, $dumpPath);
        } catch (RuntimeException $e) {
            $threw = $e;
        } finally {
            unlink($dumpPath);
        }

        self::assertNotNull($threw);
        self::assertStringStartsWith(
            $this->dbDriver === 'pgsql' ? 'psql restore failed: ' : 'mysql restore failed: ',
            $threw->getMessage()
        );
        self::assertStringContainsString(
            $this->dbDriver === 'pgsql' ? 'does not exist' : 'Unknown database',
            $threw->getMessage()
        );
    }

    public function test_make_temp_dir_builds_its_path_under_the_system_temp_dir_with_extra_entropy(): void
    {
        // uniqid($prefix, true) (more_entropy=true) appends a '.' plus 8
        // extra random digits after the 13-hex-character timestamp
        // portion -- uniqid($prefix, false) never includes that '.' at
        // all, so its presence is a reliable, format-level signal that
        // more_entropy was really passed. Checking the directory's
        // location itself (must start with sys_get_temp_dir() . '/')
        // separately confirms the concatenation order/completeness, not
        // just the uniqid() call in isolation.
        $service = new BackupService();
        $method = new ReflectionMethod(BackupService::class, 'makeTempDir');

        /** @var string $dir */
        $dir = $method->invoke($service, 'piwigo-backup-entropy-test-');

        try {
            self::assertStringStartsWith(sys_get_temp_dir() . '/', $dir);
            self::assertStringContainsString('.', basename($dir));
        } finally {
            rmdir($dir);
        }
    }

    public function test_make_temp_dir_creates_a_real_directory_with_exactly_0775_permissions(): void
    {
        // umask(0) around the call (same established technique as
        // tests/Unit/Core/FilesystemHelperTest.php's own umask tests)
        // removes the ambient umask's own bit-stripping, so the resulting
        // fileperms() exactly reflects the literal mode mkdir() was
        // called with -- otherwise this environment's default umask would
        // mask away the exact bit(s) that distinguish 0775 from its
        // DecrementInteger/IncrementInteger mutants (0774/0776), since
        // both happen to collapse to the identical masked result here.
        $service = new BackupService();
        $method = new ReflectionMethod(BackupService::class, 'makeTempDir');

        $previousUmask = umask(0);
        try {
            /** @var string $dir */
            $dir = $method->invoke($service, 'piwigo-backup-perm-test-');
        } finally {
            umask($previousUmask);
        }

        try {
            self::assertDirectoryExists($dir, 'makeTempDir() should have created a real directory');
            self::assertSame(0775, fileperms($dir) & 0777);
        } finally {
            rmdir($dir);
        }
    }

    public function test_remove_dir_is_a_no_op_when_the_directory_does_not_exist(): void
    {
        // Distinct from the shutdown-cleanup-closure tests above (which
        // also exercise this same guard indirectly, but can't tell an
        // early return apart from a fallthrough that happens to still be
        // silent) -- this reaches removeDir() directly via reflection with
        // a path that never existed at all, and deliberately does NOT
        // suppress warnings, so a skipped guard here (falling through to
        // scandir() on a nonexistent path) surfaces as a real E_WARNING
        // this project's phpunit.xml.dist (failOnWarning) converts into a
        // test failure.
        $missingDir = sys_get_temp_dir() . '/piwigo-removedir-missing-' . bin2hex(random_bytes(8));
        self::assertDirectoryDoesNotExist($missingDir);

        $service = new BackupService();
        $method = new ReflectionMethod(BackupService::class, 'removeDir');
        $method->invoke($service, $missingDir);

        self::assertDirectoryDoesNotExist($missingDir);
    }

    public function test_remove_dir_returns_early_when_scandir_fails_on_an_empty_directory(): void
    {
        // Distinct from test_remove_dir_returns_early_when_scandir_fails
        // below (which uses a NON-empty directory, so its own two
        // assertions can't tell an early return apart from a fallthrough:
        // rmdir() on a non-empty directory fails just as silently whether
        // or not it's ever even called). An EMPTY chmod(0000) directory
        // breaks that symmetry: confirmed empirically that rmdir() on
        // Linux only needs write+execute permission on the PARENT
        // directory (not the target itself) and only requires the target
        // be empty -- so if execution ever reaches the `rmdir($dir)` call
        // below the `$items === false` guard, it actually succeeds and
        // removes $dir, whereas the real early-return path never calls
        // rmdir() at all and leaves $dir in place.
        $dir = sys_get_temp_dir() . '/piwigo-unreadable-empty-dir-' . bin2hex(random_bytes(8));
        mkdir($dir);
        chmod($dir, 0000);

        $service = new BackupService();
        $method = new ReflectionMethod(BackupService::class, 'removeDir');

        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            $method->invoke($service, $dir);
        } finally {
            restore_error_handler();
        }

        self::assertDirectoryExists($dir, 'removeDir() should return early (never reach rmdir()) when scandir() fails');

        chmod($dir, 0755);
        rmdir($dir);
    }

    public function test_remove_dir_unlinks_a_directory_symlink_without_following_it(): void
    {
        // Distinct from test_remove_dir_recurses_into_subdirectories
        // below: that test only proves recursion happens for a REAL
        // subdirectory. A directory *symlink* must take the opposite
        // path -- is_dir() alone would say true for it too (is_dir()
        // follows symlinks), so the `! is_link($path)` half of the guard
        // is what keeps removeDir() from following it into some unrelated
        // real directory and deleting that target's own contents.
        // Confirmed empirically: following the symlink really does
        // unlink() the real file on the other side, and rmdir() on the
        // symlink path itself then fails ("Not a directory", since
        // rmdir() never follows a trailing symlink) -- so a real,
        // separate target directory with its own file proves this
        // precisely, not just "no exception was thrown".
        $target = sys_get_temp_dir() . '/piwigo-removedir-symlink-target-' . bin2hex(random_bytes(8));
        mkdir($target);
        file_put_contents($target . '/keep-me.txt', 'must survive');

        $root = sys_get_temp_dir() . '/piwigo-removedir-symlink-root-' . bin2hex(random_bytes(8));
        mkdir($root);
        symlink($target, $root . '/link-to-target');

        $service = new BackupService();
        $method = new ReflectionMethod(BackupService::class, 'removeDir');
        $method->invoke($service, $root);

        self::assertDirectoryDoesNotExist($root);
        self::assertDirectoryExists($target, 'removeDir() must not follow the symlink into the target directory');
        self::assertFileExists($target . '/keep-me.txt');

        unlink($target . '/keep-me.txt');
        rmdir($target);
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
        $method = new ReflectionMethod(BackupService::class, 'removeDir');
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
        $method = new ReflectionMethod(BackupService::class, 'removeDir');

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
        $tar = new PharData($tarPath);
        foreach (array_keys($files) as $name) {
            $tar->addFile($srcDir . '/' . $name, $name);
        }
        $tar->compress(Phar::GZ);
        unset($tar);
        unlink($tarPath);

        foreach (array_keys($files) as $name) {
            unlink($srcDir . '/' . $name);
        }
        rmdir($srcDir);

        return $tarPath . '.gz';
    }
}
