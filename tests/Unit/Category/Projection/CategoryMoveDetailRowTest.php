<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryMoveDetailRow;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryMoveDetailRow(2, 'Nested Sub Album', null, '1,2');

    expect($row->id)
        ->toBe(2)
        ->and($row->name)
        ->toBe('Nested Sub Album')
        ->and($row->dir)
        ->toBeNull()
        ->and($row->uppercats)
        ->toBe('1,2');
});

test('accepts a non-null dir', function (): void {
    $row = new CategoryMoveDetailRow(1, 'Physical Album', 'physical_album', '1');

    expect($row->dir)
        ->toBe('physical_album');
});
