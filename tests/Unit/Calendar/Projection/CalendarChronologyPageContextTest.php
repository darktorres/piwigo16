<?php

declare(strict_types=1);

use Latte\Runtime\Html;
use Piwigo\Calendar\Projection\CalendarChronologyPageContext;
use Piwigo\Calendar\Projection\CalendarNavBarEntry;
use Piwigo\Calendar\Projection\ChronologyNavBarRow;

test('toArray nests the chronology title and passes the navigation bar rows through unflattened, omitting chronology_views when null', function (): void {
    $row = new ChronologyNavBarRow(items: [
        new CalendarNavBarEntry(label: '2026', url: '/index.php?/calendar/2026', nbImages: null),
    ]);

    $context = new CalendarChronologyPageContext(
        fileChronologyView: 'month_calendar.latte',
        chronologyTitle: new Html('<a href="/index.php">2026</a>'),
        chronologyNavigationBars: [$row],
        chronologyViews: null,
    );

    $result = $context->toArray();

    // The rows reach month_calendar.latte as objects as of P58-A's §4:
    // toArray() no longer renames LABEL/URL, and the identity assertion
    // below is what says so -- an equality one would still pass against a
    // flatten that happened to round-trip. Split from the rest of the
    // array (toEqual(), not toBe()) only because chronologyTitle is now
    // Html (P59), a second, separately-constructed instance -- toBe()'s
    // own identity check would fail on that value alone, not on $row.
    expect($result['chronology_navigation_bars'])
        ->toBe([$row])
        ->and($result)
        ->toEqual([
            'FILE_CHRONOLOGY_VIEW' => 'month_calendar.latte',
            'chronology' => [
                'TITLE' => new Html('<a href="/index.php">2026</a>'),
            ],
            'chronology_navigation_bars' => [$row],
        ]);
});

test('toArray includes an empty chronology_navigation_bars array (not omitted)', function (): void {
    $context = new CalendarChronologyPageContext(
        fileChronologyView: 'month_calendar.latte',
        chronologyTitle: new Html('<a href="/index.php">2026</a>'),
        chronologyNavigationBars: [],
        chronologyViews: null,
    );

    expect($context->toArray()['chronology_navigation_bars'])->toBe([]);
});

test('toArray includes chronology_views when set', function (): void {
    $context = new CalendarChronologyPageContext(
        fileChronologyView: 'month_calendar.latte',
        chronologyTitle: new Html('<a href="/index.php">2026</a>'),
        chronologyNavigationBars: [],
        chronologyViews: [[
            'VALUE' => '/index.php?/calendar',
            'CONTENT' => 'monthly - calendar',
            'SELECTED' => true,
        ]],
    );

    expect($context->toArray()['chronology_views'])->toBe([[
        'VALUE' => '/index.php?/calendar',
        'CONTENT' => 'monthly - calendar',
        'SELECTED' => true,
    ]]);
});
