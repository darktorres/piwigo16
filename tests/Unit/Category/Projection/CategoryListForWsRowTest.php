<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryListForWsRow;

test('fromRow narrows a real DB row into typed properties', function (): void {
    $row = CategoryListForWsRow::fromRow([
        'id' => '1',
        'name' => 'Sample Album',
        'comment' => 'A description',
        'permalink' => 'sample-album',
        'status' => 'public',
        'uppercats' => '1',
        'global_rank' => '1',
        'id_uppercat' => null,
        'representative_picture_id' => '4',
        'image_order' => 'file ASC',
    ]);

    expect($row->id)
        ->toBe(1)
        ->and($row->name)
        ->toBe('Sample Album')
        ->and($row->comment)
        ->toBe('A description')
        ->and($row->permalink)
        ->toBe('sample-album')
        ->and($row->status)
        ->toBe('public')
        ->and($row->uppercats)
        ->toBe('1')
        ->and($row->globalRank)
        ->toBe('1')
        ->and($row->idUppercat)
        ->toBeNull()
        ->and($row->representativePictureId)
        ->toBe(4)
        ->and($row->imageOrder)
        ->toBe('file ASC');
});

test('fromRow falls back to safe defaults for a missing/invalid row', function (): void {
    $row = CategoryListForWsRow::fromRow([]);

    expect($row->id)
        ->toBe(0)
        ->and($row->name)
        ->toBe('')
        ->and($row->comment)
        ->toBeNull()
        ->and($row->permalink)
        ->toBeNull()
        ->and($row->status)
        ->toBe('')
        ->and($row->uppercats)
        ->toBe('')
        ->and($row->globalRank)
        ->toBeNull()
        ->and($row->idUppercat)
        ->toBeNull()
        ->and($row->representativePictureId)
        ->toBeNull()
        ->and($row->imageOrder)
        ->toBeNull();
});

test('toArray round-trips the exact same shape', function (): void {
    $row = CategoryListForWsRow::fromRow([
        'id' => 1,
        'name' => 'Sample Album',
        'comment' => null,
        'permalink' => null,
        'status' => 'public',
        'uppercats' => '1',
        'global_rank' => '1',
        'id_uppercat' => null,
        'representative_picture_id' => null,
        'image_order' => null,
    ]);

    expect($row->toArray())
        ->toBe([
            'id' => 1,
            'name' => 'Sample Album',
            'comment' => null,
            'permalink' => null,
            'status' => 'public',
            'uppercats' => '1',
            'global_rank' => '1',
            'id_uppercat' => null,
            'representative_picture_id' => null,
            'image_order' => null,
        ]);
});
