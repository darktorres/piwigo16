<?php

declare(strict_types=1);

use Piwigo\History\Projection\HistorySummaryCount;

/**
 * @return array<string, mixed>
 */
function fullHistorySummaryCountRow(): array
{
    return [
        'year' => '2026',
        'month' => '7',
        'day' => '12',
        'hour' => '3',
        'nb_pages' => '10',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $count = HistorySummaryCount::fromRow(fullHistorySummaryCountRow());

    expect($count->year)->toBe(2026)
        ->and($count->month)->toBe(7)
        ->and($count->day)->toBe(12)
        ->and($count->hour)->toBe(3)
        ->and($count->nbPages)->toBe(10);
});

test('fromRow defaults month/day/hour to null when absent, matching the year-only bucket', function (): void {
    $row = fullHistorySummaryCountRow();
    foreach (['month', 'day', 'hour'] as $key) {
        $row[$key] = null;
    }

    $count = HistorySummaryCount::fromRow($row);

    expect($count->month)->toBeNull()
        ->and($count->day)->toBeNull()
        ->and($count->hour)->toBeNull();
    // The NOT NULL columns (year/nb_pages) fall back to their type's zero
    // value instead -- never actually null for a real fetched row.
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = HistorySummaryCount::fromRow(fullHistorySummaryCountRow())->toArray();

    expect($roundTripped)->toBe([
        'year' => 2026,
        'month' => 7,
        'day' => 12,
        'hour' => 3,
        'nb_pages' => 10,
    ]);
});
