<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryMoveRow;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryMoveRow(2, 1, 'public', '1,2');

    expect($row->id)
        ->toBe(2)
        ->and($row->idUppercat)
        ->toBe(1)
        ->and($row->status)
        ->toBe('public')
        ->and($row->uppercats)
        ->toBe('1,2');
});

test('accepts a null idUppercat', function (): void {
    $row = new CategoryMoveRow(1, null, 'public', '1');

    expect($row->idUppercat)
        ->toBeNull();
});
