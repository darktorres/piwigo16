<?php

declare(strict_types=1);

use Piwigo\Category\Projection\ActivePermalinkRow;

test('constructs with distinct values for every property', function (): void {
    $row = new ActivePermalinkRow(1, 'sample-album', '1', '1');

    expect($row->id)
        ->toBe(1)
        ->and($row->permalink)
        ->toBe('sample-album')
        ->and($row->uppercats)
        ->toBe('1')
        ->and($row->globalRank)
        ->toBe('1');
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new ActivePermalinkRow(1, null, '1', null);

    expect($row->toArray())
        ->toBe([
            'id' => 1,
            'permalink' => null,
            'uppercats' => '1',
            'global_rank' => null,
        ]);
});
