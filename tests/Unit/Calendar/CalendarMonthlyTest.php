<?php

declare(strict_types=1);

use Piwigo\Calendar\CalendarMonthly;
use Piwigo\Calendar\CalendarQueryScope;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Permission\SqlCondition;

/**
 * Piwigo\Calendar\CalendarMonthly -- monthly calendar style (years/
 * months/days). No dedicated Integration/Browser spec of its own.
 *
 * `initialize()` and `get_date_where()` are both real, testable logic
 * given a real, container-resolved instance -- same rationale as
 * `CalendarWeeklyTest.php`.
 */
function calendarMonthlyTestSubject(): CalendarMonthly
{
    $subject = Kernel::container()->get(CalendarMonthly::class);
    if (! $subject instanceof CalendarMonthly) {
        throw new LogicException('Container returned an unexpected type for ' . CalendarMonthly::class);
    }

    return $subject;
}

function calendarMonthlyTestScope(): CalendarQueryScope
{
    return new CalendarQueryScope(new SqlCondition(''), false, new SqlCondition(''));
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('initialize sets date_field/date_field_dql to date_available for a "posted" chronology field', function (): void {
    $calendar = calendarMonthlyTestSubject();
    $calendar->chronology_field = 'posted';

    $calendar->initialize(calendarMonthlyTestScope());

    expect($calendar->date_field)->toBe('date_available')
        ->and($calendar->date_field_dql)->toBe('i.dateAvailable');
});

test('initialize builds the real year/month/day SQL expressions, with Lang::months()\'s own real output as the month labels', function (): void {
    $calendar = calendarMonthlyTestSubject();
    $calendar->chronology_field = 'created';

    $calendar->initialize(calendarMonthlyTestScope());

    // Lang::months() itself is not asserted to be non-empty here -- this
    // bare, freshly-booted container never loads a real language file
    // (Lang::$data stays empty, see langArrayGroup()'s own fallback), so
    // the real value in this specific test context is `[]`. The point of
    // comparing against a second, independent Lang::months() call
    // (rather than hardcoding `[]`) is proving initialize() really reads
    // it live, not that it's non-empty.
    $lang = Kernel::container()->get(Lang::class);
    $monthLabels = $lang instanceof Lang ? $lang->months() : null;

    expect($calendar->calendar_levels[CalendarMonthly::CYEAR]['sql'])->toBe('YEAR(date_creation)')
        ->and($calendar->calendar_levels[CalendarMonthly::CYEAR]['labels'])->toBeNull()
        ->and($calendar->calendar_levels[CalendarMonthly::CMONTH]['sql'])->toBe('MONTH(date_creation)')
        ->and($calendar->calendar_levels[CalendarMonthly::CMONTH]['labels'])->toBe($monthLabels)
        ->and($calendar->calendar_levels[CalendarMonthly::CDAY]['sql'])->toBe('DAYOFMONTH(date_creation)')
        ->and($calendar->calendar_levels[CalendarMonthly::CDAY]['labels'])->toBeNull();
});

test('get_date_where returns the real IS NOT NULL fallback for an empty chronology_date', function (): void {
    $calendar = calendarMonthlyTestSubject();
    $calendar->chronology_field = 'created';
    $calendar->initialize(calendarMonthlyTestScope());
    $calendar->chronology_date = [];

    $condition = $calendar->get_date_where();

    expect($condition->sql)->toBe(' AND date_creation IS NOT NULL')
        ->and($condition->parameters)->toBe([]);
});

test('get_date_where builds a real full-year range for a single-level chronology_date', function (): void {
    $calendar = calendarMonthlyTestSubject();
    $calendar->chronology_field = 'created';
    $calendar->initialize(calendarMonthlyTestScope());
    $calendar->chronology_date = [2026];

    $condition = $calendar->get_date_where();

    expect($condition->sql)->toBe(' AND date_creation BETWEEN :dateWhereStart AND :dateWhereEnd')
        ->and($condition->parameters)->toBe(['dateWhereStart' => '2026-01-01', 'dateWhereEnd' => '2026-12-31 23:59:59']);
});

test('get_date_where builds a real full-month range when only year+month are set', function (): void {
    $calendar = calendarMonthlyTestSubject();
    $calendar->chronology_field = 'created';
    $calendar->initialize(calendarMonthlyTestScope());
    $calendar->chronology_date = [2026, 2];

    $condition = $calendar->get_date_where();

    // 2026 is not a leap year (2026 / 4 is not an integer) -- February has 28 days.
    expect($condition->sql)->toBe(' AND date_creation BETWEEN :dateWhereStart AND :dateWhereEnd')
        ->and($condition->parameters)->toBe(['dateWhereStart' => '2026-02-01', 'dateWhereEnd' => '2026-02-28 23:59:59']);
});

test('get_date_where builds a real single-day range for a full year+month+day chronology_date', function (): void {
    $calendar = calendarMonthlyTestSubject();
    $calendar->chronology_field = 'created';
    $calendar->initialize(calendarMonthlyTestScope());
    $calendar->chronology_date = [2026, 2, 14];

    $sqlCondition = $calendar->get_date_where(3, false);
    $dqlCondition = $calendar->get_date_where(3, true);

    expect($sqlCondition->sql)->toBe(' AND date_creation BETWEEN :dateWhereStart AND :dateWhereEnd')
        ->and($sqlCondition->parameters)->toBe(['dateWhereStart' => '2026-02-14', 'dateWhereEnd' => '2026-02-14 23:59:59'])
        ->and($dqlCondition->sql)->toBe('i.dateCreation BETWEEN :dateWhereStart AND :dateWhereEnd');
});

test('get_date_where filters by month/day alone (via calendar_levels) when the year is "any"', function (): void {
    $calendar = calendarMonthlyTestSubject();
    $calendar->chronology_field = 'created';
    $calendar->initialize(calendarMonthlyTestScope());
    $calendar->chronology_date = ['any', 6, 15];

    $condition = $calendar->get_date_where();

    expect($condition->sql)->toBe(' AND date_creation IS NOT NULL AND MONTH(date_creation)= :dateWhereMonth AND DAYOFMONTH(date_creation)= :dateWhereDay')
        ->and($condition->parameters)->toBe(['dateWhereMonth' => 6, 'dateWhereDay' => 15]);
});

test('get_date_where respects max_levels, dropping the day component from a full chronology_date', function (): void {
    $calendar = calendarMonthlyTestSubject();
    $calendar->chronology_field = 'created';
    $calendar->initialize(calendarMonthlyTestScope());
    $calendar->chronology_date = [2026, 2, 14];

    $condition = $calendar->get_date_where(2);

    expect($condition->sql)->toBe(' AND date_creation BETWEEN :dateWhereStart AND :dateWhereEnd')
        ->and($condition->parameters)->toBe(['dateWhereStart' => '2026-02-01', 'dateWhereEnd' => '2026-02-28 23:59:59']);
});
