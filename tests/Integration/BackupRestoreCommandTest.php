<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Backup\BackupService;
use Piwigo\Command\BackupRestoreCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * tests/Unit/Command/BackupRestoreCommandTest.php already covers every
 * rejection branch (missing file, no --force) and one case that reaches
 * BackupService::restore() but fails inside it (a garbage archive) --
 * its own docblock explicitly defers the real success path to
 * tests/Integration/BackupServiceTest.php, which never actually drives
 * it through this *command*. This is that missing companion: a genuine
 * create() -> restore() round trip through the command, into its own
 * scratch database (never piwigo_test itself), same discipline
 * BackupServiceTest already established for BackupService directly.
 */
final class BackupRestoreCommandTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private string $archivePath = '';

    private string $scratchDb = '';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        // This class runs unwrapped, real-commit tests against the
        // shared fixture DB -- marks the shared trust flag dirty so any
        // DbTransactionTestOverride-wrapped class running after this one
        // knows it can't skip its own reimport. See
        // IntegrationTestCase::$sharedFixtureKnownPristine's own docblock.
        IntegrationTestCase::markSharedFixtureDirty();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $this->scratchDb = $this->dbName . '_backup_restore_command_test';
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->dropDatabase($this->scratchDb);

        if ($this->archivePath !== '' && is_file($this->archivePath)) {
            unlink($this->archivePath);
        }

        parent::tearDown();
    }

    public function testExecuteReportsSuccessAndReallyRestoresIntoTheNamedDatabase(): void
    {
        $this->archivePath = new BackupService()
            ->create();
        self::assertFileExists($this->archivePath);

        // BackupService::restore()'s own mysql/psql import needs the
        // target database to already exist -- neither client can create
        // it as part of the import itself, matching BackupServiceTest's
        // own identical create-the-scratch-db-first setup.
        $this->dropAndCreateDatabase($this->scratchDb);

        $command = new BackupRestoreCommand(new BackupService());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'file' => $this->archivePath,
            '--force' => true,
            '--database' => $this->scratchDb,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            "Restored {$this->archivePath} into database '{$this->scratchDb}'.\n",
            $tester->getDisplay()
        );

        $imageCount = (int) $this->queryScalarFromDatabase(
            $this->scratchDb,
            'SELECT COUNT(*) FROM images'
        );
        self::assertGreaterThanOrEqual(1, $imageCount);
    }

    public function testExecuteRestoresIntoPiwigoDbBaseWhenNoDatabaseOptionIsGiven(): void
    {
        // Exercises the `$targetDatabase = ... DbCredentials::fromEnv()->database`
        // fallback branch specifically -- still redirected onto the scratch
        // DB, but via PIWIGO_DB_BASE rather than --database, so the real
        // production default-target codepath gets covered too, not just
        // the explicit-option one above.
        $this->archivePath = new BackupService()
            ->create();

        // See the previous test's own comment: the target database must
        // already exist before BackupService::restore()'s import.
        $this->dropAndCreateDatabase($this->scratchDb);

        $originalBase = getenv('PIWIGO_DB_BASE');
        putenv('PIWIGO_DB_BASE=' . $this->scratchDb);

        try {
            $command = new BackupRestoreCommand(new BackupService());
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                'file' => $this->archivePath,
                '--force' => true,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertSame(
                "Restored {$this->archivePath} into database '{$this->scratchDb}'.\n",
                $tester->getDisplay()
            );
        } finally {
            putenv($originalBase === false ? 'PIWIGO_DB_BASE' : 'PIWIGO_DB_BASE=' . $originalBase);
        }
    }
}
