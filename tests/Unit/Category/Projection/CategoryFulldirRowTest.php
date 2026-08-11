<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryFulldirRow;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryFulldirRow(2, '1,2', 1);

    expect($row->id)
        ->toBe(2)
        ->and($row->uppercats)
        ->toBe('1,2')
        ->and($row->siteId)
        ->toBe(1);
});

test('accepts a null siteId', function (): void {
    $row = new CategoryFulldirRow(2, '1,2', null);

    expect($row->siteId)
        ->toBeNull();
});
