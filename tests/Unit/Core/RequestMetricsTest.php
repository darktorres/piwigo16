<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\RequestMetrics;
use Piwigo\Tests\Support\RequestMetricsTestFactory;

// Container-shared instance -- each test constructs its own fresh instance
// directly; no reset() needed.

afterEach(function (): void {
    Kernel::reset();
});

test('a fresh instance starts at its zero defaults', function (): void {
    $metrics = new RequestMetrics();

    expect($metrics->executionUuid)
        ->toBe('')
        ->and($metrics->countQueries)
        ->toBe(0)
        ->and($metrics->queriesTime)
        ->toBe(0.0)
        ->and($metrics->requestStart)
        ->toBe(0.0)
        ->and($metrics->debugOutput)
        ->toBe('');
});

test('addQueryTime accumulates count and time', function (): void {
    $metrics = new RequestMetrics();
    $metrics->addQueryTime(0.5);
    $metrics->addQueryTime(0.25);

    expect($metrics->countQueries)
        ->toBe(2)
        ->and($metrics->queriesTime)
        ->toBe(0.75);
});

test('addDebugOutput appends to prior debug output rather than replacing it', function (): void {
    // Kills the equivalent ConcatEqualToEqual mutation (`=` instead of
    // `.=`): a single call can't distinguish append from overwrite (both
    // start from the same empty string) -- a second call is needed to
    // prove the first line survives.
    $metrics = new RequestMetrics();
    $metrics->addDebugOutput('first line');
    $metrics->addDebugOutput('second line');

    expect($metrics->debugOutput)
        ->toBe('first linesecond line');
});

test('reset clears every property back to its constructed default', function (): void {
    $metrics = new RequestMetrics();
    $metrics->executionUuid = 'some-uuid';
    $metrics->addQueryTime(0.5);
    $metrics->requestStart = 123.456;
    $metrics->addDebugOutput('debug line');

    $metrics->reset();

    $fresh = new RequestMetrics();

    expect($metrics->executionUuid)
        ->toBe($fresh->executionUuid)
        ->and($metrics->countQueries)
        ->toBe($fresh->countQueries)
        ->and($metrics->queriesTime)
        ->toBe($fresh->queriesTime)
        ->and($metrics->requestStart)
        ->toBe($fresh->requestStart)
        ->and($metrics->debugOutput)
        ->toBe($fresh->debugOutput);
});

test('RequestMetricsTestFactory::get falls back to a memoized instance when Kernel is not booted', function (): void {
    // Memoized (not fresh-per-call), same reasoning as PageStateTestFactory::get():
    // a caller that writes via get() in one call and reads via get() in a
    // later call must see the same instance, or the write would be lost.
    $first = RequestMetricsTestFactory::get();
    $first->reset();
    $first->addQueryTime(1.0);

    $second = RequestMetricsTestFactory::get();

    expect($second)
        ->toBe($first)
        ->and($second->countQueries)
        ->toBe(1);
});

test('RequestMetricsTestFactory::get resolves the container-shared instance once Kernel is booted', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-request-metrics-test'));

    $instance = Kernel::container()->get(RequestMetrics::class);

    expect(RequestMetricsTestFactory::get())->toBe($instance);
});
