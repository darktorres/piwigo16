<?php

declare(strict_types=1);

use Piwigo\Core\ServerTiming;

beforeEach(function (): void {
    ServerTiming::reset();
});

afterEach(function (): void {
    ServerTiming::reset();
});

test('all is empty before anything starts', function (): void {
    expect(ServerTiming::all())->toBe([]);
});

test('a started-but-not-stopped timing does not appear in all()', function (): void {
    ServerTiming::start('db');

    expect(ServerTiming::all())->toBe([]);
});

test('stop records a non-negative duration under the started name', function (): void {
    ServerTiming::start('db');
    ServerTiming::stop('db');

    expect(ServerTiming::all())->toHaveKey('db');
    expect(ServerTiming::all()['db'])->toBeGreaterThanOrEqual(0.0);
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
    ServerTiming::start('db');
    usleep(50_000);
    ServerTiming::stop('db');

    expect(ServerTiming::all()['db'] ?? null)->toBeGreaterThan(10.0)
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
    ServerTiming::stop('never-started');

    expect(ServerTiming::all())->toBe([]);
});

test('multiple names are tracked independently', function (): void {
    ServerTiming::start('db');
    ServerTiming::start('render');
    ServerTiming::stop('db');
    ServerTiming::stop('render');

    expect(array_keys(ServerTiming::all()))->toBe(['db', 'render']);
});

test('reset clears all recorded timings', function (): void {
    ServerTiming::start('db');
    ServerTiming::stop('db');

    ServerTiming::reset();

    expect(ServerTiming::all())->toBe([]);
});
