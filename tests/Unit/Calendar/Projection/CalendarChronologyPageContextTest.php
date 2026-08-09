<?php

declare(strict_types=1);

use Piwigo\Calendar\Projection\CalendarChronologyPageContext;

test('toArray nests the chronology title, flattens the file view, and includes the navigation bars', function (): void {
    $context = new CalendarChronologyPageContext(
        fileChronologyView: 'month_calendar.tpl',
        chronologyTitle: '<a href="/index.php">2026</a>',
        chronologyNavigationBars: [['items' => [['LABEL' => '2026', 'URL' => '/index.php?/calendar/2026']]]],
    );

    expect($context->toArray())->toBe([
        'FILE_CHRONOLOGY_VIEW' => 'month_calendar.tpl',
        'chronology' => ['TITLE' => '<a href="/index.php">2026</a>'],
        'chronology_navigation_bars' => [['items' => [['LABEL' => '2026', 'URL' => '/index.php?/calendar/2026']]]],
    ]);
});

test('toArray includes an empty chronology_navigation_bars array (not omitted)', function (): void {
    $context = new CalendarChronologyPageContext(
        fileChronologyView: 'month_calendar.tpl',
        chronologyTitle: '<a href="/index.php">2026</a>',
        chronologyNavigationBars: [],
    );

    expect($context->toArray()['chronology_navigation_bars'])->toBe([]);
});
