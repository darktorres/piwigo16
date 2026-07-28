<?php

declare(strict_types=1);

use Piwigo\Core\CoverageCollector;
use Piwigo\Core\Paths;

/**
 * Piwigo\Core\CoverageCollector::registerIfActive() -- had zero dedicated
 * coverage (see /home/torres/.claude/plans/piped-enchanting-spark.md,
 * Wave 1). Only the two early-return guards are safely testable here:
 *
 * - `! Env::testModeIsActive()`: requires temporarily removing
 *   tests/bootstrap.php's own `HTTP_X_PIWIGO_ENV` header (every Pest
 *   process sets it once, globally, for the whole run), restored
 *   immediately after this one call.
 * - `$_SERVER['HTTP_X_PIWIGO_COVERAGE'] !== '1'`: the real, everyday
 *   state of any normal (non-coverage) test run -- this header is only
 *   ever sent by a dedicated PIWIGO_COVERAGE=1 harness invocation.
 *
 * The remaining two branches are NOT exercised, deliberately:
 * - `! extension_loaded('pcov')` is unreachable in this real environment
 *   (confirmed live: `php -m` lists pcov, matching how ContainerDetector's
 *   own test only asserts this environment's actual, stable result rather
 *   than faking an unavailable extension).
 * - The real activation path calls `\pcov\start()` and
 *   `register_shutdown_function()` for real. Triggering it here would
 *   start genuine pcov instrumentation and register a real shutdown
 *   handler inside THIS SAME shared Pest/PHPUnit process (every test file
 *   in a run shares one process, a recurring theme across this whole
 *   session) -- an unrelated later `\pcov\start()` call (e.g. from
 *   PHPUnit's own coverage driver, if a future run ever adds
 *   `--coverage`) could then collide with it. Genuinely unsafe to fake
 *   from inside the test process it would run in, not just untested for
 *   convenience.
 */
test('registerIfActive is a no-op when test mode is not active', function (): void {
    $header = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
    unset($_SERVER['HTTP_X_PIWIGO_ENV']);

    try {
        CoverageCollector::registerIfActive(Paths::fromRoot('/home/torres/piwigo17-rewrite'));
        expect(true)->toBeTrue();
    } finally {
        if ($header !== null) {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $header;
        }
    }
});

test('registerIfActive is a no-op when the coverage header is absent, the real state of every non-coverage test run', function (): void {
    expect($_SERVER['HTTP_X_PIWIGO_COVERAGE'] ?? null)->not->toBe('1');

    CoverageCollector::registerIfActive(Paths::fromRoot('/home/torres/piwigo17-rewrite'));

    expect(true)->toBeTrue();
});
