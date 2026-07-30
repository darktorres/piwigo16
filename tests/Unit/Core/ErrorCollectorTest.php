<?php

declare(strict_types=1);

use Piwigo\Core\ErrorCollector;

/**
 * Deliberately never calls ErrorCollector::install() -- it registers a real
 * set_error_handler()/register_shutdown_function() pair, and PHP has no way
 * to unregister a shutdown function once added, which would leak into every
 * later test in this shared PHPUnit/Pest process. drain()'s own return-then-
 * clear contract is exercised directly via reflection instead, matching how
 * this class's other static state has no install()-dependent test either.
 */
/**
 * @param list<string> $entries
 */
function seedCollected(array $entries): void
{
    $prop = new ReflectionProperty(ErrorCollector::class, 'collected');
    $prop->setValue(null, $entries);
}

beforeEach(function (): void {
    ErrorCollector::reset();
});

afterEach(function (): void {
    ErrorCollector::reset();
});

test('drain returns an empty array when nothing was collected', function (): void {
    expect(ErrorCollector::drain())->toBe([]);
});

test('drain returns exactly what was collected', function (): void {
    seedCollected(['[WARNING] foo in bar.php:1', '[NOTICE] baz in qux.php:2']);

    expect(ErrorCollector::drain())->toBe(['[WARNING] foo in bar.php:1', '[NOTICE] baz in qux.php:2']);
});

test('drain clears the buffer, unlike collected()', function (): void {
    seedCollected(['[WARNING] foo in bar.php:1']);

    ErrorCollector::drain();

    expect(ErrorCollector::collected())->toBe([]);
});

test('a second drain after a first returns empty', function (): void {
    seedCollected(['[WARNING] foo in bar.php:1']);

    ErrorCollector::drain();

    expect(ErrorCollector::drain())->toBe([]);
});

/**
 * writeTestErrorsLog()/flush()/label() are all private (reached in
 * production only via the set_error_handler()/register_shutdown_function()
 * pair install() registers, deliberately never exercised here -- see this
 * file's own top docblock) -- invoked directly via Reflection instead,
 * same rationale as seedCollected() above.
 *
 * flush()'s own two remaining red branches are genuinely unreachable from
 * any test in this repo, not just unexercised:
 *  - The `error_get_last()`-is-a-fatal-type branch (E_ERROR/E_PARSE/
 *    E_CORE_ERROR/E_COMPILE_ERROR): these 4 types are, by PHP's own design,
 *    the ones set_error_handler() can never intercept -- the only way to
 *    produce one for real is to actually crash the interpreter, which
 *    would also kill this test process. error_get_last() itself has no
 *    injection seam (a bare global function, and shadowing it with a
 *    same-named Piwigo\Core\error_get_last() stub would permanently hijack
 *    every other bare call to it from anywhere else in that namespace for
 *    the rest of this shared PHPUnit process -- confirmed by testing the
 *    fallback-resolution behavior live).
 *  - The header-emission loop (only reached when `self::$collected !==
 *    [] && ! headers_sent()`): PHP's CLI SAPI hardwires headers_sent() to
 *    true unconditionally (confirmed live, fresh process, zero prior
 *    output) -- there is no HTTP response to attach headers to outside of
 *    a real web SAPI request, which no Unit test runs under.
 */
test('writeTestErrorsLog is a no-op when test mode is not active, never touching CurrentPaths', function (): void {
    // CurrentPaths is deliberately left uninitialised: if the
    // testModeIsActive() guard were ever removed, the very next line
    // (CurrentPaths::get()->logs) would throw LogicException instead of
    // silently returning -- that would-be exception is the real assertion.
    \Piwigo\Core\CurrentPaths::reset();
    $original = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
    unset($_SERVER['HTTP_X_PIWIGO_ENV']);

    try {
        $method = new ReflectionMethod(ErrorCollector::class, 'writeTestErrorsLog');
        $method->invoke(null, '[WARNING] irrelevant in file.php:1');
    } finally {
        if ($original !== null) {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $original;
        }
    }

    expect(true)->toBeTrue(); // reaching here without CurrentPaths ever throwing is the assertion
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
        \Piwigo\Core\CurrentPaths::set(\Piwigo\Core\Paths::fromRoot($root));

        $method = new ReflectionMethod(ErrorCollector::class, 'writeTestErrorsLog');
        // @ suppression alone doesn't stop PHPUnit's own ErrorHandler from
        // surfacing the warning of the method's own first, expected-to-fail
        // write attempt -- a real no-op handler for the duration of this
        // one call is the reliable way to swallow it, matching
        // PersistentFileCacheTest.php's own established pattern.
        set_error_handler(static fn (): bool => true);
        try {
            $method->invoke(null, '[WARNING] irrelevant in file.php:1');
        } finally {
            restore_error_handler();
        }

        $logPath = $root . '_data/logs/test_errors.log';
        expect(is_dir($root . '_data/logs'))->toBeTrue()
            ->and(file_exists($logPath))->toBeTrue()
            ->and(file_get_contents($logPath))->toContain('[WARNING] irrelevant in file.php:1');
    } finally {
        \Piwigo\Core\CurrentPaths::reset();
        exec('rm -rf ' . escapeshellarg($root));
    }
});

test('flush returns immediately when nothing was collected', function (): void {
    seedCollected([]);

    $method = new ReflectionMethod(ErrorCollector::class, 'flush');
    $method->invoke(null);

    expect(ErrorCollector::collected())->toBe([]);
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
 * A later coverage-gap sweep re-flagged flush()'s own fatal-error branch
 * (the `error_get_last()`-is-fatal body) and its X-PHP-Error-N header-
 * emission loop as closable. Re-investigated independently of this
 * file's own docblock above and reached the identical conclusion, with
 * two additional, concrete confirmations:
 *  - eval() of malformed PHP raises a catchable \ParseError (PHP turned
 *    eval()'s own E_PARSE into a real Throwable years ago) and never
 *    touches error_get_last() at all -- confirmed live: `var_dump
 *    (error_get_last())` right after catching that ParseError prints
 *    NULL. So eval() is not the injection seam either; nothing short of
 *    actually crashing the interpreter produces one of the four fatal
 *    types flush() checks for.
 *  - PHPUnit's own console output (vendor/phpunit/phpunit/src/TextUI/
 *    Output/Printer/DefaultPrinter.php) writes every dot/summary line via
 *    a raw fwrite() to an explicitly fopen()'d php://stdout stream, never
 *    echo/print -- so the test runner's own progress output cannot be
 *    what latches headers_sent() true (fwrite() to an explicit stream
 *    handle bypasses the SAPI output layer headers_sent() tracks
 *    entirely). Whatever does latch it is real script-level output
 *    somewhere else in this large, shared, single Unit-suite process --
 *    and this CLI SAPI's implicit_flush is On by default (unchanged by
 *    tests/bootstrap.php or phpunit.xml.dist), which forces even output
 *    produced *inside* PHPUnit's own per-test ob_start() straight through
 *    to the real SAPI layer instead of staying safely discarded by its
 *    matching ob_get_clean(). Once latched, headers_sent() has no
 *    userland reset, and no runkit/uopz extension is installed here to
 *    stub the builtin itself. The two lines below add real, still-closable
 *    coverage adjacent to that guard instead: the `headers_sent()` half
 *    of flush()'s `self::$collected === [] || headers_sent()` check
 *    (previously only its `self::$collected === []` half was exercised),
 *    proving flush() never mutates the buffer either way.
 */
test('flush leaves a non-empty buffer untouched when headers are already sent', function (): void {
    $seeded = ['[WARNING] foo in bar.php:1', '[NOTICE] baz in qux.php:2'];
    seedCollected($seeded);

    $method = new ReflectionMethod(ErrorCollector::class, 'flush');
    $method->invoke(null);

    // Whether this run's headers_sent() is true (the early return fires)
    // or -- in some future environment where it genuinely isn't -- the
    // header-emission loop runs for real, flush() never drains or
    // otherwise mutates self::$collected: it is byte-for-byte identical
    // to what was seeded either way.
    expect(ErrorCollector::collected())->toBe($seeded);
});
