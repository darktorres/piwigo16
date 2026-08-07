<?php

declare(strict_types=1);

use Piwigo\Core\ServerTiming;

// Container-shared instance -- each test constructs its own fresh instance
// directly; no reset() needed.

test('all is empty before anything starts', function (): void {
    $timing = new ServerTiming();

    expect($timing->all())->toBe([]);
});

test('a started-but-not-stopped timing does not appear in all()', function (): void {
    $timing = new ServerTiming();
    $timing->start('db');

    expect($timing->all())->toBe([]);
});

test('stop records a non-negative duration under the started name', function (): void {
    $timing = new ServerTiming();
    $timing->start('db');
    $timing->stop('db');

    expect($timing->all())->toHaveKey('db');
    expect($timing->all()['db'])->toBeGreaterThanOrEqual(0.0);
});

test('stop records a duration in milliseconds close to the real elapsed wall-clock time', function (): void {
    // Kills line 36's MinusToPlus (`+` instead of `-`, summing two
    // ~1.7-billion-second Unix timestamps instead of subtracting them)
    // and MultiplicationToDivision (`/` instead of `*`, scaling the
    // real ~0.05-second duration down by 1e6 instead of up by 1e3):
    // both produce a result many orders of magnitude away from any
    // real elapsed-time measurement -- confirmed live (3.57 trillion
    // and 0.00005 respectively for a real ~50ms sleep). A generous but
    // still many-orders-of-magnitude-tighter range than either mutant
    // could ever land in proves the arithmetic shape itself, not just
    // "non-negative".
    $timing = new ServerTiming();
    $timing->start('db');
    usleep(50_000);
    $timing->stop('db');

    expect($timing->all()['db'] ?? null)->toBeGreaterThan(10.0)
        ->toBeLessThan(1000.0);
});

/**
 * Confirmed NOT reliably testable (not the same as equivalent): line
 * 36's DecrementFloat (999.0) and IncrementFloat (1001.0) instead of
 * 1000.0 scale the computed duration by +/-0.1%. Measured live across
 * 5 runs of a 200ms sleep each for both real code (1000.0: range
 * 200.30-201.20) and the 999.0 mutant (range 200.14-200.97): the two
 * ranges substantially OVERLAP, meaning ordinary OS scheduling jitter
 * for a sleep this short already exceeds the 0.1% signal the mutation
 * introduces. A test tight enough to reliably catch it would need
 * either an impractically long sleep (seconds, not milliseconds, to
 * make the absolute drift exceed jitter) or mocking the system clock,
 * which isn't available for this class's own bare microtime() calls.
 */

test('stop on a name that was never started is a no-op', function (): void {
    $timing = new ServerTiming();
    $timing->stop('never-started');

    expect($timing->all())->toBe([]);
});

test('multiple names are tracked independently', function (): void {
    $timing = new ServerTiming();
    $timing->start('db');
    $timing->start('render');
    $timing->stop('db');
    $timing->stop('render');

    expect(array_keys($timing->all()))->toBe(['db', 'render']);
});

test('reset clears all recorded timings', function (): void {
    $timing = new ServerTiming();
    $timing->start('db');
    $timing->stop('db');

    $timing->reset();

    expect($timing->all())->toBe([]);
});

test('start accepts an already-captured timestamp instead of capturing its own', function (): void {
    // The one real production caller (RequestBootstrap::configure(),
    // seeding the 'boot' timer from $requestStart -- captured before
    // Kernel::boot() runs, before a container-shared instance exists to
    // write into) needs this: an explicit $at must be used verbatim,
    // not overridden by a fresh microtime(true) read.
    $timing = new ServerTiming();
    $capturedEarlier = microtime(true) - 1.0;

    $timing->start('boot', $capturedEarlier);
    $timing->stop('boot');

    expect($timing->all()['boot'])->toBeGreaterThanOrEqual(1000.0);
});
