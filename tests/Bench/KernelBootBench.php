<?php

declare(strict_types=1);

namespace Piwigo\Tests\Bench;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use Piwigo\Core\Kernel;

/**
 * Cold-boot timing for Kernel::boot() -- the one real signal available
 * this phase (DerivativeServeBench needs a fast-path that doesn't exist in
 * the new pipeline yet, P22-ish).
 *
 * The reference repo (16.x-rewrite) has zero PHPBench files at all despite
 * the plan doc's prose describing them -- same "doc promises more than the
 * reference built" pattern as P10's OpenTelemetry finding. This benchmark
 * is hand-authored, not copied from anywhere.
 *
 * Revs(1) + reset() before every iteration: Kernel::boot() is
 * idempotent-guarded, so without a fresh reset each measured call would
 * just hit the near-zero "already booted" fast path instead of a real
 * cold boot.
 */
#[BeforeMethods('reset')]
final class KernelBootBench
{
    public function reset(): void
    {
        Kernel::reset();
    }

    #[Revs(1)]
    #[Iterations(10)]
    public function benchColdBoot(): void
    {
        Kernel::boot();
    }
}
