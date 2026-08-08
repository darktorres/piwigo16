<?php

declare(strict_types=1);

use Piwigo\Image\Projection\PathRepresentativeExt;

test('constructs with distinct values for every property', function (): void {
    $row = new PathRepresentativeExt(4, 'upload/2026/08/01/photo.jpg', 'jpg');

    expect($row->id)->toBe(4)
        ->and($row->path)->toBe('upload/2026/08/01/photo.jpg')
        ->and($row->representativeExt)->toBe('jpg');
});

test('toArray round-trips to the snake_case shape', function (): void {
    $row = new PathRepresentativeExt(4, 'upload/2026/08/01/photo.jpg', 'jpg');

    expect($row->toArray())->toBe([
        'id' => 4,
        'path' => 'upload/2026/08/01/photo.jpg',
        'representative_ext' => 'jpg',
    ]);
});

test('accepts a null representativeExt', function (): void {
    $row = new PathRepresentativeExt(4, 'upload/2026/08/01/photo.jpg', null);

    expect($row->representativeExt)->toBeNull()
        ->and($row->toArray()['representative_ext'])->toBeNull();
});
