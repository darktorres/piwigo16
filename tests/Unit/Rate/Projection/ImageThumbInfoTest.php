<?php

declare(strict_types=1);

use Piwigo\Rate\Projection\ImageThumbInfo;

test('constructs with distinct values for every property', function (): void {
    $info = new ImageThumbInfo(1, 'Photo 1', 'photo.jpg', 'upload/2026/08/01/photo.jpg', 'jpg', 1);

    expect($info->id)->toBe(1)
        ->and($info->name)->toBe('Photo 1')
        ->and($info->file)->toBe('photo.jpg')
        ->and($info->path)->toBe('upload/2026/08/01/photo.jpg')
        ->and($info->representativeExt)->toBe('jpg')
        ->and($info->level)->toBe(1);
});

test('toArray round-trips the exact same shape the constructor accepted', function (): void {
    $info = new ImageThumbInfo(1, null, 'photo.jpg', 'upload/2026/08/01/photo.jpg', null, 0);

    expect($info->toArray())->toBe([
        'id' => 1,
        'name' => null,
        'file' => 'photo.jpg',
        'path' => 'upload/2026/08/01/photo.jpg',
        'representative_ext' => null,
        'level' => 0,
    ]);
});
