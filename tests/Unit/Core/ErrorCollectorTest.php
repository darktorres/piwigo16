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
