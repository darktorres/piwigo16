<?php

declare(strict_types=1);

use Piwigo\Image\Projection\MissingDerivativeRow;

test('constructs with distinct values for every property', function (): void {
    $row = new MissingDerivativeRow(4, 'upload/2026/08/01/photo.jpg', 'jpg', 800, 600, 0);

    expect($row->id)->toBe(4)
        ->and($row->path)->toBe('upload/2026/08/01/photo.jpg')
        ->and($row->representativeExt)->toBe('jpg')
        ->and($row->width)->toBe(800)
        ->and($row->height)->toBe(600)
        ->and($row->rotation)->toBe(0);
});

test('toArray round-trips to the snake_case shape', function (): void {
    $row = new MissingDerivativeRow(4, 'upload/2026/08/01/photo.jpg', 'jpg', 800, 600, 0);

    expect($row->toArray())->toBe([
        'id' => 4,
        'path' => 'upload/2026/08/01/photo.jpg',
        'representative_ext' => 'jpg',
        'width' => 800,
        'height' => 600,
        'rotation' => 0,
    ]);
});

test('accepts null path/representativeExt/width/height/rotation', function (): void {
    $row = new MissingDerivativeRow(4, null, null, null, null, null);

    expect($row->path)->toBeNull()
        ->and($row->representativeExt)->toBeNull()
        ->and($row->width)->toBeNull()
        ->and($row->height)->toBeNull()
        ->and($row->rotation)->toBeNull();
});
