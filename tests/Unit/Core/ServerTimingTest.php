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
