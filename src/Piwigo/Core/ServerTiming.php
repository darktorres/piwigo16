<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Stopwatch registry, shared as a single instance via the container.
 *
 * `Piwigo\Bootstrap\RequestBootstrap` writes to it (via its own private
 * `serverTiming()` resolver) and
 * `Piwigo\Http\Middleware\ServerTimingMiddleware` reads it, via real
 * constructor injection -- always container-resolved, never manually
 * `new`'d.
 *
 * `start('boot')`/`stop('boot')` bracket work that begins
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
