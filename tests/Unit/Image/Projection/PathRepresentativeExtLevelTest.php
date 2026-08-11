<?php

declare(strict_types=1);

use Piwigo\Image\Projection\PathRepresentativeExtLevel;

test('constructs with distinct values for every property', function (): void {
    $row = new PathRepresentativeExtLevel(4, 'upload/2026/08/01/photo.jpg', 'jpg', 2);

    expect($row->id)
        ->toBe(4)
        ->and($row->path)
        ->toBe('upload/2026/08/01/photo.jpg')
        ->and($row->representativeExt)
        ->toBe('jpg')
        ->and($row->level)
        ->toBe(2);
});

test('toArray round-trips to the snake_case shape', function (): void {
    $row = new PathRepresentativeExtLevel(4, 'upload/2026/08/01/photo.jpg', 'jpg', 2);

    expect($row->toArray())
        ->toBe([
            'id' => 4,
            'path' => 'upload/2026/08/01/photo.jpg',
            'representative_ext' => 'jpg',
            'level' => 2,
        ]);
});

test('accepts a null representativeExt', function (): void {
    $row = new PathRepresentativeExtLevel(4, 'upload/2026/08/01/photo.jpg', null, 0);

    expect($row->representativeExt)
        ->toBeNull()
        ->and($row->toArray()['representative_ext'])->toBeNull();
});
