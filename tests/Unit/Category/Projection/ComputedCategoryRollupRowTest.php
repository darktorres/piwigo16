<?php

declare(strict_types=1);

use Piwigo\Category\Projection\ComputedCategoryRollupRow;

test('constructs with distinct values for every property', function (): void {
    $row = new ComputedCategoryRollupRow(1, null, '1', 1, '2026-07-07 05:02:36', 3);

    expect($row->catId)
        ->toBe(1)
        ->and($row->idUppercat)
        ->toBeNull()
        ->and($row->globalRank)
        ->toBe('1')
        ->and($row->rank)
        ->toBe(1)
        ->and($row->dateLast)
        ->toBe('2026-07-07 05:02:36')
        ->and($row->nbImages)
        ->toBe(3);
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new ComputedCategoryRollupRow(1, null, '1', 1, '2026-07-07 05:02:36', 3);

    expect($row->toArray())
        ->toBe([
            'cat_id' => 1,
            'id_uppercat' => null,
            'global_rank' => '1',
            'rank' => 1,
            'date_last' => '2026-07-07 05:02:36',
            'nb_images' => 3,
        ]);
});
