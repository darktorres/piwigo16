<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryRankUpdateRow;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryRankUpdateRow(2, 1, '1,2', 1, '1.1');

    expect($row->id)
        ->toBe(2)
        ->and($row->idUppercat)
        ->toBe(1)
        ->and($row->uppercats)
        ->toBe('1,2')
        ->and($row->rank)
        ->toBe(1)
        ->and($row->globalRank)
        ->toBe('1.1');
});

test('accepts a null idUppercat/rank/globalRank', function (): void {
    $row = new CategoryRankUpdateRow(1, null, '1', null, null);

    expect($row->idUppercat)
        ->toBeNull()
        ->and($row->rank)
        ->toBeNull()
        ->and($row->globalRank)
        ->toBeNull();
});
