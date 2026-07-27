<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Command\UserListCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * New CLI-only capability (no web equivalent) -- see
 * docs/PLAN.md's P12 section ("CLI tool + backup/restore +
 * graceful shutdown"). Reads via a raw `mysql` client shell-out, so this
 * needs a real DB, hence Integration tier rather than Unit.
 */
final class UserListCommandTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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
}
