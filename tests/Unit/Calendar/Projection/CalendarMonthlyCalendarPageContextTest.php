<?php

declare(strict_types=1);

use Piwigo\Calendar\Projection\CalendarBarEntry;
use Piwigo\Calendar\Projection\CalendarDayCell;
use Piwigo\Calendar\Projection\CalendarMonthlyCalendarPageContext;
use Piwigo\Calendar\Projection\CalendarMonthView;
use Piwigo\Calendar\Projection\CalendarNavBarEntry;

test('toArray nests calendar_bars under chronology_calendar when set', function (): void {
    $context = new CalendarMonthlyCalendarPageContext(
        calendarBars: [
            new CalendarBarEntry(
                uHead: '/index.php?/calendar/2026',
                nbImages: 6,
                headLabel: 2026,
                items: [
                    new CalendarNavBarEntry(label: 'Jan', url: '/index.php?/calendar/2026-1', nbImages: 6),
                ],
            ),
        ],
        monthView: null,
    );

    expect($context->toArray())
        ->toBe([
            'chronology_calendar' => [
                'calendar_bars' => [
                    [
                        'U_HEAD' => '/index.php?/calendar/2026',
                        'NB_IMAGES' => 6,
                        'HEAD_LABEL' => 2026,
                        'items' => [
                            [
                                'LABEL' => 'Jan',
                                'URL' => '/index.php?/calendar/2026-1',
                                'NB_IMAGES' => 6,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
});

test('toArray nests month_view under chronology_calendar when set', function (): void {
    $context = new CalendarMonthlyCalendarPageContext(
        calendarBars: null,
        monthView: new CalendarMonthView(
            cellWidth: 120,
            cellHeight: 90,
            wdayLabels: ['Sun', 'Mon'],
            weeks: [
                [new CalendarDayCell(day: 1)],
            ],
        ),
    );

    expect($context->toArray())
        ->toBe([
            'chronology_calendar' => [
                'month_view' => [
                    'CELL_WIDTH' => 120,
                    'CELL_HEIGHT' => 90,
                    'wday_labels' => ['Sun', 'Mon'],
                    'weeks' => [
                        [[
                            'DAY' => 1,
                        ]],
                    ],
                ],
            ],
        ]);
});

test('toArray returns an empty chronology_calendar when both calendarBars and monthView are null', function (): void {
    $context = new CalendarMonthlyCalendarPageContext(calendarBars: null, monthView: null);

    expect($context->toArray())
        ->toBe([
            'chronology_calendar' => [],
        ]);
});
