<?php

declare(strict_types=1);

use Piwigo\Image\Projection\ExtensionBreakdown;

test('constructs with distinct values for every property', function (): void {
    $row = new ExtensionBreakdown('jpg', 12, 4_096);

    expect($row->ext)
        ->toBe('jpg')
        ->and($row->counter)
        ->toBe(12)
        ->and($row->filesize)
        ->toBe(4_096);
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new ExtensionBreakdown('jpg', 12, 4_096);

    expect($row->toArray())
        ->toBe([
            'ext' => 'jpg',
            'counter' => 12,
            'filesize' => 4_096,
        ]);
});
