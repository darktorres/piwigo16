<?php

declare(strict_types=1);

use Piwigo\Image\Projection\UploadResultInfo;

test('constructs with distinct values for every property', function (): void {
    $row = new UploadResultInfo(4, 'photo', 'jpg', 'upload/2026/08/01/photo.jpg');

    expect($row->id)->toBe(4)
        ->and($row->name)->toBe('photo')
        ->and($row->representativeExt)->toBe('jpg')
        ->and($row->path)->toBe('upload/2026/08/01/photo.jpg');
});

test('toArray round-trips to the snake_case shape', function (): void {
    $row = new UploadResultInfo(4, 'photo', 'jpg', 'upload/2026/08/01/photo.jpg');

    expect($row->toArray())->toBe([
        'id' => 4,
        'name' => 'photo',
        'representative_ext' => 'jpg',
        'path' => 'upload/2026/08/01/photo.jpg',
    ]);
});

test('accepts a null name/representativeExt', function (): void {
    $row = new UploadResultInfo(4, null, null, 'upload/2026/08/01/photo.jpg');

    expect($row->name)->toBeNull()
        ->and($row->representativeExt)->toBeNull();
});
