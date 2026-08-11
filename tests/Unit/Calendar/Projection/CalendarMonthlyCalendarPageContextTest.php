<?php

declare(strict_types=1);

use Piwigo\Calendar\Projection\CalendarMonthlyCalendarPageContext;

test('toArray flattens the tpl var under the chronology_calendar key', function (): void {
    $tplVar = [
        'years' => [
            2025 => 4,
            2026 => 2,
        ],
    ];

    expect((new CalendarMonthlyCalendarPageContext($tplVar))->toArray())
        ->toBe([
            'chronology_calendar' => $tplVar,
        ]);
});
