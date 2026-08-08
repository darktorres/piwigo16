<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryIdNameUppercatsRank;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryIdNameUppercatsRank(1, 'Sample Album', '1', '1');

    expect($row->id)->toBe(1)
        ->and($row->name)->toBe('Sample Album')
        ->and($row->uppercats)->toBe('1')
        ->and($row->globalRank)->toBe('1');
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new CategoryIdNameUppercatsRank(1, 'Sample Album', '1', '1');

    expect($row->toArray())->toBe([
        'id' => 1,
        'name' => 'Sample Album',
        'uppercats' => '1',
        'global_rank' => '1',
    ]);
});

test('accepts a null globalRank', function (): void {
    $row = new CategoryIdNameUppercatsRank(1, 'Sample Album', '1', null);

    expect($row->globalRank)->toBeNull();
});
