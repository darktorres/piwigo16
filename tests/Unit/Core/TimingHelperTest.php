<?php

declare(strict_types=1);

use Piwigo\Tests\Support\PageStateTestFactory;
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

    expect($moment)->toBeGreaterThanOrEqual($before);
    expect($moment)->toBeLessThanOrEqual($after);
});

test('microSeconds returns a 16-digit numeric string (10-digit unix timestamp + 6 fractional digits)', function (): void {
    $result = TimingHelper::microSeconds();

    expect($result)->toMatch('/^\d{16}$/');
    // the first 10 digits are a real, current unix timestamp.
    expect((int) substr($result, 0, 10))->toBeGreaterThan(1_700_000_000);
});

test('microSeconds puts the real timestamp first and the real leading 6 fractional digits last, not shifted or reordered', function (): void {
    // Kills line 18's IncrementInteger (substr($t2[1], 1, 6) instead of
    // (0, 6) -- a real, current-clock fraction shifted by one
    // character) and ConcatSwitchSides (fraction-then-timestamp instead
    // of timestamp-then-fraction). The 16-digit-shape test above can't
    // distinguish either: both mutations still produce a syntactically
    // valid 16-digit string, and ConcatSwitchSides's own reordered
    // first-10-digits only OCCASIONALLY fails the ">1.7 billion" check
    // above (confirmed live: whether it does depends on the specific,
    // unrepeatable fractional digits at call time -- an unreliable
    // mutation-kill, not a real one).
    //
    // Bracketing with real microtime() calls immediately before and
    // after -- both the whole-second timestamp and the 6-digit
    // fraction are monotonically non-decreasing across 3 sequential
    // statements this fast -- gives a tight, non-flaky window neither
    // mutation can land inside: confirmed live across several runs,
    // IncrementInteger's shifted fraction and ConcatSwitchSides's
    // reordered digits both reliably fall outside it.
    $beforeParts = explode(' ', microtime());
    $beforeFraction = (int) substr(explode('.', $beforeParts[0])[1], 0, 6);
    $beforeTimestamp = (int) $beforeParts[1];

    $result = TimingHelper::microSeconds();

    $afterParts = explode(' ', microtime());
    $afterFraction = (int) substr(explode('.', $afterParts[0])[1], 0, 6);
    $afterTimestamp = (int) $afterParts[1];

    $resultTimestamp = (int) substr($result, 0, 10);
    $resultFraction = (int) substr($result, 10);

    expect($resultTimestamp)->toBeGreaterThanOrEqual($beforeTimestamp)
        ->toBeLessThanOrEqual($afterTimestamp)
        ->and($resultFraction)->toBeGreaterThanOrEqual($beforeFraction)
        ->toBeLessThanOrEqual($afterFraction);
});

/**
 * Confirmed-equivalent: line 52's RemoveDoubleCast (`$now2_float =
 * $now[1] . '.' . $now2[1];` instead of wrapping it in `(float)`). The
 * un-cast value is only ever used in `number_format($now2_float -
 * PageStateTestFactory::get()->requestStart, ...)` -- PHP's own `-` operator
 * already coerces a numeric string operand to a number identically to
 * an explicit cast (the same cast-redundant-with-implicit-operator-
 * coercion pattern already established elsewhere in this sweep).
 * Confirmed live: every test in this file passes identically with the
 * cast removed.
 */
test('debug appends a formatted line with elapsed time and query count to PageState\'s debug output', function (): void {
    PageStateTestFactory::get()->requestStart = microtime(true);
    PageStateTestFactory::get()->countQueries = 5;
    $before = PageStateTestFactory::get()->debugOutput;

    TimingHelper::debug('hello world', PageStateTestFactory::get());

    $appended = substr(PageStateTestFactory::get()->debugOutput, strlen($before));
    expect($appended)->toMatch('/^<p>\[\d+\.\d{3} s, 5 queries\] : hello world<\/p>\n$/');
});
