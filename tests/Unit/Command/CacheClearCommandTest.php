<?php

declare(strict_types=1);

use Piwigo\Command\CacheClearCommand;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;

// Latte cache dir path is hardcoded relative to the source file (same
// convention as CacheFactory's own _data/cache/ path), so this exercises
// the real project _data/templates_c/latte/ dir rather than an injected
// fake -- matching CacheFactoryTest's own precedent of touching the real
// relative _data/ tree.
$latteDir = dirname(__DIR__, 3) . '/_data/templates_c/latte';

afterEach(function () use ($latteDir): void {
    if (is_dir($latteDir)) {
        exec('rm -rf ' . escapeshellarg($latteDir));
    }
});

test('removes an existing Latte compiled-template cache dir', function () use ($latteDir): void {
    mkdir($latteDir, 0o775, true);
    file_put_contents($latteDir . '/some_compiled.php', '<?php // fixture');

    $command = new CacheClearCommand(new ArrayAdapter());
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(0)
        ->and(is_dir($latteDir))->toBeFalse()
        ->and($tester->getDisplay())->toContain('Removed Latte compiled-template cache');
});

test('reports an already-empty Latte cache dir without erroring', function () use ($latteDir): void {
    if (is_dir($latteDir)) {
        exec('rm -rf ' . escapeshellarg($latteDir));
    }

    $command = new CacheClearCommand(new ArrayAdapter());
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(0)
        ->and($tester->getDisplay())->toContain('already empty');
});

test('removes a Latte compiled-template cache dir containing a nested subdirectory', function () use ($latteDir): void {
    mkdir($latteDir . '/2026/07', 0o775, true);
    file_put_contents($latteDir . '/top-level.php', '<?php // fixture');
    file_put_contents($latteDir . '/2026/07/nested.php', '<?php // fixture');

    $command = new CacheClearCommand(new ArrayAdapter());
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(0)
        ->and(is_dir($latteDir))->toBeFalse()
        ->and($tester->getDisplay())->toContain('Removed Latte compiled-template cache');
});

test('removeDir returns without removing anything when a subdirectory cannot be listed (permission denied)', function () use ($latteDir): void {
    $unreadable = $latteDir . '/unreadable-sub';
    mkdir($unreadable, 0o775, true);
    file_put_contents($unreadable . '/hidden.txt', 'x');
    chmod($unreadable, 0o000);

    try {
        $command = new CacheClearCommand(new ArrayAdapter());
        $method = new ReflectionMethod($command, 'removeDir');

        // scandir() on a permission-denied directory emits a real PHP
        // warning (confirmed live: "Failed to open directory: Permission
        // denied") that this suite's failOnWarning="true" would otherwise
        // convert into a failure -- a plain @ does not stop PHPUnit's own
        // ErrorHandler, so a real no-op handler for the duration of this
        // one expected-to-warn call is the reliable way to swallow it.
        set_error_handler(static fn (): bool => true);
        try {
            $method->invoke($command, $unreadable);
        } finally {
            restore_error_handler();
        }

        // scandir() failing means the early `return;` fires before the
        // loop or the trailing rmdir($dir) ever run -- the directory
        // itself is left completely untouched (its content can't be
        // stat()ed at all from here anymore -- 0o000 blocks traversal
        // even for the owner, which is exactly why scandir() failed
        // above in the first place).
        expect(is_dir($unreadable))->toBeTrue();
    } finally {
        chmod($unreadable, 0o775);
    }
});

test('clears the injected PSR-6 cache pool', function (): void {
    $pool = new ArrayAdapter();
    $item = $pool->getItem('some-key');
    $item->set('some-value');
    $pool->save($item);

    expect($pool->getItem('some-key')->isHit())->toBeTrue();

    $command = new CacheClearCommand($pool);
    $tester = new CommandTester($command);
    $tester->execute([]);

    expect($pool->getItem('some-key')->isHit())->toBeFalse();
});
