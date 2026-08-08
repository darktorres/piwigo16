<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryChildRow;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryChildRow(2, 'Nested Sub Album', 'nested-sub-album', 'nested_sub_album', 1, 'public');

    expect($row->id)->toBe(2)
        ->and($row->name)->toBe('Nested Sub Album')
        ->and($row->permalink)->toBe('nested-sub-album')
        ->and($row->dir)->toBe('nested_sub_album')
        ->and($row->rank)->toBe(1)
        ->and($row->status)->toBe('public');
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new CategoryChildRow(2, 'Nested Sub Album', null, null, null, 'public');

    expect($row->toArray())->toBe([
        'id' => 2,
        'name' => 'Nested Sub Album',
        'permalink' => null,
        'dir' => null,
        'rank' => null,
        'status' => 'public',
    ]);
});
