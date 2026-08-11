<?php

declare(strict_types=1);

use Piwigo\Image\Projection\ImageIdExt;

test('constructs with distinct values for every property', function (): void {
    $row = new ImageIdExt(4, 'jpg');

    expect($row->imageId)
        ->toBe(4)
        ->and($row->ext)
        ->toBe('jpg');
});
