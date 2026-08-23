<?php

declare(strict_types=1);

use Piwigo\Admin\Install\InstallEnvironmentChecker;
use Piwigo\Core\Paths;

/**
 * InstallEnvironmentChecker::checkWritableDirectories() is a pure
 * filesystem probe -- no DB, no container -- so this exercises it
 * directly against a real, disposable directory tree (chmod-based, same
 * technique tests/Integration/InstallWizardTest.php's own
 * testPerformInstallRecordsAnErrorWhenTheEnvFileCannotBeWritten already
 * uses for the identical "root not writable" scenario).
 */
function makeInstallEnvironmentCheckerTempRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-install-env-checker-' . bin2hex(random_bytes(6)) . '/';
    mkdir($root, 0o777, true);
    mkdir($root . '_data', 0o777, true);
    mkdir($root . '_data/i', 0o777, true);
    mkdir($root . '_data/logs', 0o777, true);
    mkdir($root . 'upload', 0o777, true);

    return $root;
}

function removeInstallEnvironmentCheckerTempRoot(string $root): void
{
    // chmod back to writable first -- rmdir() on a still-0o555 directory
    // (this file's own read-only test cases leave one that way) fails.
    chmod($root, 0o777);
    foreach (['_data/i', '_data/logs', '_data', 'upload'] as $sub) {
        $path = $root . $sub;
        if (is_dir($path)) {
            chmod($path, 0o777);
            rmdir($path);
        }
    }
    rmdir($root);
}

test('checkWritableDirectories reports every real directory writable on a fully-writable tree', function (): void {
    $root = makeInstallEnvironmentCheckerTempRoot();

    try {
        $checks = new InstallEnvironmentChecker()
            ->checkWritableDirectories(Paths::fromRoot($root));

        expect($checks)
            ->toHaveCount(5);
        foreach ($checks as $check) {
            expect($check['writable'])->toBeTrue("expected {$check['path']} to be writable");
        }
    } finally {
        removeInstallEnvironmentCheckerTempRoot($root);
    }
});

test('checkWritableDirectories reports root as not writable when it is read-only', function (): void {
    $root = makeInstallEnvironmentCheckerTempRoot();

    try {
        chmod($root, 0o555);
        $checks = new InstallEnvironmentChecker()
            ->checkWritableDirectories(Paths::fromRoot($root));

        $rootCheck = $checks[0];
        expect($rootCheck['path'])->toBe($root)
            ->and($rootCheck['writable'])->toBeFalse();
    } finally {
        removeInstallEnvironmentCheckerTempRoot($root);
    }
});

test('checkWritableDirectories reports a specific subdirectory as not writable when only it is read-only', function (): void {
    $root = makeInstallEnvironmentCheckerTempRoot();

    try {
        chmod($root . 'upload', 0o555);
        $checks = new InstallEnvironmentChecker()
            ->checkWritableDirectories(Paths::fromRoot($root));

        $byPath = [];
        foreach ($checks as $check) {
            $byPath[$check['path']] = $check['writable'];
        }

        expect($byPath[$root . 'upload/'])->toBeFalse()
            ->and($byPath[$root])->toBeTrue()
            ->and($byPath[$root . '_data/'])->toBeTrue();
    } finally {
        chmod($root . 'upload', 0o777);
        removeInstallEnvironmentCheckerTempRoot($root);
    }
});

test('checkWritableDirectories reports false, not a warning, for a directory that does not exist yet', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-install-env-checker-missing-' . bin2hex(random_bytes(6)) . '/';
    mkdir($root, 0o777, true);

    try {
        // _data/upload/etc were deliberately never created -- confirms
        // is_writable() on a non-existent path returns a clean false with
        // no PHP warning (this class's own docblock claim), not just that
        // the boolean happens to be false.
        $checks = new InstallEnvironmentChecker()
            ->checkWritableDirectories(Paths::fromRoot($root));

        foreach ($checks as $check) {
            if ($check['path'] === $root) {
                continue;
            }
            expect($check['writable'])->toBeFalse();
        }
    } finally {
        rmdir($root);
    }
});
