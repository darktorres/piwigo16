<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryListingRow;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryListingRow(2, 'Nested Sub Album', null, 1, '1,2', '1.1');

    expect($row->id)
        ->toBe(2)
        ->and($row->name)
        ->toBe('Nested Sub Album')
        ->and($row->permalink)
        ->toBeNull()
        ->and($row->idUppercat)
        ->toBe(1)
        ->and($row->uppercats)
        ->toBe('1,2')
        ->and($row->globalRank)
        ->toBe('1.1');
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new CategoryListingRow(2, 'Nested Sub Album', 'nested-sub-album', 1, '1,2', '1.1');

    expect($row->toArray())
        ->toBe([
            'id' => 2,
            'name' => 'Nested Sub Album',
            'permalink' => 'nested-sub-album',
            'id_uppercat' => 1,
            'uppercats' => '1,2',
            'global_rank' => '1.1',
        ]);
});
