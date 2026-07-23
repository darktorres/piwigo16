<?php

declare(strict_types=1);

use Piwigo\Backup\BackupService;
use Piwigo\Command\BackupRestoreCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

// Destructive-operation guards only -- these must reject before ever
// touching BackupService::restore() (drops/recreates the target DB,
// overwrites galleries/), so a real BackupService instance is safe to use
// throughout: it's never actually invoked in the rejection paths. The one
// case that does reach the service (valid file + --force) intentionally
// points at a garbage archive so it fails inside BackupService's own tar
// extraction rather than needing a real DB -- proving the command wires
// through to the service without needing Integration-tier infra. The real
// success path is covered by tests/Integration/BackupServiceTest.php.

test('aborts when the file does not exist', function (): void {
    $command = new BackupRestoreCommand(new BackupService());
    $tester = new CommandTester($command);

    $exitCode = $tester->execute(['file' => '/nonexistent/piwigo-backup.tar.gz', '--force' => true]);

    expect($exitCode)->toBe(Command::INVALID)
        ->and($tester->getDisplay())->toContain('not found');
});

test('aborts without --force even when the file exists', function (): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'piwigo-backup-test-');
    expect($tmpFile)->not->toBeFalse();
    if ($tmpFile === false) {
        return;
    }

    try {
        $command = new BackupRestoreCommand(new BackupService());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['file' => $tmpFile]);

        expect($exitCode)->toBe(Command::INVALID)
            ->and($tester->getDisplay())->toContain('Refusing to restore without --force');
    } finally {
        unlink($tmpFile);
    }
});

test('a valid file with --force reaches the backup service', function (): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'piwigo-backup-test-');
    expect($tmpFile)->not->toBeFalse();
    if ($tmpFile === false) {
        return;
    }
    file_put_contents($tmpFile, 'not a real tar.gz archive');

    try {
        $command = new BackupRestoreCommand(new BackupService());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['file' => $tmpFile, '--force' => true, '--database' => 'piwigo_test_bogus']);

        expect($exitCode)->toBe(Command::FAILURE)
            ->and($tester->getDisplay())->toContain('Restore failed');
    } finally {
        unlink($tmpFile);
    }
});
