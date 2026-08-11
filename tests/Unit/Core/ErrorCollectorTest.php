<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\KernelContainerOverride;

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
    expect($errorCollector->isActive())
        ->toBeTrue();

    $errorCollector->reset();

    expect($errorCollector->isActive())
        ->toBeFalse();
});

test('drain returns an empty array when nothing was collected', function (): void {
    expect(new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir()))->drain())->toBe([]);
});

test('drain returns exactly what was collected', function (): void {
    $errorCollector = new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir()));
    seedCollected($errorCollector, ['[WARNING] foo in bar.php:1', '[NOTICE] baz in qux.php:2']);

    expect($errorCollector->drain())
        ->toBe(['[WARNING] foo in bar.php:1', '[NOTICE] baz in qux.php:2']);
});

test('drain clears the buffer, unlike collected()', function (): void {
    $errorCollector = new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir()));
    seedCollected($errorCollector, ['[WARNING] foo in bar.php:1']);

    $errorCollector->drain();

    expect($errorCollector->collected())
        ->toBe([]);
});

test('recordFatal appends a real [ERROR]-prefixed entry and writes the real message to error_log()', function (): void {
    // Real gap: recordFatal() had no direct test at all -- only exercised
    // transitively through other classes' own fatalError() call sites.
    // Kills all 3 ConcatRemoveLeft/ConcatRemoveRight/ConcatSwitchSides
    // mutations on the error_log() argument by redirecting the `error_log`
    // ini setting to a real, readable temp file for the duration of this
    // call (confirmed live: error_log() genuinely honors this in-process,
    // no subprocess needed) rather than treating the message as an
    // unverifiable diagnostic side effect.
    $errorLogFile = tempnam(sys_get_temp_dir(), 'piwigo-error-collector-recordfatal-');
    $originalErrorLog = ini_get('error_log');
    ini_set('error_log', $errorLogFile);

    try {
        $errorCollector = new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir()));

        $errorCollector->recordFatal('a genuinely fatal condition');

        expect($errorCollector->collected())
            ->toBe(['[ERROR] a genuinely fatal condition'])
            ->and(file_get_contents($errorLogFile))
            ->toContain('PHP Fatal error: a genuinely fatal condition');
    } finally {
        ini_set('error_log', $originalErrorLog === false ? '' : $originalErrorLog);
        @unlink($errorLogFile);
    }
});

test('a second drain after a first returns empty', function (): void {
    $errorCollector = new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir()));
    seedCollected($errorCollector, ['[WARNING] foo in bar.php:1']);

    $errorCollector->drain();

    expect($errorCollector->drain())
        ->toBe([]);
});

/**
 * writeTestErrorsLog()/flush()/label() are all private (reached in
 * production only via the set_error_handler()/register_shutdown_function()
 * pair install() registers, deliberately never exercised here -- see this
 * file's own top docblock) -- invoked directly via Reflection instead,
 * same rationale as seedCollected() above.
 *
 * flush()'s own header-emission loop (only reached when `$this->collected
 * !== [] && ! headers_sent()`) was previously believed genuinely
 * unreachable from any test in this repo, on the claim that CLI SAPI
 * hardwires headers_sent() to true unconditionally. CORRECTED 2026-08-11:
 * that claim was wrong -- a genuinely clean subprocess (writing its result
 * to a file instead of echoing, so the check itself can't taint the state
 * being checked) shows headers_sent() really does start false. The bare
 * CLI subprocess tests further up still can't observe header() itself
 * under plain CLI (headers_list() stays empty, no warning on a first call)
 * -- but a real `php -S` web server can, via the same raw-socket technique
 * HtmlServiceTest.php already established for setStatusHeader(). See the
 * dedicated real-server test further down for the loop's own internals.
 *
 * flush()'s OTHER remaining branch -- the `error_get_last()`-is-a-fatal-type
 * body (E_ERROR/E_PARSE/E_CORE_ERROR/E_COMPILE_ERROR) -- was believed
 * unreachable for the same reason (producing one of those 4 types for real
 * means crashing the interpreter, which would kill whatever process runs
 * it). Reachable via the same escape hatch this file's sibling
 * ShutdownHandlerTest.php uses for its own SIGTERM-exit(143) branch: crash
 * a genuinely separate PHP subprocess instead of this shared worker. See
 * the dedicated subprocess test further down.
 *
 * IMPORTANT (same subprocess-invisibility blind spot documented in
 * feedback_pest_mutate_invisible_to_subprocess_tests -- this is a
 * recurrence of that same finding, not a new one; re-confirmed 2026-08-11
 * via direct instrumentation of pest-mutate's own internals
 * (Pest\Mutate\MutationTest::start()) specifically for this class's
 * header-emission loop): every test
 * below that reaches flush()'s header-emission loop or its fatal-type
 * branch (lines 232/240/245/247/248/250) does so ONLY inside a spawned
 * subprocess (proc_open()/php -S) -- that is genuinely correct and the
 * only way to observe this behavior at all (see above). But pest-mutate's
 * own `--covered-only` mutation selection relies on PHPUnit/Xdebug's
 * PER-TEST line-coverage attribution, which is scoped to the current,
 * single PHP process -- it cannot see code that only executes inside a
 * child process, no matter how real or correct that child's own
 * assertions are. Confirmed live: none of these subprocess tests are
 * ever selected as a "covering test" for these lines by pest-mutate's
 * own filter-construction step, so mutations on these lines can NEVER be
 * credited as killed, by any subprocess-based technique, regardless of
 * whether the test would actually catch the mutation if it *were* rerun
 * (a hand-verified web-server mutation test below confirms it would). The
 * ONE in-process test that pest-mutate does select for these lines
 * (`flush leaves a non-empty buffer untouched when headers are already
 * sent`, further down) deliberately asserts something true regardless of
 * the mutation (see its own docblock), so it can't kill them either. This
 * is a structural pest-mutate limitation, not a real gap in this file's
 * own test coverage -- these lines stay permanently "untested" in any
 * future scoped-verify-rerun of this class; do not mistake that for a new
 * gap to chase.
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

    expect(is_dir($root))
        ->toBeFalse(); // the root directory itself was never even created, let alone written into
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
            ->and(file_exists($logPath))
            ->toBeTrue()
            // Exact match (not toContain()) -- kills line 163's
            // ConcatRemoveRight (would drop the trailing "\n") and
            // ConcatSwitchSides (would reorder to "\n" . $entry).
            ->and(file_get_contents($logPath))
            ->toBe("[WARNING] irrelevant in file.php:1\n");
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
        expect(file_get_contents($logPath))
            ->toBe("[WARNING] first in file.php:1\n[NOTICE] second in file.php:2\n");
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
        expect($containerInstance)
            ->toBeInstanceOf(CurrentConfig::class);

        $method = new ReflectionMethod(ErrorCollector::class, 'currentConfig');
        $resolved = $method->invoke(null);

        expect($resolved)
            ->toBe($containerInstance);
    } finally {
        Kernel::reset();
        exec('rm -rf ' . escapeshellarg($root));
    }
});

test('currentConfig throws when the container returns an unexpected type', function (): void {
    // Real gap: kills line 215's InstanceOfToTrue (`if (!true)`, never
    // taking the throw branch). Only reachable while Kernel::isBooted()
    // is true, matching every real call site's own established
    // KernelContainerOverride::withWrongTypeFor() pattern.
    $method = new ReflectionMethod(ErrorCollector::class, 'currentConfig');

    KernelContainerOverride::withWrongTypeFor(
        CurrentConfig::class,
        static fn (): mixed => $method->invoke(null),
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CurrentConfig::class);

/**
 * See the file-level docblock further up (right before the
 * writeTestErrorsLog no-op test) for the full, confirmed root cause:
 * every subprocess-based test in this file -- this one, the E_PARSE/
 * E_ERROR tests below, the headers-already-sent subprocess test, and the
 * real-web-server test further down -- is permanently invisible to `pest
 * --mutate`'s own per-test coverage attribution, regardless of how
 * correctly each one distinguishes real from mutated behavior when
 * actually run (independently confirmed for each, via a temporary
 * hand-applied mutation + a standalone rerun). Kept as real, valuable
 * regression coverage despite `pest --mutate` being structurally unable
 * to credit any of them.
 */
test('flush does not record a non-fatal error_get_last() type as if it were fatal', function (): void {
    // Would kill line 232's BitwiseAndToBitwiseOr (verified via a
    // temporary hand-applied mutation, see the docblock above for why
    // `pest --mutate` itself can never credit this): E_USER_WARNING (512)
    // shares no bits at all with the fatal mask (E_ERROR|E_PARSE|
    // E_CORE_ERROR|E_COMPILE_ERROR = 85), so `&` correctly excludes it --
    // `|` would always be truthy regardless of $last['type'], wrongly
    // recording every real error_get_last() state as a synthetic fatal
    // entry. (RemoveBooleanCast on this same line is confirmed-
    // equivalent, verified live: the surrounding `&&` already coerces its
    // right operand to bool the same way an explicit cast would, for any
    // int.)
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
    expect(is_file($autoloadPath))
        ->toBeTrue();
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
    expect($proc)
        ->toBeResource();
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
            ->and(file_exists($marker))
            ->toBeTrue();

        /** @var list<string> $collected */
        $collected = json_decode((string) file_get_contents($marker), true);
        expect($collected)
            ->toBe([]);
    } finally {
        @unlink($marker);
    }
});

test('flush records a synthetic entry for a genuine E_PARSE fatal (malformed required file) in a real subprocess', function (): void {
    // Would kill 2 of line 232's 3 BitwiseOrToBitwiseAnd mutants (the
    // ones pairing E_ERROR|E_PARSE and E_PARSE|E_CORE_ERROR -- either
    // zeroes out E_PARSE's own bit, both distinguishable from a real
    // E_PARSE) -- see the docblock above the non-fatal test further up
    // for why `pest --mutate` itself can never credit this.
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
    expect(is_file($autoloadPath))
        ->toBeTrue();
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
    expect($proc)
        ->toBeResource();
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
            ->and(file_exists($marker))
            ->toBeTrue('ErrorCollector E_PARSE subprocess never wrote its marker: stdout=' . $stdout . ' stderr=' . $stderr);

        /** @var list<string> $collected */
        $collected = json_decode((string) file_get_contents($marker), true);
        expect($collected)
            ->toHaveCount(1)
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

    expect($errorCollector->collected())
        ->toBe([]);
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
    expect(is_file($autoloadPath))
        ->toBeTrue();
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
    expect($proc)
        ->toBeResource();
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
        expect($exit)
            ->not->toBe(0)
            ->and(file_exists($marker))
            ->toBeTrue('ErrorCollector OOM subprocess never wrote its marker: stdout=' . $stdout . ' stderr=' . $stderr);

        /** @var list<string> $collected */
        $collected = json_decode((string) file_get_contents($marker), true);
        expect($collected)
            ->toHaveCount(1)
            ->and($collected[0])->toStartWith('[ERROR] Allowed memory size of')
            ->and($collected[0])->toContain('exhausted');
    } finally {
        @unlink($marker);
    }
});

test('label maps E_NOTICE/E_USER_NOTICE to the NOTICE code', function (): void {
    $method = new ReflectionMethod(ErrorCollector::class, 'label');

    expect($method->invoke(null, E_NOTICE))
        ->toBe('NOTICE')
        ->and($method->invoke(null, E_USER_NOTICE))
        ->toBe('NOTICE');
});

test('label maps E_ERROR/E_USER_ERROR/E_CORE_ERROR/E_COMPILE_ERROR to the ERROR code', function (): void {
    $method = new ReflectionMethod(ErrorCollector::class, 'label');

    expect($method->invoke(null, E_ERROR))
        ->toBe('ERROR')
        ->and($method->invoke(null, E_USER_ERROR))
        ->toBe('ERROR')
        ->and($method->invoke(null, E_CORE_ERROR))
        ->toBe('ERROR')
        ->and($method->invoke(null, E_COMPILE_ERROR))
        ->toBe('ERROR');
});

test('label maps E_WARNING/E_USER_WARNING/E_CORE_WARNING/E_COMPILE_WARNING to the WARNING code', function (): void {
    $method = new ReflectionMethod(ErrorCollector::class, 'label');

    expect($method->invoke(null, E_WARNING))
        ->toBe('WARNING')
        ->and($method->invoke(null, E_USER_WARNING))
        ->toBe('WARNING')
        ->and($method->invoke(null, E_CORE_WARNING))
        ->toBe('WARNING')
        ->and($method->invoke(null, E_COMPILE_WARNING))
        ->toBe('WARNING');
});

test('label maps E_DEPRECATED/E_USER_DEPRECATED to the DEPRECATED code', function (): void {
    $method = new ReflectionMethod(ErrorCollector::class, 'label');

    expect($method->invoke(null, E_DEPRECATED))
        ->toBe('DEPRECATED')
        ->and($method->invoke(null, E_USER_DEPRECATED))
        ->toBe('DEPRECATED');
});

test('label falls back to the PHP code for a type matching none of the known categories', function (): void {
    // E_PARSE is deliberately absent from every one of label()'s own
    // bitmasks (flush() checks it separately, as part of the fatal-type
    // guard, but label() itself never lists it under ERROR/WARNING/
    // DEPRECATED/NOTICE) -- confirmed by reading every arm above -- so
    // it is a real, always-available way to reach the `default => 'PHP'`
    // arm without relying on some made-up out-of-range integer.
    $method = new ReflectionMethod(ErrorCollector::class, 'label');

    expect($method->invoke(null, E_PARSE))
        ->toBe('PHP');
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
    expect($errorCollector->collected())
        ->toBe($seeded);
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
    // Would kill line 240's IfNegated (`if ($this->collected === [] ||
    // headers_sent())`: negated, with collected non-empty and
    // headers_sent() forced true, the guard would wrongly skip the early
    // return and fall into the header loop) and BooleanOrToBooleanAnd
    // (`&&` in place of `||`: with collected non-empty, `false && true`
    // is false, so that mutant skips the early return the exact same
    // way) -- both mutants call the real header() at least once
    // (`X-PHP-Error-1: ...` at minimum); the correct guard calls it zero
    // times. See the docblock above the non-fatal subprocess test further
    // up for why `pest --mutate` itself can never credit this (this is a
    // subprocess-based test, same as that one).
    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
    expect(is_file($autoloadPath))
        ->toBeTrue();
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
    expect($proc)
        ->toBeResource();
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
            ->and(file_exists($marker))
            ->toBeTrue();

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

/**
 * @return array{0: resource, 1: int}
 */
function errorCollectorTestStartServer(string $docRoot): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $port = random_int(20_000, 60_000);
        $proc = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $docRoot], $descriptors, $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('failed to start local test server');
        }

        set_error_handler(static fn (): bool => true);
        try {
            for ($i = 0; $i < 100; $i++) {
                $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if (is_resource($sock)) {
                    fclose($sock);

                    return [$proc, $port];
                }
                usleep(20_000);
            }
        } finally {
            restore_error_handler();
        }

        proc_terminate($proc);
        proc_close($proc);
    }

    throw new RuntimeException('local test server never became reachable after 5 attempts');
}

/**
 * @param resource $proc
 */
function errorCollectorTestStopServer($proc): void
{
    proc_terminate($proc);
    proc_close($proc);
}

/**
 * Issues a raw HTTP/1.1 request and returns every response header line
 * (excluding the status line), same raw-socket technique as
 * HtmlServiceTest.php's own htmlServiceTestRawStatusLine() but keeping
 * the full header block instead of just the first line.
 *
 * @return list<string>
 */
function errorCollectorTestResponseHeaders(int $port): array
{
    $sock = fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
    if (! is_resource($sock)) {
        throw new RuntimeException('failed to connect to local test server: ' . $errstr);
    }
    fwrite($sock, "GET / HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    $raw = '';
    while (! feof($sock)) {
        $raw .= fread($sock, 8192);
    }
    fclose($sock);

    [$head] = explode("\r\n\r\n", $raw, 2) + [''];
    $lines = explode("\r\n", $head);
    array_shift($lines); // drop the status line

    return $lines;
}

/**
 * The header-emission loop's own internal string-building (line 245's
 * ForeachEmptyIterable, line 247's substr()/str_replace() mutations, line
 * 248's `$i + 1` numbering + concatenation mutations, line 250's
 * X-PHP-Error-Count construction) was previously documented as
 * "genuinely unreachable from any test in this repo" on the claim that
 * CLI SAPI hardwires headers_sent() to true unconditionally. Re-verified
 * live (2026-08-11) and that claim was WRONG: a genuinely clean
 * subprocess (writing its result to a file instead of echoing, so the
 * check itself can't taint the very state it's checking) shows
 * headers_sent() really does start false. The earlier bare-CLI subprocess
 * tests in this file still can't observe header() under CLI (confirmed:
 * headers_list() stays empty and no warning fires for the very first
 * call), but a REAL web server can -- same `php -S` + raw-socket
 * technique HtmlServiceTest.php already established for setStatusHeader().
 *
 * This test's own assertions below DO correctly distinguish real from
 * mutated behavior on every mutation they reference (independently
 * confirmed via temporary hand-applied mutations against the real
 * source). But see the file-level docblock further up (right before the
 * writeTestErrorsLog no-op test) for why `pest --mutate` itself can never
 * credit this test -- or any subprocess-based test in this file -- with
 * killing them: its own per-test coverage attribution cannot see
 * execution inside a spawned child process at all.
 */
test('flush emits one real X-PHP-Error-N header per entry, stripped of newlines and capped at 500 chars, plus a real count header', function (): void {
    $docRoot = sys_get_temp_dir() . '/piwigo-error-collector-headers-' . bin2hex(random_bytes(8));
    mkdir($docRoot, 0o777, true);
    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';

    $longMessage = str_repeat('x', 600);
    file_put_contents(
        $docRoot . '/index.php',
        '<?php' . "\n"
        . 'require ' . var_export($autoloadPath, true) . ';'
        . '$ec = new \Piwigo\Core\ErrorCollector(new \Piwigo\Config\DeploymentPolicy(), \Piwigo\Core\Paths::fromRoot(sys_get_temp_dir()));'
        . '$prop = new ReflectionProperty(\Piwigo\Core\ErrorCollector::class, "collected");'
        . '$prop->setValue($ec, ["[WARNING] line1\\r\\nline2 in file.php:1", "[ERROR] ' . $longMessage . '"]);'
        . '$method = new ReflectionMethod(\Piwigo\Core\ErrorCollector::class, "flush");'
        . '$method->invoke($ec);',
    );

    [$proc, $port] = errorCollectorTestStartServer($docRoot);

    try {
        $headers = errorCollectorTestResponseHeaders($port);

        $errorHeaders = array_values(array_filter($headers, static fn (string $h): bool => str_starts_with($h, 'X-PHP-Error-')));

        expect($errorHeaders)
            ->toHaveCount(3)
            // Would kill line 245's ForeachEmptyIterable (would emit zero
            // X-PHP-Error-N headers) and line 248's "$i + 1" numbering
            // mutations (PlusToMinus/DecrementInteger/IncrementInteger --
            // any of those would misnumber the 2nd entry).
            ->and($errorHeaders[0])->toStartWith('X-PHP-Error-1: [WARNING] line1')
            // Would kill line 247's UnwrapStrReplace (the raw \r\n would
            // still be present instead of stripped to a single space).
            // \r and \n are each independently replaced with their own
            // space (str_replace() with parallel arrays, not a joint
            // "\r\n" match), so the pair becomes two spaces, not one.
            ->and($errorHeaders[0])->toContain('line1  line2 in file.php:1')
            ->and($errorHeaders[0])->not->toContain("\r")
            ->and($errorHeaders[1])->toStartWith('X-PHP-Error-2: [ERROR] ' . str_repeat('x', 100))
            // Would kill line 247's UnwrapSubstr and its DecrementInteger/
            // IncrementInteger/RemoveArrayItem mutations on the 500-char
            // cap -- '[ERROR] ' (9 chars) + 600 x's = 609 raw chars,
            // capped to exactly 500.
            ->and(mb_strlen($errorHeaders[1]) - mb_strlen('X-PHP-Error-2: '))->toBe(500)
            // Would kill line 250's RemoveFunctionCall/ConcatRemoveRight/
            // ConcatSwitchSides on the X-PHP-Error-Count header. ("Would
            // kill" throughout this block: see the docblock above this
            // test for why `pest --mutate` can never actually credit it.)
            ->and($errorHeaders[2])->toBe('X-PHP-Error-Count: 2');
    } finally {
        errorCollectorTestStopServer($proc);
        unlink($docRoot . '/index.php');
        rmdir($docRoot);
    }
});
