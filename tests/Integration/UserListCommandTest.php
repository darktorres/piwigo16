<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Db\DbConnection;
use Piwigo\Command\UserListCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * New CLI-only capability (no web equivalent) -- see
 * docs/PLAN.md's P12 section ("CLI tool + backup/restore +
 * graceful shutdown"). Reads via a raw `mysql`/`psql` client shell-out, so
 * this needs a real DB, hence Integration tier rather than Unit.
 */
final class UserListCommandTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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
    }

    public function test_lists_users_from_the_fixture(): void
    {
        $command = new UserListCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('ID', $tester->getDisplay());
        self::assertStringContainsString('Username', $tester->getDisplay());
    }

    public function test_reports_a_formatted_error_and_fails_when_the_mysql_query_itself_fails(): void
    {
        // Same "point PIWIGO_DB_PORT at a closed local port for a fast,
        // real connection-refused failure" trick already established this
        // session for BackupCreateCommandTest -- DbCredentials::fromEnv()
        // (not the memoized current()) picks up the change immediately.
        $originalPort = getenv('PIWIGO_DB_PORT');
        putenv('PIWIGO_DB_PORT=1');

        try {
            $command = new UserListCommand();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([]);

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertStringContainsString('Query failed:', $tester->getDisplay());
        } finally {
            putenv($originalPort === false ? 'PIWIGO_DB_PORT' : 'PIWIGO_DB_PORT=' . $originalPort);
        }
    }

    public function test_reports_no_users_found_against_an_empty_database(): void
    {
        // Destructive against the shared fixture DB (empties the two
        // tables this command queries) -- reloads the full fixture again
        // in a finally block so every other test in this shared-process
        // suite still sees the real fixture data afterward, regardless of
        // pass/fail here. loadFixture() is the same idempotent, full
        // DROP+CREATE+INSERT reset setUp() itself relies on for whichever
        // Integration test class happens to run first.
        // DELETE, not TRUNCATE -- Postgres's TRUNCATE refuses a table with
        // live incoming FK references regardless of
        // session_replication_role ("cannot truncate a table referenced
        // in a foreign key constraint"), confirmed live: that check isn't
        // trigger-based the way FK *enforcement* is, so disabling
        // triggers doesn't help it. DELETE's own FK check is trigger-based
        // and correctly bypassed the same way every other real
        // disableForeignKeyChecks() caller in this suite already relies
        // on.
        $conn = DbConnection::build();
        $this->disableForeignKeyChecks($conn);
        $conn->executeStatement('DELETE FROM ' . $this->dbPrefix . 'user_infos');
        $conn->executeStatement('DELETE FROM ' . $this->dbPrefix . 'users');
        $this->enableForeignKeyChecks($conn);

        try {
            $command = new UserListCommand();
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertSame("No users found.\n", $tester->getDisplay());
        } finally {
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
        }
    }
}
