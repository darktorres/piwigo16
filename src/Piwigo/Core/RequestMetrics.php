<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Typed reader/writer for the per-request timing/debug/correlation
 * state -- split out of `PageState` (P41, docs/PLAN.md) since these 5
 * fields are a self-contained cluster (written by
 * `Http\Middleware\ConfigBootstrapMiddleware`/`Bootstrap\RequestBootstrap`,
 * read by `TimingHelper`/`Page\PageTailRenderer`/`Logger`), unlike the
 * rest of `PageState`'s genuinely-unrelated fields.
 *
 * Container-shared; a zero-arg public constructor needs no
 * `container.php` entry, same as `PageState` itself.
 */
final class RequestMetrics
{
    public string $executionUuid = '';

    public int $countQueries = 0;

    public float $queriesTime = 0.0;

    /**
     * The instant (`microtime(true)`) this request began. Captured at
     * `include/common.inc.php`'s top-level scope, before the autoload
     * boundary, for maximum precision, and handed off here early in
     * `RequestBootstrap::configure()`'s body (right after its own
     * `Kernel::boot()` call); every other consumer reads it from here.
     */
    public float $requestStart = 0.0;

    /**
     * Accumulated debug-mode query/timing HTML, shown in the page footer.
     * Only populated when CurrentConfig::showQueries() is on.
     */
    public string $debugOutput = '';

    public function __construct() {}

    /**
     * Test-only -- re-initializes every property back to its constructed
     * default, same reasoning as `PageState`'s own reset() docblock.
     */
    public function reset(): void
    {
        $this->executionUuid = '';
        $this->countQueries = 0;
        $this->queriesTime = 0.0;
        $this->requestStart = 0.0;
        $this->debugOutput = '';
    }

    /**
     * Accumulates both counters together. No current call site invokes
     * this method in production (DB access goes through
     * `Piwigo\Db\DbConnection`), so `countQueries`/`queriesTime` stay at 0
     * outside of tests that call it directly.
     */
    public function addQueryTime(float $time): void
    {
        $this->countQueries++;
        $this->queriesTime += $time;
    }

    public function addDebugOutput(string $line): void
    {
        $this->debugOutput .= $line;
    }
}
