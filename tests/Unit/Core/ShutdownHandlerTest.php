<?php

declare(strict_types=1);

use Piwigo\Core\ShutdownHandler;

// install() wires a real SIGTERM handler -- a P10-style global-state hazard
// (same lesson as that phase's Sentry default_integrations handler
// leakage), so afterEach restores SIGTERM to SIG_DFL to keep this from
// bleeding into unrelated tests in the same worker process.
//
// Actual signal delivery to THIS process is deliberately not exercised
// here -- the real handler calls exit(143), which would terminate the test
// worker itself. runAll() is invoked directly via reflection instead for
// most tests, verifying the callback-running mechanics without needing a
// real OS signal. The one exception is the subprocess test further down:
// a genuinely separate `php -r` child process is signalled for real,
// closing the coverage gap on the handler closure's own body (runAll();
// exit(143);) without risking this shared worker.

beforeEach(function (): void {
    ShutdownHandler::reset();
});

afterEach(function (): void {
    ShutdownHandler::reset();
    pcntl_signal(SIGTERM, SIG_DFL);
});

test('install() wires a real SIGTERM handler', function (): void {
    expect(pcntl_signal_get_handler(SIGTERM))->toBe(SIG_DFL);

    ShutdownHandler::install();

    expect(pcntl_signal_get_handler(SIGTERM))->toBeInstanceOf(Closure::class);
});

test('install() is idempotent', function (): void {
    ShutdownHandler::install();
    ShutdownHandler::install();

    expect(pcntl_signal_get_handler(SIGTERM))->toBeInstanceOf(Closure::class);
});

test('registered callbacks run when the signal handler fires', function (): void {
    $ran = [];
    ShutdownHandler::register(function () use (&$ran): void {
        $ran[] = 'first';
    });
    ShutdownHandler::register(function () use (&$ran): void {
        $ran[] = 'second';
    });

    $runAll = new ReflectionMethod(ShutdownHandler::class, 'runAll');
    $runAll->invoke(null);

    expect($ran)->toBe(['first', 'second']);
});

test('reset() clears registered callbacks', function (): void {
    $ran = false;
    ShutdownHandler::register(function () use (&$ran): void {
        $ran = true;
    });

    ShutdownHandler::reset();

    $runAll = new ReflectionMethod(ShutdownHandler::class, 'runAll');
    $runAll->invoke(null);

    expect($ran)->toBeFalse();
});

test('a real SIGTERM signal delivered to a subprocess runs its registered callback, then exits 143', function (): void {
    // The real signal handler's own closure body (runAll(); exit(143);)
    // is unreachable from this shared PHPUnit/Pest worker for exactly the
    // reason this file's own top docblock gives -- exit() would kill the
    // worker. A genuinely separate PHP subprocess sidesteps that: it
    // installs the real handler, a real OS SIGTERM is delivered to it, and
    // both real effects (the callback ran, marked by a sentinel file it
    // writes; the process exited with the conventional 128+SIGTERM code)
    // are observed from the outside, without risking this test's own
    // process.
    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();
    $marker = sys_get_temp_dir() . '/piwigo-shutdownhandler-sigterm-' . bin2hex(random_bytes(8)) . '.marker';

    $script = 'require ' . var_export($autoloadPath, true) . ';'
        . '\Piwigo\Core\ShutdownHandler::register(function () { file_put_contents(' . var_export($marker, true) . ', "ran"); });'
        . '\Piwigo\Core\ShutdownHandler::install();'
        . 'usleep(3000000);';

    $cmd = [PHP_BINARY, '-r', $script];
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    expect($proc)->toBeResource();
    if ($proc === false) {
        throw new RuntimeException('proc_open failed');
    }

    $status = proc_get_status($proc);
    $pid = $status['pid'];

    // Give the child a moment to require the autoloader and install its
    // own real pcntl_signal() handler before signalling it.
    usleep(400000);
    $sent = posix_kill($pid, SIGTERM);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    try {
        expect($sent)->toBeTrue()
            ->and($exit)->toBe(143, 'ShutdownHandler subprocess exited unexpectedly: stdout=' . $stdout . ' stderr=' . $stderr)
            ->and(file_exists($marker))->toBeTrue()
            ->and(file_get_contents($marker))->toBe('ran');
    } finally {
        @unlink($marker);
    }
})->skip(! extension_loaded('posix'), 'requires ext-posix to signal the subprocess');
