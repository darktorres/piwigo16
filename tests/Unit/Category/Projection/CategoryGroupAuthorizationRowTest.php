<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryGroupAuthorizationRow;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryGroupAuthorizationRow(1, '1', '1');

    expect($row->catId)->toBe(1)
        ->and($row->uppercats)->toBe('1')
        ->and($row->globalRank)->toBe('1');
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new CategoryGroupAuthorizationRow(1, '1', null);

    expect($row->toArray())->toBe([
        'cat_id' => 1,
        'uppercats' => '1',
        'global_rank' => null,
    ]);
});
