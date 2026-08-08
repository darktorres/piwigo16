<?php

declare(strict_types=1);

use Piwigo\Calendar\Projection\RandomImageForDay;

test('constructs with distinct values for every property', function (): void {
    $row = new RandomImageForDay(4, 'fixture-photo-1.jpg', 'jpg', 'upload/2026/08/01/fixture-photo-1.jpg', 800, 600, 0, 3);

    expect($row->id)->toBe(4)
        ->and($row->file)->toBe('fixture-photo-1.jpg')
        ->and($row->representativeExt)->toBe('jpg')
        ->and($row->path)->toBe('upload/2026/08/01/fixture-photo-1.jpg')
        ->and($row->width)->toBe(800)
        ->and($row->height)->toBe(600)
        ->and($row->rotation)->toBe(0)
        ->and($row->dow)->toBe(3);
});

test('toArray round-trips the snake_case shape, excluding dow', function (): void {
    $row = new RandomImageForDay(4, 'fixture-photo-1.jpg', 'jpg', 'upload/2026/08/01/fixture-photo-1.jpg', 800, 600, 0, 3);

    expect($row->toArray())->toBe([
        'id' => 4,
        'file' => 'fixture-photo-1.jpg',
        'representative_ext' => 'jpg',
        'path' => 'upload/2026/08/01/fixture-photo-1.jpg',
        'width' => 800,
        'height' => 600,
        'rotation' => 0,
    ]);
});

test('accepts a null representativeExt/width/height/rotation', function (): void {
    $row = new RandomImageForDay(4, 'fixture-photo-1.jpg', null, 'upload/2026/08/01/fixture-photo-1.jpg', null, null, null, 3);

    expect($row->representativeExt)->toBeNull()
        ->and($row->width)->toBeNull()
        ->and($row->height)->toBeNull()
        ->and($row->rotation)->toBeNull();
});
