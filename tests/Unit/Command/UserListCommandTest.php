<?php

declare(strict_types=1);

use Piwigo\Command\UserListCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Piwigo\Command\UserListCommand -- CLI-only, shells out to a real
 * `mysql`/`psql` client (env-var credentials, DbCredentials::fromEnv())
 * rather than Doctrine. No dedicated Integration/Browser spec of its
 * own. Run for real against the real test DB (same real-DB philosophy
 * as every other Unit test in this campaign) -- proves the real
 * per-driver branching (see this class's own docblock: it used to
 * always shell out to `mysql` regardless of `PIWIGO_DB_DRIVER`, hanging
 * for the full 60s timeout against a real Postgres install) actually
 * reaches a real client binary and gets a real, well-formed table back.
 */
test('execute lists real users from the real test database', function (): void {
    $command = new UserListCommand();
    $tester = new CommandTester($command);

    $exitCode = $tester->execute([]);

    expect($exitCode)
        ->toBe(Command::SUCCESS);
    $display = $tester->getDisplay();
    expect($display)
        ->toContain('ID')
        ->and($display)
        ->toContain('Username')
        ->and($display)
        ->toContain('Status');
});
