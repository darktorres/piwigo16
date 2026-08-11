<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryNextRankByParentRow;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryNextRankByParentRow(1, 3);

    expect($row->idUppercat)
        ->toBe(1)
        ->and($row->nextRank)
        ->toBe(3);
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new CategoryNextRankByParentRow(null, 1);

    expect($row->toArray())
        ->toBe([
            'id_uppercat' => null,
            'next_rank' => 1,
        ]);
});
