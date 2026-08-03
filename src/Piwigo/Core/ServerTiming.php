<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Simple stopwatch registry -- container-shared instance (singleton/
 * service-locator elimination campaign, Phase 3; moved from Phase 1's
 * pre-boot marker trio for the identical entanglement reason, but
 * genuinely converted only now).
 *
 * Real production callers: `Piwigo\Bootstrap\RequestBootstrap` (writer,
 * via its own private `serverTiming()` resolver) and
 * `Piwigo\Http\Middleware\ServerTimingMiddleware` (reader, real
 * constructor injection -- always container-resolved, never manually
 * `new`'d). No transitional shim needed at all -- unlike every other
 * class in this campaign's Phase 1/3 pre-boot-marker group, this class's
 * real caller graph is only these 2 files, both already able to reach the
 * container-shared instance directly.
 *
 * `start('boot')`/`stop('boot')` genuinely bracket work that begins
 * *before* `Kernel::boot()` runs (`RequestBootstrap::bootEntryPoint()`/
 * `bootConfigOnly()` time the whole boot sequence, including the
 * `Kernel::boot()` call itself) -- there is no container-shared instance
 * to write into at that exact moment. `start()`'s optional `$at` lets a
 * caller pass an already-captured `microtime(true)` (taken before the
 * container exists) once the instance becomes resolvable, rather than
 * needing a second, later timestamp that would exclude `Kernel::boot()`'s
 * own cost from the measurement -- see `RequestBootstrap::configure()`'s
 * own use of this via its `$requestStart` parameter.
 */
final class ServerTiming
{
    /**
     * @var array<string, float>
     */
    private array $starts = [];

    /**
     * @var array<string, float>
     */
    private array $durations = [];

    public function start(string $name, ?float $at = null): void
    {
        $this->starts[$name] = $at ?? microtime(true);
    }

    public function stop(string $name): void
    {
        if (! isset($this->starts[$name])) {
            return;
        }

        $this->durations[$name] = (microtime(true) - $this->starts[$name]) * 1000.0;
        unset($this->starts[$name]);
    }

    /**
     * @return array<string, float> name => duration in milliseconds
     */
    public function all(): array
    {
        return $this->durations;
    }

    public function reset(): void
    {
        $this->starts = [];
        $this->durations = [];
    }
}
