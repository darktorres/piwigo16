<?php

declare(strict_types=1);

use Piwigo\Calendar\Projection\CalendarBarEntry;
use Piwigo\Calendar\Projection\CalendarChronologyCalendar;
use Piwigo\Calendar\Projection\CalendarDayCell;
use Piwigo\Calendar\Projection\CalendarMonthlyCalendarPageContext;
use Piwigo\Calendar\Projection\CalendarMonthView;
use Piwigo\Calendar\Projection\CalendarNavBarEntry;

test('toArray carries the bar entries into chronology_calendar unflattened', function (): void {
    $bar = new CalendarBarEntry(
        uHead: '/index.php?/calendar/2026',
        nbImages: 6,
        headLabel: 2026,
        items: [
            new CalendarNavBarEntry(label: 'Jan', url: '/index.php?/calendar/2026-1', nbImages: 6),
        ],
    );

    $context = new CalendarMonthlyCalendarPageContext(calendarBars: [$bar], monthView: null);

    // month_calendar.latte reads these as objects as of P58-A's §4; the
    // wrapper VO is what carries the calendarBars XOR monthView choice the
    // two nullable keys used to encode.
    $calendar = $context->toArray()['chronology_calendar'];
    expect($calendar)
        ->toBeInstanceOf(CalendarChronologyCalendar::class);
    if (! $calendar instanceof CalendarChronologyCalendar) {
        throw new LogicException('unreachable -- asserted above');
    }

    expect($calendar->calendarBars)
        ->toBe([$bar]);
    expect($calendar->monthView)
        ->toBeNull();
});

test('toArray carries the month view into chronology_calendar unflattened', function (): void {
    $monthView = new CalendarMonthView(
        cellWidth: 120,
        cellHeight: 90,
        wdayLabels: ['Sun', 'Mon'],
        weeks: [
            [new CalendarDayCell(day: 1)],
        ],
    );

    $context = new CalendarMonthlyCalendarPageContext(calendarBars: null, monthView: $monthView);

    $calendar = $context->toArray()['chronology_calendar'];
    expect($calendar)
        ->toBeInstanceOf(CalendarChronologyCalendar::class);
    if (! $calendar instanceof CalendarChronologyCalendar) {
        throw new LogicException('unreachable -- asserted above');
    }

    expect($calendar->monthView)
        ->toBe($monthView);
    expect($calendar->calendarBars)
        ->toBeNull();
});

test('toArray still assigns chronology_calendar when both calendarBars and monthView are null', function (): void {
    $context = new CalendarMonthlyCalendarPageContext(calendarBars: null, monthView: null);

    // The key is assigned unconditionally -- month_calendar.latte branches
    // on the two members, not on the variable's own presence.
    $calendar = $context->toArray()['chronology_calendar'];
    expect($calendar)
        ->toBeInstanceOf(CalendarChronologyCalendar::class);
    if (! $calendar instanceof CalendarChronologyCalendar) {
        throw new LogicException('unreachable -- asserted above');
    }

    expect($calendar->calendarBars)
        ->toBeNull();
    expect($calendar->monthView)
        ->toBeNull();
});
