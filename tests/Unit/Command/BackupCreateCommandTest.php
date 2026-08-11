<?php

declare(strict_types=1);

use Piwigo\Backup\BackupService;
use Piwigo\Command\BackupCreateCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

// Had zero dedicated coverage. Same "don't re-drive the real
// backup pipeline here" reasoning as BackupRestoreCommandTest's own
// docblock -- the real create() success path is
// tests/Integration/BackupServiceTest.php's job. This only proves the
// command wires through to BackupService::create() and formats both exit
// codes correctly, forcing the failure branch deterministically by
// pointing PIWIGO_DB_PORT at a closed local port (a fast, real
// connection-refused failure, confirmed live: mysqldump against
// 127.0.0.1:1 fails in ~20ms, not a
// hang) rather than mocking BackupService (a concrete class, no seam for
// a fake).

test('a database connection failure is reported as a formatted error', function (): void {
    $originalPort = getenv('PIWIGO_DB_PORT');
    putenv('PIWIGO_DB_PORT=1');

    try {
        $command = new BackupCreateCommand(new BackupService());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        expect($exitCode)
            ->toBe(Command::FAILURE);
        expect($tester->getDisplay())
            ->toContain('Backup failed:');
    } finally {
        if ($originalPort === false) {
            putenv('PIWIGO_DB_PORT');
        } else {
            putenv('PIWIGO_DB_PORT=' . $originalPort);
        }
    }
});
