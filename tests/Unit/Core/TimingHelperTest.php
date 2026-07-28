<?php

declare(strict_types=1);

use Piwigo\Core\PageState;
use Piwigo\Core\TimingHelper;

/**
 * Piwigo\Core\TimingHelper -- had zero dedicated coverage (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1).
 * getElapsedTime() is deterministic (plain float subtraction); getMoment()/
 * microSeconds()/debug() all read the real wall clock, so those are
 * asserted on shape/format rather than an exact value to avoid timing
 * flakiness.
 */
test('getElapsedTime formats the difference to 3 decimal places with a trailing " s"', function (): void {
    expect(TimingHelper::getElapsedTime(100.0, 100.5))->toBe('0.500 s');
    expect(TimingHelper::getElapsedTime(100.123456, 105.987654))->toBe('5.864 s');
});

test('getMoment returns a float (microtime with microsecond precision)', function (): void {
    $before = microtime(true);
    $moment = TimingHelper::getMoment();
    $after = microtime(true);

    expect($moment)->toBeFloat();
    expect($moment)->toBeGreaterThanOrEqual($before);
    expect($moment)->toBeLessThanOrEqual($after);
});

test('microSeconds returns a 16-digit numeric string (10-digit unix timestamp + 6 fractional digits)', function (): void {
    $result = TimingHelper::microSeconds();

    expect($result)->toMatch('/^\d{16}$/');
    // the first 10 digits are a real, current unix timestamp.
    expect((int) substr($result, 0, 10))->toBeGreaterThan(1_700_000_000);
});

test('debug appends a formatted line with elapsed time and query count to PageState\'s debug output', function (): void {
    PageState::current()->requestStart = microtime(true);
    PageState::current()->countQueries = 5;
    $before = PageState::current()->debugOutput;

    TimingHelper::debug('hello world');

    $appended = substr(PageState::current()->debugOutput, strlen($before));
    expect($appended)->toMatch('/^<p>\[\d+\.\d{3} s, 5 queries\] : hello world<\/p>\n$/');
});
