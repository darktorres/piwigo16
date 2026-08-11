<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryAlbumTreeRow;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryAlbumTreeRow(1, 'Sample Album', 1, 'public', true, '1', '2026-08-01 00:00:00');

    expect($row->id)
        ->toBe(1)
        ->and($row->name)
        ->toBe('Sample Album')
        ->and($row->rank)
        ->toBe(1)
        ->and($row->status)
        ->toBe('public')
        ->and($row->visible)
        ->toBeTrue()
        ->and($row->uppercats)
        ->toBe('1')
        ->and($row->lastmodified)
        ->toBe('2026-08-01 00:00:00');
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new CategoryAlbumTreeRow(1, 'Sample Album', null, 'public', false, '1', '2026-08-01 00:00:00');

    expect($row->toArray())
        ->toBe([
            'id' => 1,
            'name' => 'Sample Album',
            'rank' => null,
            'status' => 'public',
            'visible' => false,
            'uppercats' => '1',
            'lastmodified' => '2026-08-01 00:00:00',
        ]);
});
