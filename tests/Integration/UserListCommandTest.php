<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Command\UserListCommand;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Users\UserRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UserListCommandTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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
    }

    private function makeCommand(): UserListCommand
    {
        return new UserListCommand(new UserRepository(
            EntityManagerFactory::build(DbConnection::build()),
            EventDispatcherTestFactory::get(),
            CurrentConfigTestFactory::get(),
        ));
    }

    public function testListsUsersFromTheFixture(): void
    {
        $tester = new CommandTester($this->makeCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('ID', $tester->getDisplay());
        self::assertStringContainsString('Username', $tester->getDisplay());
        self::assertStringContainsString('Status', $tester->getDisplay());
    }

    public function testReportsNoUsersFoundAgainstAnEmptyDatabase(): void
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
        $conn->executeStatement('DELETE FROM user_infos');
        $conn->executeStatement('DELETE FROM users');
        $this->enableForeignKeyChecks($conn);

        try {
            $tester = new CommandTester($this->makeCommand());

            $exitCode = $tester->execute([]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertSame("No users found.\n", $tester->getDisplay());
        } finally {
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
        }
    }
}
