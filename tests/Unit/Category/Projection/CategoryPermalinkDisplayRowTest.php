<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryPermalinkDisplayRow;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryPermalinkDisplayRow(1, 'sample-album', '1 - Sample Album &radic;', '1', '1');

    expect($row->id)
        ->toBe(1)
        ->and($row->permalink)
        ->toBe('sample-album')
        ->and($row->name)
        ->toBe('1 - Sample Album &radic;')
        ->and($row->uppercats)
        ->toBe('1')
        ->and($row->globalRank)
        ->toBe('1');
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new CategoryPermalinkDisplayRow(1, null, '1 - Sample Album', '1', '1');

    expect($row->toArray())
        ->toBe([
            'id' => 1,
            'permalink' => null,
            'name' => '1 - Sample Album',
            'uppercats' => '1',
            'global_rank' => '1',
        ]);
});
