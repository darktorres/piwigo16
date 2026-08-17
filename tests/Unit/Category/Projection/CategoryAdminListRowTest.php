<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryAdminListRow;

test('fromRow narrows a real DB row into typed properties', function (): void {
    $row = CategoryAdminListRow::fromRow([
        'id' => '1',
        'name' => 'Sample Album',
        'comment' => 'A description',
        'uppercats' => '1',
        'global_rank' => '1',
        'dir' => 'sample_album',
        'status' => 'public',
        'image_order' => 'file ASC',
    ]);

    expect($row->id)
        ->toBe(1)
        ->and($row->name)
        ->toBe('Sample Album')
        ->and($row->comment)
        ->toBe('A description')
        ->and($row->uppercats)
        ->toBe('1')
        ->and($row->globalRank)
        ->toBe('1')
        ->and($row->dir)
        ->toBe('sample_album')
        ->and($row->status)
        ->toBe('public')
        ->and($row->imageOrder)
        ->toBe('file ASC');
});

test('fromRow falls back to safe defaults for a missing/invalid row', function (): void {
    $row = CategoryAdminListRow::fromRow([]);

    expect($row->id)
        ->toBe(0)
        ->and($row->name)
        ->toBe('')
        ->and($row->comment)
        ->toBeNull()
        ->and($row->uppercats)
        ->toBe('')
        ->and($row->globalRank)
        ->toBeNull()
        ->and($row->dir)
        ->toBeNull()
        ->and($row->status)
        ->toBe('')
        ->and($row->imageOrder)
        ->toBeNull();
});

test('toArray round-trips the exact same shape', function (): void {
    $row = CategoryAdminListRow::fromRow([
        'id' => 1,
        'name' => 'Sample Album',
        'comment' => null,
        'uppercats' => '1',
        'global_rank' => '1',
        'dir' => null,
        'status' => 'public',
        'image_order' => null,
    ]);

    expect($row->toArray())
        ->toBe([
            'id' => 1,
            'name' => 'Sample Album',
            'comment' => null,
            'uppercats' => '1',
            'global_rank' => '1',
            'dir' => null,
            'status' => 'public',
            'image_order' => null,
        ]);
});
