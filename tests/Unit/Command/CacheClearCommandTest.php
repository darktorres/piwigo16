<?php

declare(strict_types=1);

use Piwigo\Command\CacheClearCommand;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;

// Latte cache dir path is injected here (CacheClearCommand's own
// $latteCacheDir constructor param, defaulting to the real hardcoded
// project path when omitted) -- these tests exercise the real
// removeDir() recursion, so they point it at an isolated sys_get_temp_dir()
// fixture instead of ever touching this project's own _data/ tree. The
// fixture still ends in .../templates_c/latte to satisfy
// looksLikeLatteCacheDir()'s own basename guard, same shape the real path
// has.
$latteDir = sys_get_temp_dir() . '/piwigo-cache-clear-test-' . bin2hex(random_bytes(8)) . '/templates_c/latte';

afterEach(function () use ($latteDir): void {
    if (is_dir($latteDir)) {
        exec('rm -rf ' . escapeshellarg(dirname($latteDir)));
    }
});

test('removes an existing Latte compiled-template cache dir', function () use ($latteDir): void {
    mkdir($latteDir, 0o775, true);
    file_put_contents($latteDir . '/some_compiled.php', '<?php // fixture');

    $command = new CacheClearCommand(new ArrayAdapter(), $latteDir);
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)
        ->toBe(0)
        ->and(is_dir($latteDir))
        ->toBeFalse()
        ->and($tester->getDisplay())
        ->toContain('Removed Latte compiled-template cache');
});

test('reports an already-empty Latte cache dir without erroring', function () use ($latteDir): void {
    $command = new CacheClearCommand(new ArrayAdapter(), $latteDir);
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)
        ->toBe(0)
        ->and($tester->getDisplay())
        ->toContain('already empty');
});

test('removes a Latte compiled-template cache dir containing a nested subdirectory', function () use ($latteDir): void {
    mkdir($latteDir . '/2026/07', 0o775, true);
    file_put_contents($latteDir . '/top-level.php', '<?php // fixture');
    file_put_contents($latteDir . '/2026/07/nested.php', '<?php // fixture');

    $command = new CacheClearCommand(new ArrayAdapter(), $latteDir);
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)
        ->toBe(0)
        ->and(is_dir($latteDir))
        ->toBeFalse()
        ->and($tester->getDisplay())
        ->toContain('Removed Latte compiled-template cache');
});

test('refuses to clear a resolved path that does not look like the Latte cache dir', function (): void {
    // Guards against exactly the failure mode that motivated this guard:
    // a corrupted path computation (bug, or a mutation testing run
    // actually executing mutated production code) resolving somewhere
    // real and much broader than the Latte cache -- confirms execute()
    // refuses to recurse-delete rather than silently doing it anyway.
    $wrongDir = sys_get_temp_dir() . '/piwigo-cache-clear-test-' . bin2hex(random_bytes(8)) . '/not-the-latte-dir';
    mkdir($wrongDir, 0o775, true);
    file_put_contents($wrongDir . '/innocent-bystander.txt', 'must survive');

    try {
        $command = new CacheClearCommand(new ArrayAdapter(), $wrongDir);
        $tester = new CommandTester($command);

        expect(fn (): int => $tester->execute([]))->toThrow(RuntimeException::class);
        expect(is_dir($wrongDir))
            ->toBeTrue();
        expect(file_exists($wrongDir . '/innocent-bystander.txt'))->toBeTrue();
    } finally {
        exec('rm -rf ' . escapeshellarg($wrongDir));
    }
});

test('the Latte cache dir guard requires both the "latte" name and the "templates_c" parent, not just one', function (): void {
    // A dir literally named "latte" but with an unrelated parent must
    // still be refused -- proves the guard is a real AND, not an OR that
    // would accept either signal alone.
    $wrongDir = sys_get_temp_dir() . '/piwigo-cache-clear-test-' . bin2hex(random_bytes(8)) . '/unrelated-parent/latte';
    mkdir($wrongDir, 0o775, true);

    try {
        $command = new CacheClearCommand(new ArrayAdapter(), $wrongDir);
        $tester = new CommandTester($command);

        expect(fn (): int => $tester->execute([]))->toThrow(RuntimeException::class);
        expect(is_dir($wrongDir))
            ->toBeTrue();
    } finally {
        exec('rm -rf ' . escapeshellarg(dirname($wrongDir)));
    }
});

test('defaultLatteCacheDir resolves to _data/templates_c/latte under the real project root', function (): void {
    $method = new ReflectionMethod(CacheClearCommand::class, 'defaultLatteCacheDir');

    expect($method->invoke(null))
        ->toBe(dirname(__DIR__, 3) . '/_data/templates_c/latte');
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
        expect(is_dir($unreadable))
            ->toBeTrue();
    } finally {
        chmod($unreadable, 0o775);
    }
});

test('clears the injected PSR-6 cache pool', function (): void {
    $pool = new ArrayAdapter();
    $item = $pool->getItem('some-key');
    $item->set('some-value');
    $pool->save($item);

    expect($pool->getItem('some-key')->isHit())
        ->toBeTrue();

    $command = new CacheClearCommand($pool);
    $tester = new CommandTester($command);
    $tester->execute([]);

    expect($pool->getItem('some-key')->isHit())
        ->toBeFalse()
        ->and($tester->getDisplay())
        ->toContain('Cleared the PSR-6 cache pool.');
});
