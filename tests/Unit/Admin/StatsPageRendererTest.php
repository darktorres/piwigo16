<?php

declare(strict_types=1);

use Piwigo\Admin\StatsPageRenderer;
use Piwigo\History\Projection\HistorySummaryRow;

/**
 * Piwigo\Admin\StatsPageRenderer::render() itself needs a real HistoryService
 * whose own summarize() call performs a real, bulk piwigo_history/
 * piwigo_history_summary write (see tests/Browser/StatsPageRendererTest.php
 * for that coverage) -- HistoryRepositoryTest.php already actively manages
 * piwigo_history_summary via its own historyTestInsertSummary()/
 * historyTestClearSummary() helpers, so calling summarize() here would be
 * a genuine new write path into a table another Unit file owns, not just
 * a read. setMissingValues()/getDateObject() are pure, public static, and
 * directly testable in isolation, same pattern
 * CatModifyPageRendererTest.php already established for this exact class
 * of "render() needs real DB/template state, but a real helper method
 * doesn't" split.
 */
test('getDateObject builds a year-only DateTime when month is null', function (): void {
    $date = StatsPageRenderer::getDateObject(new HistorySummaryRow(year: 2026, month: null, day: null, hour: null, nbPages: 5));

    expect($date->format('Y-m-d'))
        ->toBe('2026-01-01');
});

test('getDateObject cascades through month/day/hour as each is provided', function (): void {
    $monthOnly = StatsPageRenderer::getDateObject(new HistorySummaryRow(year: 2026, month: 7, day: null, hour: null, nbPages: 5));
    expect($monthOnly->format('Y-m-d'))
        ->toBe('2026-07-01');

    $dayOnly = StatsPageRenderer::getDateObject(new HistorySummaryRow(year: 2026, month: 7, day: 12, hour: null, nbPages: 5));
    expect($dayOnly->format('Y-m-d'))
        ->toBe('2026-07-12');

    $withHour = StatsPageRenderer::getDateObject(new HistorySummaryRow(year: 2026, month: 7, day: 12, hour: 14, nbPages: 5));
    expect($withHour->format('Y-m-d H:i'))
        ->toBe('2026-07-12 14:00');
});

test('setMissingValues fills every day bucket between first and last date with zero, then overlays real rows', function (): void {
    $data = [
        new HistorySummaryRow(year: 2026, month: 7, day: 12, hour: null, nbPages: 10),
        new HistorySummaryRow(year: 2026, month: 7, day: 10, hour: null, nbPages: 4),
    ];

    $result = StatsPageRenderer::setMissingValues('day', $data, new DateTime('2026-07-10'), new DateTime('2026-07-12'));

    expect($result)
        ->toBe([
            '2026-07-10' => 4,
            '2026-07-11' => 0,
            '2026-07-12' => 10,
        ]);
});

test('setMissingValues sums multiple real rows landing in the same bucket', function (): void {
    $data = [
        new HistorySummaryRow(year: 2026, month: 7, day: 10, hour: 14, nbPages: 3),
        new HistorySummaryRow(year: 2026, month: 7, day: 10, hour: 15, nbPages: 5),
    ];

    // Both rows fall in the same 'month' bucket (2026-07) -- proves the
    // `+=` accumulation, not a last-value-wins overwrite.
    $result = StatsPageRenderer::setMissingValues('month', $data, new DateTime('2026-07-01'), new DateTime('2026-07-01'));

    expect($result)
        ->toBe([
            '2026-07' => 8,
        ]);
});

test('setMissingValues defaults first/last date from the data itself when neither is given', function (): void {
    // $data is expected sorted DESC (matching HistoryService::getLastByType()'s
    // own real ordering) -- $data[0] is the most recent, $data[count-1] the
    // oldest, which is exactly what the no-explicit-dates fallback reads.
    $data = [
        new HistorySummaryRow(year: 2026, month: null, day: null, hour: null, nbPages: 9),
        new HistorySummaryRow(year: 2024, month: null, day: null, hour: null, nbPages: 3),
    ];

    $result = StatsPageRenderer::setMissingValues('year', $data);

    expect($result)
        ->toBe([
            '2024' => 3,
            '2025' => 0,
            '2026' => 9,
        ]);
});

test('setMissingValues throws for an unrecognized unit', function (): void {
    expect(fn (): array => StatsPageRenderer::setMissingValues('century', [
        new HistorySummaryRow(year: 2026, month: null, day: null, hour: null, nbPages: 1),
    ], new DateTime('2026-01-01'), new DateTime('2026-01-01')))
        ->toThrow(InvalidArgumentException::class, 'Invalid unit: century');
});
