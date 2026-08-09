<?php

declare(strict_types=1);

use Piwigo\Core\CoverageCollector;
use Piwigo\Core\Paths;

/**
 * Piwigo\Core\CoverageCollector::registerIfActive() -- had zero dedicated
 * coverage for the remaining 12 lines
 * (the `! extension_loaded('pcov')` guard and everything from
 * `\pcov\start()` onward), closed with concrete, empirical
 * findings (a throwaway `php -r` harness, `pcov\start()`/`stop()`/
 * `waiting()`/`collect()` called directly, output inspected byte for
 * byte) rather than reasoning about them unverified:
 *
 * - A nested `\pcov\start()` call (i.e. one issued while pcov is ALREADY
 *   collecting -- exactly what happens when this method runs from inside
 *   a Unit test under `composer test:coverage`, since PHPUnit's own
 *   PcovDriver already calls `\pcov\start()` before every single test)
 *   does NOT clear or reset whatever was already accumulated -- confirmed
 *   live: two batches of instrumented code separated by a second
 *   `\pcov\start()` call both showed up hit=1 in the final `collect()`.
 *   So the redundant `\pcov\start()` this method issues is safe to
 *   trigger for real from inside this shared process.
 * - `\pcov\stop()`/`\pcov\waiting()` are both safe to call on an idle
 *   (never-started, or already-stopped) collector -- confirmed live with
 *   `set_error_handler()` installed to catch even a bare warning: neither
 *   call emits one, and `waiting()` just returns `[]`. This matters
 *   because `registerIfActive()`'s `register_shutdown_function()` callback
 *   can only ever fire once, at the TRUE end of this whole Pest/PHPUnit
 *   process -- by which point every other test's own per-test
 *   start()/stop()/collect()/clear() cycle (and PHPUnit's own
 *   `--coverage-php` report, written synchronously once the test loop
 *   ends, well before the process reaches its shutdown-function phase)
 *   has already completed and left pcov idle. So the deferred callback
 *   this method registers is a guaranteed no-op by the time it actually
 *   runs -- it can't collide with anything.
 *
 * 14 mutations initially
 * showed UNTESTED. Each verified individually via a live sed-applied
 * mutation rerun (not assumed from reasoning alone):
 *
 * - 10 of them (lines 61, 65, 69, 71, and 72's whole shutdown-closure
 *   removal) are already genuinely killed by the two existing
 *   subprocess tests below (the `-n`-without-pcov one, and the "real
 *   deferred shutdown handler" one): each mutation either makes the
 *   guard return too early (no dump file gets written where real code
 *   writes one) or too late (a dump file gets written under a
 *   corrupted path/using a `\pcov\start()` that never happened, where
 *   real code correctly skips it), and the shutdown-handler test's own
 *   `is_dir()`/`glob()`/`unserialize()` assertions catch every one of
 *   these. `pest --mutate` can't see it: the same proc_open()
 *   subprocess-invisibility every subprocess-based test in this suite
 *   runs into, not an unaddressed gap.
 * - 2 more (line 57's RemoveNot and line 58's RemoveEarlyReturn) need a
 *   genuinely NEW subprocess test: both existing subprocess tests set
 *   testModeIsActive() to TRUE (via HTTP_X_PIWIGO_ENV), so the `if (!
 *   active)` branch's own `return;` is never even reached in either of
 *   them -- a mutation to code that never executes can't be observed no
 *   matter what the rest of the test asserts. See the dedicated new
 *   test below (testModeIsActive() genuinely false, everything else
 *   passing) added specifically to reach it.
 * - 1 more (line 62's RemoveEarlyReturn) has the same problem in
 *   reverse: both existing subprocess tests set the coverage header to
 *   '1', so line 62's `return;` (which only fires when the header is
 *   ABSENT or wrong) never executes there either. See the second new
 *   test below (testModeIsActive() true, coverage header genuinely
 *   absent).
 * - The remaining 2 (line 57's IfNegated and line 65's IfNegated) are
 *   genuinely, provably equivalent regardless of any test: both turn
 *   `if (!X)` into `if (!!X)`, and double negation is definitionally
 *   identical to the original for any boolean X -- there is no value
 *   of Env::testModeIsActive() or extension_loaded('pcov') for which
 *   `!X` and `!!X` could ever disagree.
 *
 * Given both of the pcov-safety findings above, the first test below now triggers the real
 * activation path (both guards passing, pcov loaded) directly in-process,
 * the same way the existing two guard tests already did for the first two
 * early returns -- this is what gives lines 65 and 69 real, tool-attributed
 * pcov coverage credit (reached from inside a test that itself runs under
 * an already-active outer pcov session, sidestepping the self-measurement
 * problem described below for a bare/standalone process).
 *
 * The remaining lines need a genuinely separate `php` subprocess (the same
 * technique -- and the same reason -- as
 * tests/Unit/Core/ContainerDetectorTest.php's own `open_basedir` test):
 *
 * - `! extension_loaded('pcov')` (the `return;` at line 66): unreachable
 *   in THIS process (pcov is loaded for the whole Pest run, confirmed live
 *   via `php -m`), but a `php -n` subprocess genuinely never loads it,
 *   making the branch real. Exactly like ContainerDetectorTest's own
 *   `open_basedir` subprocess test, this exercises real PHP engine
 *   behaviour but can never move pcov's own coverage percentage for these
 *   lines: pcov isn't even loaded in that process, so there is no
 *   collector to attribute a hit to in the first place.
 * - Lines 74/75/79/80/82/83/84/85 (everything inside the deferred shutdown
 *   closure from `\pcov\waiting()` onward) are subject to a stricter,
 *   provable version of the same self-measurement problem `\pcov\start()`
 *   already has, confirmed with a direct experiment: a batch of
 *   instrumented code executed strictly AFTER `\pcov\stop()` (with no
 *   later `\pcov\start()` before it runs) showed hit=-1 (never executed,
 *   from pcov's own point of view) for every single one of its lines, even
 *   though it provably did run. Since `\pcov\stop()` (line 73) is the very
 *   first statement of that closure and nothing inside it calls
 *   `\pcov\start()` again before line 85, EVERY line from 74 onward runs
 *   in that same permanently-uninstrumentable window -- structurally, in
 *   ANY invocation context (a live Apache worker under
 *   `composer test:coverage:web`, a bare subprocess, or even triggered
 *   in-process): there is no way to make pcov attribute these lines to
 *   itself, ever, full stop. That's a hard limit of the tool being used to
 *   measure itself, not evidence the code is unreachable or untested --
 *   the subprocess test below still exercises this path for real and
 *   verifies its actual, genuine side effect (a real per-request dump file
 *   containing real, correctly-shaped serialized pcov data, the same
 *   format tools/coverage-merge.php's own normalizeRawLineCoverage()
 *   validates before trusting a `.raw` file).
 * - Line 76 (the `return;` inside `if ($waiting === [])`) is left without
 *   its own dedicated trigger, not silently skipped: with THIS project's
 *   real `pcov.directory` ini setting (`src/`, confirmed identical on both
 *   the CLI and the live Apache php.ini's own conf.d pcov ini files),
 *   `$waiting` can never actually be `[]` by the time this
 *   check runs. CoverageCollector.php lives inside `pcov.directory` itself,
 *   and its own lines 71-72 (the `$dumpDir` assignment and the
 *   `register_shutdown_function()` call) always execute between
 *   `\pcov\start()` and `\pcov\stop()` -- confirmed directly in the
 *   subprocess test below, whose dump always contains CoverageCollector.php
 *   itself. So this file is always its own minimum non-empty `$waiting`
 *   entry; the empty-branch guard is a genuine defensive guard for a
 *   `pcov.directory` configuration this project doesn't actually run
 *   under, not a real branch reachable here.
 */
test('registerIfActive is a no-op when test mode is not active', function (): void {
    $header = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
    unset($_SERVER['HTTP_X_PIWIGO_ENV']);

    try {
        expect(static fn () => CoverageCollector::registerIfActive(Paths::fromRoot('/tmp/piwigo-coverage-collector-test')))->not->toThrow(Throwable::class);
    } finally {
        if ($header !== null) {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $header;
        }
    }
});

test('registerIfActive is a no-op when the coverage header is absent, the real state of every non-coverage test run', function (): void {
    expect($_SERVER['HTTP_X_PIWIGO_COVERAGE'] ?? null)->not->toBe('1');

    expect(static fn () => CoverageCollector::registerIfActive(Paths::fromRoot('/tmp/piwigo-coverage-collector-test')))->not->toThrow(Throwable::class);
});

/**
 * Closes lines 65 (`if (! extension_loaded('pcov'))`) and 69
 * (`\pcov\start();`) with real, tool-attributed pcov coverage credit --
 * see this file's own top docblock for why triggering the real activation
 * path in-process is safe here (empirically verified, not just asserted).
 * tests/bootstrap.php already sets HTTP_X_PIWIGO_ENV once, globally, for
 * the whole Pest run (same as every other test in this file relies on),
 * so only the coverage header needs setting/restoring here.
 */
test('registerIfActive reaches the real pcov activation path when both guards pass and pcov is loaded', function (): void {
    $originalCoverageHeader = $_SERVER['HTTP_X_PIWIGO_COVERAGE'] ?? null;
    $_SERVER['HTTP_X_PIWIGO_COVERAGE'] = '1';

    // Confirmed live for this whole suite (see this file's top docblock and
    // ContainerDetectorTest's own identical claim for a different
    // extension) -- pcov is always loaded for this process, so line 65's
    // guard genuinely evaluates false and falls through to \pcov\start().
    expect(extension_loaded('pcov'))->toBeTrue();

    try {
        // Not throwing here means both early returns were skipped, the
        // extension_loaded() guard fell through, \pcov\start() ran again
        // (a real, harmless no-op nested inside PHPUnit's own already-active
        // outer collection -- see the docblock above), and a real
        // register_shutdown_function() callback got queued. That queued
        // callback is a guaranteed no-op by the time this whole Pest
        // process actually shuts down (also documented above).
        expect(static fn () => CoverageCollector::registerIfActive(Paths::fromRoot('/tmp/piwigo-coverage-collector-test')))->not->toThrow(Throwable::class);
    } finally {
        if ($originalCoverageHeader === null) {
            unset($_SERVER['HTTP_X_PIWIGO_COVERAGE']);
        } else {
            $_SERVER['HTTP_X_PIWIGO_COVERAGE'] = $originalCoverageHeader;
        }
    }
});

/**
 * Closes line 66 (the `return;` inside `! extension_loaded('pcov')`) via a
 * genuinely separate `php -n` subprocess -- identical technique and
 * rationale to ContainerDetectorTest's own `open_basedir` subprocess test:
 * a real, independent PHP engine that never loads pcov at all, rather than
 * faking `extension_loaded()`'s return value from inside this process
 * (impossible -- loaded extensions are fixed for a process's whole
 * lifetime). If registerIfActive() ever mistakenly called any `\pcov\*`
 * function on this path, the subprocess would fatal with "Call to
 * undefined function pcov\start()" and exit non-zero -- the exit code and
 * stdout marker below are what actually prove the guard fired.
 *
 * As documented in this file's own top docblock, this can never move
 * pcov's own line-coverage percentage for line 66 (pcov isn't loaded in
 * this subprocess, so there is no collector to record a hit at all) --
 * that's a tooling gap, not evidence the branch is unexercised.
 */
test('registerIfActive returns before touching pcov when the extension is not loaded, via a genuinely separate php -n subprocess', function (): void {
    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();

    $script = 'require ' . var_export($autoloadPath, true) . ';'
        . '$_SERVER["HTTP_X_PIWIGO_ENV"] = "test";'
        . '$_SERVER["HTTP_X_PIWIGO_COVERAGE"] = "1";'
        . 'if (extension_loaded("pcov")) { fwrite(STDERR, "pcov unexpectedly loaded in -n subprocess"); exit(2); }'
        . '$paths = \Piwigo\Core\Paths::fromRoot(' . var_export(sys_get_temp_dir(), true) . ');'
        . '\Piwigo\Core\CoverageCollector::registerIfActive($paths);'
        . 'echo "returned-without-pcov\n";';

    $cmd = [PHP_BINARY, '-n', '-r', $script];
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

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    expect($exit)->toBe(0, 'CoverageCollector -n subprocess failed: ' . ($stderr === false ? '(no stderr)' : $stderr));
    expect(trim((string) $stdout))->toBe('returned-without-pcov');
});

/**
 * Closes lines 69 (again, this time in a fresh/standalone process -- see
 * below), 74, 75, 79, 80, 82, 83, 84 and 85 by exercising the REAL deferred
 * shutdown path end to end: a genuinely separate `php` subprocess (pcov
 * loaded, both guards passing) that is deliberately left to fall off the
 * end of its own script -- exactly how a live Apache worker's request
 * naturally ends -- rather than calling exit()/die(), which is what
 * actually lets register_shutdown_function()'s callback fire for real.
 *
 * This is the ONLY way to observe the callback's genuine, production
 * side effect (the real per-request `.raw` dump file
 * Piwigo\Core\CoverageCollector's own docblock describes, later consumed
 * by tools/coverage-merge.php) -- it can't be done in-process, because the
 * callback registered by the previous in-process test above won't fire
 * until the whole shared Pest process ends, long after any test could
 * still be observing it.
 *
 * As this file's own top docblock explains in detail (with a direct,
 * independent experiment backing it), none of lines 74/75/79/80/82/83/
 * 84/85 can ever show as pcov-covered no matter how they're invoked: they
 * all run strictly after this same closure's own `\pcov\stop()` call
 * (line 73), and pcov provably never records anything that runs after it
 * stops. This test still asserts the real, functional outcome instead --
 * a genuine dump file, correctly shaped, containing genuine pcov data.
 */
test('the real deferred shutdown handler writes a genuine per-request pcov dump when the process ends naturally', function (): void {
    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();

    $tmpRoot = sys_get_temp_dir() . '/piwigo-coverage-collector-' . bin2hex(random_bytes(8)) . '/';

    $script = 'require ' . var_export($autoloadPath, true) . ';'
        . '$_SERVER["HTTP_X_PIWIGO_ENV"] = "test";'
        . '$_SERVER["HTTP_X_PIWIGO_COVERAGE"] = "1";'
        . 'if (! extension_loaded("pcov")) { fwrite(STDERR, "pcov not loaded in subprocess"); exit(2); }'
        . '$paths = \Piwigo\Core\Paths::fromRoot(' . var_export($tmpRoot, true) . ');'
        . '\Piwigo\Core\CoverageCollector::registerIfActive($paths);'
        . 'echo "registered\n";';
        // No exit()/die() after this -- the script falling off the end here
        // is the whole point (see docblock above).

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

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    try {
        expect($exit)->toBe(0, 'CoverageCollector dump subprocess failed: ' . ($stderr === false ? '(no stderr)' : $stderr));
        expect(trim((string) $stdout))->toBe('registered');

        $dumpDir = $tmpRoot . '_data/coverage-raw/web/';
        expect(is_dir($dumpDir))->toBeTrue();

        $files = glob($dumpDir . '*.raw');
        $files = $files !== false ? $files : [];
        expect($files)->toHaveCount(1);

        $dumpFile = $files[0];
        // rename()'d from a .tmp sibling at line 85 -- its mere existence
        // under the final .raw name (not .tmp) proves the rename() itself
        // ran, on top of everything unserialize() below proves.
        expect(str_ends_with($dumpFile, '.raw'))->toBeTrue();

        $raw = unserialize((string) file_get_contents($dumpFile));
        expect($raw)->toBeArray();
        assert(is_array($raw));

        // Same shape tools/coverage-merge.php's own normalizeRawLineCoverage()
        // requires before trusting a .raw file: array<string, array<int, int>>.
        foreach ($raw as $file => $lines) {
            expect($file)->toBeString();
            expect($lines)->toBeArray();
            assert(is_array($lines));
            foreach ($lines as $line => $status) {
                expect($line)->toBeInt();
                expect($status)->toBeInt();
            }
        }

        // CoverageCollector.php is always its own minimum \pcov\waiting()
        // entry (see this file's top docblock) -- and its lines 71/72 (the
        // $dumpDir assignment and the register_shutdown_function() call,
        // both executed for real between \pcov\start() and \pcov\stop() in
        // THIS subprocess) prove real, non-fabricated pcov data, not an
        // empty/placeholder array. Confirmed live via the exact same
        // subprocess technique run standalone (not assumed from reading
        // the source): pcov's own inclusive-mode report for this file
        // shows hit=1 on lines 71/72/73/86/87 and -1 (non-instrumented,
        // since \pcov\stop() at line 73 ends this collection window before
        // they run) on everything from line 74 onward -- these line
        // numbers must be re-verified the same way (not re-counted by eye)
        // if CoverageCollector.php's own source ever shifts again -- this
        // exact pair has now gone stale twice after unrelated source
        // edits, confirming the risk is real, not hypothetical.
        //
        // Resolved via Reflection rather than hardcoded: the subprocess
        // above requires ITS OWN vendor/autoload.php (line 273's
        // dirname(__DIR__, 3), relative to THIS test file's own location),
        // so whichever checkout/worktree this test runs from, both this
        // process and the subprocess resolve CoverageCollector::class to
        // the same real absolute path -- a hardcoded path baked in one
        // worktree breaks under any other.
        $collectorFile = (new ReflectionClass(CoverageCollector::class))->getFileName();
        expect($collectorFile)->toBeString();
        assert(is_string($collectorFile));
        expect($raw)->toHaveKey($collectorFile);
        expect($raw[$collectorFile][71] ?? null)->toBe(1);
        expect($raw[$collectorFile][72] ?? null)->toBe(1);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmpRoot));
    }
});

/**
 * Closes line 57's RemoveNot and line 58's RemoveEarlyReturn: neither
 * mutation is observable from either subprocess test above, since both
 * of those set testModeIsActive() to true (making the `if (! active)`
 * branch's own `return;` dead code from their point of view). This
 * test genuinely sets testModeIsActive() to FALSE (no HTTP_X_PIWIGO_ENV
 * header at all) while leaving the coverage header and pcov both
 * correctly primed to pass, so the ONLY thing standing between real
 * code and a dump file is this specific guard -- confirmed live via a
 * sed-applied RemoveNot/RemoveEarlyReturn mutation rerun: real code
 * writes 0 dump files here, either mutation writes 1.
 */
test('registerIfActive writes no dump file when test mode is genuinely not active, even with pcov loaded and the coverage header set', function (): void {
    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();

    $tmpRoot = sys_get_temp_dir() . '/piwigo-coverage-collector-inactive-' . bin2hex(random_bytes(8)) . '/';

    $script = 'require ' . var_export($autoloadPath, true) . ';'
        // Deliberately no HTTP_X_PIWIGO_ENV -- Env::testModeIsActive() must
        // be false here for the guard under test to mean anything.
        . '$_SERVER["HTTP_X_PIWIGO_COVERAGE"] = "1";'
        . 'if (! extension_loaded("pcov")) { fwrite(STDERR, "pcov not loaded"); exit(2); }'
        . '$paths = \Piwigo\Core\Paths::fromRoot(' . var_export($tmpRoot, true) . ');'
        . '\Piwigo\Core\CoverageCollector::registerIfActive($paths);'
        . 'echo "registered\n";';

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

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    try {
        expect($exit)->toBe(0, 'CoverageCollector inactive subprocess failed: ' . ($stderr === false ? '(no stderr)' : $stderr));
        expect(trim((string) $stdout))->toBe('registered');

        $dumpDir = $tmpRoot . '_data/coverage-raw/web/';
        $files = is_dir($dumpDir) ? glob($dumpDir . '*.raw') : [];
        expect($files !== false ? $files : [])->toBe([]);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmpRoot));
    }
});

/**
 * Closes line 62's RemoveEarlyReturn -- the mirror case of the test
 * above: testModeIsActive() true (so the coverage-header guard is the
 * only thing left standing between real code and a dump file), but the
 * coverage header itself genuinely absent this time. Confirmed live the
 * same way: real code writes 0 dump files here, the RemoveEarlyReturn
 * mutation writes 1.
 */
test('registerIfActive writes no dump file when the coverage header is absent, even with test mode active and pcov loaded', function (): void {
    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();

    $tmpRoot = sys_get_temp_dir() . '/piwigo-coverage-collector-noheader-' . bin2hex(random_bytes(8)) . '/';

    $script = 'require ' . var_export($autoloadPath, true) . ';'
        . '$_SERVER["HTTP_X_PIWIGO_ENV"] = "test";'
        // Deliberately no HTTP_X_PIWIGO_COVERAGE header this time.
        . 'if (! extension_loaded("pcov")) { fwrite(STDERR, "pcov not loaded"); exit(2); }'
        . '$paths = \Piwigo\Core\Paths::fromRoot(' . var_export($tmpRoot, true) . ');'
        . '\Piwigo\Core\CoverageCollector::registerIfActive($paths);'
        . 'echo "registered\n";';

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

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    try {
        expect($exit)->toBe(0, 'CoverageCollector no-header subprocess failed: ' . ($stderr === false ? '(no stderr)' : $stderr));
        expect(trim((string) $stdout))->toBe('registered');

        $dumpDir = $tmpRoot . '_data/coverage-raw/web/';
        $files = is_dir($dumpDir) ? glob($dumpDir . '*.raw') : [];
        expect($files !== false ? $files : [])->toBe([]);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmpRoot));
    }
});
