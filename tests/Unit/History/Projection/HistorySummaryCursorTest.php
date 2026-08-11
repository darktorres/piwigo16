<?php

declare(strict_types=1);

use Piwigo\History\Projection\HistorySummaryCursor;

/**
 * @return array<string, mixed>
 */
function fullHistorySummaryCursorRow(): array
{
    return [
        'year' => '2026',
        'month' => '7',
        'day' => '12',
        'hour' => '3',
        'history_id_to' => '200',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $cursor = HistorySummaryCursor::fromRow(fullHistorySummaryCursorRow());

    expect($cursor->year)
        ->toBe(2026)
        ->and($cursor->month)
        ->toBe(7)
        ->and($cursor->day)
        ->toBe(12)
        ->and($cursor->hour)
        ->toBe(3)
        ->and($cursor->historyIdTo)
        ->toBe(200);
});

test('fromRow defaults month/day/hour to null when absent, matching the year-only bucket', function (): void {
    $row = fullHistorySummaryCursorRow();
    foreach (['month', 'day', 'hour'] as $key) {
        $row[$key] = null;
    }

    $cursor = HistorySummaryCursor::fromRow($row);

    expect($cursor->month)
        ->toBeNull()
        ->and($cursor->day)
        ->toBeNull()
        ->and($cursor->hour)
        ->toBeNull();
    // The NOT NULL columns (year/history_id_to) fall back to their type's
    // zero value instead -- never actually null for a real fetched row.
});

test('fromRow defaults year/history_id_to to 0 when absent or non-numeric', function (): void {
    // Kills lines 31/35's DecrementInteger/IncrementInteger on the
    // hardcoded 0 fallback for year/historyIdTo -- the sibling test
    // above only asserts month/day/hour default to null, never checking
    // the exact fallback value for these two NOT NULL columns.
    $row = fullHistorySummaryCursorRow();
    $row['year'] = null;
    $row['history_id_to'] = null;

    $cursor = HistorySummaryCursor::fromRow($row);

    expect($cursor->year)
        ->toBe(0)
        ->and($cursor->historyIdTo)
        ->toBe(0);
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = HistorySummaryCursor::fromRow(fullHistorySummaryCursorRow())->toArray();

    expect($roundTripped)
        ->toBe([
            'year' => 2026,
            'month' => 7,
            'day' => 12,
            'hour' => 3,
            'history_id_to' => 200,
        ]);
});
