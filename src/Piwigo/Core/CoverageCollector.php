<?php

declare(strict_types=1);

namespace Piwigo\Core;

use function pcov\clear;
use function pcov\collect;
use function pcov\start;
use function pcov\stop;
use function pcov\waiting;
use const pcov\inclusive;

/**
 * Dumps per-request pcov line coverage to disk when coverage-mode test runs
 * are in progress, so Contract/Browser tests (both driving the live Apache
 * process, invisible to the CLI test-runner's own coverage instrumentation)
 * become measurable. Gated by Env::testModeIsActive() (loopback + a valid
 * X-Piwigo-Env header) plus a dedicated X-Piwigo-Coverage: 1 header sent only
 * by test harnesses running under PIWIGO_COVERAGE=1 -- zero cost/no-op for
 * real traffic and for ordinary (non-coverage) test runs.
 *
 * Mirrors phpunit/php-code-coverage's own PcovDriver::start()/stop() sequence
 * (pcov\start() -> ... -> pcov\stop()/waiting()/collect()/clear()) rather
 * than inventing new semantics -- tools/coverage-merge.php later feeds these
 * dumps back through that same library to produce one merged report.
 */
final class CoverageCollector
{
    public static function registerIfActive(Paths $paths): void
    {
        if (! Env::testModeIsActive()) {
            return;
        }

        if (($_SERVER['HTTP_X_PIWIGO_COVERAGE'] ?? null) !== '1') {
            return;
        }

        if (! extension_loaded('pcov')) {
            return;
        }

        start();

        $dumpDir = $paths->data . 'coverage-raw/web/';
        register_shutdown_function(static function () use ($dumpDir): void {
            stop();
            $waiting = waiting();
            if ($waiting === []) {
                return;
            }

            $collected = collect(inclusive, $waiting);
            clear();

            FilesystemHelper::mkgetdir($dumpDir, FilesystemHelper::MKGETDIR_RECURSIVE);
            $tmp = $dumpDir . uniqid('cov_', true) . '.tmp';
            file_put_contents($tmp, serialize($collected));
            rename($tmp, substr($tmp, 0, -4) . '.raw');
        });
    }
}
