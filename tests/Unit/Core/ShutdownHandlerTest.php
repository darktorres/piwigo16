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

test('install() genuinely records that it ran, not leaving $installed false', function (): void {
    // Kills line 42's TrueToFalse (`self::$installed = false;` instead
    // of `= true;`): the public API surface (pcntl_signal_get_handler
    // still returning a Closure) can't distinguish this on its own,
    // since re-registering the identical closure a second time --
    // which is what a permanently-false $installed would cause on any
    // later install() call -- produces the exact same final handler.
    // Reflection on the private property is the only way to observe
    // that the flag itself is actually set, matching this codebase's
    // established convention for exactly this kind of internal-state
    // assertion (e.g. FilterStateTest.php's own reset() test).
    ShutdownHandler::install();

    $installed = new ReflectionProperty(ShutdownHandler::class, 'installed');

    expect($installed->getValue())->toBeTrue();
});

/**
 * Confirmed-equivalent: line 39's BooleanOrToBooleanAnd (`self::
 * $installed && ! function_exists('pcntl_signal')` instead of `||`).
 * ext-pcntl is a hard composer.json requirement for this project's own
 * deployment targets and is always loaded in this test environment, so
 * `! function_exists('pcntl_signal')` is always false here -- reducing
 * the real guard to just `self::$installed` and the mutant's guard to
 * always false (never short-circuits, regardless of $installed).
 * The only input where they'd disagree ($installed === true) still
 * produces an identical final observable state either way: re-running
 * install()'s own idempotent body (pcntl_async_signals(true) +
 * pcntl_signal() with the same closure) has no separate side effect
 * from running it once. Confirmed live: every test in this file
 * (including the new $installed-reflection one above) passes
 * identically with this mutation applied.
 */

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

/**
 * This test also kills line 44's TrueToFalse (`pcntl_async_signals
 * (false)`) and RemoveFunctionCall (dropping the call entirely):
 * confirmed live via a sed-applied mutation rerun, both genuinely make
 * this test fail (the subprocess never processes the SIGTERM
 * asynchronously during its own usleep(), so the marker file never
 * gets written and the exit code is never 143). `pest --mutate`'s own
 * scan still reports both as UNTESTED regardless -- the same
 * proc_open() subprocess-invisibility every subprocess-based test in
 * this suite runs into, not an unaddressed gap.
 */
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
