<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ErrorCollector;

/**
 * Each test constructs its own fresh instance directly -- no reset()/
 * global beforeEach-afterEach is needed for the instance API.
 *
 * Deliberately never calls a real instance's install() -- it registers a
 * real set_error_handler()/register_shutdown_function() pair, and PHP has
 * no way to unregister a shutdown function once added, which would leak
 * into every later test in this shared PHPUnit/Pest process. drain()'s own
 * return-then-clear contract is exercised directly via reflection instead,
 * matching how this class's other state has no install()-dependent test
 * either. handleError()/flush() are the only two methods with instance
 * state to reflect into -- label()/writeTestErrorsLog() are pure functions
 * of their own parameters and are `private static`, so their own
 * Reflection calls below use `invoke(null, ...)`.
 *
 * @param list<string> $entries
 */
function seedCollected(ErrorCollector $errorCollector, array $entries): void
{
    $prop = new ReflectionProperty(ErrorCollector::class, 'collected');
    $prop->setValue($errorCollector, $entries);
}

test('reset actually clears isActive back to false, not leaving it true', function (): void {
    // install() is deliberately never called in this file (see the top
    // docblock), so isActive is set directly via reflection instead.
    $errorCollector = new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir()));
    $prop = new ReflectionProperty(ErrorCollector::class, 'active');
    $prop->setValue($errorCollector, true);
    expect($errorCollector->isActive())->toBeTrue();

    $errorCollector->reset();

    expect($errorCollector->isActive())->toBeFalse();
});

test('drain returns an empty array when nothing was collected', function (): void {
    expect(new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir()))->drain())->toBe([]);
});

test('drain returns exactly what was collected', function (): void {
    $errorCollector = new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir()));
    seedCollected($errorCollector, ['[WARNING] foo in bar.php:1', '[NOTICE] baz in qux.php:2']);

    expect($errorCollector->drain())->toBe(['[WARNING] foo in bar.php:1', '[NOTICE] baz in qux.php:2']);
});

test('drain clears the buffer, unlike collected()', function (): void {
    $errorCollector = new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir()));
    seedCollected($errorCollector, ['[WARNING] foo in bar.php:1']);

    $errorCollector->drain();

    expect($errorCollector->collected())->toBe([]);
});

test('a second drain after a first returns empty', function (): void {
    $errorCollector = new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir()));
    seedCollected($errorCollector, ['[WARNING] foo in bar.php:1']);

    $errorCollector->drain();

    expect($errorCollector->drain())->toBe([]);
});

/**
 * writeTestErrorsLog()/flush()/label() are all private (reached in
 * production only via the set_error_handler()/register_shutdown_function()
 * pair install() registers, deliberately never exercised here -- see this
 * file's own top docblock) -- invoked directly via Reflection instead,
 * same rationale as seedCollected() above.
 *
 * flush()'s own header-emission loop (only reached when `$this->collected
 * !== [] && ! headers_sent()`) stays genuinely unreachable from any test in
 * this repo: PHP's CLI SAPI hardwires headers_sent() to true
 * unconditionally (confirmed live, fresh process, zero prior output) --
 * there is no HTTP response to attach headers to outside of a real web SAPI
 * request, which no Unit test runs under.
 *
 * flush()'s OTHER remaining branch -- the `error_get_last()`-is-a-fatal-type
 * body (E_ERROR/E_PARSE/E_CORE_ERROR/E_COMPILE_ERROR) -- was believed
 * unreachable for the same reason (producing one of those 4 types for real
 * means crashing the interpreter, which would kill whatever process runs
 * it) until this sweep found the same escape hatch this file's sibling
 * ShutdownHandlerTest.php uses for its own SIGTERM-exit(143) branch: crash
 * a genuinely separate PHP subprocess instead of this shared worker. See
 * the dedicated subprocess test further down.
 */
test('writeTestErrorsLog is a no-op when test mode is not active, never touching the log file', function (): void {
    // The passed-in $paths deliberately points nowhere real (a throwaway
    // fromRoot(), never written to by anything else in this test): if the
    // testModeIsActive() guard were ever removed, the very next line
    // ($paths->logs) would attempt a real write to that bogus path instead
    // of silently returning -- that would-be write is the real assertion,
    // verified below by asserting the directory it would land in was never
    // created.
    Kernel::reset();
    $original = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
    unset($_SERVER['HTTP_X_PIWIGO_ENV']);
    $root = sys_get_temp_dir() . '/piwigo-error-collector-noop-' . bin2hex(random_bytes(8)) . '/';

    try {
        $method = new ReflectionMethod(ErrorCollector::class, 'writeTestErrorsLog');
        $method->invoke(null, '[WARNING] irrelevant in file.php:1', Paths::fromRoot($root));
    } finally {
        if ($original !== null) {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $original;
        }
    }

    expect(is_dir($root))->toBeFalse(); // the root directory itself was never even created, let alone written into
});

test('writeTestErrorsLog creates _data/logs/ when it does not exist yet, instead of silently failing', function (): void {
    // Real bug, found live: a fresh checkout (no prior HTTP traffic, so
    // Monolog's own RotatingFileHandler for piwigo.log has never run its
    // own self-healing createDir()) has no _data/logs/ directory at all --
    // the bare file_put_contents() this method used to call silently
    // returns false (a PHP warning, not a fatal error) in that case.
    $root = sys_get_temp_dir() . '/piwigo-error-collector-logs-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root);

    try {
        Kernel::boot(Paths::fromRoot($root));

        $method = new ReflectionMethod(ErrorCollector::class, 'writeTestErrorsLog');
        // @ suppression alone doesn't stop PHPUnit's own ErrorHandler from
        // surfacing the warning of the method's own first, expected-to-fail
        // write attempt -- a real no-op handler for the duration of this
        // one call is the reliable way to swallow it, matching
        // PersistentFileCacheTest.php's own established pattern.
        set_error_handler(static fn (): bool => true);
        try {
            $method->invoke(null, '[WARNING] irrelevant in file.php:1', Paths::fromRoot($root));
        } finally {
            restore_error_handler();
        }

        $logPath = $root . '_data/logs/test_errors.log';
        expect(is_dir($root . '_data/logs'))->toBeTrue()
            ->and(file_exists($logPath))->toBeTrue()
            // Exact match (not toContain()) -- kills line 163's
            // ConcatRemoveRight (would drop the trailing "\n") and
            // ConcatSwitchSides (would reorder to "\n" . $entry).
            ->and(file_get_contents($logPath))->toBe("[WARNING] irrelevant in file.php:1\n");
    } finally {
        Kernel::reset();
        exec('rm -rf ' . escapeshellarg($root));
    }
});

test('writeTestErrorsLog appends the entry directly when _data/logs/ already exists', function (): void {
    // Kills line 155's ConcatRemoveLeft/ConcatRemoveRight/ConcatSwitchSides
    // on the FIRST file_put_contents() call -- the test above only
    // exercises the fallback (line 163, after mkgetdir()), since its
    // directory never exists yet, so the first call there always fails
    // before ever writing anything.
    $root = sys_get_temp_dir() . '/piwigo-error-collector-logs-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root . '_data/logs', 0o777, true);

    try {
        Kernel::boot(Paths::fromRoot($root));

        $method = new ReflectionMethod(ErrorCollector::class, 'writeTestErrorsLog');
        $method->invoke(null, '[WARNING] first in file.php:1', Paths::fromRoot($root));
        $method->invoke(null, '[NOTICE] second in file.php:2', Paths::fromRoot($root));

        $logPath = $root . '_data/logs/test_errors.log';
        expect(file_get_contents($logPath))->toBe("[WARNING] first in file.php:1\n[NOTICE] second in file.php:2\n");
    } finally {
        Kernel::reset();
        exec('rm -rf ' . escapeshellarg($root));
    }
});

test('currentConfig resolves the container-shared CurrentConfig instance when Kernel is booted, not a disconnected fresh one', function (): void {
    // Kills IfNegated on the `if (Kernel::isBooted())` guard -- negated,
    // it would skip the container-resolution branch even while booted and
    // fall straight to the bottom `return new CurrentConfig()` -- and
    // kills RemoveEarlyReturn on `return $instance;` -- removed, the same
    // container-resolved $instance would fall through to that same bottom
    // `new CurrentConfig()` instead of actually being returned. Both
    // mutants hand back a brand-new, disconnected CurrentConfig instance
    // instead of the container-shared one PHP-DI already cached -- object
    // identity (toBe(), not toEqual()) is the only assertion that
    // distinguishes "the real container instance" from "a fresh look-alike
    // with the same default property values".
    $root = sys_get_temp_dir() . '/piwigo-error-collector-currentconfig-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root);

    try {
        Kernel::boot(Paths::fromRoot($root));
        $containerInstance = Kernel::container()->get(CurrentConfig::class);
        expect($containerInstance)->toBeInstanceOf(CurrentConfig::class);

        $method = new ReflectionMethod(ErrorCollector::class, 'currentConfig');
        $resolved = $method->invoke(null);

        expect($resolved)->toBe($containerInstance);
    } finally {
        Kernel::reset();
        exec('rm -rf ' . escapeshellarg($root));
    }
});

/**
 * New finding this sweep, applying to every subprocess-based test in
 * this file (including the pre-existing E_ERROR/OOM test above): a real
 * `pest --mutate` run cannot credit ANY of them with killing a mutant on
 * line 174, even though each is independently, empirically verified
 * (via a temporary sed-applied mutation + a standalone `php -r`/subprocess
 * run, matching this whole sweep's own established technique) to
 * genuinely distinguish real from mutated behavior. Root cause: pest's
 * mutation harness swaps in the mutated source only within its own
 * controlling PHP process's special test-run mechanism -- a `proc_open()`-
 * spawned child process re-reads `src/Piwigo/Core/ErrorCollector.php`
 * directly off disk, seeing the real, unmutated file every time,
 * regardless of which mutant pest is currently "applying". This is a
 * structural limitation of the subprocess-crash technique itself (real,
 * uncatchable PHP errors are only reproducible via a genuinely separate
 * process), not a code-quality gap -- the 2 tests below (and the
 * pre-existing OOM one) are kept as real, valuable coverage despite
 * `pest --mutate` being permanently unable to see it.
 */
test('flush does not record a non-fatal error_get_last() type as if it were fatal', function (): void {
    // Kills line 174's BitwiseAndToBitwiseOr: E_USER_WARNING (512) shares
    // no bits at all with the fatal mask (E_ERROR|E_PARSE|E_CORE_ERROR|
    // E_COMPILE_ERROR = 85), so `&` correctly excludes it -- `|` would
    // always be truthy regardless of $last['type'], wrongly recording
    // every real error_get_last() state as a synthetic fatal entry.
    // (RemoveBooleanCast on this same line is confirmed-equivalent,
    // verified live: the surrounding `&&` already coerces its right
    // operand to bool the same way an explicit cast would, for any int.)
    //
    // Run in an isolated subprocess, not a bare @trigger_error() here:
    // error_get_last() is only ever populated by PHP's own DEFAULT error
    // handling, which a real (even suppressing) set_error_handler()
    // bypasses entirely (confirmed live: installing one, even one that
    // returns true, leaves error_get_last() null afterward) -- and this
    // shared PHPUnit process's own installed error handler intercepts a
    // bare @trigger_error() regardless of the `@`, surfacing it as a
    // risky-test warning instead of leaving error_get_last() alone.
    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();
    $marker = sys_get_temp_dir() . '/piwigo-error-collector-nonfatal-' . bin2hex(random_bytes(8)) . '.json';

    $script = '<?php' . "\n"
        . 'require ' . var_export($autoloadPath, true) . ";\n"
        . "@trigger_error('non-fatal warning for mutation coverage', E_USER_WARNING);\n"
        . "\$errorCollector = new \\Piwigo\\Core\\ErrorCollector(new \\Piwigo\\Config\\DeploymentPolicy(), \\Piwigo\\Core\\Paths::fromRoot(sys_get_temp_dir()));\n"
        . "\$method = new ReflectionMethod(\\Piwigo\\Core\\ErrorCollector::class, 'flush');\n"
        . "\$method->invoke(\$errorCollector);\n"
        . 'file_put_contents(' . var_export($marker, true) . ", json_encode(\$errorCollector->collected()));\n";

    $scriptFile = sys_get_temp_dir() . '/piwigo-error-collector-nonfatal-script-' . bin2hex(random_bytes(8)) . '.php';
    file_put_contents($scriptFile, $script);

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open([PHP_BINARY, $scriptFile], $descriptors, $pipes);
    expect($proc)->toBeResource();
    if ($proc === false) {
        throw new RuntimeException('proc_open failed');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    @unlink($scriptFile);

    try {
        expect($exit)->toBe(0, 'ErrorCollector non-fatal subprocess exited non-zero: stdout=' . $stdout . ' stderr=' . $stderr)
            ->and(file_exists($marker))->toBeTrue();

        /** @var list<string> $collected */
        $collected = json_decode((string) file_get_contents($marker), true);
        expect($collected)->toBe([]);
    } finally {
        @unlink($marker);
    }
});

test('flush records a synthetic entry for a genuine E_PARSE fatal (malformed required file) in a real subprocess', function (): void {
    // Kills 2 of line 174's 3 BitwiseOrToBitwiseAnd mutants (the ones
    // pairing E_ERROR|E_PARSE and E_PARSE|E_CORE_ERROR -- either zeroes
    // out E_PARSE's own bit, both distinguishable from a real E_PARSE).
    // The 3rd (pairing E_CORE_ERROR|E_COMPILE_ERROR) is not chased: both
    // of those types originate from PHP's own core/compile machinery,
    // not reachable via any userland mechanism -- confirmed here that
    // even eval() (this file's own docblock) and a malformed `require`
    // (this test) only ever produce a catchable ParseError/real E_PARSE,
    // never E_CORE_ERROR or E_COMPILE_ERROR specifically.
    //
    // A syntactically invalid required file is a real, uncatchable
    // (from the requiring script's own perspective) E_PARSE that DOES
    // still populate error_get_last() and DOES still run
    // register_shutdown_function() callbacks afterward -- both confirmed
    // live, distinct from eval()'s own catchable-ParseError behavior
    // this file's docblock already ruled out.
    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();
    $marker = sys_get_temp_dir() . '/piwigo-error-collector-parse-' . bin2hex(random_bytes(8)) . '.json';
    $brokenFile = sys_get_temp_dir() . '/piwigo-error-collector-broken-' . bin2hex(random_bytes(8)) . '.php';
    file_put_contents($brokenFile, "<?php\nthis is not valid php syntax {{{\n");

    $script = '<?php' . "\n"
        . 'require ' . var_export($autoloadPath, true) . ";\n"
        . "\$errorCollector = new \\Piwigo\\Core\\ErrorCollector(new \\Piwigo\\Config\\DeploymentPolicy(), \\Piwigo\\Core\\Paths::fromRoot(sys_get_temp_dir()));\n"
        . "\$errorCollector->install();\n"
        . "register_shutdown_function(function () use (\$errorCollector) {\n"
        . '    file_put_contents(' . var_export($marker, true) . ", json_encode(\$errorCollector->collected()));\n"
        . "});\n"
        . 'require ' . var_export($brokenFile, true) . ";\n";

    $scriptFile = sys_get_temp_dir() . '/piwigo-error-collector-parse-script-' . bin2hex(random_bytes(8)) . '.php';
    file_put_contents($scriptFile, $script);

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open([PHP_BINARY, $scriptFile], $descriptors, $pipes);
    expect($proc)->toBeResource();
    if ($proc === false) {
        throw new RuntimeException('proc_open failed');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    @unlink($scriptFile);
    @unlink($brokenFile);

    try {
        expect($exit)->not->toBe(0)
            ->and(file_exists($marker))->toBeTrue('ErrorCollector E_PARSE subprocess never wrote its marker: stdout=' . $stdout . ' stderr=' . $stderr);

        /** @var list<string> $collected */
        $collected = json_decode((string) file_get_contents($marker), true);
        expect($collected)->toHaveCount(1)
            // label() itself deliberately has no E_PARSE arm (see the
            // "label falls back to the PHP code" test above) -- '[PHP]',
            // not '[ERROR]', is the real, correct label here.
            ->and($collected[0])->toStartWith('[PHP] syntax error');
    } finally {
        @unlink($marker);
    }
});

test('flush returns immediately when nothing was collected', function (): void {
    $errorCollector = new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir()));
    seedCollected($errorCollector, []);

    $method = new ReflectionMethod(ErrorCollector::class, 'flush');
    $method->invoke($errorCollector);

    expect($errorCollector->collected())->toBe([]);
});

test('flush records a synthetic entry for a genuine E_ERROR fatal (memory-limit exhaustion) in a real subprocess', function (): void {
    // register_shutdown_function() callbacks -- including flush() itself,
    // via ErrorCollector::install() -- DO still run after PHP's own
    // "Allowed memory size exhausted" fatal, and error_get_last() DOES
    // report it as a real E_ERROR at that point: both confirmed live. A
    // deliberately tiny memory_limit, set only inside this isolated
    // subprocess, triggers that fatal for real without risking (or even
    // touching) this shared PHPUnit worker -- same subprocess-crash
    // technique as ShutdownHandlerTest.php's own SIGTERM test, applied to
    // this class's sibling "can only be produced by really crashing"
    // branch.
    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();
    $marker = sys_get_temp_dir() . '/piwigo-error-collector-oom-' . bin2hex(random_bytes(8)) . '.json';

    $script = '<?php' . "\n"
        . "ini_set('memory_limit', '32M');\n"
        . 'require ' . var_export($autoloadPath, true) . ";\n"
        . "\$errorCollector = new \\Piwigo\\Core\\ErrorCollector(new \\Piwigo\\Config\\DeploymentPolicy(), \\Piwigo\\Core\\Paths::fromRoot(sys_get_temp_dir()));\n"
        . "\$errorCollector->install();\n"
        . "register_shutdown_function(function () use (\$errorCollector) {\n"
        . '    file_put_contents(' . var_export($marker, true) . ", json_encode(\$errorCollector->collected()));\n"
        . "});\n"
        . "\$sink = [];\n"
        . "while (true) {\n"
        . "    \$sink[] = str_repeat('a', 1024 * 1024);\n"
        . "}\n";

    $scriptFile = sys_get_temp_dir() . '/piwigo-error-collector-oom-script-' . bin2hex(random_bytes(8)) . '.php';
    file_put_contents($scriptFile, $script);

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open([PHP_BINARY, $scriptFile], $descriptors, $pipes);
    expect($proc)->toBeResource();
    if ($proc === false) {
        throw new RuntimeException('proc_open failed');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    @unlink($scriptFile);

    try {
        // A memory-exhaustion fatal is PHP's own uncatchable "Fatal error"
        // -- the subprocess exits non-zero (it never reaches a normal
        // `exit(0)`), same as any other fatal PHP error.
        expect($exit)->not->toBe(0)
            ->and(file_exists($marker))->toBeTrue('ErrorCollector OOM subprocess never wrote its marker: stdout=' . $stdout . ' stderr=' . $stderr);

        /** @var list<string> $collected */
        $collected = json_decode((string) file_get_contents($marker), true);
        expect($collected)->toHaveCount(1)
            ->and($collected[0])->toStartWith('[ERROR] Allowed memory size of')
            ->and($collected[0])->toContain('exhausted');
    } finally {
        @unlink($marker);
    }
});

test('label maps E_NOTICE/E_USER_NOTICE to the NOTICE code', function (): void {
    $method = new ReflectionMethod(ErrorCollector::class, 'label');

    expect($method->invoke(null, E_NOTICE))->toBe('NOTICE')
        ->and($method->invoke(null, E_USER_NOTICE))->toBe('NOTICE');
});

test('label maps E_ERROR/E_USER_ERROR/E_CORE_ERROR/E_COMPILE_ERROR to the ERROR code', function (): void {
    $method = new ReflectionMethod(ErrorCollector::class, 'label');

    expect($method->invoke(null, E_ERROR))->toBe('ERROR')
        ->and($method->invoke(null, E_USER_ERROR))->toBe('ERROR')
        ->and($method->invoke(null, E_CORE_ERROR))->toBe('ERROR')
        ->and($method->invoke(null, E_COMPILE_ERROR))->toBe('ERROR');
});

test('label maps E_WARNING/E_USER_WARNING/E_CORE_WARNING/E_COMPILE_WARNING to the WARNING code', function (): void {
    $method = new ReflectionMethod(ErrorCollector::class, 'label');

    expect($method->invoke(null, E_WARNING))->toBe('WARNING')
        ->and($method->invoke(null, E_USER_WARNING))->toBe('WARNING')
        ->and($method->invoke(null, E_CORE_WARNING))->toBe('WARNING')
        ->and($method->invoke(null, E_COMPILE_WARNING))->toBe('WARNING');
});

test('label maps E_DEPRECATED/E_USER_DEPRECATED to the DEPRECATED code', function (): void {
    $method = new ReflectionMethod(ErrorCollector::class, 'label');

    expect($method->invoke(null, E_DEPRECATED))->toBe('DEPRECATED')
        ->and($method->invoke(null, E_USER_DEPRECATED))->toBe('DEPRECATED');
});

test('label falls back to the PHP code for a type matching none of the known categories', function (): void {
    // E_PARSE is deliberately absent from every one of label()'s own
    // bitmasks (flush() checks it separately, as part of the fatal-type
    // guard, but label() itself never lists it under ERROR/WARNING/
    // DEPRECATED/NOTICE) -- confirmed by reading every arm above -- so
    // it is a real, always-available way to reach the `default => 'PHP'`
    // arm without relying on some made-up out-of-range integer.
    $method = new ReflectionMethod(ErrorCollector::class, 'label');

    expect($method->invoke(null, E_PARSE))->toBe('PHP');
});

/**
 * flush()'s X-PHP-Error-N header-emission loop only runs when
 * `$this->collected !== [] && ! headers_sent()`. Under the CLI SAPI,
 * headers_sent() is always true (there is no real HTTP response to send
 * headers to), so this loop never actually runs in this test suite --
 * header() is a documented no-op there anyway. The loop only ever reads
 * $this->collected, never mutates it, so flush() leaves the buffer
 * unchanged whether or not the loop runs.
 */
test('flush leaves a non-empty buffer untouched when headers are already sent', function (): void {
    $errorCollector = new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir()));
    $seeded = ['[WARNING] foo in bar.php:1', '[NOTICE] baz in qux.php:2'];
    seedCollected($errorCollector, $seeded);

    $method = new ReflectionMethod(ErrorCollector::class, 'flush');
    $method->invoke($errorCollector);

    // Whether this run's headers_sent() is true (the early return fires)
    // or -- in some future environment where it genuinely isn't -- the
    // header-emission loop runs for real, flush() never drains or
    // otherwise mutates $this->collected: it is byte-for-byte identical
    // to what was seeded either way.
    expect($errorCollector->collected())->toBe($seeded);
});

/**
 * Closes a real gap the test above deliberately cannot: it never pins
 * down WHICH of the guard's two operands actually made the early return
 * fire, so a broken guard that still happens to return early for some
 * other reason would sail through untouched. This test forces a single,
 * known state (headers already sent, buffer non-empty) in an isolated
 * subprocess and observes whether header() actually got called at all.
 *
 * Under the CLI SAPI, header()'s own arguments are never retrievable --
 * headers_list() stays an empty array even right after a successful call
 * (confirmed live) -- so the header-emission loop's internal string
 * building (substr bounds, the "$i + 1" numbering, str_replace's
 * newline-stripping char list, etc.) has no observable trace here at all
 * and is out of reach for any Unit test in this repo; only the guard
 * itself -- whether header() got invoked in the first place -- is
 * reachable, via the one observable side effect calling header() has
 * under CLI: PHP raises E_WARNING "Cannot modify header information" if,
 * and only if, headers_sent() is already true at call time (confirmed
 * live; header() itself never flips headers_sent() -- only real prior
 * output does, which is why this script echoes something first).
 */
test('flush never calls header() when headers are already sent, even with a non-empty buffer', function (): void {
    // Kills the `if (Kernel::isBooted())`-guard's sibling on this class --
    // IfNegated on `if ($this->collected === [] || headers_sent())`: negated,
    // with collected non-empty and headers_sent() forced true, the guard
    // would wrongly skip the early return and fall into the header loop.
    // Also kills BooleanOrToBooleanAnd (`&&` in place of `||`): with
    // collected non-empty, `false && true` is false, so that mutant skips
    // the early return the exact same way. Both mutants call the real
    // header() at least once (`X-PHP-Error-1: ...` at minimum); the
    // correct guard calls it zero times.
    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();
    $marker = sys_get_temp_dir() . '/piwigo-error-collector-headerssent-' . bin2hex(random_bytes(8)) . '.json';

    $script = '<?php' . "\n"
        . 'require ' . var_export($autoloadPath, true) . ";\n"
        . "echo 'forces headers_sent() to true before ErrorCollector ever runs';\n"
        . "\$errorCollector = new \\Piwigo\\Core\\ErrorCollector(new \\Piwigo\\Config\\DeploymentPolicy(), \\Piwigo\\Core\\Paths::fromRoot(sys_get_temp_dir()));\n"
        . "\$prop = new ReflectionProperty(\\Piwigo\\Core\\ErrorCollector::class, 'collected');\n"
        . "\$prop->setValue(\$errorCollector, ['[WARNING] foo in bar.php:1', '[NOTICE] baz in qux.php:2']);\n"
        . "\$sentBeforeFlush = headers_sent();\n"
        . "\$headerCalls = 0;\n"
        . "set_error_handler(function () use (&\$headerCalls): bool {\n"
        . "    \$headerCalls++;\n"
        . "    return true;\n"
        . "});\n"
        . "\$method = new ReflectionMethod(\\Piwigo\\Core\\ErrorCollector::class, 'flush');\n"
        . "\$method->invoke(\$errorCollector);\n"
        . "restore_error_handler();\n"
        . 'file_put_contents(' . var_export($marker, true) . ", json_encode(['sentBeforeFlush' => \$sentBeforeFlush, 'headerCalls' => \$headerCalls]));\n";

    $scriptFile = sys_get_temp_dir() . '/piwigo-error-collector-headerssent-script-' . bin2hex(random_bytes(8)) . '.php';
    file_put_contents($scriptFile, $script);

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open([PHP_BINARY, $scriptFile], $descriptors, $pipes);
    expect($proc)->toBeResource();
    if ($proc === false) {
        throw new RuntimeException('proc_open failed');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    @unlink($scriptFile);

    try {
        expect($exit)->toBe(0, 'ErrorCollector headers-sent subprocess exited non-zero: stdout=' . $stdout . ' stderr=' . $stderr)
            ->and(file_exists($marker))->toBeTrue();

        /** @var array{sentBeforeFlush: bool, headerCalls: int} $result */
        $result = json_decode((string) file_get_contents($marker), true);
        // Sanity check on the forcing technique itself -- if this is ever
        // false, the test below would pass for the wrong reason (the real
        // early-return guard never even got exercised as intended).
        expect($result['sentBeforeFlush'])->toBeTrue()
            ->and($result['headerCalls'])->toBe(0);
    } finally {
        @unlink($marker);
    }
});
