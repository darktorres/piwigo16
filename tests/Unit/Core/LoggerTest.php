<?php

declare(strict_types=1);

use Piwigo\Core\Logger;
use Piwigo\Core\PageState;

/**
 * No prior LoggerTest.php existed -- everything below targets the
 * specific still-red lines from the coverage report (this class's
 * indirect callers, e.g. LoungeMaintenance's own purge() usage, already
 * cover the rest of write()/purge()/log()'s happy paths and debug()/
 * info()/error()/levelToCode()'s EMERGENCY/INFO/DEBUG/ERROR/default arms
 * and codeToLevel()'s DEBUG/default arms).
 *
 * Three scenarios below need a real PHP-level warning to be raised as
 * part of exercising a branch the class itself already handles
 * gracefully (fopen()/glob()/fwrite() failures) -- a bare `@` does NOT
 * stop PHPUnit's ErrorHandler from converting it into a thrown Warning
 * (confirmed live), so each wraps only its one risky call with a no-op
 * set_error_handler()/restore_error_handler() pair, matching the
 * established pattern in tests/Unit/Admin/AdminUiHelperTest.php's own
 * getExtents() test.
 */
function loggerTestRrmdir(string $dir): void
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
            loggerTestRrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/piwigo-logger-test-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0o777, true);
    PageState::reset();
});

afterEach(function (): void {
    loggerTestRrmdir(is_string($this->root) ? $this->root : '');
    PageState::reset();
});

test('constructs a default log_YYYY-MM-DD.txt filename when none is given, creating the directory on first write', function (): void {
    $dir = $this->root . '/fresh-dir';

    $logger = new Logger(['directory' => $dir]);
    $logger->write('hello there');

    $expectedFile = $dir . '/log_' . date('Y-m-d') . '.txt';

    expect(is_dir($dir))->toBeTrue()
        ->and(file_exists($expectedFile))->toBeTrue()
        ->and(file_get_contents($expectedFile))->toBe('hello there');
});

test('throws writefail when the target file already exists but has lost its write permission', function (): void {
    $dir = $this->root . '/existing-readonly';
    mkdir($dir, 0o777, true);
    $filename = 'readonly.txt';
    file_put_contents($dir . '/' . $filename, 'old content');
    chmod($dir . '/' . $filename, 0o444);

    $logger = new Logger(['directory' => $dir, 'filename' => $filename]);

    expect(fn () => $logger->write('new content'))
        ->toThrow(\RuntimeException::class, 'The file could not be written to. Check that appropriate permissions have been set.');
});

test('throws openfail when fopen cannot create the target file inside a locked directory', function (): void {
    $dir = $this->root . '/locked-dir';
    mkdir($dir, 0o777, true);
    chmod($dir, 0o555);

    $logger = new Logger(['directory' => $dir, 'filename' => 'unwritable.txt']);

    set_error_handler(static fn (): bool => true);
    try {
        expect(fn () => $logger->write('anything'))
            ->toThrow(\RuntimeException::class, 'The file could not be opened. Check permissions.');
    } finally {
        restore_error_handler();
        chmod($dir, 0o755);
    }
});

test('closes the underlying file handle on destruction after a successful write', function (): void {
    $dir = $this->root . '/destruct-dir';
    $logger = new Logger(['directory' => $dir, 'filename' => 'destruct.txt']);
    $logger->write('line');

    $prop = new ReflectionProperty(Logger::class, '_fileHandle');
    $handle = $prop->getValue($logger);
    expect(is_resource($handle))->toBeTrue();

    unset($logger);

    expect(is_resource($handle))->toBeFalse();
});

test('notice/warn/alert/critical/emergency each write a line tagged with their own level code and message', function (): void {
    $dir = $this->root . '/levels-dir';
    $logger = new Logger(['directory' => $dir, 'filename' => 'levels.txt']);

    $logger->notice('storage nearing capacity', 'storage');
    $logger->warn('slow query detected', 'db');
    $logger->alert('cache backend unreachable', 'cache');
    $logger->critical('payment webhook failed', 'billing');
    $logger->emergency('disk write failures across all mounts', 'disk');

    $contents = file_get_contents($dir . '/levels.txt');

    expect($contents)
        ->toContain("[NOTICE]\t[storage]\tstorage nearing capacity")
        ->toContain("[WARNING]\t[db]\tslow query detected")
        ->toContain("[ALERT]\t[cache]\tcache backend unreachable")
        ->toContain("[CRITICAL]\t[billing]\tpayment webhook failed")
        ->toContain("[EMERGENCY]\t[disk]\tdisk write failures across all mounts");
});

test('throws writefail when fwrite itself fails on an already-open handle (e.g. a full device)', function (): void {
    // /dev/full is a standard Linux character device that accepts opens
    // but fails every write with ENOSPC -- a real, portable way to make
    // fwrite() legitimately return false without touching filesystem
    // permissions (which open()'s own is_writable() check would catch
    // earlier instead).
    $logger = new Logger(['directory' => '/dev', 'filename' => 'full']);

    set_error_handler(static fn (): bool => true);
    try {
        expect(fn () => $logger->write('this will not fit'))
            ->toThrow(\RuntimeException::class, 'The file could not be written to. Check that appropriate permissions have been set.');
    } finally {
        restore_error_handler();
    }
});

test('purge returns without touching anything when glob() itself fails (e.g. an over-long directory path)', function (): void {
    // A glob() pattern beyond PATH_MAX (4096 bytes on Linux) makes glob()
    // return false rather than an empty array -- a real, deterministic
    // failure mode distinct from "no files matched". The constructor
    // itself has a 1-in-97 random chance of also calling purge() (see its
    // own archiveDays handling), so both the construction and the
    // explicit call below are wrapped -- otherwise this test would be
    // flaky, rarely tripping the same warning before the handler is
    // installed.
    $dir = $this->root . '/' . str_repeat('a', 4200);

    set_error_handler(static fn (): bool => true);
    try {
        $logger = new Logger(['directory' => $dir, 'filename' => 'irrelevant.txt', 'archiveDays' => 1]);
        $logger->purge();
    } finally {
        restore_error_handler();
    }

    expect(true)->toBeTrue(); // reaching here without a thrown error is the assertion
});

test('purge deletes only files older than the archive window, matching the configured glob pattern', function (): void {
    $dir = $this->root . '/purge-dir';
    mkdir($dir, 0o777, true);

    $oldFile = $dir . '/log_old.txt';
    $recentFile = $dir . '/log_recent.txt';
    $otherExtension = $dir . '/keepme.dat';
    file_put_contents($oldFile, 'old');
    file_put_contents($recentFile, 'recent');
    file_put_contents($otherExtension, 'not a log file');

    touch($oldFile, time() - (10 * 86400));
    touch($recentFile, time() - 60);
    touch($otherExtension, time() - (10 * 86400));

    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'unused.txt',
        'globPattern' => 'log_*.txt',
        'archiveDays' => 5,
    ]);
    $logger->purge();

    expect(file_exists($oldFile))->toBeFalse()
        ->and(file_exists($recentFile))->toBeTrue()
        ->and(file_exists($otherExtension))->toBeTrue();
});

test('codeToLevel converts every known severity code, case-insensitively, to its matching level constant', function (): void {
    expect(Logger::codeToLevel('emergency'))->toBe(Logger::EMERGENCY)
        ->and(Logger::codeToLevel('ALERT'))->toBe(Logger::ALERT)
        ->and(Logger::codeToLevel('Critical'))->toBe(Logger::CRITICAL)
        ->and(Logger::codeToLevel('NOTICE'))->toBe(Logger::NOTICE)
        ->and(Logger::codeToLevel('info'))->toBe(Logger::INFO)
        ->and(Logger::codeToLevel('Warning'))->toBe(Logger::WARNING)
        ->and(Logger::codeToLevel('debug'))->toBe(Logger::DEBUG)
        ->and(Logger::codeToLevel('ERROR'))->toBe(Logger::ERROR);
});

test('codeToLevel throws for an unrecognized severity code', function (): void {
    expect(fn () => Logger::codeToLevel('NOT_A_LEVEL'))
        ->toThrow(\RuntimeException::class, 'Unknown severity code NOT_A_LEVEL');
});

test('levelToCode converts every level constant to its own string name', function (): void {
    expect(Logger::levelToCode(Logger::EMERGENCY))->toBe('EMERGENCY')
        ->and(Logger::levelToCode(Logger::ALERT))->toBe('ALERT')
        ->and(Logger::levelToCode(Logger::CRITICAL))->toBe('CRITICAL')
        ->and(Logger::levelToCode(Logger::NOTICE))->toBe('NOTICE')
        ->and(Logger::levelToCode(Logger::INFO))->toBe('INFO')
        ->and(Logger::levelToCode(Logger::WARNING))->toBe('WARNING')
        ->and(Logger::levelToCode(Logger::DEBUG))->toBe('DEBUG')
        ->and(Logger::levelToCode(Logger::ERROR))->toBe('ERROR');
});
