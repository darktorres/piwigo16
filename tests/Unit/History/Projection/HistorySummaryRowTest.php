<?php

declare(strict_types=1);

use Piwigo\History\Projection\HistorySummaryRow;

/**
 * @return array<string, mixed>
 */
function fullHistorySummaryRow(): array
{
    return [
        'year' => '2026',
        'month' => '7',
        'day' => '12',
        'hour' => '3',
        'nb_pages' => '5',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $row = HistorySummaryRow::fromRow(fullHistorySummaryRow());

    expect($row->year)
        ->toBe(2026)
        ->and($row->month)
        ->toBe(7)
        ->and($row->day)
        ->toBe(12)
        ->and($row->hour)
        ->toBe(3)
        ->and($row->nbPages)
        ->toBe(5);
});

test('fromRow defaults month/day/hour to null and year/nb_pages to 0 when absent', function (): void {
    $row = HistorySummaryRow::fromRow([
        'year' => null,
        'month' => null,
        'day' => null,
        'hour' => null,
        'nb_pages' => null,
    ]);

    expect($row->year)
        ->toBe(0)
        ->and($row->month)
        ->toBeNull()
        ->and($row->day)
        ->toBeNull()
        ->and($row->hour)
        ->toBeNull()
        ->and($row->nbPages)
        ->toBe(0);
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = HistorySummaryRow::fromRow(fullHistorySummaryRow())->toArray();

    expect($roundTripped)
        ->toBe([
            'year' => 2026,
            'month' => 7,
            'day' => 12,
            'hour' => 3,
            'nb_pages' => 5,
        ]);
});
