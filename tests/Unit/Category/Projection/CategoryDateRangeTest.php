<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryDateRange;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryDateRange('2019-06-15 10:00:00', '2019-06-15 10:00:00');

    expect($row->from)
        ->toBe('2019-06-15 10:00:00')
        ->and($row->to)
        ->toBe('2019-06-15 10:00:00');
});

test('accepts a null from/to', function (): void {
    $row = new CategoryDateRange(null, null);

    expect($row->from)
        ->toBeNull()
        ->and($row->to)
        ->toBeNull();
});
