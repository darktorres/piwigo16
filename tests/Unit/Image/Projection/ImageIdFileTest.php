<?php

declare(strict_types=1);

use Piwigo\Image\Projection\ImageIdFile;

test('constructs with distinct values for every property', function (): void {
    $row = new ImageIdFile(4, 'fixture-photo-1.jpg');

    expect($row->id)->toBe(4)
        ->and($row->file)->toBe('fixture-photo-1.jpg');
});
