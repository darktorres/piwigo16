<?php

declare(strict_types=1);

use Piwigo\Core\Env;

/**
 * No prior EnvTest.php existed. testModeHeader()/testModeIsActive()/
 * testModeEnvFile()/testModeInstalledStamp()/now() and loadEnvFile()'s own
 * real-load path already have full coverage through other suites'
 * bootstrap flows -- this file closes the two remaining gaps:
 * loadEnvFile()'s missing-file early return, and mergeIntoEnvFile()'s
 * existing-line-preservation and write-failure branches.
 */
function envTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    @chmod($dir, 0o755);
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        if (is_dir($path)) {
            envTestRrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/piwigo-env-test-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0o777, true);
});

afterEach(function (): void {
    envTestRrmdir(is_string($this->root) ? $this->root : '');
});

test('loadEnvFile is a safe no-op when the resolved env file does not exist', function (): void {
    // No .env.test file exists under this fresh, empty root -- loadEnvFile()
    // must return without ever constructing a Dotenv instance.
    Env::loadEnvFile(is_string($this->root) ? $this->root : '');

    expect(true)->toBeTrue(); // reaching here without error is the assertion
});

test('mergeIntoEnvFile replaces matching keys while preserving every other existing line untouched', function (): void {
    $envFile = $this->root . '/.env.merge-test';
    file_put_contents($envFile, "DB_HOST=localhost\nDB_NAME=piwigo\nUNRELATED_VAR=keepme\n");

    $result = Env::mergeIntoEnvFile($envFile, [
        'DB_NAME' => 'piwigo_new',
        'DB_USER' => 'admin',
    ]);

    expect($result)->toBeTrue()
        ->and(file_get_contents($envFile))->toBe(
            "DB_NAME=piwigo_new\nDB_USER=admin\nDB_HOST=localhost\nUNRELATED_VAR=keepme\n"
        );
});

test('mergeIntoEnvFile strips newlines and null bytes from values before writing, preventing env-file injection', function (): void {
    $envFile = $this->root . '/.env.injection-test';

    $result = Env::mergeIntoEnvFile($envFile, [
        'INJECTED' => "value\nEVIL_KEY=evil\r\0value",
    ]);

    expect($result)->toBeTrue()
        ->and(file_get_contents($envFile))->toBe("INJECTED=valueEVIL_KEY=evilvalue\n");
});

test('mergeIntoEnvFile returns false when the target directory cannot be written to', function (): void {
    $dir = $this->root . '/locked-merge-dir';
    mkdir($dir, 0o777, true);
    chmod($dir, 0o555);
    $envFile = $dir . '/.env.locked';

    set_error_handler(static fn (): bool => true);
    try {
        $result = Env::mergeIntoEnvFile($envFile, ['KEY' => 'value']);
    } finally {
        restore_error_handler();
        chmod($dir, 0o755);
    }

    expect($result)->toBeFalse()
        ->and(file_exists($envFile))->toBeFalse();
});
