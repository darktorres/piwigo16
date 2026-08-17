<?php

declare(strict_types=1);

use Piwigo\Calendar\CalendarQueryScope;
use Piwigo\Calendar\CalendarWeekly;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Permission\SqlCondition;
use Piwigo\Tests\Support\CurrentConfigTestFactory;

/**
 * Piwigo\Calendar\CalendarWeekly -- weekly calendar style (composed of
 * years/weeks in years and days in week). No dedicated Integration/
 * Browser spec of its own.
 *
 * `initialize()` and `getDateWhere()` are both real, testable logic
 * given a real, container-resolved instance: `initialize()` builds
 * `calendar_levels`/`date_field`/`date_field_dql` from real config +
 * `Lang::days()`/`Lang::t()` output (no DB access), and
 * `getDateWhere()` is pure logic once `chronology_date` is set (a
 * public, directly-settable property, matching this file's own
 * documented mutable-state contract).
 */
function calendarWeeklyTestSubject(): CalendarWeekly
{
    $subject = Kernel::container()->get(CalendarWeekly::class);
    if (! $subject instanceof CalendarWeekly) {
        throw new LogicException('Container returned an unexpected type for ' . CalendarWeekly::class);
    }

    return $subject;
}

function calendarWeeklyTestScope(): CalendarQueryScope
{
    return new CalendarQueryScope('', false, SqlCondition::fromRawSql(''), SqlCondition::fromRawSql(''));
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

test('initialize sets date_field/date_field_dql to date_available for a "posted" chronology field', function (): void {
    $calendar = calendarWeeklyTestSubject();
    $calendar->chronology_field = 'posted';

    $calendar->initialize(calendarWeeklyTestScope());

    expect($calendar->date_field)
        ->toBe('date_available')
        ->and($calendar->date_field_dql)
        ->toBe('i.dateAvailable');
});

test('initialize sets date_field/date_field_dql to date_creation for any non-"posted" chronology field', function (): void {
    $calendar = calendarWeeklyTestSubject();
    $calendar->chronology_field = 'created';

    $calendar->initialize(calendarWeeklyTestScope());

    expect($calendar->date_field)
        ->toBe('date_creation')
        ->and($calendar->date_field_dql)
        ->toBe('i.dateCreation');
});

test('initialize builds the Monday-first week/day SQL expressions when weekStartsOn is monday', function (): void {
    CurrentConfigTestFactory::get()->weekStartsOn = 'monday';
    $calendar = calendarWeeklyTestSubject();
    $calendar->chronology_field = 'created';

    $calendar->initialize(calendarWeeklyTestScope());

    expect($calendar->calendar_levels[CalendarWeekly::CWEEK]['sql'])->toBe('WEEK(date_creation, 5)+1')
        ->and($calendar->calendar_levels[CalendarWeekly::CDAY]['sql'])->toBe('WEEKDAY(date_creation)');
});

test('initialize keeps the default Sunday-first week/day SQL expressions when weekStartsOn is not monday', function (): void {
    CurrentConfigTestFactory::get()->weekStartsOn = 'sunday';
    $calendar = calendarWeeklyTestSubject();
    $calendar->chronology_field = 'created';

    $calendar->initialize(calendarWeeklyTestScope());

    expect($calendar->calendar_levels[CalendarWeekly::CWEEK]['sql'])->toBe('WEEK(date_creation)+1')
        ->and($calendar->calendar_levels[CalendarWeekly::CDAY]['sql'])->toBe('DAYOFWEEK(date_creation)-1');
});

test('initialize populates 53 real week-number labels', function (): void {
    $calendar = calendarWeeklyTestSubject();
    $calendar->chronology_field = 'created';

    $calendar->initialize(calendarWeeklyTestScope());

    $weekLabels = $calendar->calendar_levels[CalendarWeekly::CWEEK]['labels'];
    expect($weekLabels)
        ->toHaveCount(53);
    if (is_array($weekLabels)) {
        expect($weekLabels[1])->not->toBe('')
            ->and($weekLabels[53])->not->toBe('');
    }
});

test('getDateWhere returns the real IS NOT NULL fallback for an empty chronology_date', function (): void {
    $calendar = calendarWeeklyTestSubject();
    $calendar->chronology_field = 'created';
    $calendar->initialize(calendarWeeklyTestScope());
    $calendar->chronology_date = [];

    $condition = $calendar->getDateWhere();

    expect((string) $condition->expr)
        ->toBe(' AND date_creation IS NOT NULL')
        ->and($condition->parameters)
        ->toBe([]);
});

test('getDateWhere builds a real year-range condition for a single-level chronology_date', function (): void {
    $calendar = calendarWeeklyTestSubject();
    $calendar->chronology_field = 'created';
    $calendar->initialize(calendarWeeklyTestScope());
    $calendar->chronology_date = [2026];

    $condition = $calendar->getDateWhere();

    expect((string) $condition->expr)
        ->toBe(' AND date_creation BETWEEN :dateWhereYearStart AND :dateWhereYearEnd')
        ->and($condition->parameters)
        ->toBe([
            'dateWhereYearStart' => '2026-01-01',
            'dateWhereYearEnd' => '2026-12-31 23:59:59',
        ]);
});

test('getDateWhere builds a year+week+day condition, stripping the leading AND for DQL', function (): void {
    $calendar = calendarWeeklyTestSubject();
    $calendar->chronology_field = 'created';
    $calendar->initialize(calendarWeeklyTestScope());
    $calendar->chronology_date = [2026, 15, 3];

    $sqlCondition = $calendar->getDateWhere(3, false);
    $dqlCondition = $calendar->getDateWhere(3, true);

    expect((string) $sqlCondition->expr)
        ->toBe(' AND date_creation BETWEEN :dateWhereYearStart AND :dateWhereYearEnd AND WEEK(date_creation, 5)+1= :dateWhereWeek AND WEEKDAY(date_creation)= :dateWhereDay')
        ->and($sqlCondition->parameters)
        ->toBe([
            'dateWhereYearStart' => '2026-01-01',
            'dateWhereYearEnd' => '2026-12-31 23:59:59',
            'dateWhereWeek' => 15,
            'dateWhereDay' => 3,
        ])
        ->and((string) $dqlCondition->expr)
        ->toBe('i.dateCreation BETWEEN :dateWhereYearStart AND :dateWhereYearEnd AND WEEK(i.dateCreation, 5)+1= :dateWhereWeek AND WEEKDAY(i.dateCreation)= :dateWhereDay');
});

test('getDateWhere respects max_levels, dropping deeper chronology_date components', function (): void {
    $calendar = calendarWeeklyTestSubject();
    $calendar->chronology_field = 'created';
    $calendar->initialize(calendarWeeklyTestScope());
    $calendar->chronology_date = [2026, 15, 3];

    $condition = $calendar->getDateWhere(1);

    expect((string) $condition->expr)
        ->toBe(' AND date_creation BETWEEN :dateWhereYearStart AND :dateWhereYearEnd')
        ->and($condition->parameters)
        ->not->toHaveKey('dateWhereWeek');
});

test('getDateWhere treats an "any" chronology_date component as unset', function (): void {
    $calendar = calendarWeeklyTestSubject();
    $calendar->chronology_field = 'created';
    $calendar->initialize(calendarWeeklyTestScope());
    $calendar->chronology_date = ['any'];

    $condition = $calendar->getDateWhere();

    expect((string) $condition->expr)
        ->toBe(' AND date_creation IS NOT NULL');
});
