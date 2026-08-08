<?php

declare(strict_types=1);

use Piwigo\Calendar\Projection\CalendarChronologyPageContext;

test('toArray nests the chronology title and flattens the file view', function (): void {
    $context = new CalendarChronologyPageContext(
        fileChronologyView: 'month_calendar.tpl',
        chronologyTitle: '<a href="/index.php">2026</a>',
    );

    expect($context->toArray())->toBe([
        'FILE_CHRONOLOGY_VIEW' => 'month_calendar.tpl',
        'chronology' => ['TITLE' => '<a href="/index.php">2026</a>'],
    ]);
});
