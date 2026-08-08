<?php

declare(strict_types=1);

use Piwigo\Category\Projection\PhotoCountDateRange;

test('constructs with distinct values for every property', function (): void {
    $row = new PhotoCountDateRange(3, '2026-08-01', '2026-08-02');

    expect($row->count)->toBe(3)
        ->and($row->minDate)->toBe('2026-08-01')
        ->and($row->maxDate)->toBe('2026-08-02');
});

test('accepts a null minDate/maxDate', function (): void {
    $row = new PhotoCountDateRange(0, null, null);

    expect($row->minDate)->toBeNull()
        ->and($row->maxDate)->toBeNull();
});
